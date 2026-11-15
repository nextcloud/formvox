<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Files;

use OCA\Files_Trashbin\Trash\ITrashItem;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\FormVox\Listener\FormRemovedListener;
use OCA\FormVox\Pdf\PdfStore;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormReader;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\FormTrash;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormFolders;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\Orphans;
use OCA\FormVox\Store\OutboxStore;
use OCA\FormVox\Store\SecretStore;
use OCA\FormVox\Store\StoredForm;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\Server;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Against a real Nextcloud: a form whose file is deleted for good through Nextcloud is gone with its responses and files
 * when that request ends; in the trash, moved, or disappeared behind Nextcloud's back, it keeps them.
 *
 * A request's end is the listener's flush(): PHPUnit runs no shutdown function between tests.
 *
 * @group DB
 */
class FormRemovedIntegrationTest extends IntegrationTestCase {
	private const GROUP = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
	private const TABLES = ['formvox_resp', 'formvox_keys', 'formvox_counts', 'formvox_outbox', 'formvox_secrets'];
	private const TEAM_FOLDER = 'FormVox Removed IT';
	private const TEAM = 'fvremoved-team';

	private FormRemovedListener $listener;
	private ITrashManager $trash;

	protected function setUp(): void {
		parent::setUp();
		$apps = Server::get(IAppManager::class);
		if (!$apps->isEnabledForUser('files_trashbin')) {
			$this->markTestSkipped('files_trashbin is not enabled');
		}
		$apps->loadApp('files_trashbin');
		$this->reconnectNodeEvents();
		// \Test\TestCase clears the legacy hooks after each class; Nextcloud keeps FormVox's connected.
		$hooks = \OC_Hook::getHooks()['\OCA\Files_Trashbin\Trashbin']['post_restore'] ?? [];
		if (!in_array(FormTrash::class, array_column($hooks, 'class'), true)) {
			\OCP\Util::connectHook('\OCA\Files_Trashbin\Trashbin', 'post_restore', FormTrash::class, 'onRestore');
		}
		$this->trash = Server::get(ITrashManager::class);
		$this->listener = Server::get(FormRemovedListener::class);
		// What earlier tests deleted is theirs.
		$this->listener->flush();
		// Deleting has to go through the trash here, or there is nothing to tell apart.
		$probe = $this->userFolder->newFile('probe ' . uniqid() . '.txt', 'x');
		$name = $probe->getName();
		$probe->delete();
		if ($this->inTrash($name) === null) {
			$this->markTestSkipped('this test filesystem deletes without the trash');
		}
	}

	protected function tearDown(): void {
		// Whatever a failed test left switched off.
		if (!Server::get(IAppManager::class)->isEnabledForUser('files_trashbin')) {
			Server::get(IAppManager::class)->enableApp('files_trashbin');
		}
		Server::get(ITrashManager::class)->resumeTrash();
		parent::tearDown();
	}

	public static function tearDownAfterClass(): void {
		try {
			self::removeTeamFolder();
			Server::get(IGroupManager::class)->get(self::TEAM)?->delete();
		} catch (\Throwable) {
			// best effort; the next run removes leftovers before it starts
		}
		parent::tearDownAfterClass();
	}

	/** A form in the database with two responses, a respondent's file, a PDF and a secret, all in app data. */
	private function form(string $folder = ''): File {
		$repository = Server::get(FormRepository::class);
		$fileId = (int)$repository->create('Removed ' . uniqid(), $folder)['fileId'];
		$repository->update($fileId, [
			'questions' => [
				['id' => 'q', 'type' => 'text', 'question' => 'Q'],
				['id' => 'meal', 'type' => 'choice', 'question' => 'Meal', 'options' => [['id' => 'opt1', 'label' => 'Soup', 'capacity' => 5], ['id' => 'opt2', 'label' => 'Salad']]],
			],
			'settings' => ['anonymous' => true, 'allow_multiple' => true],
		]);
		foreach (['Anna', 'Bob'] as $i => $name) {
			$request = $this->createMock(IRequest::class);
			$request->method('getRemoteAddress')->willReturn('192.0.2.' . ($i + 1));
			$request->method('getHeader')->willReturn('IT browser');
			Server::get(ResponseService::class)->submitAnonymousWithForm($fileId, $repository->loadPublic($fileId), ['q' => $name, 'meal' => 'opt1'], $request);
		}
		Server::get(UploadService::class)->storeUploadPublic($fileId, self::GROUP, $this->makeUploadedFile('cv.pdf', 'cv', 'application/pdf'));
		$file = $this->rootFolder->getUserFolder($this->ownerUid)->getFirstNodeById($fileId);
		$this->assertInstanceOf(File::class, $file);
		Server::get(PdfStore::class)->put($file, (string)$repository->load($fileId)['responses'][0]['id'], '0123456789abcdef', '%PDF-1.4 a response');
		$row = $this->row($file);
		Server::get(SecretStore::class)->save($row->id, ['public_token' => 'a-token-for-' . $row->id]);
		foreach (self::TABLES as $table) {
			$this->assertGreaterThan(0, $this->rows($table, $row->id), 'the form has no rows in ' . $table);
		}
		Server::get(FormFolders::class)->kind($row->id, FormFolders::UPLOADS)->get(self::GROUP . '/cv.pdf');
		Server::get(FormFolders::class)->kind($row->id, FormFolders::PDFS);
		return $file;
	}

	/** A form as 1.5 wrote it, with two responses and a respondent's file beside it: still to be moved in. */
	private function legacy(): File {
		$file = $this->writeFormFile($this->userFolder, 'Legacy ' . uniqid(), [
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'responses' => [
				['id' => 'r1', 'submitted_at' => '2026-10-01T10:00:00+00:00', 'answers' => ['q' => 'Anna']],
				['id' => 'r2', 'submitted_at' => '2026-10-02T10:00:00+00:00', 'answers' => ['q' => 'Bob']],
			],
		]);
		$file->getParent()->newFolder('.formvox-uploads-' . $file->getId())->newFolder(self::GROUP)->newFile('cv.pdf', 'cv');
		return $file;
	}

	private function row(File $file): StoredForm {
		$form = Server::get(FormStore::class)->byFileId($file->getId());
		$this->assertNotNull($form);
		return $form;
	}

	private function rows(string $table, int $formId): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Where Nextcloud has the file, as its storage names the path. */
	private function pathOf(int $fileId): string {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('path')->from('filecache')->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return (string)$qb->executeQuery()->fetchOne();
	}

	private function inTrash(string $name): ?ITrashItem {
		foreach ($this->trash->listTrashRoot($this->owner) as $item) {
			if (basename($item->getOriginalLocation()) === $name) {
				return $item;
			}
		}
		return null;
	}

	/** Deletes it from the trash for good, as the Files app does for one item (Sabre\AbstractTrash::delete). */
	private function deleteFromTrash(string $name): void {
		$item = $this->inTrash($name);
		$this->assertNotNull($item, $name . ' is not in the trash');
		$this->trash->removeItem($item);
	}

	private function assertKept(StoredForm $form, string $message = ''): void {
		$this->assertNotNull(Server::get(FormStore::class)->byId($form->id), $message);
		$this->assertSame(2, $this->rows('formvox_resp', $form->id), $message);
		$this->assertSame('cv', Server::get(FormFolders::class)->kind($form->id, FormFolders::UPLOADS)->get(self::GROUP . '/cv.pdf')->getContent(), $message);
	}

	private function assertGone(StoredForm $form, string $message = ''): void {
		$forms = Server::get(FormStore::class);
		$this->assertNull($forms->byId($form->id), $message);
		$this->assertNull($forms->byUuid($form->uuid), $message);
		foreach (self::TABLES as $table) {
			$this->assertSame(0, $this->rows($table, $form->id), trim($message . ' rows left in ' . $table));
		}
		try {
			Server::get(FormFolders::class)->all($form->id);
			$this->fail(trim($message . ' its files are still in app data'));
		} catch (NotFoundException) {
		}
	}

	/** @return list<string> */
	private function answers(int $fileId): array {
		return array_column(array_column(Server::get(FormRepository::class)->load($fileId)['responses'] ?? [], 'answers'), 'q');
	}

	/** A listener of its own, for events made up here. */
	private function listener(): FormRemovedListener {
		return new FormRemovedListener(Server::get(IAppManager::class), Server::get(IRequest::class), Server::get(LoggerInterface::class), Server::get(ContainerInterface::class));
	}

	/**
	 * What the listener noted of the files Nextcloud forgot, taken from it before the request ends.
	 *
	 * @return list<array{0: int, 1: int, 2: string, 3: bool}>
	 */
	private function takeForgotten(): array {
		$noted = (new \ReflectionProperty(FormRemovedListener::class, 'forgotten'))->getValue($this->listener);
		foreach (['forgotten', 'outright', 'deleting'] as $property) {
			(new \ReflectionProperty(FormRemovedListener::class, $property))->setValue($this->listener, []);
		}
		return $noted;
	}

	// =====================================================================
	// In the trash, and back
	// =====================================================================

	public function testAFormInTheTrashKeepsEverythingAndComesBackWithIt(): void {
		$file = $this->form();
		$form = $this->row($file);
		$name = $file->getName();
		$forms = Server::get(FormStore::class);

		$file->delete();
		$this->listener->flush();

		$this->assertNotNull($this->inTrash($name));
		$this->assertKept($form, 'in the trash');
		foreach (self::TABLES as $table) {
			$this->assertGreaterThan(0, $this->rows($table, $form->id), $table);
		}
		$trashed = $forms->byId($form->id);
		$this->assertNull($trashed->orphanedAt);
		$this->assertSame($forms->place($form->fileId), [$trashed->storageId, $trashed->pathHash], 'it knows where it lies in the trash');
		$this->assertNotSame($form->pathHash, $trashed->pathHash);

		$this->trash->restoreItem($this->inTrash($name));
		$this->listener->flush();

		$this->assertSame(['Anna', 'Bob'], $this->answers($form->fileId));
		$this->assertKept($form, 'back from the trash');
		$this->assertSame($form->pathHash, $forms->byId($form->id)->pathHash, 'and where it is now');
	}

	// =====================================================================
	// Deleted for good through Nextcloud
	// =====================================================================

	public function testAFormDeletedFromTheTrashIsGoneWithEverythingItOwned(): void {
		$file = $this->form();
		$form = $this->row($file);
		$other = $this->row($this->form());
		$name = $file->getName();
		$file->delete();
		$this->listener->flush();

		$this->deleteFromTrash($name);
		$this->assertKept($form, 'until the request ends');
		$this->listener->flush();

		$this->assertGone($form);
		$this->assertKept($other, 'another form');
	}

	/** As the Files app empties the trash (Sabre\TrashRoot::delete): the whole trash folder goes at once. */
	public function testEmptyingTheTrashRemovesEveryFormInIt(): void {
		$first = $this->form();
		$second = $this->form();
		$kept = $this->row($this->form());
		$forms = [$this->row($first), $this->row($second)];
		$first->delete();
		$second->delete();
		$this->listener->flush();

		\OCA\Files_Trashbin\Trashbin::deleteAll();
		$this->listener->flush();

		$this->assertGone($forms[0]);
		$this->assertGone($forms[1]);
		$this->assertKept($kept, 'a form not in the trash');
	}

	public function testAFolderDeletedForGoodTakesTheFormsInIt(): void {
		$name = 'Removed folder ' . uniqid();
		$this->userFolder->newFolder($name)->newFolder('deeper');
		$forms = [$this->row($this->form($name)), $this->row($this->form($name . '/deeper'))];

		$this->userFolder->get($name)->delete();
		$this->listener->flush();
		$this->assertKept($forms[0], 'in the trash');
		$this->assertKept($forms[1], 'in the trash');

		$this->deleteFromTrash($name);
		$this->listener->flush();

		$this->assertGone($forms[0]);
		$this->assertGone($forms[1]);
	}

	/**
	 * Nextcloud 34 and 35.0.0 number the files in a removed folder (0, 1, 2…) instead of naming their ids, and an
	 * emptied trash is such a folder: the forms are known by where they lay in the trash.
	 */
	public function testFormsInARemovedFolderAreKnownByWhereTheyLayWhenNextcloudNumbersThem(): void {
		$name = 'Numbered folder ' . uniqid();
		$this->userFolder->newFolder($name);
		$inFolder = $this->form($name);
		$alone = $this->form();
		$forms = [$this->row($inFolder), $this->row($alone)];
		$storage = $alone->getStorage();
		$this->userFolder->get($name)->delete();
		$alone->delete();
		$this->listener->flush();

		\OCA\Files_Trashbin\Trashbin::deleteAll();
		$noted = $this->takeForgotten();
		$this->assertKept($forms[0], 'nothing is flushed yet');

		$this->assertCount(2, $noted);
		$listener = $this->listener();
		foreach ($noted as $i => [, $storageId, $path]) {
			$listener->handle(new CacheEntryRemovedEvent($storage, $path, $i, $storageId));
		}
		$listener->flush();

		$this->assertGone($forms[0]);
		$this->assertGone($forms[1]);
	}

	public function testAFormDeletedWhereNoTrashTakesItIsGoneAtOnce(): void {
		$name = 'No trash ' . uniqid();
		$this->userFolder->newFolder($name);
		$file = $this->form();
		$forms = [$this->row($file), $this->row($this->form($name))];
		$kept = $this->row($this->form());

		Server::get(IAppManager::class)->disableApp('files_trashbin');
		$fileName = $file->getName();
		$file->delete();
		$this->userFolder->get($name)->delete();
		$this->listener->flush();
		Server::get(IAppManager::class)->enableApp('files_trashbin');

		$this->assertNull($this->inTrash($fileName), 'the trash took it after all');
		$this->assertGone($forms[0], 'the form:');
		$this->assertGone($forms[1], 'the form in the folder:');
		$this->assertKept($kept, 'another form');
	}

	// =====================================================================
	// Not deleted: disappeared, moved, or not known to be gone
	// =====================================================================

	public function testAFileThatDisappearsBehindNextcloudsBackKeepsItsFormForTheSweep(): void {
		$file = $this->form();
		$form = $this->row($file);
		$name = 'Vanished ' . uniqid();
		$this->userFolder->newFolder($name);
		$inFolder = $this->row($this->form($name));

		$disk = $file->getStorage();
		while ($disk->instanceOfStorage(\OC\Files\Storage\Wrapper\Wrapper::class)) {
			$disk = $disk->getWrapperStorage();
		}
		$this->assertTrue($disk->unlink($file->getInternalPath()));
		$this->assertTrue($disk->rmdir($this->userFolder->get($name)->getInternalPath()));
		$file->getStorage()->getScanner()->scan('files');
		$this->assertNull(Server::get(FormStore::class)->place($form->fileId), 'the scan did not find it gone');
		$this->assertNull(Server::get(FormStore::class)->place($inFolder->fileId), 'the scan did not find the folder gone');
		$this->listener->flush();

		$this->assertKept($form);
		$this->assertKept($inFolder);
		$this->assertNull(Server::get(FormStore::class)->byId($form->id)->orphanedAt);

		Server::get(Orphans::class)->sweep();

		$this->assertNotNull(Server::get(FormStore::class)->byId($form->id)->orphanedAt, 'the sweep marks it, and gives it its grace');
		$this->assertNotNull(Server::get(FormStore::class)->byId($inFolder->id)->orphanedAt);
		$this->assertKept($form);
	}

	/** Found gone by a scan where no trash is at work: still no one deleted it. */
	public function testAFileThatDisappearsWhereNoTrashIsAtWorkKeepsItsForm(): void {
		$file = $this->form();
		$form = $this->row($file);
		$disk = $file->getStorage();
		while ($disk->instanceOfStorage(\OC\Files\Storage\Wrapper\Wrapper::class)) {
			$disk = $disk->getWrapperStorage();
		}
		$this->assertTrue($disk->unlink($file->getInternalPath()));

		Server::get(IAppManager::class)->disableApp('files_trashbin');
		// Something else is deleted in the same request.
		$this->userFolder->newFolder('Unrelated ' . uniqid())->delete();
		$file->getStorage()->getScanner()->scan('files');
		$this->listener->flush();
		Server::get(IAppManager::class)->enableApp('files_trashbin');

		$this->assertNull(Server::get(FormStore::class)->place($form->fileId));
		$this->assertKept($form);
	}

	/**
	 * Deleted while a trash is at work but did not take it (another app said no, the file was too large): the file may
	 * as well lie in a trash under a new id, so the form keeps its grace.
	 */
	public function testAFormDeletedPastATrashThatIsAtWorkKeepsItsGrace(): void {
		$file = $this->form();
		$form = $this->row($file);

		$this->trash->pauseTrash();
		$file->delete();
		$this->trash->resumeTrash();
		$this->listener->flush();

		$this->assertNull(Server::get(FormStore::class)->place($form->fileId), 'the trash took it');
		$this->assertKept($form);
	}

	/** Its file still there, whatever id an event came with: Nextcloud 34 and 35.0.0 number where they should name. */
	public function testAnEntryThatNamesAFormWhoseFileIsStillThereRemovesNothing(): void {
		$live = $this->form();
		$trashed = $this->form();
		$forms = [$this->row($live), $this->row($trashed)];
		$trashed->delete();
		$this->listener->flush();

		$listener = $this->listener();
		foreach ($forms as $form) {
			$listener->handle(new CacheEntryRemovedEvent($live->getStorage(), 'files_trashbin/files/Emptied.d1700000000/' . $form->fileId . '.fvform', $form->fileId, $form->storageId));
		}
		$listener->flush();

		$this->assertKept($forms[0], 'a form in Files');
		$this->assertKept($forms[1], 'a form in the trash');
	}

	/** A form whose file disappeared earlier, where another form lies now: that one's deletion says nothing of it. */
	public function testAFormThatDisappearedWhereADeletedFormLayKeepsItsGrace(): void {
		$forms = Server::get(FormStore::class);
		$file = $this->form();
		$deleted = $this->row($file);
		$disappeared = $this->row($this->form());
		$name = $file->getName();
		$file->delete();
		$this->listener->flush();
		$trashed = $forms->byId($deleted->id);
		$forms->rebind($disappeared->id, 2000000002, $trashed->storageId, $trashed->pathHash);

		$this->deleteFromTrash($name);
		$this->listener->flush();

		$this->assertGone($deleted);
		$this->assertKept($disappeared);
	}

	/** Bound to another file between the look and the removal, as a move to another storage does: it lives on there. */
	public function testAFormBoundToAnotherFileWhileItsRemovalWasDecidedIsKept(): void {
		$file = $this->form();
		$form = $this->row($file);
		$now = $this->writeFormFile($this->userFolder, 'Moved on ' . uniqid());
		$name = $file->getName();
		$file->delete();
		$this->deleteFromTrash($name);
		$noted = $this->takeForgotten();
		$this->assertKept($form, 'nothing is flushed yet');

		$forms = new class(Server::get(IDBConnection::class), Server::get(ITimeFactory::class), $form->id, $now->getId()) extends FormStore {
			public function __construct(
				IDBConnection $db,
				ITimeFactory $time,
				private int $formId,
				private int $nowAt,
			) {
				parent::__construct($db, $time);
			}

			public function goneOrTrashed(): array {
				$found = parent::goneOrTrashed();
				foreach ($found as [$form, $place]) {
					if ($form->id === $this->formId) {
						[$storageId, $pathHash] = $this->place($this->nowAt);
						$this->rebind($form->id, $this->nowAt, $storageId, $pathHash);
					}
				}
				return $found;
			}
		};
		$orphans = new Orphans($forms, Server::get(FormFolders::class), Server::get(ITimeFactory::class), Server::get(LoggerInterface::class),
			Server::get(FormFileLocator::class), Server::get(FormReader::class), Server::get(OutboxStore::class), Server::get(IJobList::class));

		$removed = $orphans->removeDeleted(array_map(fn (array $entry): array => [$entry[0], $entry[1], $entry[2]], $noted));

		$this->assertSame([], $removed);
		$this->assertKept($form);
		$this->assertSame($form->id, Server::get(FormStore::class)->byFileId($now->getId())?->id);
	}

	// =====================================================================
	// A form not moved into app data yet
	// =====================================================================

	public function testTheDraftOfAFileDeletedForGoodIsRemovedToo(): void {
		$file = $this->legacy();
		$draft = Server::get(FormConverter::class)->prepare($file, true);
		$this->assertTrue($draft->draft);
		$this->assertSame(2, $this->rows('formvox_resp', $draft->id));
		Server::get(FormFolders::class)->kind($draft->id, FormFolders::UPLOADS)->get(self::GROUP . '/cv.pdf');
		$name = $file->getName();

		$file->delete();
		$this->listener->flush();
		$this->assertNotNull(Server::get(FormStore::class)->byId($draft->id), 'in the trash');

		$this->deleteFromTrash($name);
		$this->listener->flush();

		$this->assertNull(Server::get(FormStore::class)->byId($draft->id));
		$this->assertSame(0, $this->rows('formvox_resp', $draft->id));
		try {
			Server::get(FormFolders::class)->all($draft->id);
			$this->fail('the files copied ahead are still in app data');
		} catch (NotFoundException) {
		}
	}

	/** The folders beside it went into the trash as items of their own, with the respondents' files. */
	public function testAFormDeletedFromTheTrashTakesTheFoldersThatWentWithIt(): void {
		$file = $this->legacy();
		$other = $this->legacy();
		$ids = [$file->getId(), $other->getId()];
		$uploads = '.formvox-uploads-' . $ids[0];
		$otherUploads = '.formvox-uploads-' . $ids[1];
		$name = $file->getName();
		$otherName = $other->getName();
		$file->delete();
		$other->delete();
		$this->listener->flush();
		$this->assertNotNull($this->inTrash($uploads));

		// While the form is still in the trash, its folders stay with it.
		Server::get(FormTrash::class)->removed($ids);
		$this->assertNotNull($this->inTrash($uploads));

		$this->deleteFromTrash($name);
		$this->listener->flush();

		$this->assertNull($this->inTrash($uploads), 'the respondents\' files are still in the trash');
		$this->assertNotNull($this->inTrash($otherUploads), 'another form\'s folder went too');
		$this->assertNotNull($this->inTrash($otherName));
	}

	// =====================================================================
	// A team folder
	// =====================================================================

	public function testAFormMovedToATeamFolderAndBackIsFollowedNotRemoved(): void {
		$team = $this->teamFolder();
		$file = $this->form();
		$form = $this->row($file);
		$forms = Server::get(FormStore::class);

		$moved = $file->move($team->getPath() . '/' . $file->getName());
		$this->listener->flush();

		$now = $forms->byId($form->id);
		$this->assertNotNull($now, 'the form was removed with the file\'s old place');
		$this->assertNotSame($form->storageId, $now->storageId, 'it did not change storage');
		$this->assertSame($forms->place($moved->getId()), [$now->storageId, $now->pathHash]);
		$this->assertSame($moved->getId(), $now->fileId);
		$this->assertSame(['Anna', 'Bob'], $this->answers($moved->getId()));
		$this->assertKept($form);

		$back = $moved->move($this->userFolder->getPath() . '/' . $file->getName());
		$this->listener->flush();

		$this->assertSame($form->storageId, $forms->byId($form->id)?->storageId);
		$this->assertSame(['Anna', 'Bob'], $this->answers($back->getId()));
		$this->assertKept($form);
	}

	/** @return array<string, array{bool}> */
	public static function teamFolders(): array {
		return [
			'a team folder with a storage of its own' => [false],
			'a team folder made before Groupfolders 20, in the instance\'s storage' => [true],
		];
	}

	/** @dataProvider teamFolders */
	public function testAFormDeletedFromATeamFoldersTrashIsGone(bool $legacy): void {
		$team = $this->teamFolder($legacy);
		$team->newFolder('Sub');
		$file = $this->form(self::TEAM_FOLDER);
		$forms = [$this->row($file), $this->row($this->form(self::TEAM_FOLDER . '/Sub'))];
		$kept = $this->row($this->form(self::TEAM_FOLDER));
		$name = $file->getName();
		$this->assertStringNotContainsString('home::', $file->getStorage()->getId());
		$this->assertSame($legacy, str_starts_with($this->pathOf($file->getId()), '__groupfolders/'));

		$file->delete();
		$team->get('Sub')->delete();
		$this->listener->flush();
		$this->assertKept($forms[0], 'in the team folder\'s trash');
		$this->assertKept($forms[1], 'in the team folder\'s trash');
		$trashed = Server::get(FormStore::class)->byId($forms[0]->id);
		$this->assertSame(Server::get(FormStore::class)->place($forms[0]->fileId), [$trashed->storageId, $trashed->pathHash]);
		$this->assertStringStartsWith($legacy ? '__groupfolders/trash/' : 'trash/', $this->pathOf($forms[0]->fileId));

		$this->deleteFromTrash($name);
		$this->deleteFromTrash('Sub');
		$this->listener->flush();

		$this->assertGone($forms[0], 'the form:');
		$this->assertGone($forms[1], 'the form in the folder:');
		$this->assertKept($kept, 'another form in the team folder');
	}

	/** A folder named as a team folder's trash is, in a storage that is no team folder's: no trash at all. */
	public function testAFolderNamedTrashInAnotherStorageIsNoTrash(): void {
		$file = $this->form();
		$form = $this->row($file);
		$disk = $file->getStorage();
		while ($disk->instanceOfStorage(\OC\Files\Storage\Wrapper\Wrapper::class)) {
			$disk = $disk->getWrapperStorage();
		}
		$this->assertTrue($disk->unlink($file->getInternalPath()));
		$file->getStorage()->getScanner()->scan('files');
		$this->takeForgotten();

		$listener = $this->listener();
		$listener->handle(new CacheEntryRemovedEvent($file->getStorage(), 'trash/' . $file->getName() . '.d1700000000', $form->fileId, $form->storageId));
		$listener->flush();

		$this->assertKept($form);
	}

	/** @param bool $legacy as Groupfolders made them before version 20: in the instance's own storage, under __groupfolders/ */
	private function teamFolder(bool $legacy = false): Folder {
		$apps = Server::get(IAppManager::class);
		if (!$apps->isEnabledForAnyone('groupfolders')) {
			$message = 'The Groupfolders app is not enabled, so nothing can be deleted from a team folder.';
			if (getenv('FORMVOX_REQUIRE_GROUPFOLDERS')) {
				$this->fail($message);
			}
			$this->markTestSkipped($message);
		}
		$apps->loadApp('groupfolders');
		self::removeTeamFolder();
		$groups = Server::get(IGroupManager::class);
		($groups->get(self::TEAM) ?? $groups->createGroup(self::TEAM))->addUser($this->owner);
		$output = new BufferedOutput();
		if ($legacy) {
			$folderId = $this->legacyTeamFolder();
		} else {
			$this->assertSame(0, Server::get(\OCA\GroupFolders\Command\Create::class)->run(new ArrayInput(['name' => self::TEAM_FOLDER]), $output));
			$folderId = (int)trim($output->fetch());
		}
		$this->assertGreaterThan(0, $folderId, 'no team folder was made');
		$this->assertSame(0, Server::get(\OCA\GroupFolders\Command\Group::class)->run(new ArrayInput([
			'folder_id' => (string)$folderId, 'group' => self::TEAM, 'permissions' => ['read', 'write', 'share', 'delete'],
		]), $output));
		// A new request, which mounts the folder for its member.
		$this->loginAs($this->ownerUid, $this->owner);
		$this->userFolder = $this->rootFolder->getUserFolder($this->ownerUid);
		$team = $this->userFolder->get(self::TEAM_FOLDER);
		$this->assertInstanceOf(Folder::class, $team);
		return $team;
	}

	private function legacyTeamFolder(): int {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->insert('group_folders')->values([
			'mount_point' => $qb->createNamedParameter(self::TEAM_FOLDER),
			'quota' => $qb->createNamedParameter(\OCA\GroupFolders\Folder\FolderManager::SPACE_DEFAULT),
		]);
		$qb->executeStatement();
		$folderId = $qb->getLastInsertId();
		['storage_id' => $storageId, 'root_id' => $rootId] = Server::get(\OCA\GroupFolders\Mount\FolderStorageManager::class)
			->initRootAndStorageForFolder($folderId, false, []);
		$qb = $db->getQueryBuilder();
		$qb->update('group_folders')
			->set('storage_id', $qb->createNamedParameter($storageId))
			->set('root_id', $qb->createNamedParameter($rootId))
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
		$qb->executeStatement();
		return $folderId;
	}

	private static function removeTeamFolder(): void {
		if (!Server::get(IAppManager::class)->isEnabledForAnyone('groupfolders')) {
			return;
		}
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('folder_id')->from('group_folders')->where($qb->expr()->eq('mount_point', $qb->createNamedParameter(self::TEAM_FOLDER)));
		foreach ($qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) as $id) {
			$command = Server::get(\OCA\GroupFolders\Command\Delete::class);
			$command->setHelperSet(new HelperSet([new QuestionHelper()]));
			if ($command->run(new ArrayInput(['folder_id' => (string)$id, '--force' => true]), new BufferedOutput()) !== 0) {
				Server::get(\OCA\GroupFolders\Folder\FolderManager::class)->removeFolder((int)$id);
			}
		}
	}
}
