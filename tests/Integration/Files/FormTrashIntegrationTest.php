<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Files;

use OCA\Files_Trashbin\Trash\ITrashItem;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\FormVox\Service\FormTrash;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Server;

/**
 * The files of a deleted response are gone for good, not left in a trash nobody looks in (qual#4, secu#47); a form
 * put back from the trash takes its folders along, so the respondents' files come back with it (qual#98, PO 5).
 *
 * @group DB
 */
class FormTrashIntegrationTest extends IntegrationTestCase {
	private const RESPONSE = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

	protected function setUp(): void {
		parent::setUp();
		if (!Server::get(IAppManager::class)->isEnabledForUser('files_trashbin')) {
			$this->markTestSkipped('files_trashbin is not enabled');
		}
		$this->reconnectNodeEvents();
		// \Test\TestCase clears the legacy hooks after each class; Nextcloud keeps FormVox's connected.
		$hooks = \OC_Hook::getHooks()['\OCA\Files_Trashbin\Trashbin']['post_restore'] ?? [];
		if (!in_array(FormTrash::class, array_column($hooks, 'class'), true)) {
			\OCP\Util::connectHook('\OCA\Files_Trashbin\Trashbin', 'post_restore', FormTrash::class, 'onRestore');
		}
		// Deleting has to go through the trash here, or there is nothing to tell apart.
		$probe = $this->userFolder->newFile('probe ' . uniqid() . '.txt', 'x');
		$name = $probe->getName();
		$probe->delete();
		if ($this->inTrash($name) === null) {
			$this->markTestSkipped('this test filesystem deletes without the trash');
		}
	}

	private function inTrash(string $name): ?ITrashItem {
		foreach (Server::get(ITrashManager::class)->listTrashRoot($this->owner) as $item) {
			if (basename($item->getOriginalLocation()) === $name) {
				return $item;
			}
		}
		return null;
	}

	private function formWithAFile(): File {
		$file = $this->writeFormFile($this->userFolder, 'Trash ' . uniqid(), [
			'questions' => [['id' => 'cv', 'type' => 'file', 'question' => 'CV']],
			'responses' => [['id' => self::RESPONSE, 'answers' => ['cv' => ['filename' => 'cv.pdf']]]],
		]);
		$this->userFolder->newFolder('.formvox-uploads-' . $file->getId())->newFolder(self::RESPONSE)->newFile('cv.pdf', 'a respondent\'s file');
		return $file;
	}

	public function testTheFilesOfADeletedResponseAreGoneForGood(): void {
		$file = $this->formWithAFile();

		Server::get(UploadService::class)->deleteResponseUploads($file, self::RESPONSE);

		$this->assertFalse($this->userFolder->nodeExists('.formvox-uploads-' . $file->getId() . '/' . self::RESPONSE));
		$this->assertNull($this->inTrash(self::RESPONSE));
	}

	public function testAFormBackFromTheTrashBringsItsFilesAndKeepsItsResponses(): void {
		$file = $this->formWithAFile();
		$name = $file->getName();
		$uploads = '.formvox-uploads-' . $file->getId();

		$jobs = Server::get(\OCP\BackgroundJob\IJobList::class);
		$jobs->remove(\OCA\FormVox\BackgroundJob\SideEffectsJob::class, ['fileId' => $file->getId()]);

		$this->userFolder->get($name)->delete();
		$this->assertFalse($this->userFolder->nodeExists($uploads));
		Server::get(ITrashManager::class)->restoreItem($this->inTrash($name));

		$this->assertSame('a respondent\'s file', $this->userFolder->get($uploads . '/' . self::RESPONSE . '/cv.pdf')->getContent());
		$this->assertTrue($jobs->has(\OCA\FormVox\BackgroundJob\SideEffectsJob::class, ['fileId' => $file->getId()]), 'what it still had to send goes out');
		$this->assertSame([self::RESPONSE], array_column($this->readFormFromStorage($this->userFolder->get($name))['responses'], 'id'));
	}
}
