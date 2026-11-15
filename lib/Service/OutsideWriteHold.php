<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * The form's lock for a write from outside FormVox (Files, WebDAV, another app), from before that write to after it
 * (7a, 3b-3, spike M2/A1): submissions wait meanwhile instead of being written over, and the merge reads what is
 * there. One per request, shared by the WebDAV plugin, FormIntake and FormMovedListener. Waited for briefly only,
 * as such a write may hold Nextcloud's lock that a FormVox writer holding the form's lock waits on.
 */
class OutsideWriteHold {
	public const WAIT_SECONDS = 3.0;

	/** @var array<int, string> the tokens held in this request, by file id */
	private array $tokens = [];

	public function __construct(
		private FormLock $lock,
		private float $waitSeconds = self::WAIT_SECONDS,
	) {
	}

	/**
	 * @param float|null $waitSeconds how long; null: briefly, as a write from outside may hold Nextcloud's lock
	 * @throws FormLockedException a FormVox write held the form all that time
	 */
	public function take(int $fileId, ?float $waitSeconds = null): void {
		if (!$this->holds($fileId)) {
			$this->tokens[$fileId] = $this->lock->acquire($fileId, microtime(true) + ($waitSeconds ?? $this->waitSeconds));
		}
	}

	/** Only a hold that is still ours: one a long-running process kept past the lock's time is gone. */
	public function holds(int $fileId): bool {
		if (isset($this->tokens[$fileId]) && !$this->lock->stillHeld($fileId, $this->tokens[$fileId])) {
			unset($this->tokens[$fileId]);
		}
		return isset($this->tokens[$fileId]);
	}

	/** Also when the write failed; what is left is released when the request ends. */
	public function release(int $fileId): void {
		if (isset($this->tokens[$fileId])) {
			$this->lock->release($fileId, $this->tokens[$fileId]);
			unset($this->tokens[$fileId]);
		}
	}

	public function releaseAll(): void {
		foreach (array_keys($this->tokens) as $fileId) {
			$this->release($fileId);
		}
	}
}
