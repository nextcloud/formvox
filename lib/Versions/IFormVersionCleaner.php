<?php

declare(strict_types=1);

namespace OCA\FormVox\Versions;

use OCP\Files\File;

/**
 * Removes the file versions that a response/form write would otherwise create.
 *
 * Every .fvform write goes through the shared lock, and each write would leave a
 * version behind — for a busy form that is one version per submission, bloating
 * version history with noise. The lock path deletes them immediately after a
 * successful write.
 *
 * Behind an interface for two reasons: the real implementation depends on the
 * optional files_versions app (which may be disabled), and injecting the
 * dependency removes the unmockable static \OCP\Server::get() calls the logic
 * used to make. When files_versions is unavailable, {@see NullVersionCleaner}
 * is bound instead.
 */
interface IFormVersionCleaner {
	/**
	 * Delete all stored versions of $file. Best-effort: never throws — a
	 * failure here must not fail the write that just succeeded.
	 */
	public function deleteVersionsForFile(File $file): void;
}
