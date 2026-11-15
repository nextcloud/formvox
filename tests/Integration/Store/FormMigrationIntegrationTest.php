<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OC\Files\Mount\MountPoint;
use OC\Files\Storage\Wrapper\Wrapper;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\BackgroundJob\MigrateFormsJob;
use OCA\FormVox\Command\Migrate;
use OCA\FormVox\Migration\QueueFormMigration;
use OCA\FormVox\Service\FormFactory;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\OwnWrites;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormMigration;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Against a real Nextcloud: after an update every form still in its file is found through the file cache and moved in
 * by the background job or by occ, the largest first; a file that holds no form is passed over.
 *
 * @group DB
 */
class FormMigrationIntegrationTest extends IntegrationTestCase {
	private ?Folder $outOfReach = null;
	/** @var list<int> */
	private array $withoutFile = [];

	protected function setUp(): void {
		parent::setUp();
		Server::get(IAppConfig::class)->deleteKey(Application::APP_ID, MigrateFormsJob::DONE);
	}

	protected function tearDown(): void {
		Server::get(IAppConfig::class)->deleteKey(Application::APP_ID, MigrateFormsJob::DONE);
		Server::get(FormMigration::class)->forgetBroken();
		$this->outOfReach?->delete();
		foreach ($this->withoutFile as $formId) {
			Server::get(FormStore::class)->delete($formId);
		}
		parent::tearDown();
	}

	private function legacy(int $responses): File {
		return $this->writeFormFile($this->userFolder, 'To move ' . uniqid(), [
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'responses' => array_map(fn (int $i) => ['id' => 'r' . $i, 'submitted_at' => '2026-10-01T10:00:00+00:00', 'answers' => ['q' => str_repeat('x', 100) . $i]], range(1, $responses)),
		]);
	}

	/** A form file no account can open: in app data, outside every account's files. */
	private function outOfReach(string $description = ''): File {
		if ($this->outOfReach === null) {
			$appData = $this->rootFolder->get($this->rootFolder->getAppDataDirectoryName());
			$this->assertInstanceOf(Folder::class, $appData);
			$this->outOfReach = $appData->newFolder('formvox-test-' . uniqid());
		}
		$form = ['description' => $description] + Server::get(FormFactory::class)->createFormStructure('Out of reach', $this->ownerUid);
		return $this->outOfReach->newFile('Out of reach ' . uniqid() . '.fvform', json_encode($form));
	}

	/** A form in the database whose file is gone: what its move left can never be finished. */
	private function movedInWithoutFile(): int {
		$forms = Server::get(FormStore::class);
		$fileId = 2000000000 + random_int(0, 99999999);
		$formId = $forms->draft(FormFactory::uuid(), $fileId)->id;
		$forms->activate($formId);
		$this->withoutFile[] = $formId;
		return $fileId;
	}

	private function stored(File $file): bool {
		return Server::get(FormStore::class)->byFileId($file->getId()) !== null;
	}

	/** The next read of this file from its storage gives nothing, as object storage does when a request to it fails. */
	private function failNextReadOf(File $file): void {
		$mount = $file->getMountPoint();
		$this->assertInstanceOf(MountPoint::class, $mount);
		$path = $file->getInternalPath();
		$mount->wrapStorage(fn (string $mountPoint, IStorage $storage) => new class(['storage' => $storage], $path) extends Wrapper {
			private bool $failed = false;

			public function __construct(
				array $parameters,
				private string $failing,
			) {
				parent::__construct($parameters);
			}

			public function file_get_contents(string $path): string|false {
				if ($path === $this->failing && !$this->failed) {
					$this->failed = true;
					return false;
				}
				return parent::file_get_contents($path);
			}
		});
	}

	/** A file that reads as nothing once is a busy form, not a file holding none: the next run moves it in. */
	public function testAFileThatWouldNotReadOnceIsNotTakenForHoldingNoForm(): void {
		$file = $this->legacy(2);
		$migration = Server::get(FormMigration::class);
		$this->failNextReadOf($file);

		$done = $migration->run(60.0);

		$this->assertFalse($this->stored($file), 'passed over this run');
		$this->assertNotContains($file->getId(), $migration->status()['broken']);
		$this->assertContains($file->getId(), $migration->pending(PHP_INT_MAX));
		$this->assertTrue($done['left'], 'looked at again soon');

		$migration->run(60.0);
		$this->assertTrue($this->stored($file));
	}

	/** A file that held no form is looked at again once it changes, without waiting for an update. */
	public function testAMendedFileMovesInWithoutAnUpdate(): void {
		$broken = $this->userFolder->newFile('Mended ' . uniqid() . '.fvform', 'not json');
		$migration = Server::get(FormMigration::class);
		$this->assertSame('skipped: holds no form', $migration->moveOne($broken->getId()));
		$this->assertContains($broken->getId(), $migration->status()['broken']);
		$this->assertNotContains($broken->getId(), $migration->pending(PHP_INT_MAX));

		$form = Server::get(FormFactory::class)->createFormStructure('Mended', $this->ownerUid);
		$form['responses'] = [['id' => 'r1', 'submitted_at' => '2026-10-01T10:00:00+00:00', 'answers' => []]];
		Server::get(OwnWrites::class)->during(fn () => $broken->putContent(json_encode($form)));

		$this->assertContains($broken->getId(), $migration->pending(PHP_INT_MAX), 'changed since it was judged');
		$this->assertSame('moved in, 1 responses', $migration->moveOne($broken->getId()));
		$this->assertNotContains($broken->getId(), $migration->status()['broken']);
	}

	/** What a respondent uploads under the form's name, beside a form or in app data, is no form to move in. */
	public function testWhatRespondentsUploadedUnderTheFormsNameIsNoForm(): void {
		$legacy = $this->legacy(1);
		$this->requirePublicResolvable($legacy->getId());
		$inDatabase = (int)Server::get(FormRepository::class)->create('Born in the database ' . uniqid())['fileId'];
		$this->requirePublicResolvable($inDatabase);
		$uploads = Server::get(UploadService::class);
		$beside = $uploads->storeUploadPublic($legacy->getId(), '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', $this->makeUploadedFile('junk.fvform', 'not json'));
		$inAppData = $uploads->storeUploadPublic($inDatabase, '6f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', $this->makeUploadedFile('junk.fvform', 'not json'));
		$migration = Server::get(FormMigration::class);

		$pending = $migration->pending(PHP_INT_MAX);
		$this->assertContains($legacy->getId(), $pending);
		$this->assertNotContains((int)$beside['fileId'], $pending);
		$this->assertNotContains((int)$inAppData['fileId'], $pending);

		Server::get(MigrateFormsJob::class)->start(Server::get(IJobList::class));
		$this->assertTrue($this->stored($legacy));
		$this->assertSame([], $migration->status()['broken']);
		$this->assertGreaterThan(0, Server::get(IAppConfig::class)->getValueInt(Application::APP_ID, MigrateFormsJob::DONE), 'nothing left');
	}

	/** A form file no account can open is passed over; it does not keep the job running every few minutes. */
	public function testAFormNobodyCanOpenDoesNotKeepTheJobFromFinishing(): void {
		$file = $this->outOfReach();
		$migration = Server::get(FormMigration::class);
		$this->assertContains($file->getId(), $migration->pending(PHP_INT_MAX));

		$done = $migration->run(60.0);

		$this->assertFalse($this->stored($file));
		$this->assertFalse($done['left'], 'nothing this run could do is left');
		Server::get(MigrateFormsJob::class)->start(Server::get(IJobList::class));
		$this->assertGreaterThan(0, Server::get(IAppConfig::class)->getValueInt(Application::APP_ID, MigrateFormsJob::DONE));
	}

	/** A run goes on past the forms it looks up at a time: a site with thousands is not moved a hundred per cron run. */
	public function testARunMovesInAllThereIsWhileItHasTime(): void {
		$ids = [];
		for ($i = 0; $i < 230; $i++) {
			$ids[] = $this->legacy(1)->getId();
		}
		$migration = Server::get(FormMigration::class);
		$told = [];

		$done = $migration->run(900.0, function (int $fileId, string $what) use (&$told): void {
			$told[] = $fileId . ': ' . $what;
		});

		$this->assertSame([], array_values(array_intersect($ids, $migration->pending(PHP_INT_MAX))), 'none of them waits for a next run');
		foreach ($ids as $fileId) {
			$this->assertSame([$fileId . ': moved in, 1 responses' => 1], array_count_values(preg_grep('/^' . $fileId . ': /', $told)));
		}
		$this->assertGreaterThanOrEqual(230, $done['moved']);
		$this->assertFalse($done['left']);
	}

	/** A run out of time starts on nothing more, in either of its parts, and the next one goes on where it stopped. */
	public function testARunOutOfTimeLeavesTheRestToTheNext(): void {
		$files = [$this->legacy(1), $this->legacy(1), $this->legacy(1)];
		$ids = array_map(fn (File $file) => $file->getId(), $files);
		$unfinished = [$this->movedInWithoutFile(), $this->movedInWithoutFile()];
		$migration = Server::get(FormMigration::class);
		$told = [];
		// Each of these forms uses up all the run's time.
		$slowFor = function (array $slow) use (&$told): \Closure {
			$told = [];
			return function (int $fileId) use (&$told, $slow): void {
				if (in_array($fileId, $slow, true)) {
					$told[] = $fileId;
					usleep(1100000);
				}
			};
		};

		$done = $migration->run(1.0, $slowFor($unfinished));

		$this->assertTrue($done['left']);
		$this->assertLessThanOrEqual(1, count($told), 'nothing more is finished once the time is spent');
		$this->assertCount(3, array_filter($files, fn (File $file) => !$this->stored($file)), 'nor moved in');

		$done = $migration->run(1.0, $slowFor($ids));

		$this->assertTrue($done['left']);
		$this->assertLessThanOrEqual(1, count($told), 'no form is moved in once the time is spent');
		$this->assertGreaterThanOrEqual(2, count(array_filter($files, fn (File $file) => !$this->stored($file))));

		$done = $migration->run(60.0);

		$this->assertSame([], array_filter($files, fn (File $file) => !$this->stored($file)));
		$this->assertFalse($done['left']);
	}

	/** What a run cannot move in or finish is tried once: it does not use up the run's time, nor come back in it. */
	public function testAFormThatCannotBeMovedIsTriedOnceInARun(): void {
		$unreachable = $this->outOfReach()->getId();
		$gone = $this->movedInWithoutFile();
		$movable = $this->legacy(1);
		$migration = Server::get(FormMigration::class);
		$tries = [];

		$done = $migration->run(8.0, function (int $fileId) use (&$tries): void {
			$tries[$fileId] = ($tries[$fileId] ?? 0) + 1;
		});

		$this->assertSame(1, $tries[$unreachable] ?? 0, 'the form nobody can open');
		$this->assertSame(1, $tries[$gone] ?? 0, 'the form whose file is gone');
		$this->assertSame(1, $tries[$movable->getId()] ?? 0);
		$this->assertTrue($this->stored($movable), 'the others are moved in all the same');
		$this->assertFalse($done['left'], 'the run ended for want of work, not of time');
	}

	/** More forms than a run looks up at a time that it cannot move in or finish do not keep it from those after them. */
	public function testFormsThatCannotBeMovedDoNotHoldBackThoseAfterThem(): void {
		for ($i = 0; $i < 101; $i++) {
			$this->movedInWithoutFile();
			// Larger than the form that can be moved in, so they come before it.
			$this->outOfReach(str_repeat('x', 4000));
		}
		$touched = $this->legacy(1);
		Server::get(FormConverter::class)->convert($touched);
		$this->assertFalse(Server::get(FormStore::class)->byFileId($touched->getId())->filesMoved, 'moved in at a touch: its files wait for the job');
		$movable = $this->legacy(1);
		$migration = Server::get(FormMigration::class);
		$pending = $migration->pending(PHP_INT_MAX);
		$this->assertGreaterThanOrEqual(101, array_search($movable->getId(), $pending, true));

		$done = $migration->run(300.0);

		$this->assertTrue(Server::get(FormStore::class)->byFileId($touched->getId())->filesMoved, 'finished, past those that cannot be');
		$this->assertTrue($this->stored($movable), 'moved in, past those that cannot be');
		$this->assertFalse($done['left']);
	}

	/** Cron comes every five minutes: a run that began well into its own cron run does not make the next one pass. */
	public function testTheJobIsDueAtTheNextCronRunHoweverLateItBegan(): void {
		$jobs = Server::get(IJobList::class);
		if (!$jobs->has(MigrateFormsJob::class, null)) {
			$jobs->add(MigrateFormsJob::class);
		}
		$file = $this->legacy(1);
		// The run before began 50 seconds into its cron run; this cron run is the next, 300 seconds after that one.
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('jobs')
			->set('last_run', $qb->createNamedParameter(time() - 250, IQueryBuilder::PARAM_INT))
			->set('last_checked', $qb->createNamedParameter(time() - 250, IQueryBuilder::PARAM_INT))
			->set('reserved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('class', $qb->createNamedParameter(MigrateFormsJob::class)))
			->executeStatement();

		$job = $jobs->getNext(false, [MigrateFormsJob::class]);

		$this->assertInstanceOf(MigrateFormsJob::class, $job, 'the job list hands it out');
		try {
			$job->start($jobs);
		} finally {
			$jobs->unlockJob($job);
		}
		$this->assertTrue($this->stored($file), 'and it runs');
	}

	public function testTheFormsStillInTheirFileAreFoundTheLargestFirst(): void {
		$small = $this->legacy(1);
		$large = $this->legacy(50);
		$inDatabase = (int)Server::get(FormRepository::class)->create('Born in the database ' . uniqid())['fileId'];

		$pending = Server::get(FormMigration::class)->pending(PHP_INT_MAX);

		$this->assertContains($small->getId(), $pending);
		$this->assertContains($large->getId(), $pending);
		$this->assertNotContains($inDatabase, $pending);
		$this->assertLessThan(array_search($small->getId(), $pending, true), array_search($large->getId(), $pending, true));
	}

	/** A form in a deleted folder keeps its name in the trash: it moves in once it is restored, not before. */
	public function testAFormInTheTrashIsNotMoved(): void {
		$folder = $this->userFolder->newFolder('Deleted ' . uniqid());
		$file = $this->writeFormFile($folder, 'In the trash', ['questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']]]);
		$folder->delete();

		$this->assertNotContains($file->getId(), Server::get(FormMigration::class)->pending(PHP_INT_MAX));
	}

	public function testTheJobMovesThemInAndThenLooksOnceADay(): void {
		$file = $this->legacy(3);
		$broken = $this->userFolder->newFile('Broken ' . uniqid() . '.fvform', 'not json');
		$job = Server::get(MigrateFormsJob::class);

		$job->start(Server::get(IJobList::class));

		$this->assertTrue($this->stored($file));
		$this->assertSame(3, Server::get(FormStore::class)->byFileId($file->getId())->count);
		$this->assertContains($broken->getId(), Server::get(FormMigration::class)->status()['broken'], 'passed over, and remembered');
		$this->assertNotContains($broken->getId(), Server::get(FormMigration::class)->pending(PHP_INT_MAX));
		$this->assertGreaterThan(0, Server::get(IAppConfig::class)->getValueInt(Application::APP_ID, MigrateFormsJob::DONE), 'nothing left');

		$later = $this->legacy(1);
		$job->start(Server::get(IJobList::class));
		$this->assertFalse($this->stored($later), 'the next look is a day later');
	}

	public function testAnUpdateQueuesTheMoveAndLooksAtEveryFileAgain(): void {
		$config = Server::get(IAppConfig::class);
		$config->setValueInt(Application::APP_ID, MigrateFormsJob::DONE, time());
		$broken = $this->userFolder->newFile('Broken ' . uniqid() . '.fvform', 'not json');
		Server::get(FormMigration::class)->moveOne($broken->getId());
		$this->assertContains($broken->getId(), Server::get(FormMigration::class)->status()['broken']);

		Server::get(QueueFormMigration::class)->run($this->createMock(IOutput::class));

		$this->assertSame(0, $config->getValueInt(Application::APP_ID, MigrateFormsJob::DONE));
		$this->assertSame([], Server::get(FormMigration::class)->status()['broken']);
		$this->assertTrue(Server::get(IJobList::class)->has(MigrateFormsJob::class, null));
	}

	public function testOccShowsMovesOneAndMovesAll(): void {
		$one = $this->legacy(2);
		$other = $this->legacy(4);
		$run = function (array $input): string {
			$output = new BufferedOutput();
			$this->assertSame(0, Server::get(Migrate::class)->run(new ArrayInput($input), $output));
			return $output->fetch();
		};

		$this->assertMatchesRegularExpression('/Forms still in their file: [1-9]/', $run(['--status' => true]));
		$this->assertStringContainsString('moved in, 2 responses', $run(['--file-id' => (string)$one->getId()]));
		$this->assertTrue($this->stored($one));
		$this->assertFalse($this->stored($other));

		$this->assertStringContainsString('File ' . $other->getId() . ': moved in, 4 responses', $run([]));
		$this->assertTrue($this->stored($other));
		$this->assertStringContainsString('in the database already', $run(['--file-id' => (string)$one->getId()]));
	}

	/** occ goes on until every form has had its turn, and names each once: also the one it cannot move in. */
	public function testOccNamesAFormItCannotMoveInOnceAndEnds(): void {
		$unreachable = $this->outOfReach()->getId();
		$movable = $this->legacy(2);
		$output = new BufferedOutput();

		$this->assertSame(0, Server::get(Migrate::class)->run(new ArrayInput([]), $output));

		$said = $output->fetch();
		$this->assertSame(1, substr_count($said, 'File ' . $unreachable . ': skipped'), $said);
		$this->assertSame(1, substr_count($said, 'File ' . $movable->getId() . ': moved in, 2 responses'), $said);
		$this->assertMatchesRegularExpression('/^Moved in: \d+, finished: \d+, passed over: \d+$/m', $said);
	}

	/** A form busy while occ ran is named once and left to the job, which then looks at its next run. */
	public function testOccLeavesABusyFormToTheJobsNextRun(): void {
		$busy = $this->legacy(2);
		$other = $this->legacy(1);
		$config = Server::get(IAppConfig::class);
		$config->setValueInt(Application::APP_ID, MigrateFormsJob::DONE, time());
		$this->failNextReadOf($busy);
		$output = new BufferedOutput();

		$this->assertSame(0, Server::get(Migrate::class)->run(new ArrayInput([]), $output));

		$said = $output->fetch();
		$this->assertStringContainsString('File ' . $busy->getId() . ': skipped: busy, tried again later', $said);
		$this->assertSame(1, substr_count($said, 'File ' . $busy->getId() . ':'), $said);
		$this->assertTrue($this->stored($other));
		$this->assertFalse($this->stored($busy));

		Server::get(MigrateFormsJob::class)->start(Server::get(IJobList::class));
		$this->assertTrue($this->stored($busy), 'the job did not wait a day');
	}
}
