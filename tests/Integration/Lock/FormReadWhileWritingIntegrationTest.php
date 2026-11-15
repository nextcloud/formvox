<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Lock;

use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Server;

/**
 * Against a REAL Nextcloud: a form is read while a save holds its file. Nextcloud's own read waits for no one and
 * fails; FormVox's reads go below the lock, so a respondent opening the link, a submission and the editor are not
 * turned away by someone else's save (7a, 3a-3).
 *
 * @group DB
 */
class FormReadWhileWritingIntegrationTest extends IntegrationTestCase {
	private function lockedForm(): File {
		$file = $this->userFolder->get($this->writeFormFile(null, 'Read While Writing', ['settings' => ['public_token' => 'tok']])->getName());
		$this->assertInstanceOf(File::class, $file);
		// What a save through Nextcloud holds while it writes the file.
		$file->lock(ILockingProvider::LOCK_EXCLUSIVE);
		return $file;
	}

	public function testAFormIsReadWhileASaveHoldsItsFile(): void {
		$file = $this->lockedForm();
		try {
			try {
				$file->getContent();
				$this->fail('Nextcloud read a file a save holds; the lock this test relies on is not there');
			} catch (LockedException) {
			}

			$repository = Server::get(FormRepository::class);
			$this->assertSame('Read While Writing', $repository->loadPublic($file->getId())['title'], 'the link');
			$this->assertSame('Read While Writing', $repository->load($file->getId())['title'], 'the editor');
		} finally {
			$file->unlock(ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
