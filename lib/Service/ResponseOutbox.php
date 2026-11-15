<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\BackgroundJob\SideEffectsJob;
use OCA\FormVox\Pdf\ResponsePdf;
use OCP\BackgroundJob\IJobList;

/**
 * What an event on a response still has to set off: webhooks, notifications, the confirmation email. Stored with the
 * response in one transaction (OutboxStore), so nothing is lost between storing and sending; SideEffectsJob sends it
 * after the request, in order, at least once (7a, 3a-4). A form still in its file keeps it there until it moves in.
 */
class ResponseOutbox {
	public const KEY = '_outbox';
	public const WEBHOOKS = 'webhooks';
	public const NOTIFY = 'notify';
	public const MAIL = 'mail';
	/** The response's PDF, made first on a form that keeps one, for the confirmation email and the webhooks that name it. */
	public const PDF = 'pdf';
	/** One webhook still to send, after the others went out. */
	public const WEBHOOK = 'webhook:';
	public const CREATED = 'response.created';
	public const UPDATED = 'response.updated';
	public const DELETED = 'response.deleted';

	public function __construct(
		private IJobList $jobList,
	) {
	}

	/**
	 * Put an event's effects in the form's outbox, inside the write that stores the event; only those the form has.
	 *
	 * @param list<string> $effects what the caller sets off: webhooks, notify, mail
	 * @param string $language what the respondent filled the form in, for the confirmation email
	 * @return bool whether there is anything to send
	 */
	/**
	 * @param bool $signedIn whether the respondent was signed in: only then the confirmation carries their PDF by default
	 * @param string|null $account the signed-in respondent's account, whose address the confirmation goes to
	 */
	public static function add(array &$form, array $response, array $effects, string $language, string $event = self::CREATED, bool $signedIn = false, ?string $account = null): bool {
		// An import sets nothing off, so it gets no PDF either: it brings history, not submissions.
		$submitted = $effects !== [];
		$effects = array_values(array_intersect($effects, self::effectsFor($form, $event)));
		if (ResponsePdf::keptFor($form) && (($event === self::CREATED && $submitted) || ($event === self::UPDATED && in_array(self::WEBHOOKS, $effects, true)))) {
			array_unshift($effects, self::PDF);
		}
		if ($effects === []) {
			return false;
		}
		$responseId = (string)$response['id'];
		$entry = [
			// Also the webhook's event id: the same on every try, different for every update (at least once).
			'id' => $responseId . ':' . $event . ($event === self::UPDATED ? ':' . bin2hex(random_bytes(4)) : ''),
			'response' => $responseId,
			'event' => $event,
			'todo' => $effects,
		];
		// Only what an entry needs: deleting all responses adds one per response (review of the 3a fixes).
		if ($language !== '') {
			$entry['language'] = $language;
		}
		if ($signedIn || ($account !== null && $account !== '')) {
			$entry['signedIn'] = true;
		}
		if ($account !== null && $account !== '') {
			$entry['account'] = $account;
		}
		if (!is_array($form[self::KEY] ?? null)) {
			$form[self::KEY] = [];
		}
		$form[self::KEY][] = $entry;
		return true;
	}

	/**
	 * What this form sets off for an event: a webhook that listens for it, someone to tell, a confirmation to send.
	 *
	 * @return list<string>
	 */
	public static function effectsFor(array $form, string $event): array {
		$settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
		$effects = [];
		if (self::listeningWebhooks($form, $event) !== []) {
			$effects[] = self::WEBHOOKS;
		}
		if ($event === self::CREATED) {
			if (($settings['notify_owner'] ?? true) !== false || !empty($settings['notify_recipients'])) {
				$effects[] = self::NOTIFY;
			}
			if (!empty($settings['sendConfirmationEmail'])) {
				$effects[] = self::MAIL;
			}
		}
		return $effects;
	}

	/**
	 * The ids of the enabled webhooks that listen for an event (no events chosen: all of them).
	 *
	 * @return list<string>
	 */
	public static function listeningWebhooks(array $form, string $event): array {
		$ids = [];
		$webhooks = $form['settings']['webhooks'] ?? [];
		foreach (is_array($webhooks) ? $webhooks : [] as $webhook) {
			if (!is_array($webhook) || !($webhook['enabled'] ?? true)) {
				continue;
			}
			$events = $webhook['events'] ?? [];
			if (empty($events) || (is_array($events) && in_array($event, $events, true))) {
				$ids[] = (string)($webhook['id'] ?? '');
			}
		}
		return $ids;
	}

	/** Whether anything still waits for this response. */
	public static function waitsFor(array $form, string $responseId): bool {
		foreach (is_array($form[self::KEY] ?? null) ? $form[self::KEY] : [] as $entry) {
			if (is_array($entry) && ($entry['response'] ?? null) === $responseId) {
				return true;
			}
		}
		return false;
	}

	/** One job per form however many events wait: Nextcloud keeps one per argument. */
	public function schedule(int $fileId): void {
		$this->jobList->add(SideEffectsJob::class, ['fileId' => $fileId]);
	}

	/** Again in a while, not in the same cron run: what failed gets time to come back. */
	public function scheduleAfter(int $fileId, int $seconds): void {
		$this->jobList->scheduleAfter(SideEffectsJob::class, time() + $seconds, ['fileId' => $fileId]);
	}
}
