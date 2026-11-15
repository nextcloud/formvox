<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Files;

use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Copying in Nextcloud, of one form or of a folder full of them: every copy is a new form without the
 * original's responses, link, keys or upload folders, and the original is untouched (PO 3+).
 *
 * @group DB
 */
class FormCopyIntegrationTest extends IntegrationTestCase {
	private function formWithEverything(Folder $folder, string $title): File {
		$file = $this->writeFormFile($folder, $title, [
			'settings' => [
				'anonymous' => true,
				'public_token' => 'TOKEN' . uniqid(),
				'api_keys' => [['id' => 'k1', 'name' => 'CRM', 'hash' => 'enc:v1:hash', 'created_by' => $this->ownerUid]],
				'webhooks' => [['id' => 'w1', 'url' => 'https://example.com/hook', 'secret' => 'enc:v1:secret', 'created_by' => $this->ownerUid]],
			],
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'responses' => [['id' => 'r1', 'submitted_at' => date('c'), 'answers' => ['q' => 'private answer']]],
		]);
		$folder->newFolder('.formvox-uploads-' . $file->getId())->newFolder('5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f')->newFile('cv.pdf')->putContent('a respondent\'s file');
		return $file;
	}

	private function assertIsACleanCopy(File $copy, string $title): void {
		$form = $this->readFormFromStorage($copy);
		$this->assertSame($title, $form['title']);
		$this->assertSame('Q', $form['questions'][0]['question']);
		$this->assertSame([], $form['responses']);
		$this->assertSame($this->ownerUid, $form['permissions']['owner']);
		foreach (['public_token', 'api_keys', 'webhooks'] as $key) {
			$this->assertArrayNotHasKey($key, $form['settings'], "$key came along with the copy");
		}
		$this->assertStringNotContainsString('private answer', $copy->getContent());
	}

	public function testACopiedFolderHoldsCleanFormsAndNoneOfTheOriginalsUploads(): void {
		$this->reconnectNodeEvents();
		$source = $this->userFolder->newFolder('Surveys ' . uniqid());
		$top = $this->formWithEverything($source, 'Top');
		$nested = $this->formWithEverything($source->newFolder('2026'), 'Nested');
		$source->newFile('notes.txt')->putContent('just notes');

		/** @var Folder $copy */
		$copy = $source->copy($source->getPath() . ' copy');
		try {
			$this->assertIsACleanCopy($copy->get($top->getName()), 'Top');
			$this->assertIsACleanCopy($copy->get('2026/' . $nested->getName()), 'Nested');
			$this->assertFalse($copy->nodeExists('.formvox-uploads-' . $top->getId()), 'the original\'s uploads came along');
			$this->assertFalse($copy->nodeExists('2026/.formvox-uploads-' . $nested->getId()), 'the original\'s uploads came along');
			$this->assertSame('just notes', $copy->get('notes.txt')->getContent());

			// What the copy brought of the original's is gone for good, not waiting in the trash of whoever copied it.
			foreach (\OCP\Server::get(\OCA\Files_Trashbin\Trash\ITrashManager::class)->listTrashRoot($this->owner) as $item) {
				$this->assertStringNotContainsString('.formvox-', $item->getName(), 'in the trash: ' . $item->getName());
			}

			// The original keeps everything.
			$this->assertSame('private answer', $this->readFormFromStorage($top)['responses'][0]['answers']['q']);
			$this->assertTrue($source->nodeExists('.formvox-uploads-' . $top->getId() . '/5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f/cv.pdf'));
		} finally {
			$copy->delete();
			$source->delete();
		}
	}

	public function testACopiedFormIsClean(): void {
		$this->reconnectNodeEvents();
		$original = $this->formWithEverything($this->userFolder, 'Single ' . uniqid());

		/** @var File $copy */
		$copy = $original->copy($this->userFolder->getPath() . '/Copy of single ' . uniqid() . '.fvform');
		try {
			$this->assertIsACleanCopy($copy, $this->readFormFromStorage($original)['title']);
		} finally {
			$copy->delete();
		}
	}
}
