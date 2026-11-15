<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Files;

use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\FormVox\BackgroundJob\AccountFormsJob;
use OCA\FormVox\BackgroundJob\SideEffectsJob;
use OCA\FormVox\Listener\FormRemovedListener;
use OCA\FormVox\Pdf\PdfMailPolicy;
use OCA\FormVox\Service\ConfirmationMail;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormFolders;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\OutboxStore;
use OCA\FormVox\Store\SecretStore;
use OCA\FormVox\Store\StoredForm;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Against a real Nextcloud: the forms in a deleted account's home, its trash too, are gone with their responses and
 * files when that request ends; a form the account only had access to stays.
 *
 * The removal is a background job (AccountFormsJob), run here as cron would.
 *
 * @group DB
 */
class AccountDeletedIntegrationTest extends IntegrationTestCase {
	private const GROUP = '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e';
	private const TABLES = ['formvox_resp', 'formvox_keys', 'formvox_counts', 'formvox_outbox', 'formvox_secrets'];
	private const TEAM_FOLDER = 'FormVox Account IT';
	private const TEAM = 'fvaccount-team';

	/** A new account each test: Nextcloud 32 keeps a deleted account's avatar folder for the request. */
	private string $leaver;

	protected function setUp(): void {
		parent::setUp();
		$this->reconnectNodeEvents();
		// What earlier tests deleted is theirs.
		$this->runAccountJobs();
		Server::get(FormRemovedListener::class)->flush();
		$this->leaver = 'formvox-int-leaver-' . uniqid();
	}

	protected function tearDown(): void {
		Server::get(IUserManager::class)->get($this->leaver)?->delete();
		$this->runAccountJobs();
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

	/** Signs in as the account that is to be deleted, as a request of its own. */
	private function leaver(): IUser {
		$user = $this->switchToUser($this->leaver);
		$this->userFolder->getStorage()->getCache()->getNumericStorageId();
		return $user;
	}

	/** A form in the database in the signed-in account's home, with two responses, a respondent's file and a secret. */
	private function form(string $folder = ''): File {
		$repository = Server::get(FormRepository::class);
		$fileId = (int)$repository->create('Account ' . uniqid(), $folder)['fileId'];
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
		Server::get(UploadService::class)->storeUploadPublic($fileId, self::GROUP, $this->makeUploadedFile('cv.pdf', 'cv', 'application/pdf'));
		$file = $this->userFolder->getFirstNodeById($fileId);
		$this->assertInstanceOf(File::class, $file);
		$row = $this->row($file);
		Server::get(SecretStore::class)->save($row->id, ['public_token' => 'a-token-for-' . $row->id]);
		foreach (['formvox_resp', 'formvox_keys', 'formvox_outbox', 'formvox_secrets'] as $table) {
			$this->assertGreaterThan(0, $this->rows($table, $row->id), 'the form has no rows in ' . $table);
		}
		Server::get(FormFolders::class)->kind($row->id, FormFolders::UPLOADS)->get(self::GROUP . '/cv.pdf');
		return $file;
	}

	/** A form as 1.5 wrote it, with two responses: still to be moved in. */
	private function legacy(): File {
		$file = $this->writeFormFile($this->userFolder, 'Legacy ' . uniqid(), [
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'responses' => [
				['id' => 'r1', 'submitted_at' => '2026-10-01T10:00:00+00:00', 'answers' => ['q' => 'Anna']],
				['id' => 'r2', 'submitted_at' => '2026-10-02T10:00:00+00:00', 'answers' => ['q' => 'Bob']],
			],
		]);
		$this->assertNull(Server::get(FormStore::class)->byFileId($file->getId()), 'a form of 1.5 is in no table');
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

	/**
	 * Runs the queued removals of deleted accounts' forms, as cron would: one job at a time, each built just before it
	 * runs, since Nextcloud builds every job of a class on one shared instance.
	 */
	private function runAccountJobs(): void {
		$jobs = Server::get(IJobList::class);
		$ids = [];
		foreach ($jobs->getJobsIterator(AccountFormsJob::class, null, 0) as $job) {
			$ids[] = $job->getId();
		}
		foreach ($ids as $id) {
			$jobs->getById($id)?->start($jobs);
		}
	}

	private function deleteLeaver(): void {
		$user = Server::get(IUserManager::class)->get($this->leaver);
		$this->assertNotNull($user);
		$this->logout();
		$this->assertTrue($user->delete());
		$this->runAccountJobs();
		$this->loginAs($this->ownerUid, $this->owner);
		$this->userFolder = $this->rootFolder->getUserFolder($this->ownerUid);
	}

	private function assertKept(StoredForm $form, string $message = ''): void {
		$this->assertNotNull(Server::get(FormStore::class)->byId($form->id), $message);
		$this->assertSame(2, $this->rows('formvox_resp', $form->id), $message);
	}

	private function assertGone(StoredForm $form, string $message = ''): void {
		$this->assertNull(Server::get(FormStore::class)->byId($form->id), $message);
		foreach (self::TABLES as $table) {
			$this->assertSame(0, $this->rows($table, $form->id), trim($message . ' rows left in ' . $table));
		}
		try {
			Server::get(FormFolders::class)->all($form->id);
			$this->fail(trim($message . ' its files are still in app data'));
		} catch (NotFoundException) {
		}
	}

	public function testTheFormsInADeletedAccountsHomeAndTrashGoWithIt(): void {
		$kept = $this->row($this->form());
		$this->leaver();
		$this->userFolder->newFolder('Sub');
		$forms = [$this->row($this->form()), $this->row($this->form('Sub'))];
		$trashed = $this->form();
		$forms[] = $this->row($trashed);
		$legacy = $this->legacy();
		$draft = Server::get(FormConverter::class)->prepare($legacy, true);
		$this->assertTrue($draft->draft);
		$apps = Server::get(IAppManager::class);
		if ($apps->isEnabledForUser('files_trashbin')) {
			$apps->loadApp('files_trashbin');
			$trashed->delete();
			Server::get(FormRemovedListener::class)->flush();
			$this->assertNotEmpty(Server::get(ITrashManager::class)->listTrashRoot(Server::get(IUserManager::class)->get($this->leaver)), 'the form lies in the trash');
			$this->assertKept($forms[2], 'in the trash');
		}

		$this->deleteLeaver();

		$this->assertGone($forms[0], 'a form in the home:');
		$this->assertGone($forms[1], 'a form in a folder:');
		$this->assertGone($forms[2], 'a form in the trash:');
		$this->assertGone($draft, 'a draft:');
		$this->assertKept($kept, 'another account\'s form');
	}

	public function testAFormSharedWithTheDeletedAccountStays(): void {
		$file = $this->form();
		$form = $this->row($file);
		$this->share($file);
		$this->leaver();
		$this->assertNotEmpty($this->userFolder->getById($file->getId()), 'the account sees the form');

		$this->deleteLeaver();

		$this->assertKept($form);
	}

	public function testAFormMovedOutOfTheHomeIntoASharedFolderStays(): void {
		$shared = $this->userFolder->newFolder('Shared ' . uniqid());
		$this->share($shared);
		$this->leaver();
		$file = $this->form();
		$form = $this->row($file);
		$file->move($this->userFolder->get($shared->getName())->getPath() . '/' . $file->getName());

		$this->deleteLeaver();

		$this->assertKept($form);
		$this->assertSame($form->id, Server::get(FormStore::class)->byFileId($form->fileId)?->id);
	}

	/** Bound to the home as the database last saw it, as after a move it was not told of: the file lives elsewhere. */
	public function testAFormBoundToTheHomeWhoseFileLivesElsewhereStays(): void {
		$form = $this->row($this->form());
		$this->leaver();
		$homes = Server::get(FormStore::class)->homeStorages($this->leaver);
		$this->assertCount(1, $homes);
		Server::get(FormStore::class)->seenAt($form->id, $form->fileId, $homes[0], $form->pathHash);

		$this->deleteLeaver();

		$this->assertKept($form);
	}

	/** Its file in the home, though the database last saw it elsewhere: it is the account's. */
	public function testAFormInTheHomeBoundElsewhereGoesToo(): void {
		$elsewhere = $this->row($this->form())->storageId;
		$this->leaver();
		$form = $this->row($this->form());
		Server::get(FormStore::class)->seenAt($form->id, $form->fileId, $elsewhere, $form->pathHash);

		$this->deleteLeaver();

		$this->assertGone($form);
	}

	/** Gone behind Nextcloud's back, another account's form keeps the grace the sweep gives it. */
	public function testAnotherAccountsFormWhoseFileIsGoneKeepsItsGrace(): void {
		$file = $this->form();
		$form = $this->row($file);
		$disk = $file->getStorage();
		while ($disk->instanceOfStorage(\OC\Files\Storage\Wrapper\Wrapper::class)) {
			$disk = $disk->getWrapperStorage();
		}
		$this->assertTrue($disk->unlink($file->getInternalPath()));
		$file->getStorage()->getScanner()->scan('files');
		Server::get(FormRemovedListener::class)->flush();
		$this->assertSame([$form->fileId], Server::get(FormStore::class)->gone([$form->fileId]));
		$this->leaver();
		$this->form();

		$this->deleteLeaver();

		$this->assertKept($form);
	}

	public function testATeamFolderFormOfTheDeletedAccountStays(): void {
		$this->teamFolder();
		$this->leaver();
		$file = $this->form(self::TEAM_FOLDER);
		$form = $this->row($file);
		$this->assertStringNotContainsString('home::', $file->getStorage()->getId());

		$this->deleteLeaver();

		$this->assertKept($form);
	}

	public function testAFormOf15InTheDeletedHomeIsNoError(): void {
		$this->leaver();
		$legacy = $this->legacy();
		$form = $this->row($this->form());

		$this->deleteLeaver();

		$this->assertGone($form, 'the form beside it:');
		$this->assertNull(Server::get(FormStore::class)->byFileId($legacy->getId()));
	}

	/** The account is in the outbox as a respondent of another account's form: no email goes to anyone for it. */
	public function testAConfirmationForADeletedRespondentIsCrossedOffUnsent(): void {
		$file = $this->writeFormFile($this->userFolder, 'Confirm ' . uniqid(), [
			'settings' => ['allow_multiple' => true, 'anonymous' => true, 'public_token' => 'tok', 'sendConfirmationEmail' => true, 'notify_owner' => false],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Email', 'useAsRespondentEmail' => true]],
		]);
		$fileId = $file->getId();
		$repository = Server::get(FormRepository::class);
		$leaver = $this->ensureUser($this->leaver);
		$leaver->setSystemEMailAddress('leaver@example.org');
		$form = $repository->loadPublic($fileId);
		Server::get(ResponseService::class)->submitAnonymousWithForm($fileId, $form, ['q1' => 'typed@example.org'], Server::get(IRequest::class), null, null, null, $this->leaver);
		$stored = $this->row($file);
		$this->assertSame($this->leaver, Server::get(OutboxStore::class)->entries($stored->id)[0]['account'] ?? null);

		$this->deleteLeaver();

		$this->assertNotNull(Server::get(FormStore::class)->byId($stored->id), 'the form is another account\'s');
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->expects($this->never())->method('send');
		$mail = new ConfirmationMail($mailer, Server::get(IFactory::class), Server::get(LoggerInterface::class), Server::get(PdfMailPolicy::class), Server::get(IUserManager::class));
		$this->assertTrue($mail->send($form, ['q1' => 'typed@example.org'], 'en', null, $this->leaver, $fileId), 'nothing to send, nothing to try again');
		$job = Server::get(SideEffectsJob::class);
		$job->setArgument(['fileId' => $fileId]);
		$job->start(Server::get(IJobList::class));
		$this->assertSame([], Server::get(OutboxStore::class)->entries($stored->id), 'crossed off');
	}

	public function testAnAccountWithoutFormsOrStorageIsDeletedWithoutError(): void {
		$this->leaver();
		$this->deleteLeaver();

		$this->leaver .= '-never-signed-in';
		$user = $this->ensureUser($this->leaver);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('storages')->where($qb->expr()->in('id', $qb->createNamedParameter(['home::' . $this->leaver, 'object::user:' . $this->leaver], IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
		$this->assertSame([], Server::get(FormStore::class)->homeStorages($this->leaver));
		$this->assertTrue($user->delete());
		$this->runAccountJobs();
		$this->assertNull(Server::get(IUserManager::class)->get($this->leaver));
	}

	private function share(\OCP\Files\Node $node): void {
		$this->ensureUser($this->leaver);
		$shares = Server::get(IShareManager::class);
		$share = $shares->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedWith($this->leaver)
			->setSharedBy($this->ownerUid)
			->setPermissions($node instanceof File ? Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE : Constants::PERMISSION_ALL);
		$shares->createShare($share);
	}

	private function teamFolder(): void {
		$apps = Server::get(IAppManager::class);
		if (!$apps->isEnabledForAnyone('groupfolders')) {
			$message = 'The Groupfolders app is not enabled, so there is no team folder.';
			if (getenv('FORMVOX_REQUIRE_GROUPFOLDERS')) {
				$this->fail($message);
			}
			$this->markTestSkipped($message);
		}
		$apps->loadApp('groupfolders');
		self::removeTeamFolder();
		$groups = Server::get(IGroupManager::class);
		($groups->get(self::TEAM) ?? $groups->createGroup(self::TEAM))->addUser($this->ensureUser($this->leaver));
		$output = new BufferedOutput();
		$this->assertSame(0, Server::get(\OCA\GroupFolders\Command\Create::class)->run(new ArrayInput(['name' => self::TEAM_FOLDER]), $output));
		$folderId = (int)trim($output->fetch());
		$this->assertGreaterThan(0, $folderId, 'no team folder was made');
		$this->assertSame(0, Server::get(\OCA\GroupFolders\Command\Group::class)->run(new ArrayInput([
			'folder_id' => (string)$folderId, 'group' => self::TEAM, 'permissions' => ['read', 'write', 'share', 'delete'],
		]), $output));
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
