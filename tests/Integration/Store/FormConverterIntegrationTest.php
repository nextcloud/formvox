<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\Security\FormSecrets;
use OCA\FormVox\Service\FormBrokenException;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormFolders;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\OutboxStore;
use OCA\FormVox\Store\StoredForm;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Against a real Nextcloud: a form file from before 1.6 moves into the database with everything it holds and keeps
 * beside it, once, also when it changes between the copy ahead and the switch.
 *
 * @group DB
 */
class FormConverterIntegrationTest extends IntegrationTestCase {
	private const LONG = 'r-long-0000000000-1111111111-2222222222-3333333333-4444444444-5555555555-6666666666-77777777';
	private const GROUP = '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e';
	private const REVISION = '0123456789abcdef';

	/** A form as 1.5 and the 3b builds wrote it, with its folders beside it. */
	private function legacy(array $overrides = []): File {
		$file = $this->writeFormFile($this->userFolder, 'Legacy ' . uniqid(), $overrides + [
			'questions' => [
				['id' => 'name', 'type' => 'text', 'question' => 'Name'],
				['id' => 'pick', 'type' => 'choice', 'question' => 'Pick', 'options' => [['id' => 'a', 'value' => 'a', 'label' => 'A'], ['id' => 'b', 'value' => 'b', 'label' => 'B']]],
				['id' => 'cv', 'type' => 'file', 'question' => 'CV'],
			],
			'settings' => [
				'anonymous' => false, 'allow_multiple' => true,
				'public_token' => 'tok-legacy-123',
				'webhooks' => [['id' => 'wh_1', 'url' => 'http://127.0.0.1:9/hook', 'secret' => 'whsec-legacy', 'enabled' => true, 'created_by' => $this->ownerUid]],
			],
			'responses' => [
				['id' => 'r1', 'submitted_at' => '2026-10-01T10:00:00+00:00', 'respondent' => ['type' => 'user', 'user_id' => 'alice', 'display_name' => 'Alice'], 'answers' => ['name' => 'Alice', 'pick' => 'a']],
				['id' => 'r2', 'submitted_at' => '2026-10-02T10:00:00+00:00', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'fp-2'], 'answers' => ['name' => 'Bo', 'pick' => 'b',
					'cv' => [['responseId' => self::GROUP, 'filename' => 'cv.pdf', 'originalName' => 'cv.pdf']]]],
				['id' => self::LONG, 'submitted_at' => '2026-10-03T10:00:00+00:00', 'source' => 'api', 'answers' => ['name' => 'Long 😀', 'pick' => 'a']],
			],
			'_submissions' => ['fill-0123456789abcdef|0011223344556677' => 'r1'],
			'_outbox' => [
				['id' => 'r1:response.created', 'response' => 'r1', 'event' => 'response.created', 'todo' => ['webhook:wh_1'], 'attempts' => 2, 'notBefore' => 2000000000, 'language' => 'nl'],
				['id' => 'r2:response.created', 'response' => 'r2', 'event' => 'response.created', 'todo' => ['notify'], 'claimedAt' => 1800000000],
			],
		]);
		$beside = $file->getParent();
		$beside->newFolder('.formvox-uploads-' . $file->getId())->newFolder(self::GROUP)->newFile('cv.pdf', 'the cv');
		$beside->newFolder('.formvox-pdfs-' . $file->getId())->newFolder('r1')->newFile(self::REVISION . '.pdf', "%PDF-1.7 kept\n%%EOF");
		$beside->newFolder('.formvox-branding-' . $file->getId())->newFile('block_logo.png', 'logo');
		$beside->newFolder('.formvox-templates-' . $file->getId())->newFile('template.odt', 'odt');
		return $file;
	}

	private function raw(File $file): array {
		return json_decode((string)$this->userFolder->getFirstNodeById($file->getId())->getContent(), true);
	}

	/** @return list<string> */
	private function ids(int $fileId): array {
		return array_column(Server::get(FormRepository::class)->load($fileId)['responses'] ?? [], 'id');
	}

	private function inAppData(int $formId, string $kind, string $path): string {
		$node = Server::get(FormFolders::class)->kind($formId, $kind)->get($path);
		return $node instanceof File ? $node->getContent() : '';
	}

	public function testEverythingAFormFileHoldsMovesInOnce(): void {
		$file = $this->legacy();
		$uuid = $this->raw($file)['id'];

		$form = Server::get(FormConverter::class)->convert($file, true);

		$this->assertSame($uuid, $form->uuid);
		$this->assertSame(['r1', 'r2', self::LONG], $this->ids($file->getId()));
		$loaded = Server::get(FormRepository::class)->loadForm($file->getId());
		$this->assertSame(3, $loaded['_index']['response_count']);
		// The database gives the counts in no set order.
		$counts = $loaded['_index']['answer_counts']['pick'];
		ksort($counts);
		$this->assertSame(['a' => 2, 'b' => 1], $counts);
		$this->assertSame('r1', Server::get(ResponsePersistenceService::class)->submitted($file->getId(), $loaded, 'fill-0123456789abcdef|0011223344556677')['id'] ?? null, 'a retry of r1 is recognised');
		$this->assertSame('Long 😀', Server::get(FormRepository::class)->response($file->getId(), $loaded, self::LONG)['answers']['name'] ?? null);

		$outbox = Server::get(OutboxStore::class)->entries($form->id);
		$this->assertSame(['r1:response.created', 'r2:response.created'], array_column($outbox, 'id'));
		$this->assertSame([['webhook:wh_1'], 2, 2000000000, 'nl'], [$outbox[0]['todo'], $outbox[0]['attempts'], $outbox[0]['notBefore'], $outbox[0]['language']]);
		$this->assertSame(1800000000, $outbox[1]['claimedAt']);

		$this->assertSame('tok-legacy-123', $loaded['settings']['public_token'] ?? null);
		$this->assertSame('whsec-legacy', Server::get(FormSecrets::class)->open((string)($loaded['settings']['webhooks'][0]['secret'] ?? '')));

		$this->assertSame('the cv', $this->inAppData($form->id, FormFolders::UPLOADS, self::GROUP . '/cv.pdf'));
		$this->assertSame("%PDF-1.7 kept\n%%EOF", $this->inAppData($form->id, FormFolders::PDFS, 'r1/' . self::REVISION . '.pdf'), 'the same PDF, the same hash');
		$this->assertSame('logo', $this->inAppData($form->id, FormFolders::BRANDING, 'block_logo.png'));
		$this->assertSame('odt', $this->inAppData($form->id, FormFolders::TEMPLATES, 'template.odt'));

		$raw = $this->raw($file);
		$this->assertSame([], $raw['responses']);
		$this->assertArrayNotHasKey('_outbox', $raw);
		$this->assertArrayNotHasKey('_submissions', $raw);
		$this->assertArrayNotHasKey('public_token', $raw['settings']);
		$this->assertArrayNotHasKey('webhooks', $raw['settings']);
		foreach (['uploads', 'pdfs', 'branding', 'templates'] as $kind) {
			$this->assertFalse($file->getParent()->nodeExists('.formvox-' . $kind . '-' . $file->getId()), $kind . ' moved');
		}
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('migrated_at')->from(FormStore::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($form->id, IQueryBuilder::PARAM_INT)));
		$this->assertNotNull($qb->executeQuery()->fetchOne());
	}

	public function testRunningItAgainDoublesNothing(): void {
		$file = $this->legacy();
		$converter = Server::get(FormConverter::class);
		$first = $converter->prepare($file);
		$converter->prepare($file);

		$form = $converter->convert($file);
		$this->assertSame($first->id, $form->id);
		$this->assertSame($form->id, $converter->convert($file)->id);

		$this->assertSame(['r1', 'r2', self::LONG], $this->ids($file->getId()));
		$this->assertCount(2, Server::get(OutboxStore::class)->entries($form->id));
		$this->assertSame(3, Server::get(FormStore::class)->byId($form->id)->count);
	}

	/** Until the switch the form is its file's: a reader reads the file, and no rule of the database applies. */
	public function testACopyAheadIsNobodysYet(): void {
		$file = $this->legacy();

		$draft = Server::get(FormConverter::class)->prepare($file);

		$this->assertTrue($draft->draft);
		$this->assertNull(Server::get(FormStore::class)->byFileId($file->getId()));
		$this->assertSame(['r1', 'r2', self::LONG], array_column($this->raw($file)['responses'], 'id'), 'the file still holds them');
		$this->assertTrue($file->getParent()->nodeExists('.formvox-uploads-' . $file->getId()), 'nothing beside it removed');
	}

	/** Responses added, changed or removed, and files added, between the copy ahead and the switch all count. */
	public function testWhatChangedAfterTheCopyAheadIsTakenAlong(): void {
		$file = $this->legacy();
		$converter = Server::get(FormConverter::class);
		$converter->prepare($file);
		$document = $this->raw($file);
		$document['responses'] = [
			$document['responses'][0],
			['id' => self::LONG, 'submitted_at' => '2026-10-03T10:00:00+00:00', 'source' => 'api', 'answers' => ['name' => 'Changed', 'pick' => 'b']],
			['id' => 'r4', 'submitted_at' => '2026-10-04T10:00:00+00:00', 'respondent' => ['type' => 'user', 'user_id' => 'dan', 'display_name' => 'Dan'], 'answers' => ['name' => 'Dan', 'pick' => 'b']],
		];
		$document['_outbox'][] = ['id' => 'r4:response.created', 'response' => 'r4', 'event' => 'response.created', 'todo' => ['notify']];
		$file->getStorage()->file_put_contents($file->getInternalPath(), (string)json_encode($document));
		$file->getParent()->get('.formvox-uploads-' . $file->getId())->get(self::GROUP)->newFile('late.pdf', 'late');

		$form = $converter->convert($file, true);

		$this->assertSame(['r1', self::LONG, 'r4'], $this->ids($file->getId()));
		$loaded = Server::get(FormRepository::class)->loadForm($file->getId());
		// The database gives the counts in no set order.
		$counts = $loaded['_index']['answer_counts']['pick'];
		ksort($counts);
		$this->assertSame(['a' => 1, 'b' => 2], $counts);
		$this->assertSame('Changed', Server::get(FormRepository::class)->response($file->getId(), $loaded, self::LONG)['answers']['name'] ?? null);
		$this->assertSame(['r1:response.created', 'r2:response.created', 'r4:response.created'], array_column(Server::get(OutboxStore::class)->entries($form->id), 'id'));
		$this->assertSame('late', $this->inAppData($form->id, FormFolders::UPLOADS, self::GROUP . '/late.pdf'));
	}

	/** A slower run's older read of the file never writes over a form another run switched meanwhile. */
	public function testACopyAheadNeverWritesOverAFormThatSwitchedMeanwhile(): void {
		$file = $this->legacy();
		$converter = Server::get(FormConverter::class);
		$draft = $converter->prepare($file);
		$older = $this->raw($file);
		$converter->convert($file);
		Server::get(ResponsePersistenceService::class)->append($this->userFolder->getFirstNodeById($file->getId()),
			['id' => 'r5', 'submitted_at' => '2026-10-06T10:00:00+00:00', 'respondent' => ['type' => 'user', 'user_id' => 'eve', 'display_name' => 'Eve'], 'answers' => ['name' => 'Eve', 'pick' => 'a']]);

		$this->assertFalse(Server::get(\OCA\FormVox\Store\ResponseStore::class)->mirror($draft->id, $older));
		$this->assertSame(['r1', 'r2', self::LONG, 'r5'], $this->ids($file->getId()));
		$this->assertSame(4, Server::get(FormStore::class)->byId($draft->id)->count);
	}

	/** A writer that held the form while the switch waited for it: what it wrote counts. */
	public function testAResponseWrittenWhileTheSwitchWaitsIsTakenAlong(): void {
		$file = $this->legacy();
		$writer = function () use ($file): void {
			$document = $this->raw($file);
			$document['responses'][] = ['id' => 'r4', 'submitted_at' => '2026-10-04T10:00:00+00:00', 'answers' => ['name' => 'Dan', 'pick' => 'b']];
			$file->getStorage()->file_put_contents($file->getInternalPath(), (string)json_encode($document));
		};

		$this->converterWith($this->hookedLock(['acquire:form' => $writer]))->convert($file);

		$this->assertSame(['r1', 'r2', self::LONG, 'r4'], $this->ids($file->getId()));
	}

	/**
	 * The form's lock with something happening once at a moment: 'acquire:<purpose>' just before that lock is taken,
	 * 'release:<purpose>' just after it is let go.
	 *
	 * @param array<string, \Closure> $hooks
	 */
	private function hookedLock(array $hooks): FormLock {
		return new class(Server::get(ICacheFactory::class), Server::get(IDBConnection::class), Server::get(LoggerInterface::class), $hooks) extends FormLock {
			public function __construct(
				ICacheFactory $cache,
				IDBConnection $db,
				LoggerInterface $logger,
				private array $hooks,
			) {
				parent::__construct($cache, $db, $logger);
			}

			public function acquire(int $fileId, float $deadline, string $purpose = 'form'): string {
				$this->at('acquire:' . $purpose);
				return parent::acquire($fileId, $deadline, $purpose);
			}

			public function release(int $fileId, string $token, string $purpose = 'form'): void {
				parent::release($fileId, $token, $purpose);
				$this->at('release:' . $purpose);
			}

			private function at(string $moment): void {
				$hook = $this->hooks[$moment] ?? null;
				unset($this->hooks[$moment]);
				if ($hook !== null) {
					$hook();
				}
			}
		};
	}

	private function converterWith(FormLock $lock): FormConverter {
		return new FormConverter(Server::get(FormStore::class), Server::get(\OCA\FormVox\Store\ResponseStore::class), Server::get(\OCA\FormVox\Store\SecretStore::class),
			Server::get(FormSecrets::class), Server::get(FormFolders::class), $lock, Server::get(FormLockManager::class), Server::get(FormFileLocator::class), Server::get(LoggerInterface::class));
	}

	/** @return list<string> what the ZIP of the form's uploads holds */
	private function zipped(File $file): array {
		$tmp = tempnam(sys_get_temp_dir(), 'fvit_');
		file_put_contents($tmp, Server::get(UploadService::class)->createUploadsZip($file));
		$zip = new \ZipArchive();
		$zip->open($tmp);
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}
		$zip->close();
		unlink($tmp);
		sort($names);
		return $names;
	}

	/** An upload that lands beside the form after the bulk copy, before the last step, is brought over by that step. */
	public function testAnUploadThatLandsBesideDuringTheBulkCopyIsMoved(): void {
		$file = $this->legacy();
		$form = Server::get(FormConverter::class)->convert($file);
		$uploads = Server::get(UploadService::class);
		$lock = $this->hookedLock(['acquire:form' => function () use ($file, $uploads): void {
			$uploads->storeUploadPublic($file->getId(), self::GROUP, $this->makeUploadedFile('late.pdf', 'late upload', 'application/pdf'));
			$this->assertSame('late upload', $uploads->getUpload($file, self::GROUP, 'late.pdf')->getContent(), 'served from beside the form meanwhile');
			$this->assertSame([self::GROUP . '/cv.pdf', self::GROUP . '/late.pdf'], $this->zipped($file));
		}]);

		$this->converterWith($lock)->moveFiles($file, $form);

		$this->assertTrue(Server::get(FormStore::class)->byId($form->id)->filesMoved);
		$this->assertSame('late upload', $this->inAppData($form->id, FormFolders::UPLOADS, self::GROUP . '/late.pdf'));
		$this->assertSame('the cv', $this->inAppData($form->id, FormFolders::UPLOADS, self::GROUP . '/cv.pdf'));
		$this->assertFalse($file->getParent()->nodeExists('.formvox-uploads-' . $file->getId()));
		$this->assertSame('late upload', $uploads->getUpload($file, self::GROUP, 'late.pdf')->getContent(), 'now from app data');
		$this->assertSame([self::GROUP . '/cv.pdf', self::GROUP . '/late.pdf'], $this->zipped($file), 'each file once, from one place');
	}

	/** A response's files removed beside the form after the copy ahead, and a PDF during the bulk copy, do not come back. */
	public function testADeletionBesideBeforeTheStampDoesNotComeBack(): void {
		$file = $this->legacy();
		$converter = Server::get(FormConverter::class);
		$converter->prepare($file, true);
		$form = $converter->convert($file);
		$this->assertSame('the cv', $this->inAppData($form->id, FormFolders::UPLOADS, self::GROUP . '/cv.pdf'), 'copied ahead');
		Server::get(UploadService::class)->deleteResponseUploads($file, self::GROUP);
		$lock = $this->hookedLock(['acquire:form' => fn () => $file->getParent()->get('.formvox-pdfs-' . $file->getId() . '/r1')->delete()]);

		$this->converterWith($lock)->moveFiles($file, $form);

		$this->assertTrue(Server::get(FormStore::class)->byId($form->id)->filesMoved);
		$this->assertFalse(Server::get(FormFolders::class)->kind($form->id, FormFolders::UPLOADS)->nodeExists(self::GROUP), 'the group is gone from app data');
		$this->assertFalse(Server::get(FormFolders::class)->kind($form->id, FormFolders::PDFS)->nodeExists('r1'), 'the PDF too');
		$this->assertSame([], Server::get(UploadService::class)->getUploadsFolder($file)->getDirectoryListing());
		$this->assertSame('logo', $this->inAppData($form->id, FormFolders::BRANDING, 'block_logo.png'), 'what stayed beside the form is there');
	}

	/** A crash right after the stamp: the next run brings what is still beside the form over, late arrivals included. */
	public function testACrashAfterTheStampIsFinishedBySettle(): void {
		$file = $this->legacy();
		$form = Server::get(FormConverter::class)->convert($file);
		$uploads = Server::get(UploadService::class);
		$forms = Server::get(FormStore::class);
		$lock = $this->hookedLock(['release:form' => function (): void {
			throw new \RuntimeException('crashed after the stamp');
		}]);
		try {
			$this->converterWith($lock)->moveFiles($file, $form);
			$this->fail('The crash did not happen');
		} catch (\RuntimeException $e) {
			$this->assertSame('crashed after the stamp', $e->getMessage());
		}
		$stamped = $forms->byId($form->id);
		$this->assertTrue($stamped->filesMoved);
		$this->assertTrue($file->getParent()->nodeExists('.formvox-uploads-' . $file->getId()), 'still beside the form');
		$this->assertSame('the cv', $uploads->getUpload($file, self::GROUP, 'cv.pdf')->getContent(), 'a reader is served from app data already');
		$this->assertSame([self::GROUP . '/cv.pdf'], $this->zipped($file), 'each file once, from one place');
		$this->assertContains($form->id, array_map(fn (StoredForm $form) => $form->id, $forms->unsettled(1000)), 'still to be finished');
		// A writer that chose its folder before the stamp lands beside the form after it.
		$file->getParent()->get('.formvox-uploads-' . $file->getId())->get(self::GROUP)->newFile('stale.pdf', 'from a late writer');

		Server::get(FormConverter::class)->settle($file, $stamped);

		$this->assertSame('from a late writer', $this->inAppData($form->id, FormFolders::UPLOADS, self::GROUP . '/stale.pdf'));
		$this->assertSame('the cv', $this->inAppData($form->id, FormFolders::UPLOADS, self::GROUP . '/cv.pdf'));
		$this->assertSame("%PDF-1.7 kept\n%%EOF", $this->inAppData($form->id, FormFolders::PDFS, 'r1/' . self::REVISION . '.pdf'));
		foreach (['uploads', 'pdfs', 'branding', 'templates'] as $kind) {
			$this->assertFalse($file->getParent()->nodeExists('.formvox-' . $kind . '-' . $file->getId()), $kind . ' cleared');
		}
		$this->assertNotContains($form->id, array_map(fn (StoredForm $form) => $form->id, $forms->unsettled(1000)), 'done');
	}

	/** v1 bytes put back into a stored form's file, from a backup without the database: the next save writes the form only. */
	public function testV1ContentPutBackIntoAStoredFormsFileIsCleanedByTheNextSave(): void {
		$file = $this->legacy();
		$v1 = $file->getStorage()->file_get_contents($file->getInternalPath());
		$form = Server::get(FormConverter::class)->convert($file);
		$file->getStorage()->file_put_contents($file->getInternalPath(), $v1);
		$this->assertSame(['r1', 'r2', self::LONG], array_column($this->raw($file)['responses'], 'id'), 'the file holds them again');
		$this->assertSame([], Server::get(FormRepository::class)->loadForm($file->getId())['responses'], 'read as the form only, before any save');

		Server::get(FormRepository::class)->update($file->getId(), ['description' => 'Saved after the restore']);

		$raw = $this->raw($file);
		$this->assertSame('Saved after the restore', $raw['description']);
		$this->assertSame([], $raw['responses']);
		$this->assertSame(0, $raw['_index']['response_count']);
		$this->assertArrayNotHasKey('_outbox', $raw);
		$this->assertArrayNotHasKey('_submissions', $raw);
		$this->assertArrayNotHasKey('public_token', $raw['settings']);
		$this->assertArrayNotHasKey('webhooks', $raw['settings']);
		$this->assertSame($form->uuid, $raw['id']);
		$this->assertSame([], Server::get(FormRepository::class)->loadForm($file->getId())['responses'], 'the editor gets none from the file');
		$this->assertSame(['r1', 'r2', self::LONG], $this->ids($file->getId()), 'the database\'s');
	}

	/** An account at its quota: tidying shrinks the file, so it goes through, and the file holds the form only. */
	public function testAFormIsTidiedWhenItsOwnersQuotaIsFull(): void {
		$file = $this->legacy();
		$this->owner->setQuota($this->userFolder->getSize() . ' B');
		// The quota applies from the next filesystem setup on, as in a new request.
		$this->loginAs($this->ownerUid, $this->owner);
		$this->userFolder = $this->rootFolder->getUserFolder($this->ownerUid);
		try {
			$form = Server::get(FormConverter::class)->convert($this->userFolder->getFirstNodeById($file->getId()));

			$this->assertTrue(Server::get(FormStore::class)->byId($form->id)->tidied);
			$raw = $this->raw($file);
			$this->assertSame([], $raw['responses']);
			$this->assertArrayNotHasKey('public_token', $raw['settings']);
		} finally {
			$this->owner->setQuota('none');
		}
	}

	/** Copies from before 1.6 carry their original's uuid: each becomes a form of its own with its own responses. */
	public function testTwinsWithOneUuidBecomeTwoForms(): void {
		$original = $this->legacy();
		$uuid = $this->raw($original)['id'];
		$twin = $this->writeFormFile($this->userFolder, 'Twin ' . uniqid(), ['id' => $uuid, 'questions' => [['id' => 'name', 'type' => 'text', 'question' => 'Name']],
			'responses' => [['id' => 't1', 'submitted_at' => '2026-10-05T10:00:00+00:00', 'answers' => ['name' => 'Twin']]]]);
		$converter = Server::get(FormConverter::class);

		$first = $converter->convert($original);
		$second = $converter->convert($twin);

		$this->assertSame($uuid, $first->uuid);
		$this->assertNotSame($uuid, $second->uuid);
		$this->assertSame($second->uuid, $this->raw($twin)['id']);
		$this->assertSame(['r1', 'r2', self::LONG], $this->ids($original->getId()));
		$this->assertSame(['t1'], $this->ids($twin->getId()));
	}

	/** A respondent who sets the move off waits for the database only: the files follow in the background. */
	public function testAWriteMovesTheResponsesAndTheFilesFollowLater(): void {
		$file = $this->legacy();
		$converter = Server::get(FormConverter::class);
		$uploads = Server::get(\OCA\FormVox\Service\UploadService::class);

		$form = $converter->convert($file);

		$this->assertSame(['r1', 'r2', self::LONG], $this->ids($file->getId()));
		$this->assertFalse(Server::get(FormStore::class)->byId($form->id)->filesMoved);
		$this->assertTrue($file->getParent()->nodeExists('.formvox-uploads-' . $file->getId()), 'not moved in the request');
		$this->assertSame('the cv', $uploads->getUpload($file, self::GROUP, 'cv.pdf')->getContent(), 'still served from beside the form');
		$this->assertSame([], $this->raw($file)['responses'], 'the file is tidied all the same');

		$converter->moveFiles($file, $form);

		$this->assertTrue(Server::get(FormStore::class)->byId($form->id)->filesMoved);
		$this->assertSame('the cv', $uploads->getUpload($file, self::GROUP, 'cv.pdf')->getContent(), 'now from app data');
		$this->assertSame('the cv', $this->inAppData($form->id, FormFolders::UPLOADS, self::GROUP . '/cv.pdf'));
		$this->assertFalse($file->getParent()->nodeExists('.formvox-uploads-' . $file->getId()));
	}

	/** A crash between the switch and tidying the file: what is left is done on the next run. */
	public function testWhatACrashLeftIsSettledLater(): void {
		$file = $this->legacy();
		$converter = Server::get(FormConverter::class);
		$forms = Server::get(FormStore::class);
		$draft = $converter->prepare($file);
		Server::get(\OCA\FormVox\Store\ResponseStore::class)->mirror($draft->id, $this->raw($file), fn () => $forms->activate($draft->id));
		$crashed = $forms->byFileId($file->getId());
		$this->assertFalse($crashed->tidied);
		$this->assertContains($crashed->id, array_map(fn ($form) => $form->id, $forms->unsettled(1000)));

		$converter->settle($file, $crashed);

		$this->assertTrue($forms->byId($crashed->id)->tidied);
		$this->assertTrue($forms->byId($crashed->id)->filesMoved);
		$this->assertArrayNotHasKey('public_token', $this->raw($file)['settings']);
		$this->assertSame([], $this->raw($file)['responses']);
	}

	/** A second draft of a file another run switched holds personal data nobody reads: the daily sweep removes it. */
	public function testASecondDraftOfAMovedFormIsRemoved(): void {
		$file = $this->legacy();
		$form = Server::get(FormConverter::class)->convert($file);
		$second = Server::get(FormStore::class)->draft(\OCA\FormVox\Service\FormFactory::uuid(), $file->getId());

		Server::get(\OCA\FormVox\Store\Orphans::class)->sweep();

		$this->assertNull(Server::get(FormStore::class)->byId($second->id));
		$this->assertSame($form->id, Server::get(FormStore::class)->byFileId($file->getId())->id);
	}

	/** Tidying the file is no change to the form: it does not look changed, and a save right after keeps its version. */
	public function testTidyingLeavesTheFormsTimesAsTheyWere(): void {
		$file = $this->legacy(['modified_at' => '2026-01-01T00:00:00+00:00']);
		$this->userFolder->getFirstNodeById($file->getId())->touch(time() - 3600);
		$mtime = $this->userFolder->getFirstNodeById($file->getId())->getMTime();

		Server::get(FormConverter::class)->convert($file);

		$this->assertSame([], $this->raw($file)['responses'], 'tidied');
		$this->assertSame('2026-01-01T00:00:00+00:00', $this->raw($file)['modified_at']);
		$this->assertSame($mtime, $this->userFolder->getFirstNodeById($file->getId())->getMTime());
	}

	/** An editor's save moves the form in first: the file it writes holds the form only. */
	public function testAnEditorSaveMovesTheFormInFirst(): void {
		$file = $this->legacy();

		Server::get(FormRepository::class)->update($file->getId(), ['description' => 'Changed']);

		$this->assertNotNull(Server::get(FormStore::class)->byFileId($file->getId()));
		$this->assertSame(['r1', 'r2', self::LONG], $this->ids($file->getId()));
		$raw = $this->raw($file);
		$this->assertSame('Changed', $raw['description']);
		$this->assertSame([], $raw['responses']);
		$this->assertArrayNotHasKey('public_token', $raw['settings']);
	}

	/** A changed setting with a secret in it lands in the database, never in the file. */
	public function testASettingsChangeKeepsItsSecretOutOfTheFile(): void {
		$file = $this->legacy();

		Server::get(FormRepository::class)->mutateSettings($file->getId(), fn (array $settings) => ['public_token' => 'tok-changed'] + $settings);

		$this->assertArrayNotHasKey('public_token', $this->raw($file)['settings']);
		$this->assertSame('tok-changed', Server::get(FormRepository::class)->loadForm($file->getId())['settings']['public_token'] ?? null);
		$this->assertSame(['r1', 'r2', self::LONG], $this->ids($file->getId()));
	}

	/** A form put over one still in its file: its responses move in first, the upload changes the form only. */
	public function testAnUploadOverAFormMovesItInFirst(): void {
		$file = $this->legacy();
		$upload = $this->raw($file);
		$upload['description'] = 'Uploaded';
		$upload['responses'] = [];

		$this->assertTrue(Server::get(\OCA\FormVox\Service\FormIntake::class)->overwrite($file, (string)json_encode($upload)));

		$this->assertSame(['r1', 'r2', self::LONG], $this->ids($file->getId()));
		$raw = $this->raw($file);
		$this->assertSame('Uploaded', $raw['description']);
		$this->assertSame([], $raw['responses']);
	}

	public function testAFileThatHoldsNoFormIsRefused(): void {
		$file = $this->userFolder->newFile('Broken ' . uniqid() . '.fvform', 'not json');

		$this->expectException(FormBrokenException::class);
		Server::get(FormConverter::class)->convert($file);
	}

	public function testABesideFolderIsNotTakenForAFolderOfItsOwn(): void {
		$file = $this->legacy();
		$stray = $file->getParent()->newFolder('.formvox-uploads-999999999');
		$stray->newFile('other.pdf', 'not this form\'s');

		Server::get(FormConverter::class)->convert($file, true);

		$this->assertTrue($stray->nodeExists('other.pdf'), 'another form\'s folder stays where it is');
		$this->assertInstanceOf(Folder::class, $file->getParent()->get('.formvox-uploads-999999999'));
	}
}
