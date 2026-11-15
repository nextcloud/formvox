<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\Command\Orphans as OrphansCommand;
use OCA\FormVox\Command\Rebind;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormReader;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormFolders;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\Orphans;
use OCA\FormVox\Store\OutboxStore;
use OCA\FormVox\Store\StoredForm;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Against a real Nextcloud: a form whose file is gone for good waits 30 days as an orphan, then goes with its rows and
 * files; an administrator sees the orphans and binds one to a file by hand.
 *
 * @group DB
 */
class OrphansIntegrationTest extends IntegrationTestCase {
	/** A file id no file has. */
	private const GONE = 2000000001;
	private const GROUP = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	protected function setUp(): void {
		parent::setUp();
		$this->reconnectNodeEvents();
	}

	/** A form in the database with two responses and a respondent's file. */
	private function form(string $folder = ''): File {
		$repository = Server::get(FormRepository::class);
		$fileId = (int)$repository->create('Orphan ' . uniqid(), $folder)['fileId'];
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
		return $this->userFolder->getFirstNodeById($fileId);
	}

	private function row(File $file): StoredForm {
		return Server::get(FormStore::class)->byFileId($file->getId());
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

	/**
	 * The sweep with a store that switches the draft to the form right after the sweep took its look, as a request or
	 * the migration job does meanwhile; $gone pretends the look found no file for it.
	 */
	private function sweepWhileSwitching(File $file, bool $gone): array {
		$forms = new class(Server::get(IDBConnection::class), Server::get(ITimeFactory::class), $file, $gone) extends FormStore {
			public function __construct(
				IDBConnection $db,
				ITimeFactory $time,
				private File $file,
				private bool $gone,
			) {
				parent::__construct($db, $time);
			}

			public function withPlaces(): array {
				$places = parent::withPlaces();
				Server::get(FormConverter::class)->convert($this->file);
				foreach ($places as $i => [$form]) {
					if ($form->fileId === (int)$this->file->getId() && $form->draft && $this->gone) {
						$places[$i][1] = null;
					}
				}
				return $places;
			}
		};
		$orphans = new Orphans($forms, Server::get(FormFolders::class), Server::get(ITimeFactory::class), Server::get(LoggerInterface::class),
			Server::get(FormFileLocator::class), Server::get(FormReader::class), Server::get(OutboxStore::class), Server::get(IJobList::class));
		return $orphans->sweep();
	}

	/** Its file gone for good, with nothing to tell, as when a trash is emptied: nothing is where it was. */
	private function lose(StoredForm $form): void {
		Server::get(FormStore::class)->rebind($form->id, self::GONE, $form->storageId, md5('files/gone ' . uniqid()));
	}

	private function goneSince(StoredForm $form, int $days): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(FormStore::TABLE)
			->set('orphaned_at', $qb->createNamedParameter(time() - $days * 86400, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($form->id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return array{int, string} */
	private function occ(string $command, array $input): array {
		$output = new BufferedOutput();
		$code = Server::get($command)->run(new ArrayInput($input), $output);
		return [$code, $output->fetch()];
	}

	/** @return list<string> */
	private function answers(int $fileId): array {
		return array_column(array_column(Server::get(FormRepository::class)->load($fileId)['responses'] ?? [], 'answers'), 'q');
	}

	public function testAFormWhoseFileIsGoneBecomesAnOrphanAndOneWhoseFileIsThereComesBack(): void {
		$forms = Server::get(FormStore::class);
		$lost = $this->row($this->form());
		$kept = $this->form();
		$this->lose($lost);
		$forms->markOrphan($this->row($kept)->id);

		Server::get(Orphans::class)->sweep();

		$this->assertNotNull($forms->byId($lost->id)?->orphanedAt);
		$this->assertContains($lost->id, array_map(fn (StoredForm $form) => $form->id, $forms->orphans()));
		$this->assertNull($forms->byFileId($kept->getId())?->orphanedAt, 'its file is there');
		$this->assertSame(['Anna', 'Bob'], $this->answers($kept->getId()));
	}

	/** Files put back or scanned again give the form a new id where it was: the sweep binds it again, as opening it would. */
	public function testAFormWithANewIdWhereItWasIsFoundAgain(): void {
		$file = $this->form();
		$form = $this->row($file);
		Server::get(FormStore::class)->rebind($form->id, self::GONE, $form->storageId, $form->pathHash);

		$done = Server::get(Orphans::class)->sweep();

		$this->assertSame($form->id, Server::get(FormStore::class)->byFileId($file->getId())?->id);
		$this->assertNull(Server::get(FormStore::class)->byId($form->id)?->orphanedAt);
		$this->assertGreaterThanOrEqual(1, $done['found']);
	}

	/** A job asked for under an id the form no longer has never runs: the sweep asks again under the one it has. */
	public function testWhatWaitsToBeSentGetsItsJobUnderTheFormsIdNow(): void {
		$file = $this->form();
		$jobs = Server::get(\OCP\BackgroundJob\IJobList::class);
		$jobs->remove(\OCA\FormVox\BackgroundJob\SideEffectsJob::class, ['fileId' => $file->getId()]);

		Server::get(Orphans::class)->sweep();

		$this->assertTrue($jobs->has(\OCA\FormVox\BackgroundJob\SideEffectsJob::class, ['fileId' => $file->getId()]));
	}

	public function testAFormInTheTrashIsNoOrphan(): void {
		$file = $this->form();
		$form = $this->row($file);

		$file->delete();
		Server::get(Orphans::class)->sweep();

		$this->assertNull(Server::get(FormStore::class)->byId($form->id)?->orphanedAt);
	}

	public function testAnOrphanIsRemovedAfterThirtyDaysWithItsRowsAndFiles(): void {
		$forms = Server::get(FormStore::class);
		$old = $this->row($this->form());
		$young = $this->row($this->form());
		$this->lose($old);
		$this->lose($young);
		Server::get(Orphans::class)->sweep();
		$this->goneSince($old, 31);
		$this->goneSince($young, 29);

		Server::get(Orphans::class)->sweep();

		$this->assertNull($forms->byId($old->id));
		$this->assertNull($forms->byUuid($old->uuid));
		$this->assertSame(0, $this->rows('formvox_resp', $old->id));
		try {
			Server::get(FormFolders::class)->all($old->id);
			$this->fail('Its files are still in app data');
		} catch (NotFoundException) {
		}
		$this->assertNotNull($forms->byId($young->id), 'not yet');
		$this->assertSame(2, $this->rows('formvox_resp', $young->id));
		Server::get(FormFolders::class)->all($young->id);
	}

	/** A folder renamed tells the forms in it nothing: the sweep sees where they are now. */
	public function testTheSweepKnowsWhereAFormInARenamedFolderIsNow(): void {
		$name = 'Sweep ' . uniqid();
		$this->userFolder->newFolder($name);
		$file = $this->form($name);
		$folder = $this->userFolder->get($name)->move($this->userFolder->getPath() . '/' . $name . ' renamed');

		Server::get(Orphans::class)->sweep();

		$form = $this->row($file);
		$this->assertSame(md5($folder->get($file->getName())->getInternalPath()), $form->pathHash);
	}

	public function testAnAdministratorSeesRemovesAndBindsOrphans(): void {
		$forms = Server::get(FormStore::class);
		$found = $this->row($this->form());
		$dropped = $this->row($this->form());
		$this->lose($found);
		$this->lose($dropped);
		Server::get(Orphans::class)->sweep();

		[$code, $list] = $this->occ(OrphansCommand::class, []);
		$this->assertSame(0, $code);
		$this->assertStringContainsString($found->uuid, $list);
		$this->assertStringContainsString($dropped->uuid, $list);

		$this->assertSame(0, $this->occ(OrphansCommand::class, ['--remove' => (string)$dropped->id])[0]);
		$this->assertNull($forms->byId($dropped->id));
		$this->assertSame(1, $this->occ(OrphansCommand::class, ['--remove' => (string)$this->row($this->form())->id])[0], 'no orphan');

		$file = $this->writeFormFile($this->userFolder, 'Found again ' . uniqid());
		$this->assertSame(0, $this->occ(Rebind::class, ['file-id' => (string)$file->getId(), 'form-id' => (string)$found->id])[0]);
		$this->assertSame(['Anna', 'Bob'], $this->answers($file->getId()));
		$this->assertSame(1, $this->occ(Rebind::class, ['file-id' => (string)$file->getId(), 'form-id' => (string)$this->row($this->form())->id])[0], 'it holds a form already');
		$text = $this->userFolder->newFile('Notes ' . uniqid() . '.txt', 'no form');
		$this->assertSame(1, $this->occ(Rebind::class, ['file-id' => (string)$text->getId(), 'form-id' => (string)$found->id])[0], 'no form');
	}

	/** A draft switched to between the sweep's look and its check is the form now, by the same row: nothing of it goes. */
	public function testADraftSwitchedDuringTheSweepIsKept(): void {
		$file = $this->legacy();
		$draft = Server::get(FormConverter::class)->prepare($file, true);
		$this->assertTrue($draft->draft);

		$done = $this->sweepWhileSwitching($file, false);

		$this->assertSame(0, $done['drafts']);
		$form = Server::get(FormStore::class)->byFileId($file->getId());
		$this->assertSame($draft->id, $form?->id);
		$this->assertFalse($form->draft);
		$this->assertSame(['Anna', 'Bob'], $this->answers($file->getId()));
		$this->assertSame('cv', Server::get(FormFolders::class)->kind($draft->id, FormFolders::UPLOADS)->get(self::GROUP . '/cv.pdf')->getContent(), 'its files in app data stay');
	}

	/** Whatever the sweep saw, the removal itself refuses a row that is no longer a draft, and touches nothing else. */
	public function testTheRemovalRefusesADraftThatIsTheFormNow(): void {
		$file = $this->legacy();
		$draft = Server::get(FormConverter::class)->prepare($file, true);

		$done = $this->sweepWhileSwitching($file, true);

		$this->assertSame(0, $done['drafts']);
		$this->assertSame($draft->id, Server::get(FormStore::class)->byFileId($file->getId())?->id);
		$this->assertSame(['Anna', 'Bob'], $this->answers($file->getId()));
		$this->assertSame(2, $this->rows('formvox_resp', $draft->id));
		$this->assertSame('cv', Server::get(FormFolders::class)->kind($draft->id, FormFolders::UPLOADS)->get(self::GROUP . '/cv.pdf')->getContent());
	}

	/** A draft that is truly abandoned still goes, rows and files, and is counted. */
	public function testAnAbandonedDraftIsRemovedWithItsFiles(): void {
		$file = $this->legacy();
		$draft = Server::get(FormConverter::class)->prepare($file, true);
		$file->delete();
		Server::get(FormStore::class)->rebind($draft->id, self::GONE, $draft->storageId, md5('files/gone ' . uniqid()));
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(FormStore::TABLE)
			->set('state', $qb->createNamedParameter(FormStore::DRAFT, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($draft->id, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$done = Server::get(Orphans::class)->sweep();

		$this->assertSame(1, $done['drafts']);
		$this->assertNull(Server::get(FormStore::class)->byId($draft->id));
		$this->assertSame(0, $this->rows('formvox_resp', $draft->id));
		try {
			Server::get(FormFolders::class)->all($draft->id);
			$this->fail('Its files are still in app data');
		} catch (NotFoundException) {
		}
	}

	private function rows(string $table, int $formId): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
