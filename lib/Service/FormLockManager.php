<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\Store\SecretOverlay;
use OCP\Files\File;
use OCP\IUserSession;
use OCP\Lock\LockedException;

/**
 * The concurrency core: every read-modify-write of a .fvform file (editor saves, settings, a form put over one, tidying
 * after a move) goes through {@see mutateFormFileWithLock()}, one writer per form at a time (FormLock), so concurrent
 * writes never clobber each other (#3/#7/#90/#97/#101). A writer that finds the form busy waits instead of failing, up
 * to a deadline; then the caller gets 503 busy and may retry. The write is checked for room and for a silent cut
 * (FormWriteGuard), refused writes carry diagnostics, and a change to the form is kept as a version (FormVersionListener).
 */
class FormLockManager {
	/** How long a writer waits for the form, also for Nextcloud's own lock on the file while a reader holds it. */
	private const WAIT_SECONDS = 10.0;

	public function __construct(
		private IUserSession $userSession,
		private OwnWrites $ownWrites,
		private FormWriteGuard $writeGuard,
		private DecodedForms $decoded,
		private FormLock $lock,
		private float $waitSeconds = self::WAIT_SECONDS,
		private ?SecretOverlay $secrets = null,
	) {
	}

	/** How long a writer waits for the form before it is busy. */
	public function waitSeconds(): float {
		return $this->waitSeconds;
	}

	/**
	 * Run $mutator against the form as it is on disk now, under the form's lock, and write the result.
	 *
	 * The $mutator receives the freshly-read form by reference; capacity, duplicate and limit checks in it are therefore
	 * race-free. Throwing from it aborts the write (nothing is persisted) and the exception propagates.
	 *
	 * @param callable $mutator function(array &$form): mixed
	 * @param bool $version true for a change to the form itself, kept as a version; responses leave none (PO 5)
	 * @param bool $stamp false for housekeeping that leaves the form as it was: its modified_at and the file's time stay
	 * @param bool $counted true for a write within what the file holds already, which a full quota may not refuse
	 * @return mixed whatever $mutator returns (usually null)
	 * @throws FormLockedException the form stayed busy until the deadline
	 */
	public function mutateFormFileWithLock(File $file, callable $mutator, bool $version = false, bool $stamp = true, bool $counted = false) {
		$deadline = microtime(true) + $this->waitSeconds;
		$fileId = (int)$file->getId();
		$token = $this->lock->acquire($fileId, $deadline);
		try {
			// Read straight from storage: what is on disk now, not a cached copy
			$storage = $file->getStorage();
			$internalPath = $file->getInternalPath();
			$content = $storage->file_get_contents($internalPath);
			if (!is_string($content)) {
				throw new FormBrokenException();
			}
			$form = $this->decoded->recall($fileId, $content) ?? json_decode($content, true);
			if (!is_array($form)) {
				throw new FormBrokenException();
			}
			$stored = $this->secrets?->stored($fileId);
			$form = $this->secrets?->add($stored, $form) ?? $form;
			// What a stored form's file says of its responses counts for nothing: the database holds them.
			$form = $stored?->formOnly($form) ?? $form;
			$secretsBefore = SecretOverlay::secretsOf($form);

			$result = $mutator($form);
			$form = $stored?->formOnly($form) ?? $form;
			if ($stamp) {
				$form['modified_at'] = date('c');
			}
			$revokeSecrets = null;
			$addSecrets = null;
			if ($this->secrets !== null) {
				[$form, $revokeSecrets, $addSecrets] = $this->secrets->split($stored, $form, $secretsBefore);
			}

			// Through the node API: storage wrappers that refuse a write by returning false (the group-folder mask, the
			// ACL wrapper, some object stores) make putContent() throw, where a raw storage write looked like success
			// and lost the response (#90, #97, #101).
			$payload = json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			$expectedBytes = strlen($payload);
			// A holder that stalled past the lock's time may have a successor by now: then this copy is stale.
			if (!$this->lock->stillHeld($fileId, $token)) {
				throw new FormLockedException('Form ' . $fileId . ' was taken over while this write waited');
			}
			if ($revokeSecrets !== null) {
				$revokeSecrets();
			}
			// Housekeeping keeps the file's time too: a save right after keeps its version, which Nextcloud names after it.
			$mtime = $stamp ? null : $file->getMTime();
			$this->writeWaiting($file, $storage, $internalPath, $payload, $content, $version, $deadline, $counted, $mtime);

			// A backend that reports a positive but short write would slip past putContent(): compare the stored size
			// (#97). The encryption wrapper reports the plain-text size, which is what is compared; false means unknown.
			$writtenBytes = $storage->filesize($internalPath);
			if ($writtenBytes !== false && $writtenBytes < $expectedBytes) {
				throw new \RuntimeException(
					'Short write for fileId ' . $fileId
					. ' (stored ' . var_export($writtenBytes, true) . ' of ' . $expectedBytes . ' bytes)'
					. ' [' . $this->describeWriteContext($file, $storage, $internalPath) . ']'
				);
			}
			if ($addSecrets !== null) {
				$addSecrets();
			}
			// A read of these bytes later in this request need not decode them again.
			$this->decoded->remember($fileId, $payload, $form);

			return $result;
		} finally {
			$this->lock->release($fileId, $token);
		}
	}

	/**
	 * Nextcloud takes its own lock on the file to write it; a reader that holds it for a moment is waited for, as far
	 * as the deadline, instead of failing the write.
	 *
	 * @param int|null $mtime the file's time to put back after the write, within the same window the quota allows
	 * @throws FormLockedException
	 */
	private function writeWaiting(File $file, $storage, string $internalPath, #[\SensitiveParameter] string $payload, #[\SensitiveParameter] string $previous, bool $version, float $deadline, bool $counted, ?int $mtime): void {
		$write = function () use ($file, $payload, $mtime): void {
			$file->putContent($payload);
			if ($mtime !== null) {
				$file->touch($mtime);
			}
		};
		for ($attempt = 0; ; $attempt++) {
			try {
				$this->writeGuard->write($storage, $internalPath, $payload, fn () => $this->ownWrites->during($write, $version), $previous, $counted);
				return;
			} catch (LockedException $e) {
				if (microtime(true) >= $deadline) {
					throw new FormLockedException('Form ' . $file->getId() . ' stayed locked by Nextcloud', 0, $e);
				}
				FormLock::backoff($attempt, $deadline);
			} catch (ClientErrorException $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new \RuntimeException(
					'Could not write form file (fileId ' . $file->getId() . '): ' . $e->getMessage()
					. ' [' . $this->describeWriteContext($file, $storage, $internalPath) . ']',
					0,
					$e
				);
			}
		}
	}

	private function describeWriteContext(File $file, $storage, string $internalPath): string {
		$parts = [];

		try {
			$parts[] = 'storage=' . get_class($storage);
		} catch (\Throwable $e) {
			$parts[] = 'storage=?';
		}

		try {
			$parts[] = 'updatable=' . var_export($file->isUpdateable(), true);
		} catch (\Throwable $e) {
			$parts[] = 'updatable=?';
		}

		try {
			$parts[] = 'storagePerms=' . var_export($storage->getPermissions($internalPath), true);
		} catch (\Throwable $e) {
			$parts[] = 'storagePerms=?';
		}

		try {
			$parts[] = 'freeSpace=' . var_export($storage->free_space($internalPath), true);
		} catch (\Throwable $e) {
			$parts[] = 'freeSpace=?';
		}

		try {
			$parts[] = 'user=' . ($this->userSession->getUser()?->getUID() ?? 'anonymous');
		} catch (\Throwable $e) {
			$parts[] = 'user=?';
		}

		return implode(' ', $parts);
	}
}
