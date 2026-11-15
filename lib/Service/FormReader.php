<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Store\SecretOverlay;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ILockingStorage;
use OCP\Files\Storage\IStorage;
use OCP\HintException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Reads a form file below Nextcloud's file locks, so a save in progress never fails a reader (7a, 3a-3). What a read
 * meets mid-save is half a file or a decryption error; it reads again until the form is whole. The same unusable bytes
 * or the same error, while no one is saving the form, are a broken file (500); still unreadable at the deadline is a
 * busy form (503). The read is announced as Nextcloud's own would be, so the audit log still records it.
 */
class FormReader {
	public const WAIT_SECONDS = 3.0;
	/** Only a stalled writer leaves the same half file this long. */
	private const STEADY_BYTES_SECONDS = 0.25;
	/** An encrypted save fails every read in the same place while it lasts: a broken file only with no save under way. */
	private const STEADY_ERROR_SECONDS = 1.0;

	public function __construct(
		private DecodedForms $decoded,
		private IEventDispatcher $dispatcher,
		private FormLock $lock,
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
		private ?SecretOverlay $secrets = null,
	) {
	}

	/**
	 * @param int|null $maxBytes larger is answered as not found: what an anonymous request may make the server read
	 * @return array the decoded form
	 * @throws NotFoundException larger than $maxBytes
	 * @throws FormLockedException still unreadable at the deadline
	 * @throws FormBrokenException the file holds no form
	 */
	public function read(File $file, ?int $maxBytes = null, float $waitSeconds = self::WAIT_SECONDS): array {
		$fileId = $file->getId();
		$storage = $file->getStorage();
		$path = $file->getInternalPath();
		$deadline = microtime(true) + $waitSeconds;
		$this->announce($file);
		/** @var array{0: string, 1: float, 2: float}|null $unusable what made the last read unusable, since when, and how long counts as steady */
		$unusable = null;
		$failure = null;
		for ($attempt = 0; ; $attempt++) {
			// Asked before the read it judges, and again after it: a save that ends or starts during it is no broken file.
			$idle = false;
			if ($unusable !== null && $unusable[0] !== 'missing' && microtime(true) - $unusable[1] >= $unusable[2]) {
				$idle = !$this->beingSaved($file, $storage, $path);
				if (!$idle) {
					// After a save the whole form usually reads at once: Nextcloud's lock is only asked again a full window on.
					$unusable[1] = microtime(true);
				}
			}
			$readStarted = microtime(true);
			$raw = null;
			$error = null;
			try {
				$raw = $storage->file_get_contents($path);
			} catch (\Throwable $e) {
				// Encryption mid-save: a bad signature, a short block, a failed decryption.
				$error = $failure = $e;
			}
			if (is_string($raw)) {
				if ($maxBytes !== null && strlen($raw) > $maxBytes) {
					throw new NotFoundException('Form not found');
				}
				$form = $this->decoded->recall($fileId, $raw) ?? json_decode($raw, true);
				if (is_array($form)) {
					$this->decoded->remember($fileId, $raw, $form);
					return $this->secrets === null ? $form : $this->secrets->add($this->secrets->stored($fileId, is_string($form['id'] ?? null) ? $form['id'] : null), $form);
				}
				$why = 'bytes:' . hash('xxh128', $raw);
				$steady = self::STEADY_BYTES_SECONDS;
			} else {
				// The same error from the same place: no save is changing what the read meets.
				$why = $error === null ? 'missing' : 'error:' . get_class($error) . '@' . $error->getFile() . ':' . $error->getLine();
				$steady = self::STEADY_ERROR_SECONDS;
			}
			if ($unusable === null || $unusable[0] !== $why) {
				// From when this read began, so a slow read still counts; within the wait, so a short one (the forms list)
				// still tells a broken file from a busy one.
				$unusable = [$why, $readStarted, min($steady, $waitSeconds / 2)];
			} elseif ($idle && !$this->beingSaved($file, $storage, $path)) {
				// The same all this time, and nobody saving before or after this read: the file is broken (review of the 3a fixes).
				if ($error !== null) {
					// An empty file is no news; a form that cannot be decrypted or reached is.
					$this->logger->warning('FormVox: form ' . $fileId . ' cannot be read: ' . get_class($error), ['app' => Application::APP_ID, 'exception' => $error]);
				}
				throw new FormBrokenException($failure);
			}
			if (microtime(true) >= $deadline) {
				break;
			}
			FormLock::backoff($attempt, $deadline);
		}

		$this->logger->warning('FormVox: form ' . $fileId . ' stayed unreadable for ' . $waitSeconds . ' s', ['app' => Application::APP_ID, 'exception' => $failure]);
		throw new FormLockedException('Form ' . $fileId . ' stayed unreadable');
	}

	/**
	 * What a read through Nextcloud announces, so the audit log records it; a listener that fails is logged and the read
	 * goes on, as Nextcloud's own hooks do (review of the 3a fixes).
	 */
	private function announce(File $file): void {
		try {
			$this->dispatcher->dispatchTyped(new BeforeNodeReadEvent($file));
		} catch (\Throwable $e) {
			// What OC_Hook rethrows; the second is a private class, so it is compared by name.
			if ($e instanceof HintException || in_array('OC\ServerNotAvailableException', [get_class($e), ...array_values(class_parents($e))], true)) {
				// Logged first, as OC_Hook does: the forms list skips an unreadable form without a word.
				$this->logger->error('FormVox: reading form ' . $file->getId() . ' stopped: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
				throw $e;
			}
			$this->logger->error('FormVox: a listener failed on reading form ' . $file->getId() . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	/** Whether someone is saving the form: a writer holds FormVox's lock on it, or Nextcloud's on its file. */
	private function beingSaved(File $file, IStorage $storage, string $path): bool {
		try {
			if ($this->lock->heldByAnother((int)$file->getId())) {
				return true;
			}
			if (!$storage instanceof ILockingStorage) {
				return false;
			}
			$storage->acquireLock($path, ILockingProvider::LOCK_SHARED, $this->lockingProvider);
		} catch (LockedException) {
			return true;
		} catch (\Throwable) {
			// Cannot tell: the read decides.
			return false;
		}
		try {
			$storage->releaseLock($path, ILockingProvider::LOCK_SHARED, $this->lockingProvider);
		} catch (\Throwable) {
		}
		return false;
	}
}
