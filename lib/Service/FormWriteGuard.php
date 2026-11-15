<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCP\Encryption\IManager;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * A form file is written whole, so a write that does not fit would leave a cut-off form in place of the old one.
 * Nextcloud refuses such a write, except under the encryption app's wrapper (also with server-side encryption off):
 * there its quota stream cuts the file off silently. So: check the room first, and where the cut is silent, read
 * the file back and put the old form back if what is there is a cut-off part of what was written.
 */
class FormWriteGuard {
	/** The encryption module's header and blocks: 8 KiB on disk per 8,096 bytes of plain text, 6,072 in legacy base64. */
	private const ENCRYPTED_HEADER = 8192;
	private const ENCRYPTED_BLOCK = 8192;
	private const PLAIN_PER_BLOCK = 8096;
	private const PLAIN_PER_LEGACY_BLOCK = 6072;
	/** A read-back that fails is tried again this long: object storage may not show a fresh write at once. */
	private const READ_BACK_SECONDS = 0.5;
	/** What a counted write may grow by: pretty printing and the empty counts a tidied, minified form gains. */
	private const COUNTED_MARGIN = 8192;

	public function __construct(
		private IManager $encryption,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run $write, which stores $payload at $path, without ever leaving a cut-off form behind.
	 *
	 * @param callable(): mixed $write
	 * @param string|null $previous what the file holds now, when the caller already has it
	 * @param bool $counted the bytes were counted already: a payload no larger than what the file holds is written
	 *                      past the quota, as a put-back is, so a full account can still shrink its form
	 * @throws RequestException storage_full: nothing was changed, or the old form was put back
	 */
	public function write(IStorage $storage, string $path, #[\SensitiveParameter] string $payload, callable $write, #[\SensitiveParameter] ?string $previous = null, bool $counted = false): void {
		$free = $storage->free_space($path);
		// false or negative: unknown, not computed or unlimited; then no quota stream is put around the write either.
		$limited = (is_int($free) || is_float($free)) && $free >= 0;
		$silent = $limited && $this->cutsSilently($storage);
		$lifted = [];
		if ($limited && $counted && $previous !== null && strlen($payload) <= strlen($previous) + self::COUNTED_MARGIN) {
			$lifted = $this->quotaWrappers($storage);
		} elseif ($limited && $free < $this->bytesOnDisk(strlen($payload), $silent && $this->encryption->isEnabled())) {
			throw RequestException::storageFull();
		}
		foreach ($lifted as $quota) {
			$quota->enableQuota(false);
		}
		try {
			$this->writeWhole($storage, $path, $payload, $write, $previous, $silent);
		} finally {
			foreach ($lifted as $quota) {
				$quota->enableQuota(true);
			}
		}
	}

	/** @param callable(): mixed $write */
	private function writeWhole(IStorage $storage, string $path, #[\SensitiveParameter] string $payload, callable $write, #[\SensitiveParameter] ?string $previous, bool $silent): void {
		if (!$silent) {
			$write();
			return;
		}

		$previous ??= $this->readBack($storage, $path, 0.0);
		$failure = null;
		try {
			$write();
		} catch (LockedException|FormLockedException $busy) {
			// Refused before anything was written: what is on disk is someone else's, never a cut of ours.
			throw $busy;
		} catch (\Throwable $e) {
			// A short count or an error halfway: what is on disk decides, not the exception.
			$failure = $e;
		}
		$now = $this->readBack($storage, $path, self::READ_BACK_SECONDS);

		if ($now === $payload || ($now === $previous && $failure !== null)) {
			if ($failure !== null) {
				throw $failure;
			}
			return;
		}
		if ($now === null || (strlen($now) < strlen($payload) && str_starts_with($payload, $now))) {
			// Never with the exception: its trace carries the form being written.
			$this->logger->error('FormVox: a form file was cut off while being written (' . $path . '); the previous version is put back' . ($failure === null ? '' : ': ' . get_class($failure) . ': ' . $failure->getMessage()), ['app' => Application::APP_ID]);
			if ($previous !== null) {
				$this->rewrite($storage, $path, $previous);
			}
			throw RequestException::storageFull();
		}
		// Neither what was written nor a cut of it: another write came in between, and that one stays.
		$this->logger->warning('FormVox: a form file was changed by another write while being written (' . $path . ')', ['app' => Application::APP_ID]);
		if ($failure !== null) {
			throw $failure;
		}
	}

	/**
	 * Write bytes that were counted before (what a form held, or the server's data a write left out), also when the
	 * account is out of room: the quota is lifted for this write. Through the quota stream a put-back was cut off as
	 * well (spike t6c).
	 *
	 * @return bool whether the file holds $bytes now
	 */
	public function rewrite(IStorage $storage, string $path, #[\SensitiveParameter] string $bytes): bool {
		$lifted = $this->quotaWrappers($storage);
		try {
			foreach ($lifted as $quota) {
				$quota->enableQuota(false);
			}
			$storage->file_put_contents($path, $bytes);
			$storage->getUpdater()->update($path);
			if ($this->readBack($storage, $path, self::READ_BACK_SECONDS) === $bytes) {
				return true;
			}
			$this->logger->critical('FormVox: a form file could not be written whole (' . $path . '); restore it from a version or a backup', ['app' => Application::APP_ID]);
		} catch (\Throwable $e) {
			// Never with the exception: its trace carries the form being written.
			$this->logger->critical('FormVox: could not write a form file back (' . $path . '): ' . get_class($e) . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
		} finally {
			foreach ($lifted as $quota) {
				$quota->enableQuota(true);
			}
		}
		return false;
	}

	/** The file's bytes, or null while it cannot be read; tried again until $waitSeconds have passed. */
	private function readBack(IStorage $storage, string $path, float $waitSeconds): ?string {
		$deadline = microtime(true) + $waitSeconds;
		for ($attempt = 0; ; $attempt++) {
			try {
				$read = $storage->file_get_contents($path);
				if (is_string($read)) {
					return $read;
				}
			} catch (\Throwable) {
				// A cut inside the encryption header cannot be decrypted; object storage may lag behind.
			}
			if (microtime(true) >= $deadline) {
				return null;
			}
			FormLock::backoff($attempt, $deadline);
		}
	}

	/**
	 * The quota wrappers around this storage (a home's, a team folder's), found by what they can do: Nextcloud's
	 * wrappers are not public API.
	 *
	 * @return list<object>
	 */
	private function quotaWrappers(IStorage $storage): array {
		$found = [];
		$layer = $storage;
		while (is_object($layer)) {
			if (method_exists($layer, 'enableQuota')) {
				$found[] = $layer;
			}
			$layer = method_exists($layer, 'getWrapperStorage') ? $layer->getWrapperStorage() : null;
		}
		return $found;
	}

	/** The encryption app wraps storages even with server-side encryption off; its quota stream truncates. */
	private function cutsSilently(IStorage $storage): bool {
		return $storage->instanceOfStorage('OC\Files\Storage\Wrapper\Encryption') === true;
	}

	private function bytesOnDisk(int $plain, bool $encrypted): int {
		if (!$encrypted) {
			return $plain;
		}
		$perBlock = $this->config->getSystemValueBool('encryption.use_legacy_base64_encoding', false) ? self::PLAIN_PER_LEGACY_BLOCK : self::PLAIN_PER_BLOCK;
		return self::ENCRYPTED_HEADER + (int)ceil($plain / $perBlock) * self::ENCRYPTED_BLOCK;
	}
}
