<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Files;

use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\Server;

/**
 * A form file is never left without its responses or cut off, on a real Nextcloud: encrypt-all and decrypt-all copy
 * each file aside and rename the copy back over it, and a form that does not fit its owner's quota is not written.
 *
 * @group DB
 */
class FormWriteSafetyIntegrationTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		// Core clears the hooks after each test class; without them no rename event reaches FormVox and these tests
		// would pass without it.
		$this->reconnectNodeEvents();
	}

	private function form(): File {
		$file = $this->writeFormFile($this->userFolder, 'Safety Form', [
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
		]);
		foreach (['Anna', 'Bob'] as $name) {
			Server::get(ResponsePersistenceService::class)->append($this->userFolder->getFirstNodeById($file->getId()), [
				'id' => bin2hex(random_bytes(16)), 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => ['q1' => $name],
			]);
		}
		return $this->userFolder->get($file->getName());
	}

	/** @return list<string> the names answered, from the database where the responses are kept */
	private function names(File $file): array {
		return array_column(array_column(Server::get(FormRepository::class)->load($file->getId())['responses'], 'answers'), 'q1');
	}

	/** @return array<string, array{string}> */
	public static function asideNames(): array {
		return [
			'occ encryption:encrypt-all' => ['.encrypted.'],
			'occ encryption:decrypt-all' => ['.decrypted.'],
		];
	}

	/**
	 * As apps/encryption EncryptAll::encryptFile and core DecryptAll do it: a copy aside, renamed back over the file.
	 *
	 * @dataProvider asideNames
	 */
	public function testAnEncryptionRoundTripKeepsTheResponses(string $aside): void {
		$file = $this->form();
		$name = $file->getName();
		$path = $file->getPath();
		// An old date in the form: a rewrite would set it to now, also within the same second (review 3a-1 fixes).
		$old = json_decode($file->getContent(), true);
		$old['modified_at'] = '2020-01-01T00:00:00+00:00';
		$file->getStorage()->file_put_contents($file->getInternalPath(), (string)json_encode($old, JSON_PRETTY_PRINT));
		$before = $file->getStorage()->file_get_contents($file->getInternalPath());
		$view = new \OC\Files\View('');
		$copy = $path . $aside . time();

		$this->assertTrue($view->copy($path, $copy));
		$this->assertTrue($view->rename($copy, $path));

		$after = $this->userFolder->get($name);
		$this->assertSame(['Anna', 'Bob'], $this->names($after));
		$form = json_decode($after->getContent(), true);
		$this->assertNotEmpty($form['permissions']['owner'] ?? null);
		$this->assertSame('2020-01-01T00:00:00+00:00', $form['modified_at'], 'the same bytes back are not rewritten');
		$this->assertSame($before, $this->userFolder->get($name)->getContent());
	}

	/** The control: another file renamed over the form is undone, which only happens when the rename reaches FormVox. */
	public function testAPlainFileRenamedOverAFormIsUndone(): void {
		$file = $this->form();
		$before = $file->getContent();
		$notes = $this->userFolder->newFile('notes.txt', 'just some notes');
		$view = new \OC\Files\View('');

		$view->rename($notes->getPath(), $file->getPath());

		$this->assertSame($before, $this->userFolder->get($file->getName())->getContent());
	}

	/**
	 * A file put over a form outside FormVox with a number that reads as infinity is undone: the form keeps its
	 * responses, nothing planted stays, and the form still takes responses.
	 */
	public function testAnUploadThatCannotBeStoredIsUndone(): void {
		$file = $this->form();
		$before = $file->getContent();
		$upload = json_decode($before, true);
		$upload['title'] = 'Taken over';
		$upload['responses'][] = ['id' => 'planted', 'answers' => ['q1' => 'Planted']];
		$upload['questions'][] = ['id' => 'q2', 'type' => 'number', 'question' => 'Age', 'min' => 0, 'max' => 'INF'];

		$file->putContent(str_replace('"INF"', '1e999', (string)json_encode($upload)));

		$this->assertSame($before, $this->userFolder->get($file->getName())->getContent());
		Server::get(ResponsePersistenceService::class)->append($this->userFolder->getFirstNodeById($file->getId()), [
			'id' => bin2hex(random_bytes(16)), 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => ['q1' => 'Carol'],
		]);
		$this->assertSame(['Anna', 'Bob', 'Carol'], $this->names($this->userFolder->get($file->getName())));
	}

	/**
	 * A change that would not fit the owner's quota is refused with 507 and the form stays whole; a response goes to the
	 * database, so it is still taken and does not grow the form file.
	 */
	public function testAFormThatNoLongerFitsTheQuotaIsNotWritten(): void {
		$file = $this->form();
		$before = $file->getContent();
		$this->owner->setQuota((string)(strlen($before) + 4096) . ' B');
		// The quota applies from the next filesystem setup on, as in a new request.
		$this->loginAs($this->ownerUid, $this->owner);
		$this->userFolder = $this->rootFolder->getUserFolder($this->ownerUid);
		try {
			Server::get(ResponsePersistenceService::class)->append($this->userFolder->get($file->getName()), [
				'id' => bin2hex(random_bytes(16)), 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => ['q1' => str_repeat('x', 20000)],
			]);
			try {
				Server::get(FormRepository::class)->update($file->getId(), ['description' => str_repeat('x', 20000)]);
				$this->fail('A form that does not fit was written');
			} catch (RequestException $e) {
				$this->assertSame([507, 'storage_full'], [$e->getStatus(), $e->getReason()]);
			}
		} finally {
			$this->owner->setQuota('none');
		}
		$this->assertSame($before, $this->userFolder->get($file->getName())->getContent());
		$this->assertCount(3, Server::get(FormRepository::class)->load($file->getId())['responses']);
	}
}
