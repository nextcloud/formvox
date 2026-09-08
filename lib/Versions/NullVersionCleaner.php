<?php

declare(strict_types=1);

namespace OCA\FormVox\Versions;

use OCP\Files\File;

/**
 * No-op version cleaner, bound when the files_versions app is unavailable.
 *
 * If there is no version backend, writes create no versions to clean up, so
 * doing nothing is correct.
 */
class NullVersionCleaner implements IFormVersionCleaner {
	public function deleteVersionsForFile(File $file): void {
		// No version backend — nothing to delete.
	}
}
