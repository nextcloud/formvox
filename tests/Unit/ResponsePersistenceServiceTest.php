<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormReader;
use OCA\FormVox\Service\ResponseOutbox;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseRefusedException;
use OCA\FormVox\Service\SubmissionIds;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\ResponseStore;
use OCA\FormVox\Store\StoredForm;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * ResponsePersistenceService moves a form still in its file into the database, then has ResponseStore write there.
 * Pinned here is what it decides itself: what a write sets off and when the job is asked for, where a submission is
 * looked up, and which uploads and PDFs a changed or removed response takes along. The form's rules, counts and keys
 * belong to ResponseStore, whose integration tests pin them.
 */
class ResponsePersistenceServiceTest extends TestCase {
	private const FOLDER = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

	private FormFileLocator $fileLocator;
	private UploadService $uploadService;
	private ResponseOutbox $outbox;
	private ResponsePdf $pdfs;
	private FormStore $forms;
	private FormReader $reader;
	private FormConverter $converter;
	private File $file;
	private StoredForm $stored;
	/** The form as its file holds it. */
	private array $form = ['settings' => ['notify_owner' => false]];
	/** Still in its file: not in the database yet. */
	private bool $inFile = false;
	/** @var array<string, array> the stored responses, by id */
	private array $rows = [];
	/** @var array<string, string> the response stored under each submission */
	private array $submissions = [];
	/** @var list<array> what the store was given to send */
	private array $queued = [];
	/** @var list<array{int, ?array, ?string, bool}> each add: the form's row, what it sets off, the submission, the rules */
	private array $added = [];
	private ?\Throwable $refusal = null;
	/** @var list<array{int, list<string>, ?string}> how the other responses naming a folder were asked for */
	private array $groupsAsked = [];
	/** @var list<File> the files the form was read from */
	private array $readFrom = [];
	/** @var list<int> the forms' rows the responses were counted again in */
	private array $recounted = [];

	protected function setUp(): void {
		parent::setUp();
		$this->file = $this->createMock(File::class);
		$this->file->method('getId')->willReturn(7);
		$this->stored = new StoredForm(3, 'uuid', 7, 1);
		$this->fileLocator = $this->createMock(FormFileLocator::class);
		$this->fileLocator->method('getFileById')->willReturn($this->file);
		$this->fileLocator->method('getFileByIdPublic')->willReturn($this->file);
		$this->uploadService = $this->createMock(UploadService::class);
		$this->outbox = $this->createMock(ResponseOutbox::class);
		$this->pdfs = $this->createMock(ResponsePdf::class);
		$this->forms = $this->createMock(FormStore::class);
		$this->forms->method('byFileId')->willReturnCallback(fn (int $fileId) => $this->inFile ? null : $this->stored);
		$this->converter = $this->createMock(FormConverter::class);
		$this->reader = $this->createMock(FormReader::class);
		$this->reader->method('read')->willReturnCallback(function (File $file): array {
			$this->readFrom[] = $file;
			return $this->form;
		});
	}

	private function service(): ResponsePersistenceService {
		return new ResponsePersistenceService($this->fileLocator, $this->uploadService, $this->outbox, $this->pdfs, $this->forms, $this->store(), $this->reader, $this->converter);
	}

	/** Keeps responses by id and what it is given to send; holding them to the form's rules is its own (integration tests). */
	private function store(): ResponseStore {
		$store = $this->createMock(ResponseStore::class);
		$store->method('add')->willReturnCallback(function (StoredForm $stored, array $form, array $response, ?array $outbox, ?string $submissionId, bool $applyRules): array {
			$this->added[] = [$stored->id, $outbox, $submissionId, $applyRules];
			if ($this->refusal !== null) {
				throw $this->refusal;
			}
			if ($submissionId !== null && isset($this->submissions[$submissionId])) {
				return [$this->rows[$this->submissions[$submissionId]], false];
			}
			$this->rows[(string)$response['id']] = $response;
			$this->queue($outbox);
			return [$response, true];
		});
		$store->method('change')->willReturnCallback(function (StoredForm $stored, array $form, string $responseId, callable $change, callable $outbox): array {
			$old = $this->rows[$responseId] ?? throw new NotFoundException('Response not found');
			$new = $change($old);
			$this->rows[$responseId] = $new;
			$this->queue($outbox($new));
			return [$old, $new];
		});
		$store->method('remove')->willReturnCallback(function (StoredForm $stored, array $form, string $responseId, callable $outbox): array {
			$removed = $this->rows[$responseId] ?? throw new NotFoundException('Response not found');
			unset($this->rows[$responseId]);
			$this->queue($outbox($removed));
			return $removed;
		});
		$store->method('removeAll')->willReturnCallback(function (StoredForm $stored, callable $outbox): array {
			$ids = array_map('strval', array_keys($this->rows));
			$this->rows = [];
			foreach ($ids as $id) {
				$this->queue($outbox(['id' => $id]));
			}
			return $ids;
		});
		$store->method('bySubmission')->willReturnCallback(fn (StoredForm $stored, string $submission) => isset($this->submissions[$submission]) ? $this->rows[$this->submissions[$submission]] : null);
		$store->method('namingUploads')->willReturnCallback(function (int $formId, array $groups, ?string $exceptResponse = null): array {
			$this->groupsAsked[] = [$formId, $groups, $exceptResponse];
			$naming = [];
			foreach ($this->rows as $id => $response) {
				if ((string)$id !== $exceptResponse && array_intersect(ResponseStore::uploadGroupsOf($response), $groups) !== []) {
					$naming[] = $response;
				}
			}
			return $naming;
		});
		$store->method('recount')->willReturnCallback(function (StoredForm $stored): void {
			$this->recounted[] = $stored->id;
		});
		return $store;
	}

	private function queue(?array $entry): void {
		if ($entry !== null) {
			$this->queued[] = $entry;
		}
	}

	/** The form's row a form still in its file gets when it moves in. */
	private function movesIn(): StoredForm {
		$this->inFile = true;
		$moved = new StoredForm(4, 'uuid', 7, 1);
		$this->converter->expects($this->once())->method('convert')->with($this->file)->willReturn($moved);
		return $moved;
	}

	/**
	 * What a response sets off is stored with it, in the same write, and one job per form is asked to send it: stored
	 * and sent can never drift apart (7a, 3a-4).
	 */
	public function testAResponseIsStoredTogetherWithWhatItSetsOff(): void {
		$this->form = ['settings' => ['keepResponsePdf' => true, 'webhooks' => [['id' => 'w1']], 'sendConfirmationEmail' => true, 'notify_owner' => false]];
		$this->outbox->expects($this->once())->method('schedule')->with(7);

		$ret = $this->service()->append($this->file, ['id' => 'r1'], [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY, ResponseOutbox::MAIL], 'nl');

		$this->assertSame(['id' => 'r1'], $ret);
		// Nobody is told on this form: only what it has goes in.
		$this->assertSame([[3, [
			'id' => 'r1:response.created', 'response' => 'r1', 'event' => 'response.created',
			'todo' => [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS, ResponseOutbox::MAIL], 'language' => 'nl',
		], null, true]], $this->added);
	}

	/** A form that keeps PDFs gets one with every submission, whatever else it sets off. */
	public function testAFormThatKeepsPdfsQueuesOneWithEverySubmission(): void {
		$this->form = ['settings' => ['keepResponsePdf' => true, 'notify_owner' => false]];
		$this->outbox->expects($this->once())->method('schedule')->with(7);

		$this->service()->append($this->file, ['id' => 'r1'], [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY, ResponseOutbox::MAIL], 'nl');

		$this->assertSame([ResponseOutbox::PDF], $this->added[0][1]['todo']);
	}

	/** A form with no webhook, nobody to tell and no confirmation sets nothing off: no outbox, no job (review 3a-4). */
	public function testAFormThatSetsNothingOffQueuesNothing(): void {
		$this->outbox->expects($this->never())->method('schedule');

		$this->service()->append($this->file, ['id' => 'r1'], [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY, ResponseOutbox::MAIL], 'nl');

		$this->assertSame([], $this->queued);
	}

	/**
	 * A retry of a stored submission, its answer lost or the form busy, is the stored one. Its job is asked for again even
	 * when this try sets nothing off: the first try may have stored and then failed before it asked (review 3a-5).
	 */
	public function testAStoredSubmissionAskedAgainIsTheStoredOne(): void {
		$submission = SubmissionIds::of('sub-0000000000000001', ['q1' => 'a']);
		$this->rows = ['r1' => ['id' => 'r1', 'submitted_at' => 'then']];
		$this->submissions = [$submission => 'r1'];
		$this->outbox->expects($this->once())->method('schedule')->with(7);

		$ret = $this->service()->append($this->file, ['id' => 'r2'], [ResponseOutbox::NOTIFY], 'nl', $submission);

		$this->assertSame(['id' => 'r1', 'submitted_at' => 'then'], $ret);
		$this->assertSame($submission, $this->added[0][2]);
		$this->assertSame(['r1'], array_keys($this->rows));
	}

	/** A refused response asks for no job: nothing of it was stored. */
	public function testARefusedResponseAsksForNoJob(): void {
		$this->form = ['settings' => ['webhooks' => [['id' => 'w1']]]];
		$this->refusal = ResponseRefusedException::closed();
		$this->outbox->expects($this->never())->method('schedule');

		$this->expectException(ResponseRefusedException::class);
		$this->service()->append($this->file, ['id' => 'r1'], [ResponseOutbox::WEBHOOKS]);
	}

	/** Every write goes to the database: a form still in its file moves in first. */
	public function testAFormStillInItsFileMovesInBeforeAResponseIsStored(): void {
		$moved = $this->movesIn();

		$this->service()->append($this->file, ['id' => 'r1']);

		$this->assertSame($moved->id, $this->added[0][0]);
	}

	/** A respondent's language and sign-in go with what their response sets off; their submission goes to the store. */
	public function testAPublicResponseIsStoredWithItsLanguageSubmissionAndSignIn(): void {
		$this->form = ['settings' => ['sendConfirmationEmail' => true, 'notify_owner' => false]];
		$submission = SubmissionIds::of('sub-0000000000000001', ['q1' => 'a']);
		$this->outbox->expects($this->once())->method('schedule')->with(7);

		$this->service()->appendResponsePublic(7, ['id' => 'r1'], [ResponseOutbox::MAIL], 'de', $submission, true);

		$this->assertSame([[3, [
			'id' => 'r1:response.created', 'response' => 'r1', 'event' => 'response.created',
			'todo' => [ResponseOutbox::MAIL], 'language' => 'de', 'signedIn' => true,
		], $submission, true]], $this->added);
	}

	public function testAPublicResponseToAFormStillInItsFileMovesItInFirst(): void {
		$moved = $this->movesIn();

		$this->service()->appendResponsePublic(7, ['id' => 'r1']);

		$this->assertSame($moved->id, $this->added[0][0]);
	}

	/** Moved during this request: the form is read where Nextcloud now has it. */
	public function testAPublicResponseToAFormMovedMeanwhileIsReadWhereItIsNow(): void {
		$moved = $this->createMock(File::class);
		$moved->method('getId')->willReturn(7);
		$this->fileLocator = $this->createMock(FormFileLocator::class);
		$this->fileLocator->method('getFileByIdPublic')->willThrowException(new NotFoundException());
		$this->fileLocator->expects($this->once())->method('findAgain')->with(7, false)->willReturn($moved);

		$this->assertSame(['id' => 'r1'], $this->service()->appendResponsePublic(7, ['id' => 'r1']));
		$this->assertSame([$moved], $this->readFrom);
	}

	/** An import brings in earlier responses: history, not a submission, so no rules apply and nothing is set off (PO). */
	public function testAnImportIsNotHeldToTheRulesAndSetsNothingOff(): void {
		$this->form = ['settings' => ['keepResponsePdf' => true, 'webhooks' => [['id' => 'w1']], 'sendConfirmationEmail' => true]];
		$this->outbox->expects($this->never())->method('schedule');

		$ret = $this->service()->appendImported(7, ['id' => 'old']);

		$this->assertSame(['id' => 'old'], $ret);
		$this->assertSame([[3, null, null, false]], $this->added);
	}

	public function testASubmissionOfAStoredFormIsLookedUpInTheDatabase(): void {
		$submission = SubmissionIds::of('sub-0000000000000001', ['q1' => 'a']);
		$this->rows = ['r1' => ['id' => 'r1']];
		$this->submissions = [$submission => 'r1'];
		$service = $this->service();

		$this->assertSame(['id' => 'r1'], $service->submitted(7, [], $submission));
		$this->assertNull($service->submitted(7, [], SubmissionIds::of('sub-0000000000000002', [])));
		$this->assertNull($service->submitted(7, [], null));
	}

	/** A form still in its file keeps its last submissions there, until it moves in. */
	public function testASubmissionOfAFormInItsFileIsLookedUpInTheFile(): void {
		$this->inFile = true;
		$submission = SubmissionIds::of('sub-0000000000000001', ['q1' => 'a']);
		$form = ['responses' => [['id' => 'r1', 'answers' => ['q1' => 'a']]]];
		$form[SubmissionIds::KEY][$submission] = 'r1';
		// Found in the file, even with the database holding another answer under it.
		$this->rows = ['r9' => ['id' => 'r9']];
		$this->submissions = [$submission => 'r9'];

		$this->assertSame(['id' => 'r1', 'answers' => ['q1' => 'a']], $this->service()->submitted(7, $form, $submission));
	}

	/** A stored form keeps its outbox in rows: the job itself finds out whether a retry's effects still wait. */
	public function testARetryOfAStoredFormAsksForItsJob(): void {
		$this->outbox->expects($this->once())->method('schedule')->with(7);

		$this->service()->retried(7, [], ['id' => 'r1']);
	}

	/**
	 * A retry found stored, of a form still in its file, asks for the job while its first try's effects still wait, and
	 * only then (review of the 3a fixes).
	 */
	public function testARetryOfAFormInItsFileAsksForTheJobOnlyWhileItsEffectsWait(): void {
		$this->inFile = true;
		$this->outbox->expects($this->once())->method('schedule')->with(7);
		$waiting = [ResponseOutbox::KEY => [['id' => 'r1:response.created', 'response' => 'r1', 'event' => ResponseOutbox::CREATED, 'todo' => ['mail']]]];
		$service = $this->service();

		$service->retried(7, $waiting, ['id' => 'r1']);
		$service->retried(7, $waiting, ['id' => 'r2']);
		$service->retried(7, [], ['id' => 'r1']);
	}

	/** A change goes through the outbox too, in order after the response's own created (review 3a-4). */
	public function testAnUpdateIsQueuedWithItsOwnEventId(): void {
		$this->form = ['settings' => ['keepResponsePdf' => true, 'webhooks' => [['id' => 'w1']]]];
		$this->rows = ['r1' => ['id' => 'r1', 'answers' => ['q1' => 'old']]];
		$this->outbox->expects($this->once())->method('schedule')->with(7);

		$updated = $this->service()->update($this->file, 'r1', fn (array $r) => ['answers' => ['q1' => 'new']] + $r);

		$this->assertSame(['answers' => ['q1' => 'new'], 'id' => 'r1'], $updated);
		[$entry] = $this->queued;
		$this->assertSame([ResponseOutbox::UPDATED, 'r1', [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS]], [$entry['event'], $entry['response'], $entry['todo']]);
		$this->assertMatchesRegularExpression('/^r1:response\.updated:[0-9a-f]{8}$/', $entry['id'], 'every update its own event id');
	}

	/** Only a webhook hears of a change: a form without one queues nothing and asks for no job. */
	public function testAnUpdateThatSetsNothingOffQueuesNothing(): void {
		$this->form = ['settings' => ['sendConfirmationEmail' => true]];
		$this->rows = ['r1' => ['id' => 'r1', 'answers' => ['q1' => 'old']]];
		$this->outbox->expects($this->never())->method('schedule');

		$this->service()->update($this->file, 'r1', fn (array $r) => ['answers' => ['q1' => 'new']] + $r);

		$this->assertSame([], $this->queued);
	}

	/** Files a changed response no longer names are removed: a whole upload folder, or one file in a kept one (L32). */
	public function testAChangeRemovesTheFilesTheResponseNoLongerNames(): void {
		$b = '0123456789abcdef0123456789abcdef';
		$this->rows = ['r1' => ['id' => 'r1', 'answers' => [
			'f' => [['responseId' => self::FOLDER, 'filename' => 'keep.pdf'], ['responseId' => self::FOLDER, 'filename' => 'drop.pdf']],
			'g' => ['responseId' => $b, 'filename' => 'gone.pdf'],
		]]];
		$this->uploadService->expects($this->once())->method('deleteResponseUploads')->with($this->file, $b);
		$this->uploadService->expects($this->once())->method('deleteUpload')->with($this->file, self::FOLDER, 'drop.pdf');

		$this->service()->update($this->file, 'r1', fn (array $r) => ['answers' => ['f' => [['responseId' => self::FOLDER, 'filename' => 'keep.pdf']]]] + $r);

		// Its own folders do not count as another's.
		$this->assertSame([[3, [self::FOLDER, $b], 'r1']], $this->groupsAsked);
	}

	/** A folder the changed answer still names as a whole keeps every file in it. */
	public function testAFolderTheChangedResponseStillNamesAsAWholeKeepsItsFiles(): void {
		$this->rows = ['r1' => ['id' => 'r1', 'answers' => ['f' => [['responseId' => self::FOLDER, 'filename' => 'a.pdf'], ['responseId' => self::FOLDER, 'filename' => 'b.pdf']]]]];
		$this->uploadService->expects($this->never())->method('deleteResponseUploads');
		$this->uploadService->expects($this->never())->method('deleteUpload');

		$this->service()->update($this->file, 'r1', fn (array $r) => ['answers' => ['f' => ['responseId' => self::FOLDER]]] + $r);
	}

	/** A file another stored response names stays, whatever the changed one drops: a fill sent again names the same file (H5). */
	public function testAChangeNeverRemovesAFileAnotherResponseNames(): void {
		$this->rows = [
			'r1' => ['id' => 'r1', 'answers' => ['f' => ['responseId' => self::FOLDER, 'filename' => 'cv.pdf']]],
			'r2' => ['id' => 'r2', 'answers' => ['f' => ['responseId' => self::FOLDER, 'filename' => 'cv.pdf']]],
		];
		$this->uploadService->expects($this->never())->method('deleteResponseUploads');
		$this->uploadService->expects($this->never())->method('deleteUpload');

		$this->service()->update($this->file, 'r1', fn (array $r) => ['answers' => []] + $r);
	}

	/** From a folder two responses share, a change takes only the file it dropped and nobody else names (H5). */
	public function testAChangeInASharedFolderRemovesOnlyItsOwnDroppedFile(): void {
		$this->rows = [
			'r1' => ['id' => 'r1', 'answers' => ['f' => [['responseId' => self::FOLDER, 'filename' => 'keep.pdf'], ['responseId' => self::FOLDER, 'filename' => 'drop.pdf'], ['responseId' => self::FOLDER, 'filename' => 'cv.pdf']]]],
			'r2' => ['id' => 'r2', 'answers' => ['f' => ['responseId' => self::FOLDER, 'filename' => 'cv.pdf']]],
		];
		$this->uploadService->expects($this->never())->method('deleteResponseUploads');
		$this->uploadService->expects($this->once())->method('deleteUpload')->with($this->file, self::FOLDER, 'drop.pdf');

		$this->service()->update($this->file, 'r1', fn (array $r) => ['answers' => ['f' => [['responseId' => self::FOLDER, 'filename' => 'keep.pdf']]]] + $r);
	}

	/** Deleted meanwhile: it does not come back, and nothing of it is touched. */
	public function testAnUpdateOfAResponseDeletedMeanwhileTouchesNothing(): void {
		$this->rows = ['r2' => ['id' => 'r2']];
		$this->outbox->expects($this->never())->method('schedule');
		$this->pdfs->expects($this->never())->method('forget');
		$this->uploadService->expects($this->never())->method('deleteResponseUploads');

		try {
			$this->service()->update($this->file, 'r1', fn (array $r) => $r);
			$this->fail('expected NotFound');
		} catch (NotFoundException) {
		}
		$this->assertSame(['r2'], array_keys($this->rows));
	}

	public function testDeleteRemovesTheResponseAndItsUploads(): void {
		$this->rows = ['r1' => ['id' => 'r1'], 'r2' => ['id' => 'r2', 'answers' => ['f' => ['responseId' => self::FOLDER, 'filename' => 'a.pdf']]]];
		$this->form = ['title' => 'T'];
		$this->outbox->expects($this->once())->method('schedule')->with(7);
		$this->uploadService->expects($this->once())->method('deleteResponseUploads')->with($this->file, self::FOLDER);

		[$removed, $form] = $this->service()->delete($this->file, 'r2');

		$this->assertSame(['id' => 'r2', 'answers' => ['f' => ['responseId' => self::FOLDER, 'filename' => 'a.pdf']]], $removed);
		$this->assertSame(['title' => 'T'], $form);
		$this->assertSame([[3, [self::FOLDER], 'r2']], $this->groupsAsked);
	}

	/** A deletion tells the webhooks only which response is gone: no answers, language or PDF (PO: deleted is gone). */
	public function testADeletionSetsOffItsIdOnly(): void {
		$this->form = ['settings' => ['webhooks' => [['id' => 'w1']], 'sendConfirmationEmail' => true]];
		$this->rows = ['r1' => ['id' => 'r1', 'answers' => ['q1' => 'secret']]];

		$this->service()->delete($this->file, 'r1');

		$this->assertSame([['id' => 'r1:response.deleted', 'response' => 'r1', 'event' => ResponseOutbox::DELETED, 'todo' => [ResponseOutbox::WEBHOOKS]]], $this->queued);
	}

	/** A folder another stored response names as a whole stays, whatever the deleted one claims (H5). */
	public function testDeletingAResponseNeverRemovesAFolderAnotherResponseNames(): void {
		$this->rows = [
			'spam' => ['id' => 'spam', 'answers' => ['t' => ['responseId' => self::FOLDER, 'filename' => 'x']]],
			'r2' => ['id' => 'r2', 'answers' => ['t' => ['responseId' => self::FOLDER]]],
		];
		$this->uploadService->expects($this->never())->method('deleteResponseUploads');
		$this->uploadService->expects($this->never())->method('deleteUpload');

		$this->service()->delete($this->file, 'spam');
	}

	/**
	 * Two responses naming one folder, as a fill sent again after a lost answer does: deleting one keeps the other's
	 * files and takes only its own; deleting the last takes the folder (H5).
	 */
	public function testDeletingOneOfTwoResponsesSharingAFolderKeepsTheOthersFiles(): void {
		$this->rows = [
			'r1' => ['id' => 'r1', 'answers' => ['f' => [['responseId' => self::FOLDER, 'filename' => 'cv.pdf'], ['responseId' => self::FOLDER, 'filename' => 'mine.pdf']]]],
			'r2' => ['id' => 'r2', 'answers' => ['f' => ['responseId' => self::FOLDER, 'filename' => 'cv.pdf']]],
		];
		$this->uploadService->expects($this->once())->method('deleteUpload')->with($this->file, self::FOLDER, 'mine.pdf');
		$this->uploadService->expects($this->once())->method('deleteResponseUploads')->with($this->file, self::FOLDER);
		$service = $this->service();

		$service->delete($this->file, 'r1');
		$this->assertSame([[3, [self::FOLDER], 'r1']], $this->groupsAsked);

		$service->delete($this->file, 'r2');
	}

	/**
	 * A response whose file question was deleted since takes its files along, by the clean-up's rule (review of the 3a
	 * fixes: they stayed for good).
	 */
	public function testADeletedResponseTakesItsFilesAlongAfterItsQuestionWentAway(): void {
		$this->form = ['questions' => []];
		$this->rows = ['r1' => ['id' => 'r1', 'answers' => ['gone' => ['responseId' => self::FOLDER, 'filename' => 'cv.pdf']]]];
		$this->uploadService->expects($this->once())->method('deleteResponseUploads')->with($this->file, self::FOLDER);

		$this->service()->delete($this->file, 'r1');
	}

	/**
	 * A respondent controls what an answer says; only a well-formed id names an upload folder (H5).
	 *
	 * @return array<string, array{array}>
	 */
	public static function answersThatNameNoUploadFolder(): array {
		return [
			'empty id' => [['f' => ['responseId' => '', 'filename' => 'x']]],
			'slash' => [['f' => ['responseId' => '/', 'filename' => 'x']]],
			'parent' => [['f' => ['responseId' => '..', 'filename' => 'x']]],
			'another form path' => [['f' => [['responseId' => '../../other', 'filename' => 'x']]]],
		];
	}

	/**
	 * @dataProvider answersThatNameNoUploadFolder
	 */
	public function testDeletingAResponseNeverRemovesAnUploadFolderItDoesNotOwn(array $answers): void {
		$this->rows = ['spam' => ['id' => 'spam', 'answers' => $answers]];
		$this->uploadService->expects($this->never())->method('deleteResponseUploads');
		$this->uploadService->expects($this->never())->method('deleteUpload');

		$this->service()->delete($this->file, 'spam');
	}

	public function testDeleteOfAnUnknownResponseThrowsAndAsksForNoJob(): void {
		$this->rows = ['r1' => ['id' => 'r1']];
		$this->outbox->expects($this->never())->method('schedule');
		$this->pdfs->expects($this->never())->method('forget');

		$this->expectException(NotFoundException::class);
		$this->service()->delete($this->file, 'does-not-exist');
	}

	/** Deleting all leaves only the ids, for the webhooks, and takes every upload along. */
	public function testDeleteAllReturnsTheIdsAndCleansAllUploads(): void {
		$this->form = ['settings' => ['webhooks' => [['id' => 'w1']]]];
		$this->rows = ['r1' => ['id' => 'r1', 'answers' => ['q1' => 'secret']], 'r2' => ['id' => 'r2']];
		$this->outbox->expects($this->once())->method('schedule')->with(7);
		$this->uploadService->expects($this->once())->method('deleteAllUploads')->with($this->file);

		[$removed, $form] = $this->service()->deleteAll($this->file);

		$this->assertSame([['id' => 'r1'], ['id' => 'r2']], $removed);
		$this->assertSame($this->form, $form);
		$this->assertSame(['r1:response.deleted', 'r2:response.deleted'], array_column($this->queued, 'id'));
		$this->assertStringNotContainsString('secret', (string)json_encode($this->queued));
	}

	/**
	 * Until its files are moved to app data they are beside the owner's form: someone it is shared with deletes them
	 * there too, not in their own folder, where they are not.
	 */
	public function testFilesNotMovedYetAreDeletedWhereTheyAre(): void {
		$this->stored = new StoredForm(3, 'uuid', 7, 1, filesMoved: false);
		$owners = $this->createMock(File::class);
		$this->fileLocator->method('getFileByIdPublicForWrite')->with(7, true)->willReturn($owners);
		$this->rows = ['r1' => ['id' => 'r1', 'answers' => ['f' => ['responseId' => '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', 'filename' => 'a.pdf']]], 'r2' => ['id' => 'r2']];
		$this->uploadService->expects($this->once())->method('deleteResponseUploads')->with($this->identicalTo($owners), '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f');
		$this->pdfs->expects($this->once())->method('forgetAll')->with($this->identicalTo($owners));
		$this->uploadService->expects($this->once())->method('deleteAllUploads')->with($this->identicalTo($owners));
		$service = $this->service();

		$service->delete($this->file, 'r1');
		$service->deleteAll($this->file);
	}

	/** In app data they are found by the form, whoever deletes: no other account is needed. */
	public function testFilesInAppDataNeedNoOtherAccount(): void {
		$this->fileLocator->expects($this->never())->method('getFileByIdPublicForWrite');
		$this->rows = ['r1' => ['id' => 'r1']];
		$this->uploadService->expects($this->once())->method('deleteAllUploads')->with($this->identicalTo($this->file));

		$this->service()->deleteAll($this->file);
	}

	/** Counted again where the responses are: a form still in its file moves in first. */
	public function testRebuildIndexCountsAgainInTheDatabase(): void {
		$moved = $this->movesIn();

		$this->service()->rebuildIndex(7);

		$this->assertSame([$moved->id], $this->recounted);
	}

	/** A changed or deleted response's PDF is no longer its document. */
	public function testAChangedOrDeletedResponseLosesItsPdf(): void {
		$this->rows = ['r1' => ['id' => 'r1', 'answers' => []], 'r2' => ['id' => 'r2', 'answers' => []], 'r3' => ['id' => 'r3']];
		$forgotten = [];
		$this->pdfs->method('forget')->willReturnCallback(function (File $file, string $id) use (&$forgotten): void {
			$forgotten[] = $id;
		});
		$this->pdfs->expects($this->once())->method('forgetAll')->with($this->file);
		$service = $this->service();

		$service->update($this->file, 'r1', fn (array $response) => ['answers' => ['q' => 'new']] + $response);
		$service->delete($this->file, 'r2');
		$service->deleteAll($this->file);

		$this->assertSame(['r1', 'r2'], $forgotten);
	}
}
