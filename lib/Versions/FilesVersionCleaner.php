<?php

declare(strict_types=1);

namespace OCA\FormVox\Versions;

use OCP\Files\File;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Deletes file versions via the files_versions app's IVersionManager.
 *
 * The IVersionManager is resolved lazily through the server container and
 * guarded by class_exists, because files_versions is optional: when it is
 * disabled, {@see NullVersionCleaner} is bound instead of this class, so in
 * practice this class only runs when the app IS present. The lazy get is kept
 * defensive anyway.
 *
 * IUserSession + IUserManager are injected (not fetched statically as the code
 * used to) so this is unit-testable: a public submission has no session, so the
 * file owner is resolved through IUserManager instead.
 */
class FilesVersionCleaner implements IFormVersionCleaner {
	private IUserSession $userSession;
	private IUserManager $userManager;

	public function __construct(IUserSession $userSession, IUserManager $userManager) {
		$this->userSession = $userSession;
		$this->userManager = $userManager;
	}

	public function deleteVersionsForFile(File $file): void {
		try {
			$versionsBackend = \OCP\Server::get(\OCA\Files_Versions\Versions\IVersionManager::class);
			$user = $this->userSession->getUser();

			if ($user === null) {
				// Try to get user from file owner for public submissions
				$owner = $file->getOwner();
				if ($owner === null) {
					return;
				}
				// Get IUser object from owner
				$user = $this->userManager->get($owner->getUID());
				if ($user === null) {
					return;
				}
			}

			// Get all versions for this file
			$versions = $versionsBackend->getVersionsForFile($user, $file);

			// Delete each version
			foreach ($versions as $version) {
				$versionsBackend->deleteVersion($version);
			}
		} catch (\Exception $e) {
			// Versions app might not be available or other error, ignore
		}
	}
}
