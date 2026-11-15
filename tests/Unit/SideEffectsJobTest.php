<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\BackgroundJob\SideEffectsJob;
use OCA\FormVox\Service\ConfirmationMail;
use OCA\FormVox\Service\FormBrokenException;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormReader;
use OCA\FormVox\Service\ResponseNotificationService;
use OCA\FormVox\Service\ResponseOutbox;
use OCA\FormVox\Service\WebhookService;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\OutboxStore;
use OCA\FormVox\Store\ResponseStore;
use OCA\FormVox\Store\StoredForm;
use OCA\FormVox\Tests\Unit\Support\Fake\Redis;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SideEffectsJob sends what events on a form's responses set off, in order per response, all that is due in one run,
 * and crosses off what went out. Each try is claimed and counted in its outbox row before anything goes out, so a run
 * that cannot cross off, or dies, sends again only once its claim runs out, never without end (review of the 3a fixes).
 * What fails waits five minutes, up to about an hour; one run per form at a time; a run that fails comes back later.
 */
class SideEffectsJobTest extends TestCase {
	private const FORM_ID = 3;
	/** When the kept PDF was written, well before any test runs. */
	private const PDF_MADE_AT = 1762770000;

	/** The form's rows held as a form's outbox and responses; OutboxStore::apply writes back as the database would. */
	private array $stored = [];
	/** @var list<array{string, string}> what was sent: [effect, response id or address] */
	private array $sent = [];
	/** @var list<string> webhook ids that cannot be reached */
	private array $down = [];
	private bool $mailServerUp = true;
	/** @var array<string, \Throwable> what throws when sent: 'webhook:<id>', 'notify' or 'mail' */
	private array $throws = [];
	private bool $notifyReachesNobody = false;
	private ?\OCA\FormVox\Pdf\ResponsePdf $pdfs = null;
	/** Whether the form keeps a PDF of every response. */
	private bool $keepsPdf = true;
	private ?\Closure $mailPdf = null;
	private string $mailPdfName = '';
	private bool $pdfKept = true;
	/** @var list<?string> the account each confirmation email was given */
	private array $mailAccounts = [];
	/** @var list<array|null> the pdf block each webhook was given */
	private array $pdfBlocks = [];
	/** @var list<int> the outbox writes of a run, counted from 1, that the database fails */
	private array $failsAt = [];
	private int $applies = 0;
	/** How many times the outbox rows were written. */
	private int $writes = 0;
	/** How many times the form file was read. */
	private int $reads = 0;
	/** Still in its file: not in the database yet. */
	private bool $inFile = false;
	private int $converted = 0;
	/** @var list<array> entries that arrive while the job sends */
	private array $arriving = [];
	/** What happens to the form while the job sends: a deletion, say. */
	private ?\Closure $whileSending = null;
	private ?\Throwable $readFails = null;
	private bool $gone = false;
	private ?float $runSeconds = null;
	/** @var array<string, array> the job's array properties after its run */
	private array $lastJobState = [];
	/** @var list<array> what the webhooks were given */
	private array $payloads = [];
	/** @var list<int> seconds after which this run asked for the job again, the backstop first */
	private array $again = [];
	/** How many times this run asked for the job at once. */
	private int $atOnce = 0;
	/** Whether the form's row was held at each write of the outbox. */
	private array $writtenHeld = [];
	private bool $held = false;
	private LoggerInterface $logger;
	private Redis $locks;
	private Redis $sentCache;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->locks = new Redis();
		$this->sentCache = new Redis();
	}

	private function entry(string $rid, array $todo = [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY, ResponseOutbox::MAIL], int $attempts = 0, string $event = ResponseOutbox::CREATED, array $extra = []): array {
		return ['id' => $rid . ':' . $event, 'response' => $rid, 'event' => $event, 'language' => 'nl', 'todo' => $todo, 'attempts' => $attempts] + $extra;
	}

	/** @param list<string> $webhooks */
	private function form(array $outbox, array $responseIds = ['r1', 'r2'], array $webhooks = ['w1']): array {
		return [
			'title' => 'Survey',
			'settings' => ['keepResponsePdf' => $this->keepsPdf, 'webhooks' => array_map(fn (string $id) => ['id' => $id], $webhooks)],
			'responses' => array_map(fn (string $id) => ['id' => $id, 'answers' => ['q1' => 'a@b.com']], $responseIds),
			ResponseOutbox::KEY => $outbox,
		];
	}

	private function runJob(): void {
		$this->applies = 0;
		$this->again = [];
		$this->atOnce = 0;
		$file = $this->createMock(File::class);
		$locator = $this->createMock(FormFileLocator::class);
		$locator->method('getFileByIdPublic')->willReturnCallback(fn () => $this->gone ? throw new NotFoundException() : $file);
		$locator->method('getFileByIdPublicForWrite')->willReturnCallback(fn () => $this->gone ? throw new NotFoundException() : $file);
		$stored = new StoredForm(self::FORM_ID, 'uuid', 7, 1);
		$forms = $this->createMock(FormStore::class);
		$forms->method('byFileId')->willReturnCallback(fn () => $this->inFile ? null : $stored);
		$converter = $this->createMock(FormConverter::class);
		$converter->method('convert')->willReturnCallback(function () use ($stored): StoredForm {
			$this->converted++;
			$this->inFile = false;
			return $stored;
		});
		$reader = $this->createMock(FormReader::class);
		$reader->method('read')->willReturnCallback(function (): array {
			$this->reads++;
			// The file holds the form only: its responses and outbox are rows.
			return $this->readFails ? throw $this->readFails : array_diff_key($this->stored, ['responses' => true, ResponseOutbox::KEY => true]);
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createLocking')->willReturn($this->locks);
		$cacheFactory->method('createDistributed')->willReturn($this->sentCache);
		$lock = new FormLock($cacheFactory, $this->createMock(IDBConnection::class), $this->logger);
		$outbox = $this->createMock(ResponseOutbox::class);
		$outbox->method('scheduleAfter')->willReturnCallback(function (int $fileId, int $seconds): void {
			$this->again[] = $seconds;
		});
		$outbox->method('schedule')->willReturnCallback(function (int $fileId): void {
			$this->atOnce++;
		});
		$webhooks = $this->createMock(WebhookService::class);
		$webhooks->method('trigger')->willReturnCallback(function (int $fileId, array $form, string $event, array $response, ?array $only, ?string $eventId, ?array $pdf = null) {
			foreach ($this->arriving as $entry) {
				$this->stored[ResponseOutbox::KEY][] = $entry;
			}
			$this->arriving = [];
			if ($this->whileSending !== null) {
				($this->whileSending)();
				$this->whileSending = null;
			}
			foreach ((array)$only as $id) {
				if (isset($this->throws['webhook:' . $id])) {
					throw $this->throws['webhook:' . $id];
				}
			}
			$this->sent[] = ['webhook:' . implode(',', (array)$only) . ':' . $eventId, $response['id']];
			$this->payloads[] = $response;
			$this->pdfBlocks[] = $pdf;
			return array_values(array_intersect($this->down, (array)$only));
		});
		$notifications = $this->createMock(ResponseNotificationService::class);
		$notifications->method('notifyNewResponse')->willReturnCallback(function (int $fileId, array $form, array $response): bool {
			if (isset($this->throws['notify'])) {
				throw $this->throws['notify'];
			}
			if ($this->notifyReachesNobody) {
				return false;
			}
			$this->sent[] = ['notify', $response['id']];
			return true;
		});
		$mail = $this->createMock(ConfirmationMail::class);
		$mail->method('send')->willReturnCallback(function (array $form, array $answers, string $language, ?\Closure $pdf = null, ?string $account = null, int $fileId = 0, string $pdfName = '') {
			if (isset($this->throws['mail'])) {
				throw $this->throws['mail'];
			}
			$this->sent[] = ['mail:' . $language, $answers['q1']];
			$this->mailPdf = $pdf;
			$this->mailPdfName = $pdfName;
			$this->mailAccounts[] = $account;
			return $this->mailServerUp;
		});

		$job = new SideEffectsJob($this->createMock(ITimeFactory::class), $locator, $lock, $outbox, $webhooks, $notifications, $mail, $this->pdfs ?? $this->anyPdf(),
			$this->urls(), $cacheFactory, $this->logger, $forms, $this->outboxRows(), $this->rowLock(), $reader, $converter);
		if ($this->runSeconds !== null) {
			(new \ReflectionProperty($job, 'runSeconds'))->setValue($job, $this->runSeconds);
		}
		(new \ReflectionMethod($job, 'run'))->invoke($job, ['fileId' => 7]);
		$this->lastJobState = [];
		foreach ((new \ReflectionObject($job))->getProperties() as $property) {
			$value = $property->getValue($job);
			if (is_array($value)) {
				$this->lastJobState[$property->getName()] = $value;
			}
		}
	}

	/** The form's row, held while the work runs, as ResponseStore holds it in a transaction. */
	private function rowLock(): ResponseStore {
		$responses = $this->createMock(ResponseStore::class);
		$responses->method('whileHeld')->willReturnCallback(function (StoredForm $stored, callable $work): void {
			$this->held = true;
			try {
				$work();
			} finally {
				$this->held = false;
			}
		});
		return $responses;
	}

	/** The outbox rows held in $this->stored; a row is found again by its place, as entries only arrive at the end. */
	private function outboxRows(): OutboxStore {
		$rows = $this->createMock(OutboxStore::class);
		$rows->method('entries')->willReturnCallback(function (int $formId): array {
			$this->assertSame(self::FORM_ID, $formId);
			$entries = [];
			foreach ($this->stored[ResponseOutbox::KEY] ?? [] as $row => $entry) {
				$entries[] = [OutboxStore::ROW => $row] + $entry;
			}
			return $entries;
		});
		$rows->method('responses')->willReturnCallback(function (int $formId, array $ids): array {
			$this->assertSame(self::FORM_ID, $formId);
			$found = [];
			foreach ($this->stored['responses'] ?? [] as $response) {
				if (in_array((string)$response['id'], $ids, true)) {
					$found[(string)$response['id']] = $response;
				}
			}
			return $found;
		});
		$rows->method('apply')->willReturnCallback(function (array $before, array $after): void {
			$this->writtenHeld[] = $this->held;
			if (in_array(++$this->applies, $this->failsAt, true)) {
				throw new \RuntimeException('Deadlock found when trying to get lock');
			}
			$read = array_flip(array_column($before, OutboxStore::ROW));
			$kept = array_column($after, null, OutboxStore::ROW);
			$outbox = [];
			foreach ($this->stored[ResponseOutbox::KEY] ?? [] as $row => $entry) {
				if (!isset($read[$row])) {
					// Arrived meanwhile.
					$outbox[] = $entry;
					continue;
				}
				$now = $kept[$row] ?? null;
				if ($now === null) {
					continue;
				}
				// A row keeps only what a run makes of it.
				$entry['todo'] = array_map('strval', (array)($now['todo'] ?? []));
				$entry['attempts'] = (int)($now['attempts'] ?? 0);
				foreach (['claimedAt', 'notBefore'] as $key) {
					if (isset($now[$key])) {
						$entry[$key] = (int)$now[$key];
					} else {
						unset($entry[$key]);
					}
				}
				$outbox[] = $entry;
			}
			if ($outbox === []) {
				unset($this->stored[ResponseOutbox::KEY]);
			} else {
				$this->stored[ResponseOutbox::KEY] = $outbox;
			}
			$this->writes++;
		});
		return $rows;
	}

	/** This run's last word: the backstop first, then back in about this many seconds, never at once. */
	private function assertBackIn(int $seconds, string $message = ''): void {
		$this->assertCount(2, $this->again, $message);
		$this->assertSame([SideEffectsJob::CLAIM_SECONDS, 0], [$this->again[0], $this->atOnce], $message);
		$this->assertGreaterThanOrEqual($seconds - 2, $this->again[1], $message);
		$this->assertLessThanOrEqual($seconds, $this->again[1], $message);
	}

	/** @return array the stored outbox entry with this id */
	private function stored(string $id): array {
		foreach ($this->stored[ResponseOutbox::KEY] ?? [] as $entry) {
			if ($entry['id'] === $id) {
				return $entry;
			}
		}
		$this->fail($id . ' is not in the outbox');
	}

	public function testEveryDueEventIsSentInOneRunAndCrossedOff(): void {
		$this->stored = $this->form([$this->entry('r1'), $this->entry('r2', [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY])]);

		$this->runJob();

		$this->assertSame([
			['webhook:w1:r1:response.created', 'r1'], ['notify', 'r1'], ['mail:nl', 'a@b.com'],
			['webhook:w1:r2:response.created', 'r2'], ['notify', 'r2'],
		], $this->sent);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
		$this->assertSame(2, $this->writes, 'claimed once, crossed off once, for all of them');
		$this->assertSame([[SideEffectsJob::CLAIM_SECONDS], 0], [$this->again, $this->atOnce], 'only the backstop, set before the claim');
	}

	/** A response deleted at the same moment is gone before the claim or finds its entry claimed, never in between. */
	public function testTheClaimAndTheCrossingOffAreWrittenWhileTheFormsRowIsHeld(): void {
		$this->stored = $this->form([$this->entry('r1')]);

		$this->runJob();

		$this->assertSame([true, true], $this->writtenHeld);
	}

	/** Only what failed is tried again, and only five minutes later: never in the same cron run (review 3a-4). */
	public function testWhatFailedWaitsFiveMinutesAndIsTriedAgainAlone(): void {
		$this->stored = $this->form([$this->entry('r1')]);
		$this->down = ['w1'];
		$this->mailServerUp = false;

		$this->runJob();

		$entry = $this->stored('r1:response.created');
		$this->assertSame([[ResponseOutbox::WEBHOOK . 'w1', ResponseOutbox::MAIL], 1], [$entry['todo'], $entry['attempts']]);
		$this->assertArrayNotHasKey('claimedAt', $entry);
		$this->assertGreaterThanOrEqual(time() + SideEffectsJob::RETRY_SECONDS - 1, $entry['notBefore']);
		$this->assertBackIn(SideEffectsJob::RETRY_SECONDS);

		// Run again at once: not due yet, nothing is sent and nothing written.
		$this->sent = [];
		$this->writes = 0;
		$this->runJob();
		$this->assertSame([[], 0], [$this->sent, $this->writes]);
		$this->assertBackIn(SideEffectsJob::RETRY_SECONDS);

		// Due: only what failed goes, and the notification is not sent twice.
		$this->stored[ResponseOutbox::KEY][0]['notBefore'] = time() - 1;
		$this->down = [];
		$this->mailServerUp = true;
		$this->runJob();
		$this->assertSame([['webhook:w1:r1:response.created', 'r1'], ['mail:nl', 'a@b.com']], $this->sent);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
	}

	/**
	 * A webhook that breaks, not merely fails, holds back nothing else: not the other webhooks, the notification and the
	 * email of its response, nor the other responses of the run. It is tried again like any failed webhook.
	 */
	public function testABrokenWebhookHoldsBackNothingElse(): void {
		$this->stored = $this->form([$this->entry('r1'), $this->entry('r2')], ['r1', 'r2'], ['w1', 'w2']);
		$this->throws = ['webhook:w1' => new \TypeError('broken receiver config')];

		$this->runJob();

		$this->assertSame([
			['webhook:w2:r1:response.created', 'r1'], ['notify', 'r1'], ['mail:nl', 'a@b.com'],
			['webhook:w2:r2:response.created', 'r2'], ['notify', 'r2'], ['mail:nl', 'a@b.com'],
		], $this->sent);
		foreach (['r1', 'r2'] as $rid) {
			$entry = $this->stored($rid . ':response.created');
			$this->assertSame([[ResponseOutbox::WEBHOOK . 'w1'], 1], [$entry['todo'], $entry['attempts']], $rid);
		}
		$this->assertBackIn(SideEffectsJob::RETRY_SECONDS);
	}

	/** @return array<string, array{string, string}> */
	public static function brokenEffects(): array {
		return ['notification' => ['notify', ResponseOutbox::NOTIFY], 'email' => ['mail', ResponseOutbox::MAIL]];
	}

	/**
	 * @dataProvider brokenEffects
	 */
	public function testABrokenNotificationOrEmailHoldsBackNothingElse(string $breaks, string $effect): void {
		$this->stored = $this->form([$this->entry('r1'), $this->entry('r2')]);
		$this->throws = [$breaks => new \Error('broken')];

		$this->runJob();

		$this->assertCount(4, $this->sent, 'the webhook and the other effect of both responses went out');
		foreach (['r1', 'r2'] as $rid) {
			$this->assertSame([$effect], $this->stored($rid . ':response.created')['todo'], $rid);
		}
	}

	/** A notification that reached nobody for a failure worth trying again is tried again, not crossed off. */
	public function testANotificationThatReachedNobodyIsTriedAgain(): void {
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::NOTIFY])]);
		$this->notifyReachesNobody = true;

		$this->runJob();
		$this->assertSame([ResponseOutbox::NOTIFY], $this->stored('r1:response.created')['todo']);

		$this->stored[ResponseOutbox::KEY][0]['notBefore'] = time() - 1;
		$this->notifyReachesNobody = false;
		$this->runJob();
		$this->assertSame([['notify', 'r1']], $this->sent);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
	}

	/** The last try goes out; the run after it gives up, logs it and sends nothing. */
	public function testAfterTheLastTryItIsGivenUpAndLogged(): void {
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::MAIL], SideEffectsJob::MAX_ATTEMPTS - 1)]);
		$this->mailServerUp = false;
		$this->logger->expects($this->once())->method('error');

		$this->runJob();
		$this->assertSame(SideEffectsJob::MAX_ATTEMPTS, $this->stored('r1:response.created')['attempts']);

		$this->sent = [];
		$this->stored[ResponseOutbox::KEY][0]['notBefore'] = time() - 1;
		$this->runJob();

		$this->assertSame([], $this->sent);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
	}

	/** A deletion's webhook carries only the id of the response that is gone (PO: deleted is gone). */
	public function testAResponseDeletedMeanwhileSetsNothingOffButItsDeletionGoesOutWithItsIdOnly(): void {
		$this->stored = $this->form([
			$this->entry('r9'),
			$this->entry('r9', [ResponseOutbox::WEBHOOKS], 0, ResponseOutbox::DELETED),
		], []);

		$this->runJob();

		$this->assertSame([['webhook:w1:r9:response.deleted', 'r9']], $this->sent);
		$this->assertSame([['id' => 'r9']], $this->payloads);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
	}

	/**
	 * A receiver hears of a response in order: an update waits while the response's created has not gone out in full,
	 * uncounted, and comes back with it, not every cron run before then.
	 */
	public function testEventsOfOneResponseGoOutInOrder(): void {
		$this->stored = $this->form([
			$this->entry('r1', [ResponseOutbox::WEBHOOKS]),
			$this->entry('r1', [ResponseOutbox::WEBHOOKS], 0, ResponseOutbox::UPDATED),
			$this->entry('r2', [ResponseOutbox::WEBHOOKS]),
		]);
		$this->down = ['w1'];

		$this->runJob();

		$this->assertSame([['webhook:w1:r1:response.created', 'r1'], ['webhook:w1:r2:response.created', 'r2']], $this->sent);
		$updated = $this->stored('r1:response.updated');
		$this->assertSame(0, $updated['attempts']);
		$this->assertArrayNotHasKey('claimedAt', $updated);
		$this->assertBackIn(SideEffectsJob::RETRY_SECONDS, 'the held update does not bring the job back sooner');

		// The created went out: the update follows in the same run.
		$this->sent = [];
		$this->down = [];
		foreach ($this->stored[ResponseOutbox::KEY] as &$entry) {
			unset($entry['notBefore']);
		}
		unset($entry);
		$this->runJob();
		$this->assertSame([
			['webhook:w1:r1:response.created', 'r1'], ['webhook:w1:r1:response.updated', 'r1'], ['webhook:w1:r2:response.created', 'r2'],
		], $this->sent);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
	}

	/**
	 * The order is kept per receiver: a webhook that is down holds a response's later events for that webhook only; the
	 * others get them at once (review of the 3a fixes: one broken webhook held every receiver about an hour per event).
	 */
	public function testABrokenWebhookHoldsOnlyItself(): void {
		$this->stored = $this->form([
			$this->entry('r1', [ResponseOutbox::WEBHOOKS]),
			$this->entry('r1', [ResponseOutbox::WEBHOOKS], 0, ResponseOutbox::UPDATED),
		], ['r1'], ['w1', 'w2']);
		$this->down = ['w2'];

		$this->runJob();

		$this->assertSame([
			['webhook:w1:r1:response.created', 'r1'], ['webhook:w2:r1:response.created', 'r1'], ['webhook:w1:r1:response.updated', 'r1'],
		], $this->sent);
		$this->assertSame([ResponseOutbox::WEBHOOK . 'w2'], $this->stored('r1:response.updated')['todo']);

		// w2 is back: it hears of the created first, then of the update.
		$this->sent = [];
		$this->down = [];
		foreach ($this->stored[ResponseOutbox::KEY] as &$entry) {
			unset($entry['notBefore']);
		}
		unset($entry);
		$this->runJob();
		$this->assertSame([['webhook:w2:r1:response.created', 'r1'], ['webhook:w2:r1:response.updated', 'r1']], $this->sent);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
	}

	/** An update whose one webhook waits behind its created is not counted as a try, nor sent before it. */
	public function testAnEventThatOnlyWaitsCountsNoTry(): void {
		$this->stored = $this->form([
			$this->entry('r1', [ResponseOutbox::WEBHOOKS]),
			$this->entry('r1', [ResponseOutbox::WEBHOOKS], 0, ResponseOutbox::UPDATED),
		], ['r1']);
		$this->down = ['w1'];

		$this->runJob();

		$this->assertSame([['webhook:w1:r1:response.created', 'r1']], $this->sent);
		$updated = $this->stored('r1:response.updated');
		$this->assertSame([0, [ResponseOutbox::WEBHOOK . 'w1']], [$updated['attempts'], $updated['todo']]);
		$this->assertBackIn(SideEffectsJob::RETRY_SECONDS);
	}

	/** A confirmation email that cannot go out holds no webhook back: it has no order to keep. */
	public function testAFailedMailHoldsNoWebhook(): void {
		$this->stored = $this->form([
			$this->entry('r1', [ResponseOutbox::MAIL]),
			$this->entry('r1', [ResponseOutbox::WEBHOOKS], 0, ResponseOutbox::UPDATED),
		], ['r1']);
		$this->mailServerUp = false;

		$this->runJob();

		$this->assertSame([['mail:nl', 'a@b.com'], ['webhook:w1:r1:response.updated', 'r1']], $this->sent);
		$this->assertSame(['r1:response.created'], array_column($this->stored[ResponseOutbox::KEY], 'id'));
	}

	/**
	 * Deleted while this run sent its created, and a webhook failed: the entry goes, only the deletion stays, due at once
	 * (PO: deleted is gone; review of the 3a fixes: it stayed with its language and held the deletion five minutes).
	 */
	public function testADeletionWhileSendingLeavesOnlyTheDeletion(): void {
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::WEBHOOKS, ResponseOutbox::MAIL])], ['r1']);
		$this->down = ['w1'];
		$this->whileSending = function (): void {
			$this->stored['responses'] = [];
			$this->stored[ResponseOutbox::KEY][] = $this->entry('r1', [ResponseOutbox::WEBHOOKS], 0, ResponseOutbox::DELETED);
		};

		$this->runJob();

		$this->assertSame(['r1:response.deleted'], array_column($this->stored[ResponseOutbox::KEY], 'id'));
		$this->assertSame(1, $this->atOnce);
	}

	/** Nothing of the form stays with the job after its run: Nextcloud keeps one job object for the whole cron run. */
	public function testTheJobKeepsNoFormAfterItsRun(): void {
		$this->stored = $this->form([$this->entry('r1')]);

		$this->runJob();

		$this->assertStringNotContainsString('a@b.com', print_r($this->lastJobState, true));
	}

	/** An event that came in while this run sent stays as it came, and the job comes back for it at once. */
	public function testWhatArrivesWhileSendingStays(): void {
		$this->stored = $this->form([$this->entry('r1')]);
		$this->arriving = [$this->entry('r2')];

		$this->runJob();

		$this->assertSame([$this->entry('r2')], $this->stored[ResponseOutbox::KEY]);
		$this->assertSame(1, $this->atOnce);
	}

	/**
	 * Sent, but the crossing off could not be written: the claim stays, so the next runs send nothing and write nothing
	 * until it runs out; then the sent markers spare the receivers, and it is crossed off (review of the 3a fixes: it was
	 * sent again at once and without end).
	 */
	public function testACrossingOffThatCannotBeWrittenSendsNothingTwice(): void {
		$this->stored = $this->form([$this->entry('r1')]);
		$this->failsAt = [2];

		$this->runJob();
		$this->assertCount(3, $this->sent);
		$this->assertSame([[SideEffectsJob::CLAIM_SECONDS, SideEffectsJob::RETRY_SECONDS], 0], [$this->again, $this->atOnce]);
		$this->assertSame(1, $this->stored('r1:response.created')['attempts']);

		$this->failsAt = [];
		$this->sent = [];
		$this->writes = 0;
		$this->runJob();
		$this->assertSame([[], 0], [$this->sent, $this->writes]);
		$this->assertBackIn(SideEffectsJob::CLAIM_SECONDS);

		$this->stored[ResponseOutbox::KEY][0]['claimedAt'] = time() - SideEffectsJob::CLAIM_SECONDS;
		$this->runJob();
		$this->assertSame([], $this->sent);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
	}

	/** A run that died after its claim, with no shared cache to tell what went out: sent once more, as one more try. */
	public function testAClaimThatRanOutIsTriedAgainAsOneMoreTry(): void {
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::WEBHOOKS], 1, ResponseOutbox::CREATED, ['claimedAt' => time() - SideEffectsJob::CLAIM_SECONDS])]);
		$this->down = ['w1'];

		$this->runJob();

		$this->assertSame([['webhook:w1:r1:response.created', 'r1']], $this->sent);
		$this->assertSame(2, $this->stored('r1:response.created')['attempts']);
	}

	/** What another run claimed is not sent by this one, even with the effects lock gone. */
	public function testWhatAnotherRunClaimedIsLeftToIt(): void {
		$this->stored = $this->form([
			$this->entry('r1', [ResponseOutbox::WEBHOOKS], 1, ResponseOutbox::CREATED, ['claimedAt' => time()]),
			$this->entry('r1', [ResponseOutbox::WEBHOOKS], 0, ResponseOutbox::UPDATED),
		]);

		$this->runJob();

		$this->assertSame([[], 0], [$this->sent, $this->writes]);
		$this->assertBackIn(SideEffectsJob::CLAIM_SECONDS);
	}

	/** Past its time a run takes nothing new: what it did not reach is not counted and goes in the next run, at once. */
	public function testARunStopsTakingNewEntriesAfterItsTime(): void {
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::WEBHOOKS]), $this->entry('r2', [ResponseOutbox::WEBHOOKS])]);
		$this->runSeconds = -1.0;

		$this->runJob();

		$this->assertSame([['webhook:w1:r1:response.created', 'r1']], $this->sent);
		$this->assertSame([$this->entry('r2', [ResponseOutbox::WEBHOOKS])], $this->stored[ResponseOutbox::KEY]);
		$this->assertSame(1, $this->atOnce);
	}

	/** Another run sending the same form's outbox: this one comes back in a minute, and nothing goes out twice. */
	public function testOneRunPerFormAtATime(): void {
		$this->stored = $this->form([$this->entry('r1')]);
		$this->locks->add('formvox/form/effects/7', time() . ':other:elsewhere:1', 120);

		$this->runJob();

		$this->assertSame([], $this->sent);
		$this->assertSame([SideEffectsJob::BUSY_SECONDS], $this->again);
	}

	/** A claim that cannot be written: nothing goes out uncounted, and the job comes back. */
	public function testAClaimThatCannotBeWrittenSendsNothing(): void {
		$this->stored = $this->form([$this->entry('r1')]);
		$this->failsAt = [1];

		$this->runJob();

		$this->assertSame([], $this->sent);
		$this->assertSame([[SideEffectsJob::CLAIM_SECONDS, SideEffectsJob::RETRY_SECONDS], 0], [$this->again, $this->atOnce]);
		$this->assertSame(0, $this->stored('r1:response.created')['attempts']);
	}

	/**
	 * Whatever fails before the crossing off (an unreadable form, a busy read), the outbox stays and the job comes back:
	 * it was lost before (review 3a-4).
	 */
	public function testAFailedRunComesBackLater(): void {
		$this->stored = $this->form([$this->entry('r1')]);
		$this->readFails = new FormLockedException('Form 7 stayed unreadable');

		$this->runJob();

		// The job list keeps the last time asked for.
		$this->assertSame(SideEffectsJob::RETRY_SECONDS, end($this->again));
		$this->assertSame([], $this->sent);
		$this->assertCount(1, $this->stored[ResponseOutbox::KEY]);
	}

	/** A file that holds no form is not mended by waiting: logged, and not tried again every five minutes. */
	public function testABrokenFormIsNotTriedAgainWithoutEnd(): void {
		$this->stored = $this->form([$this->entry('r1')]);
		$this->readFails = new FormBrokenException();
		$this->logger->expects($this->once())->method('error');

		$this->runJob();

		$this->assertSame([[], [], 0], [$this->sent, $this->again, $this->atOnce]);
	}

	public function testAFormThatIsGoneSendsNothingAndIsNotAskedForAgain(): void {
		$this->gone = true;

		$this->runJob();

		$this->assertSame([[], []], [$this->sent, $this->again]);
	}

	/** A form still in its file moves into the database first; what it sets off is sent from there. */
	public function testAFormStillInItsFileMovesInBeforeItsOutboxIsSent(): void {
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::NOTIFY])]);
		$this->inFile = true;

		$this->runJob();

		$this->assertSame([1, [['notify', 'r1']]], [$this->converted, $this->sent]);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
	}

	/** Nothing waiting: nothing to come back for, and the form file is not read. */
	public function testAnEmptyOutboxIsOnlyLookedAt(): void {
		$this->stored = $this->form([]);

		$this->runJob();

		$this->assertSame([[], 0, 0, 0, 0], [$this->again, $this->atOnce, $this->reads, $this->writes, $this->converted]);
	}

	private function urls(): \OCP\IURLGenerator {
		$urls = $this->createMock(\OCP\IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(fn (string $route, array $params) => 'https://cloud.example/' . $route . '/' . $params['fileId'] . '/' . $params['responseId']);
		return $urls;
	}

	/** @param bool $works whether the PDF can be made */
	/** Where a test is about something else: a PDF is there, kept, empty. */
	private function anyPdf(): \OCA\FormVox\Pdf\ResponsePdf {
		$pdfs = $this->createMock(\OCA\FormVox\Pdf\ResponsePdf::class);
		$pdfs->method('keptOf')->willReturn('');
		return $pdfs;
	}

	private function pdfMaker(bool $works = true): \OCA\FormVox\Pdf\ResponsePdf {
		$pdfs = $this->createMock(\OCA\FormVox\Pdf\ResponsePdf::class);
		$make = function (File $file, array $form, array $response, ?string $language) use ($works): string {
			$this->sent[] = ['pdf:' . $language, $response['id']];
			return $works ? '%PDF-1.7' : throw new \RuntimeException('out of memory');
		};
		$pdfs->method('of')->willReturnCallback($make);
		$pdfs->method('keptOf')->willReturnCallback(fn (File $file, array $form, array $response, ?string $language) => $this->pdfKept ? $make($file, $form, $response, $language) : null);
		$pdfs->method('fileName')->willReturnCallback(fn (array $form, array $response): string => 'Form_2026-11-10_' . $response['id'] . '.pdf');
		$kept = $this->createMock(File::class);
		$kept->method('getMTime')->willReturn(self::PDF_MADE_AT);
		$pdfs->method('stored')->willReturn($kept);
		return $pdfs;
	}

	/** The PDF is made first, so the webhooks after it name a document that is there. */
	public function testThePdfIsMadeBeforeTheWebhooksThatNameIt(): void {
		$this->pdfs = $this->pdfMaker();
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS])], ['r1']);

		$this->runJob();

		// Made by the step; the webhook asks for it again, kept, for the checksum it names.
		$this->assertSame([['pdf:nl', 'r1'], ['pdf:nl', 'r1'], ['webhook:w1:r1:response.created', 'r1']], $this->sent);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored);
	}

	/** A PDF that cannot be made now is tried again later; the rest goes out meanwhile. */
	public function testAPdfThatCannotBeMadeIsTriedAgainAndHoldsNothing(): void {
		$this->pdfs = $this->pdfMaker(false);
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::PDF, ResponseOutbox::NOTIFY])], ['r1']);

		$this->runJob();

		$this->assertSame([['pdf:nl', 'r1'], ['notify', 'r1']], $this->sent);
		$this->assertSame([ResponseOutbox::PDF], $this->stored('r1:response.created')['todo']);
		$this->assertBackIn(SideEffectsJob::RETRY_SECONDS);
	}

	/** An update's PDF waits with the webhooks it is for, behind its created, and counts no try meanwhile. */
	public function testAPdfWaitsWithTheWebhooksItIsFor(): void {
		$this->pdfs = $this->pdfMaker();
		$this->stored = $this->form([
			$this->entry('r1', [ResponseOutbox::WEBHOOKS]),
			$this->entry('r1', [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS], 0, ResponseOutbox::UPDATED),
		], ['r1']);
		$this->down = ['w1'];

		$this->runJob();

		$this->assertSame([['pdf:nl', 'r1'], ['webhook:w1:r1:response.created', 'r1']], $this->sent, "the created's own webhook names its PDF");
		$this->assertSame(0, $this->stored('r1:response.updated')['attempts']);
	}

	/** The confirmation email gets the response's kept PDF, asked for only when it goes out. */
	public function testTheConfirmationEmailGetsTheResponsesPdf(): void {
		$this->pdfs = $this->pdfMaker();
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::MAIL])], ['r1']);

		$this->runJob();

		$this->assertSame([['mail:nl', 'a@b.com']], $this->sent);
		$this->assertSame('Form_2026-11-10_r1.pdf', $this->mailPdfName, 'by the name it goes by everywhere');
		$this->assertSame('%PDF-1.7', ($this->mailPdf)());
		$this->assertSame(['pdf:nl', 'r1'], end($this->sent));
	}

	/** Deleted while its PDF was being made: the PDF made after its deletion goes too. */
	public function testThePdfOfAResponseDeletedWhileSendingGoes(): void {
		$this->pdfs = $this->pdfMaker();
		$forgotten = [];
		$this->pdfs->method('forget')->willReturnCallback(function (File $file, string $id) use (&$forgotten): void {
			$forgotten[] = $id;
		});
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS])], ['r1', 'r2']);
		$this->whileSending = function (): void {
			$this->stored['responses'] = [['id' => 'r2', 'answers' => []]];
		};

		$this->runJob();

		$this->assertSame(['r1'], $forgotten);
	}

	/** A webhook names the response's PDF: where to fetch it with an API key, and its checksum. */
	public function testAWebhookNamesWhereThePdfIs(): void {
		$this->pdfs = $this->pdfMaker();
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS])], ['r1']);

		$this->runJob();

		$this->assertSame([
			'url' => 'https://cloud.example/formvox.external_api.getResponsePdf/7/r1',
			'sha256' => hash('sha256', '%PDF-1.7'),
			'size' => 8,
			'filename' => 'Form_2026-11-10_r1.pdf',
			'status' => 'stored',
			'generated_at' => date(DATE_ATOM, self::PDF_MADE_AT),
		], $this->pdfBlocks[0] ?? null);
		$this->assertSame(self::PDF_MADE_AT, strtotime($this->pdfBlocks[0]['generated_at']), 'when the kept file was written, not now');
	}

	/** Made but not kept, the PDF would not be the one the API gives later: the webhook waits for one that is. */
	public function testAWebhookWaitsForAPdfThatCannotBeKept(): void {
		$this->pdfs = $this->pdfMaker();
		$this->pdfKept = false;
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::WEBHOOKS])], ['r1']);
		$logged = [];
		$this->logger->method('warning')->willReturnCallback(function (string $message) use (&$logged): void {
			$logged[] = $message;
		});

		$this->runJob();

		$this->assertContains('FormVox: the PDF a webhook of form 7 names could not be kept; the webhook waits for it', $logged, 'said as such, not as a failed webhook');

		$this->assertSame([], $this->pdfBlocks, 'no webhook went out');
		$this->assertSame([ResponseOutbox::WEBHOOK . 'w1'], $this->stored('r1:response.created')['todo']);
	}

	/** A deletion names no PDF; one that cannot be made holds the webhook, which never goes without it. */
	public function testAWebhookWaitsForAPdfThatCannotBeMade(): void {
		$this->pdfs = $this->pdfMaker(false);
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::WEBHOOKS])], ['r1']);

		$this->runJob();

		$this->assertSame([['pdf:nl', 'r1']], $this->sent, 'no webhook went out');
		$this->assertSame([ResponseOutbox::WEBHOOK . 'w1'], $this->stored('r1:response.created')['todo']);
	}

	public function testADeletionNamesNoPdf(): void {
		$this->pdfs = $this->pdfMaker();
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::WEBHOOKS], 0, ResponseOutbox::DELETED)], []);

		$this->runJob();

		$this->assertSame([null], $this->pdfBlocks);
	}

	/**
	 * A run that made a deleted response's PDF but could not cross it off: the next run, dropping the entry, takes
	 * the PDF too.
	 */
	public function testTheNextRunTakesThePdfOfAResponseDeletedMeanwhile(): void {
		$this->pdfs = $this->pdfMaker();
		$forgotten = [];
		$this->pdfs->method('forget')->willReturnCallback(function (File $file, string $id) use (&$forgotten): void {
			$forgotten[] = $id;
		});
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS], 1, ResponseOutbox::CREATED, ['claimedAt' => time() - SideEffectsJob::CLAIM_SECONDS - 1])], ['r2']);

		$this->runJob();

		$this->assertSame(['r1'], $forgotten);
	}

	/**
	 * The email learns the signed-in respondent's account, whose address it goes to: as the entry carries it, else as
	 * the response names them, else '' (signed in, not known: nothing goes to a typed address); null for anonymous.
	 */
	public function testTheEmailLearnsTheRespondentsAccount(): void {
		$this->stored = $this->form([
			$this->entry('r1', [ResponseOutbox::MAIL], 0, ResponseOutbox::CREATED, ['signedIn' => true, 'account' => 'bob']),
			$this->entry('r2', [ResponseOutbox::MAIL], 0, ResponseOutbox::CREATED, ['signedIn' => true]),
			$this->entry('r3', [ResponseOutbox::MAIL], 0, ResponseOutbox::CREATED, ['signedIn' => true]),
			$this->entry('r4', [ResponseOutbox::MAIL]),
		], ['r1', 'r2', 'r3', 'r4']);
		$this->stored['responses'][1]['respondent'] = ['type' => 'user', 'user_id' => 'carol', 'display_name' => 'Carol'];
		$this->stored['responses'][3]['respondent'] = ['type' => 'user', 'user_id' => 'dave'];

		$this->runJob();

		$this->assertSame(['bob', 'carol', '', null], $this->mailAccounts);
	}

	/** The response must arrive even where its PDF cannot be kept: after PDF_TRIES the webhook goes with pdf null (PO). */
	public function testAfterItsTriesAWebhookGoesWithoutThePdfItCouldNotKeep(): void {
		$this->pdfs = $this->pdfMaker();
		$this->pdfKept = false;
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::WEBHOOKS], SideEffectsJob::PDF_TRIES - 1)], ['r1']);

		$this->runJob();
		$this->assertSame([], $this->pdfBlocks, 'the last try with the PDF still waits for it');

		$this->stored[ResponseOutbox::KEY][0]['notBefore'] = time() - 1;
		$this->runJob();

		$this->assertSame([null], $this->pdfBlocks);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored, 'sent and crossed off');
	}

	/** A mail server that refuses the PDF (its size, say) refuses it every time: after PDF_TRIES the email goes without it. */
	public function testAfterItsTriesTheEmailGoesWithoutThePdf(): void {
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::MAIL], SideEffectsJob::PDF_TRIES - 1)], ['r1']);
		$this->mailServerUp = false;

		$this->runJob();
		$this->assertInstanceOf(\Closure::class, $this->mailPdf, 'the last try with the PDF');

		$this->mailServerUp = true;
		$this->stored[ResponseOutbox::KEY][0]['notBefore'] = time() - 1;
		$this->runJob();

		$this->assertNull($this->mailPdf);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored, 'sent and crossed off');
	}

	/**
	 * Switched off since the submission: no PDF is made for what still waits, and its webhook goes at once with pdf
	 * null, not after the tries a missing PDF gets.
	 */
	public function testAFormThatKeepsNoPdfMakesNoneAndItsWebhookNamesNone(): void {
		$this->keepsPdf = false;
		$this->pdfs = $this->pdfMaker();
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::PDF, ResponseOutbox::WEBHOOKS])], ['r1']);
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();

		$this->assertSame([['webhook:w1:r1:response.created', 'r1']], $this->sent, 'no PDF made');
		$this->assertSame([null], $this->pdfBlocks);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored, 'sent and crossed off');
	}

	/** The confirmation of a form that keeps no PDFs goes without one, whatever the email option says. */
	public function testAFormThatKeepsNoPdfSendsTheConfirmationWithoutOne(): void {
		$this->keepsPdf = false;
		$this->pdfs = $this->pdfMaker();
		$this->stored = $this->form([$this->entry('r1', [ResponseOutbox::PDF, ResponseOutbox::MAIL])], ['r1']);
		$this->stored['settings']['confirmationEmailPdf'] = true;
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();

		$this->assertSame([['mail:nl', 'a@b.com']], $this->sent);
		$this->assertNull($this->mailPdf);
		$this->assertArrayNotHasKey(ResponseOutbox::KEY, $this->stored, 'sent and crossed off');
	}
}
