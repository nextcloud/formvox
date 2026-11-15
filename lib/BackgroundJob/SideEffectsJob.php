<?php

declare(strict_types=1);

namespace OCA\FormVox\BackgroundJob;

use OCA\FormVox\AppInfo\Application;
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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Sends what events on one form's responses set off (ResponseOutbox), in order, all that is due in one run, after the
 * request (7a, 3a-4). Who may receive is decided now: a webhook only while its creator can open the form, notifications
 * only to who may still see it. What fails waits five minutes before it is tried again, up to about an hour. One run per
 * form at a time; a run that fails keeps the outbox and comes back later.
 */
class SideEffectsJob extends QueuedJob {
	/** About an hour of tries, RETRY_SECONDS apart. */
	public const MAX_ATTEMPTS = 12;
	public const RETRY_SECONDS = 300;
	/** A run that could not finish, or another run still sending: back in a minute. */
	public const BUSY_SECONDS = 60;
	/** What went out is remembered this long, so a run that could not cross it off does not send it again. */
	private const SENT_SECONDS = 86400;
	/** A claimed entry not crossed off (the run died or could not write) is tried again after this. */
	public const CLAIM_SECONDS = 600;
	/** Tries a webhook or the confirmation email waits for the response's PDF; after them they go without it. */
	public const PDF_TRIES = 3;

	private ?ICache $sent = null;
	/** How long a run takes new entries, within the effects lock's 120 s for a few slow receivers on the last one. */
	private float $runSeconds = 60.0;

	public function __construct(
		ITimeFactory $time,
		private FormFileLocator $locator,
		private FormLock $lock,
		private ResponseOutbox $outbox,
		private WebhookService $webhooks,
		private ResponseNotificationService $notifications,
		private ConfirmationMail $mail,
		private \OCA\FormVox\Pdf\ResponsePdf $pdfs,
		private \OCP\IURLGenerator $urls,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private FormStore $forms,
		private OutboxStore $rows,
		private ResponseStore $responses,
		private FormReader $reader,
		private FormConverter $converter,
	) {
		parent::__construct($time);
	}

	/**
	 * @param array{fileId?: int} $argument
	 */
	protected function run($argument): void {
		$fileId = (int)($argument['fileId'] ?? 0);
		try {
			$this->sendFor($fileId);
		} catch (FormBrokenException $e) {
			// Waiting does not mend it: the next event on the form, after a repair, brings the job back (review of the 3a
			// fixes: it came back every five minutes without end).
			$this->logger->error('FormVox: what form ' . $fileId . ' sets off waits: the form file holds no form', ['app' => Application::APP_ID, 'exception' => $e]);
		} catch (\Throwable $e) {
			// The outbox rows stay: nothing is lost by trying again later.
			$this->logger->warning('FormVox: what form ' . $fileId . ' sets off could not be sent now: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			$this->outbox->scheduleAfter($fileId, self::RETRY_SECONDS);
		}
	}

	private function sendFor(int $fileId): void {
		$file = $this->file($fileId);
		if ($file === null) {
			// The form is gone or in the trash, and what it still had to send with it.
			return;
		}
		// A form file moves into the database first; the converter takes the effects' lock itself.
		$stored = $this->forms->byFileId($fileId) ?? $this->converter->convert($file);
		try {
			$token = $this->lock->acquire($fileId, microtime(true), 'effects');
		} catch (FormLockedException) {
			// Another run is sending this form's outbox.
			$this->outbox->scheduleAfter($fileId, self::BUSY_SECONDS);
			return;
		}
		try {
			$this->sendDue($fileId, $file, $stored);
		} finally {
			$this->lock->release($fileId, $token, 'effects');
		}
	}

	private function file(int $fileId): ?File {
		try {
			return $this->locator->getFileByIdPublicForWrite($fileId);
		} catch (NotFoundException) {
			return null;
		} catch (\RuntimeException $e) {
			// No account may write it: in the trash, or a rights problem worth trying again for.
			try {
				$this->locator->getFileByIdPublic($fileId);
			} catch (NotFoundException) {
				return null;
			}
			throw $e;
		}
	}

	/**
	 * Claim what is due (counted as a try and written down before anything goes out), send it, cross it off. The outbox
	 * rows come in the shape of a form's outbox, with the responses they name. A run that cannot cross off, or dies
	 * halfway, leaves its claims: they are sent again only after CLAIM_SECONDS and count as a try, so nothing is sent
	 * without end (review of the 3a fixes).
	 */
	private function sendDue(int $fileId, File $file, StoredForm $stored): void {
		if ($this->rows->entries($stored->id) === []) {
			return;
		}
		// The form and its secrets, never all its responses: only those the outbox names. Read before the backstop, so a
		// form that cannot be read waits for its next event instead of coming back without end.
		$form = $this->reader->read($file);
		// The backstop: a run killed halfway (out of memory, a restart) comes back.
		$this->outbox->scheduleAfter($fileId, self::CLAIM_SECONDS);
		$state = [];
		$claimed = [];
		$kept = [];
		$dropped = [];
		// While the form's row is held: a response deleted meanwhile is gone before the claim or finds its entry claimed.
		$this->responses->whileHeld($stored, function () use ($form, $stored, $fileId, &$state, &$claimed, &$kept, &$dropped): void {
			$entries = $this->rows->entries($stored->id);
			$state = $this->withOutbox($form, $stored, $entries);
			if ($this->claim($state, $fileId, time(), $claimed, $kept, $dropped)) {
				$this->rows->apply($entries, $kept);
			}
		});
		$this->forgetPdfs($file, $dropped);
		if ($claimed === []) {
			$this->scheduleNext($fileId, $kept);
			return;
		}
		$left = $this->sendClaimed($fileId, $file, $state, $claimed);
		unset($state, $claimed);

		// Against what is stored now: rows that came in meanwhile stay, a response deleted meanwhile takes its rows.
		$gone = [];
		$this->responses->whileHeld($stored, function () use ($form, $stored, $left, &$gone, &$kept): void {
			$entries = $this->rows->entries($stored->id);
			$kept = $this->crossOff($this->withOutbox($form, $stored, $entries), $left, $gone);
			$this->rows->apply($entries, $kept);
		});
		$this->forgetPdfs($file, $gone);
		$this->scheduleNext($fileId, $kept);
	}

	/** @param list<array> $entries */
	private function withOutbox(array $form, StoredForm $stored, array $entries): array {
		$form[ResponseOutbox::KEY] = $entries;
		$form['responses'] = array_values($this->rows->responses($stored->id, array_map('strval', array_column($entries, 'response'))));
		return $form;
	}

	/**
	 * After a run: what it sent goes, what failed waits RETRY_SECONDS, what it did not reach is not counted as a try.
	 *
	 * @param array<string, array{0: list<string>, 1: bool}|null> $left per claimed entry, from sendClaimed
	 * @param list<string> $gone set to the responses deleted while this run sent their entries
	 * @return list<mixed> the outbox as it is to stay
	 */
	private function crossOff(array $form, array $left, array &$gone): array {
		$present = self::responseIds($form);
		$kept = [];
		foreach (is_array($form[ResponseOutbox::KEY] ?? null) ? $form[ResponseOutbox::KEY] : [] as $entry) {
			$id = is_array($entry) ? ($entry['id'] ?? null) : null;
			if (!is_string($id) || !array_key_exists($id, $left)) {
				// Not claimed by this run: waiting, or arrived meanwhile.
				$kept[] = $entry;
				continue;
			}
			if (self::isGone($entry, $present)) {
				// Deleted while this run sent it: only the deletion stays (PO: deleted is gone).
				$gone[] = (string)($entry['response'] ?? '');
				continue;
			}
			unset($entry['claimedAt']);
			$result = $left[$id];
			if ($result !== null && $result[0] === []) {
				continue;
			}
			if ($result === null || !$result[1]) {
				// Not reached, or all it had waits behind an earlier event: no try counted.
				$entry['attempts'] = max(0, (int)($entry['attempts'] ?? 1) - 1);
			} else {
				$entry['notBefore'] = time() + self::RETRY_SECONDS;
			}
			if ($result !== null) {
				$entry['todo'] = $result[0];
			}
			$kept[] = $entry;
		}
		return $kept;
	}

	/** @param list<string> $responseIds deleted responses */
	private function forgetPdfs(File $file, array $responseIds): void {
		foreach (array_unique($responseIds) as $responseId) {
			$this->pdfs->forget($file, $responseId);
		}
	}

	/**
	 * Under the effects lock: mark what is due as claimed, counting the try. A receiver hears of a response in order
	 * (created, updated, deleted): an entry whose every webhook still waits behind an earlier entry of its response
	 * (not due yet, or out with another run) waits too; one with other receivers is claimed, and what waits is held when
	 * it is sent. A confirmation email or notification holds nothing back (review of the 3a fixes).
	 *
	 * @param list<array{entry: array, held: array<string, true>}> $claimed set to the claimed entries, each with the
	 *                                                                      webhooks it must not reach yet
	 * @param list<mixed> $kept set to the outbox as written
	 * @param list<string> $dropped set to the responses whose entries were dropped as deleted
	 * @return bool whether anything changed: claimed, given up, or dropped with its deleted response
	 */
	private function claim(array &$form, int $fileId, int $now, array &$claimed, array &$kept, array &$dropped = []): bool {
		$outbox = is_array($form[ResponseOutbox::KEY] ?? null) ? $form[ResponseOutbox::KEY] : [];
		$present = self::responseIds($form);
		$claimed = [];
		$kept = [];
		$dropped = [];
		/** @var array<string, array<string, true>> $held per response: the webhooks an earlier entry has yet to reach */
		$held = [];
		$changed = false;
		foreach ($outbox as $entry) {
			if (!is_array($entry) || !is_string($entry['id'] ?? null)) {
				continue;
			}
			if (self::isGone($entry, $present)) {
				// Its response is deleted: it sends nothing and holds nothing back (PO: deleted is gone).
				$dropped[] = (string)($entry['response'] ?? '');
				$changed = true;
				continue;
			}
			$responseId = (string)($entry['response'] ?? '');
			$before = $held[$responseId] ?? [];
			if (!$this->isDue($entry, $now) || $this->allHeld($form, $entry, $before)) {
				$held[$responseId] = $before + $this->webhooksOf($form, $entry);
				$kept[] = $entry;
				continue;
			}
			if ((int)($entry['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
				$changed = true;
				$this->logger->error('FormVox: gave up sending ' . implode(', ', (array)($entry['todo'] ?? [])) . ' for ' . $entry['id'] . ' of form ' . $fileId . ' after ' . $entry['attempts'] . ' tries', ['app' => Application::APP_ID]);
				continue;
			}
			$entry['attempts'] = (int)($entry['attempts'] ?? 0) + 1;
			$entry['claimedAt'] = $now;
			$claimed[] = ['entry' => $entry, 'held' => $before];
			$kept[] = $entry;
			$changed = true;
		}
		if ($changed) {
			if ($kept === []) {
				unset($form[ResponseOutbox::KEY]);
			} else {
				$form[ResponseOutbox::KEY] = $kept;
			}
		}
		return $changed;
	}

	/**
	 * Send the claimed entries in order, within this run's time. A webhook an earlier entry of the same response did not
	 * reach is held for the later ones, per receiver: the others still get them.
	 *
	 * @param list<array{entry: array, held: array<string, true>}> $claimed
	 * @return array<string, array{0: list<string>, 1: bool}|null> per entry: what is left and whether anything was tried;
	 *                                                             null when this run did not reach it
	 */
	private function sendClaimed(int $fileId, File $file, array $form, array $claimed): array {
		$responses = [];
		foreach (is_array($form['responses'] ?? null) ? $form['responses'] : [] as $response) {
			if (is_array($response) && isset($response['id'])) {
				$responses[(string)$response['id']] = $response;
			}
		}
		$started = microtime(true);
		$left = [];
		/** @var array<string, array<string, true>> $waits per response: the webhooks an earlier entry of this run did not reach */
		$waits = [];
		foreach ($claimed as ['entry' => $entry, 'held' => $before]) {
			$responseId = (string)($entry['response'] ?? '');
			// Within the effects lock's time, with room for one slow receiver: the rest goes in the next run.
			if ($left !== [] && microtime(true) - $started > $this->runSeconds) {
				$left[$entry['id']] = null;
				$waits[$responseId] = ($waits[$responseId] ?? []) + $this->webhooksOf($form, $entry);
				continue;
			}
			$event = self::eventOf($entry);
			// A deletion says which response is gone, nothing more (PO); the others send it as it is now.
			$response = $event === ResponseOutbox::DELETED ? ['id' => $responseId] : ($responses[$responseId] ?? null);
			if ($response === null) {
				$left[$entry['id']] = [[], true];
				continue;
			}
			$result = $this->send($fileId, $file, $form, $entry, $event, $response, $before + ($waits[$responseId] ?? []));
			$left[$entry['id']] = $result;
			$waits[$responseId] = ($waits[$responseId] ?? []) + $this->webhooksOf($form, ['todo' => $result[0]] + $entry);
		}
		return $left;
	}

	/** @return array<string, true> the webhooks an entry has yet to reach */
	private function webhooksOf(array $form, array $entry): array {
		$ids = [];
		foreach (is_array($entry['todo'] ?? null) ? $entry['todo'] : [] as $effect) {
			$effect = (string)$effect;
			if ($effect === ResponseOutbox::WEBHOOKS) {
				foreach (ResponseOutbox::listeningWebhooks($form, self::eventOf($entry)) as $webhookId) {
					$ids[$webhookId] = true;
				}
			} elseif (str_starts_with($effect, ResponseOutbox::WEBHOOK)) {
				$ids[substr($effect, strlen(ResponseOutbox::WEBHOOK))] = true;
			}
		}
		return $ids;
	}

	/** Whether all an entry has to do waits behind an earlier entry of its response: held webhooks and their PDF only. */
	private function allHeld(array $form, array $entry, array $held): bool {
		if ($held === []) {
			return false;
		}
		foreach (is_array($entry['todo'] ?? null) ? $entry['todo'] : [] as $effect) {
			$effect = (string)$effect;
			// The PDF is for the webhooks: it waits with them.
			if ($effect !== ResponseOutbox::PDF && $effect !== ResponseOutbox::WEBHOOKS && !str_starts_with($effect, ResponseOutbox::WEBHOOK)) {
				return false;
			}
		}
		$ids = $this->webhooksOf($form, $entry);
		return $ids !== [] && array_diff_key($ids, $held) === [];
	}

	private static function eventOf(array $entry): string {
		return is_string($entry['event'] ?? null) ? $entry['event'] : ResponseOutbox::CREATED;
	}

	/** @return array<string, true> the ids of the responses the form holds */
	private static function responseIds(array $form): array {
		$ids = [];
		foreach (is_array($form['responses'] ?? null) ? $form['responses'] : [] as $response) {
			if (is_array($response) && isset($response['id'])) {
				$ids[(string)$response['id']] = true;
			}
		}
		return $ids;
	}

	/** An entry for a response that is deleted, other than its deletion. */
	private static function isGone(array $entry, array $present): bool {
		return self::eventOf($entry) !== ResponseOutbox::DELETED && !isset($present[(string)($entry['response'] ?? '')]);
	}

	private function isDue(array $entry, int $now): bool {
		$claimedAt = (int)($entry['claimedAt'] ?? 0);
		return (int)($entry['notBefore'] ?? 0) <= $now && ($claimedAt === 0 || $claimedAt + self::CLAIM_SECONDS <= $now);
	}

	/**
	 * Back when the first waiting entry is due: at once for one that came in or was not reached, otherwise not before
	 * its retry or its claim runs out. An entry held behind an earlier one for its response is due no sooner than that
	 * one, so a held entry does not bring the job back every cron run. Nothing waiting: this run's backstop finds an
	 * empty outbox and only reads.
	 */
	private function scheduleNext(int $fileId, array $outbox): void {
		$now = time();
		$next = null;
		/** @var array<string, int> $after per response: when its earliest waiting entry is due */
		$after = [];
		foreach ($outbox as $entry) {
			if (!is_array($entry) || !is_string($entry['id'] ?? null)) {
				continue;
			}
			$responseId = (string)($entry['response'] ?? '');
			$claimedAt = (int)($entry['claimedAt'] ?? 0);
			$due = max((int)($entry['notBefore'] ?? 0), $claimedAt === 0 ? 0 : $claimedAt + self::CLAIM_SECONDS, $after[$responseId] ?? 0);
			$after[$responseId] = $due;
			$next = $next === null ? $due : min($next, $due);
		}
		if ($next === null) {
			return;
		}
		if ($next <= $now) {
			$this->outbox->schedule($fileId);
		} else {
			$this->outbox->scheduleAfter($fileId, $next - $now);
		}
	}

	/**
	 * @param array<string, true> $held webhooks an earlier event of this response has yet to reach: not before it
	 * @return array{0: list<string>, 1: bool} what is still to do (a webhook that failed or waits, a confirmation email
	 *                                         that could not be sent), and whether anything was tried
	 */
	private function send(int $fileId, File $file, array $form, array $entry, string $event, array $response, array $held): array {
		$entryId = (string)$entry['id'];
		$left = [];
		$tried = false;
		// The PDF waits, without counting a try, while what it is for waits.
		$pdfWaits = $this->allHeld($form, $entry, $held);
		$language = (string)($entry['language'] ?? '');
		// As the form is set now: switched off since the submission, no PDF is made for what still waits.
		$keepsPdf = \OCA\FormVox\Pdf\ResponsePdf::keptFor($form);
		// What waited this long for a PDF goes without it: the response itself must arrive (PO).
		$waitedOut = (int)($entry['attempts'] ?? 0) > self::PDF_TRIES;
		$withoutPdf = !$keepsPdf || $waitedOut;
		/** @var array{url: string, sha256: string, size: int, filename: string, status: string, generated_at: string}|null|false $pdfBlock false: not asked for yet */
		$pdfBlock = false;
		foreach (is_array($entry['todo'] ?? null) ? $entry['todo'] : [] as $effect) {
			$effect = (string)$effect;
			if ($effect === ResponseOutbox::PDF && !$keepsPdf) {
				continue;
			}
			if ($effect === ResponseOutbox::PDF && $pdfWaits) {
				$left[] = ResponseOutbox::PDF;
			} elseif ($effect === ResponseOutbox::PDF) {
				$tried = true;
				try {
					$this->pdfs->of($file, $form, $response, (string)($entry['language'] ?? ''));
				} catch (\Throwable $e) {
					$this->logger->warning('FormVox: the PDF of ' . $entryId . ' of form ' . $fileId . ' could not be made: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
					$left[] = ResponseOutbox::PDF;
				}
			} elseif ($effect === ResponseOutbox::WEBHOOKS || str_starts_with($effect, ResponseOutbox::WEBHOOK)) {
				$ids = $effect === ResponseOutbox::WEBHOOKS ? ResponseOutbox::listeningWebhooks($form, $event) : [substr($effect, strlen(ResponseOutbox::WEBHOOK))];
				foreach ($ids as $webhookId) {
					if (isset($held[$webhookId])) {
						$left[] = ResponseOutbox::WEBHOOK . $webhookId;
						continue;
					}
					$tried = true;
					// One webhook that breaks holds back neither the others nor the rest of the run.
					try {
						if ($this->wasSent($fileId, $entryId, ResponseOutbox::WEBHOOK . $webhookId)) {
							continue;
						}
						if ($event !== ResponseOutbox::DELETED && $keepsPdf && $pdfBlock === false) {
							$pdfBlock = $this->pdfBlock($fileId, $file, $form, $response, $language);
						}
						if ($pdfBlock === null && !$withoutPdf) {
							// A webhook goes without the PDF it names only once that has been waited for.
							$left[] = ResponseOutbox::WEBHOOK . $webhookId;
							continue;
						}
						if ($pdfBlock === null && $waitedOut) {
							$this->logger->warning('FormVox: webhook ' . $webhookId . ' of ' . $entryId . ' of form ' . $fileId . ' goes without its PDF after ' . self::PDF_TRIES . ' tries', ['app' => Application::APP_ID]);
						}
						if ($this->webhooks->trigger($fileId, $form, $event, $response, [$webhookId], $entryId, is_array($pdfBlock) ? $pdfBlock : null) === []) {
							$this->markSent($fileId, $entryId, ResponseOutbox::WEBHOOK . $webhookId);
						} else {
							$left[] = ResponseOutbox::WEBHOOK . $webhookId;
						}
					} catch (\Throwable $e) {
						$this->failed($fileId, $entryId, 'webhook ' . $webhookId, $e);
						$left[] = ResponseOutbox::WEBHOOK . $webhookId;
					}
				}
			} elseif ($effect === ResponseOutbox::NOTIFY) {
				$tried = true;
				try {
					if (!$this->wasSent($fileId, $entryId, $effect)) {
						if (!$this->notifications->notifyNewResponse($fileId, $form, $response)) {
							$left[] = ResponseOutbox::NOTIFY;
							continue;
						}
						$this->markSent($fileId, $entryId, $effect);
					}
				} catch (\Throwable $e) {
					$this->failed($fileId, $entryId, 'notification', $e);
					$left[] = ResponseOutbox::NOTIFY;
				}
			} elseif ($effect === ResponseOutbox::MAIL) {
				$tried = true;
				try {
					if ($this->wasSent($fileId, $entryId, $effect)) {
						continue;
					}
					$answers = is_array($response['answers'] ?? null) ? $response['answers'] : [];
					if ($waitedOut && \OCA\FormVox\Pdf\ResponsePdf::attachedFor($form)) {
						$this->logger->warning('FormVox: the confirmation email of ' . $entryId . ' of form ' . $fileId . ' goes without its PDF after ' . self::PDF_TRIES . ' tries', ['app' => Application::APP_ID]);
					}
					$pdf = $withoutPdf ? null : fn (): string => $this->pdfs->of($file, $form, $response, $language);
					if ($this->mail->send($form, $answers, $language, $pdf, self::accountOf($entry, $response), $fileId, $this->pdfs->fileName($form, $response))) {
						$this->markSent($fileId, $entryId, $effect);
					} else {
						$left[] = ResponseOutbox::MAIL;
					}
				} catch (\Throwable $e) {
					$this->failed($fileId, $entryId, 'confirmation email', $e);
					$left[] = ResponseOutbox::MAIL;
				}
			}
		}
		return [$left, $tried];
	}

	/**
	 * The account of a signed-in respondent, whose address the confirmation goes to: as the entry carries it, else as
	 * the response names them; '' when signed in but not known, so nothing goes to a typed address.
	 */
	private static function accountOf(array $entry, array $response): ?string {
		if (empty($entry['signedIn'])) {
			return null;
		}
		$account = $entry['account'] ?? null;
		if (!is_string($account) || $account === '') {
			$respondent = is_array($response['respondent'] ?? null) ? $response['respondent'] : [];
			$account = ($respondent['type'] ?? null) === 'user' && is_string($respondent['user_id'] ?? null) ? $respondent['user_id'] : '';
		}
		return $account;
	}

	/** Tried again like any failed effect. */
	private function failed(int $fileId, string $entryId, string $what, \Throwable $e): void {
		$this->logger->warning('FormVox: the ' . $what . ' of ' . $entryId . ' of form ' . $fileId . ' failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
	}

	/**
	 * @return array{url: string, sha256: string, size: int, filename: string, status: string, generated_at: string}|null null when it cannot be had now
	 */
	private function pdfBlock(int $fileId, File $file, array $form, array $response, string $language): ?array {
		try {
			$pdf = $this->pdfs->keptOf($file, $form, $response, $language);
			// When the kept file was written: the one the API hands out.
			$madeAt = $pdf === null ? null : $this->pdfs->stored($file, $response)?->getMTime();
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: the PDF a webhook of form ' . $fileId . ' names could not be made: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return null;
		}
		if ($pdf === null) {
			$this->logger->warning('FormVox: the PDF a webhook of form ' . $fileId . ' names could not be kept; the webhook waits for it', ['app' => Application::APP_ID]);
			return null;
		}
		return [
			'url' => $this->urls->linkToRouteAbsolute(Application::APP_ID . '.external_api.getResponsePdf', ['fileId' => $fileId, 'responseId' => (string)($response['id'] ?? '')]),
			'sha256' => hash('sha256', $pdf),
			'size' => strlen($pdf),
			'filename' => $this->pdfs->fileName($form, $response),
			'status' => 'stored',
			'generated_at' => (new \DateTime())->setTimestamp($madeAt ?? time())->format(\DateTime::ATOM),
		];
	}

	private function wasSent(int $fileId, string $entryId, string $effect): bool {
		return $this->sentCache()->get($fileId . '/' . $entryId . '/' . $effect) !== null;
	}

	private function markSent(int $fileId, string $entryId, string $effect): void {
		$this->sentCache()->set($fileId . '/' . $entryId . '/' . $effect, 1, self::SENT_SECONDS);
	}

	/** Shared between servers where there is a distributed cache; without one, a failed crossing off may send twice. */
	private function sentCache(): ICache {
		return $this->sent ??= $this->cacheFactory->createDistributed('formvox_sent');
	}
}
