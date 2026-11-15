<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\IRequest;
use OCP\Server;

/**
 * Against a real Nextcloud: a form's row follows its file, also when Nextcloud gives the file a new id, and a copy is a
 * form of its own.
 *
 * @group DB
 */
class StoredBindingIntegrationTest extends IntegrationTestCase {
	/** A file id no file has. */
	private const GONE = 2000000000;

	protected function setUp(): void {
		parent::setUp();
		// Core clears the hooks after each test class; without them no file event reaches FormVox.
		$this->reconnectNodeEvents();
	}

	/** A form in the database with two responses. */
	private function form(): File {
		$repository = Server::get(FormRepository::class);
		$fileId = (int)$repository->create('Binding ' . uniqid())['fileId'];
		$repository->update($fileId, [
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'settings' => ['anonymous' => true, 'allow_multiple' => true],
		]);
		foreach (['Anna', 'Bob'] as $i => $name) {
			$request = $this->createMock(IRequest::class);
			$request->method('getRemoteAddress')->willReturn('192.0.2.' . ($i + 1));
			$request->method('getHeader')->willReturn('IT browser');
			Server::get(ResponseService::class)->submitAnonymousWithForm($fileId, $repository->loadPublic($fileId), ['q' => $name], $request);
		}
		return $this->userFolder->getFirstNodeById($fileId);
	}

	/** @return list<string> */
	private function answers(int $fileId): array {
		return array_column(array_column(Server::get(FormRepository::class)->load($fileId)['responses'] ?? [], 'answers'), 'q');
	}

	/** @return array<string, array{string}> */
	public static function asideNames(): array {
		return [
			'occ encryption:encrypt-all' => ['.encrypted.'],
			'occ encryption:decrypt-all' => ['.decrypted.'],
		];
	}

	/**
	 * As apps/encryption EncryptAll::encryptFile and core DecryptAll do it: a copy aside, renamed back over the file,
	 * which gives the form the copy's id.
	 *
	 * @dataProvider asideNames
	 */
	public function testAnEncryptionRoundTripTakesTheFormsRowToItsNewId(string $aside): void {
		$file = $this->form();
		$forms = Server::get(FormStore::class);
		$row = $forms->byFileId($file->getId());
		$view = new \OC\Files\View('');
		$copy = $file->getPath() . $aside . time();

		$this->assertTrue($view->copy($file->getPath(), $copy));
		$this->assertTrue($view->rename($copy, $file->getPath()));

		$now = $this->userFolder->get($file->getName());
		$this->assertNotSame($file->getId(), $now->getId(), 'Nextcloud gave it a new id');
		$this->assertSame($row->id, $forms->byFileId($now->getId())?->id);
		$this->assertNull($forms->byFileId($file->getId()));
		$this->assertSame(['Anna', 'Bob'], $this->answers($now->getId()));
		$this->assertTrue(Server::get(\OCP\BackgroundJob\IJobList::class)->has(\OCA\FormVox\BackgroundJob\SideEffectsJob::class, ['fileId' => $now->getId()]), 'what waited is sent under the new id');
	}

	public function testAMovedFormsRowKnowsWhereItIsNow(): void {
		$file = $this->form();
		$forms = Server::get(FormStore::class);

		$moved = $file->move($this->userFolder->newFolder('Moved ' . uniqid())->getPath() . '/' . $file->getName());

		$row = $forms->byFileId($moved->getId());
		$this->assertSame($forms->place($moved->getId()), [$row?->storageId, $row?->pathHash]);
		$this->assertSame(md5($moved->getInternalPath()), $row?->pathHash);
		$this->assertSame(['Anna', 'Bob'], $this->answers($moved->getId()));
	}

	/** Lost its id with nothing to tell, as when files are put back without the database: bound again where it was. */
	public function testAFormThatLostItsIdIsBoundAgainWhereItWas(): void {
		$file = $this->form();
		$forms = Server::get(FormStore::class);
		$row = $forms->byFileId($file->getId());
		$forms->rebind($row->id, self::GONE, $row->storageId, $row->pathHash);
		$this->assertNull($forms->byFileId($file->getId()));

		$this->assertSame(['Anna', 'Bob'], $this->answers($file->getId()));
		$this->assertSame($row->id, $forms->byFileId($file->getId())?->id);
	}

	/** Anywhere else a file with its uuid is a form of its own: only an administrator binds it by hand. */
	public function testAFormThatLostItsIdIsNotBoundAgainElsewhere(): void {
		$file = $this->form();
		$forms = Server::get(FormStore::class);
		$row = $forms->byFileId($file->getId());
		$forms->rebind($row->id, self::GONE, $row->storageId, md5('files/somewhere else.fvform'));

		$this->assertSame([], $this->answers($file->getId()));
		$this->assertNull($forms->byFileId($file->getId()));
		$this->assertSame(self::GONE, $forms->byUuid($row->uuid)?->fileId);
	}

	/** A copy Nextcloud did not report as one, as a downloaded form put back: the original's responses stay its own. */
	public function testAnUnreportedCopyDoesNotReachTheOriginalsResponses(): void {
		$file = $this->form();

		$copy = $file->getParent()->newFile('Put back ' . uniqid() . '.fvform', $file->getContent());

		$this->assertSame([], $this->answers($copy->getId()));
		$this->assertNull(Server::get(FormStore::class)->byFileId($copy->getId()));
		$this->assertSame(['Anna', 'Bob'], $this->answers($file->getId()));
	}

	/** Where the row last saw it, a copy put back while the original lives elsewhere takes nothing over. */
	public function testAnUnreportedCopyWhereTheOriginalWasDoesNotTakeItsRow(): void {
		$file = $this->form();
		$forms = Server::get(FormStore::class);
		$row = $forms->byFileId($file->getId());
		$name = 'Put back ' . uniqid() . '.fvform';
		$forms->rebind($row->id, $file->getId(), $row->storageId, md5(dirname($file->getInternalPath()) . '/' . $name));

		$copy = $file->getParent()->newFile($name, $file->getContent());

		$this->assertSame([], $this->answers($copy->getId()));
		$this->assertSame($row->id, $forms->byFileId($file->getId())?->id);
		$this->assertSame(['Anna', 'Bob'], $this->answers($file->getId()));
	}

	public function testACopyIsAFormOfItsOwnThatStartsEmpty(): void {
		$file = $this->form();
		$forms = Server::get(FormStore::class);

		$copy = $file->copy($file->getParent()->getPath() . '/Copy ' . uniqid() . '.fvform');

		$original = $forms->byFileId($file->getId());
		$own = $forms->byFileId($copy->getId());
		$this->assertNotNull($own, 'in the database from the start');
		$this->assertNotSame($original->id, $own->id);
		$this->assertNotSame($original->uuid, $own->uuid);
		$this->assertSame($own->uuid, json_decode($this->userFolder->getFirstNodeById($copy->getId())->getContent(), true)['id']);
		$this->assertSame([], $this->answers($copy->getId()));
		$this->assertSame(['Anna', 'Bob'], $this->answers($file->getId()));
	}
}
