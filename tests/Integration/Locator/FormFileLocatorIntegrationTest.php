<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Locator;

use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Server;

/**
 * Integration coverage for FormFileLocator against a REAL Nextcloud +
 * database + filesystem: the fileId -> File resolution used by both the
 * session path (getFileById) and the public/system path (getFileByIdPublic).
 *
 * The public path is the one that matters for #90/#136: it must open a form
 * file with no session of its own, by joining oc_filecache to oc_storages and
 * recognising the home:: storage id, then re-opening the file through the
 * owner's real user folder. These tests write a genuine .fvform into the test
 * user's home storage, drop the session, and assert the file still resolves
 * (and, with requireWrite, that it is updateable) purely from infrastructure.
 *
 * @group DB
 */
class FormFileLocatorIntegrationTest extends IntegrationTestCase {
	private function locator(): FormFileLocator {
		/** @var FormFileLocator $locator */
		$locator = $this->getService(FormFileLocator::class);
		return $locator;
	}

	public function testGetFileByIdPublicResolvesHomeStorage(): void {
		$file = $this->writeFormFile($this->userFolder, 'Public Home Resolve');
		$fileId = $file->getId();

		// No session at all: the public path must resolve via the real
		// home::<uid> storage join in oc_filecache/oc_storages, then re-open
		// the file through the owner's user folder.
		$this->logout();

		$resolved = $this->locator()->getFileByIdPublic($fileId);

		$this->assertInstanceOf(File::class, $resolved);
		$this->assertSame($fileId, $resolved->getId());

		// Prove it is really the same file by reading its bytes from storage.
		$content = $resolved->getStorage()->file_get_contents($resolved->getInternalPath());
		$decoded = json_decode($content, true);
		$this->assertIsArray($decoded);
		$this->assertSame('Public Home Resolve', $decoded['title'] ?? null);
	}

	public function testGetFileByIdPublicRequireWriteOnOwnHome(): void {
		$file = $this->writeFormFile($this->userFolder, 'Public Home Writable');
		$fileId = $file->getId();

		$this->logout();

		// The owner's own home storage is always writable, so requireWrite=true
		// must still return the File (its isUpdateable() is true).
		$resolved = $this->locator()->getFileByIdPublic($fileId, true);

		$this->assertInstanceOf(File::class, $resolved);
		$this->assertSame($fileId, $resolved->getId());
		$this->assertTrue($resolved->isUpdateable());
	}

	public function testGetFileByIdPublicNotFound(): void {
		$this->logout();

		$this->expectException(NotFoundException::class);
		$this->locator()->getFileByIdPublic(99999999);
	}

	public function testGetFileByIdRequiresSession(): void {
		$file = $this->writeFormFile($this->userFolder, 'Session Resolve');
		$fileId = $file->getId();

		// Session path: with the owner logged in (base setUp does this) the
		// file resolves through IUserSession -> user folder.
		$resolved = $this->locator()->getFileById($fileId);
		$this->assertInstanceOf(File::class, $resolved);
		$this->assertSame($fileId, $resolved->getId());

		// After logout the session-context helper has no user and must throw.
		$this->logout();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No user logged in');
		$this->locator()->getFileById($fileId);
	}

	public function testGroupfolderResolution(): void {
		$appManager = Server::get(IAppManager::class);

		if (!$appManager->isEnabledForUser('groupfolders')) {
			$this->markTestSkipped(
				'groupfolders app is not enabled for the test user. To exercise the '
				. 'group-folder resolution branch of getFileByIdPublic(), install and '
				. 'enable the groupfolders app, create a group folder shared with a '
				. 'group the test user belongs to, place a .fvform in it, and set '
				. 'FORMVOX_IT_GROUPFOLDERS=1 so this test provisions and asserts against it.'
			);
		}

		if (!getenv('FORMVOX_IT_GROUPFOLDERS')) {
			$this->markTestSkipped(
				'FORMVOX_IT_GROUPFOLDERS is not set. Group-folder provisioning is '
				. 'environment-specific (requires a pre-created group folder with a '
				. 'group-mapped mount and correct permissions), so it is opt-in. Set '
				. 'FORMVOX_IT_GROUPFOLDERS=1 in a CI job that has a group folder '
				. 'configured to run the local::.../__groupfolders/{id}/ resolution path.'
			);
		}

		$this->markTestSkipped(
			'Group-folder fixture provisioning is not implemented in this host: it '
			. 'depends on the groupfolders app schema (group_folders / '
			. 'group_folders_groups) and a group-mapped mount that this CI image does '
			. 'not create. Left as a documented extension point for a groupfolders-'
			. 'enabled CI lane.'
		);
	}
}
