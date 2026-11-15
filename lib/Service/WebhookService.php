<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Security\FormSecrets;
use OCP\Http\Client\IClientService;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class WebhookService {
	public function __construct(
		private IClientService $clientService,
		private ISecureRandom $secureRandom,
		private LoggerInterface $logger,
		private FormSecrets $secrets,
		private AccountAccess $access,
		private FormSanitizer $sanitizer,
	) {
	}

	/**
	 * Generate a new webhook secret
	 */
	public function generateSecret(): string {
		return 'whsec_' . $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
	}

	/**
	 * A webhook delivers to http or https only: no file:, gopher: or other schemes.
	 */
	public static function isAllowedUrl(string $url): bool {
		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		return in_array($scheme, ['http', 'https'], true) && (string)parse_url($url, PHP_URL_HOST) !== '';
	}

	/**
	 * The URL as it may appear in a log: no credentials, query or fragment, which often carry a token.
	 */
	public static function describeUrl(string $url): string {
		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['host'])) {
			return '(invalid url)';
		}
		return ($parts['scheme'] ?? '') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
	}

	/**
	 * Generate a webhook ID
	 */
	public function generateId(): string {
		return 'wh_' . $this->secureRandom->generate(8, ISecureRandom::CHAR_ALPHANUMERIC);
	}

	/**
	 * Send an event to the form's webhooks that listen for it.
	 *
	 * @param list<string>|null $only just these webhooks, by id: the ones that failed before
	 * @param string|null $eventId the same on every try of one event (X-FormVox-Event-Id)
	 * @param array{url: string, sha256: string, size: int, filename: string, status: string, generated_at: string}|null $pdf where the response's PDF is fetched with an API key
	 * @return list<string> the ids of webhooks that could not be reached, to try again
	 */
	public function trigger(int $fileId, array $form, string $event, array $data, ?array $only = null, ?string $eventId = null, ?array $pdf = null): array {
		$webhooks = $form['settings']['webhooks'] ?? [];
		$failed = [];

		foreach (is_array($webhooks) ? $webhooks : [] as $webhook) {
			if (!is_array($webhook) || !$this->shouldTrigger($webhook, $event)) {
				continue;
			}
			if ($only !== null && !in_array($webhook['id'] ?? null, $only, true)) {
				continue;
			}
			// Sends responses out, so only while its creator may still take them out of Nextcloud, as with an export (M29).
			if ($this->access->fileFor($webhook['created_by'] ?? null, $fileId, FormPermission::DOWNLOAD) === null) {
				$this->logger->info('FormVox webhook skipped: its creator may no longer download the form', ['fileId' => $fileId, 'webhook' => $webhook['id'] ?? '']);
				continue;
			}

			if (!$this->send($fileId, $webhook, $event, $data, $form, $eventId ?? (($data['id'] ?? '') . ':' . $event), $pdf)) {
				$failed[] = (string)($webhook['id'] ?? '');
			}
		}
		return $failed;
	}

	/**
	 * Check if webhook should be triggered for this event
	 */
	private function shouldTrigger(array $webhook, string $event): bool {
		if (!($webhook['enabled'] ?? true)) {
			return false;
		}

		$events = $webhook['events'] ?? [];

		// Empty events = all events
		if (empty($events)) {
			return true;
		}

		return in_array($event, $events, true);
	}

	/**
	 * @return bool false when the receiver could not be reached or refused it; a webhook that may not send is no failure
	 */
	private function send(int $fileId, array $webhook, string $event, array $data, array $form, string $eventId, ?array $pdf = null): bool {
		$url = (string)($webhook['url'] ?? '');
		if (!self::isAllowedUrl($url)) {
			$this->logger->warning('FormVox webhook skipped: its URL is not an http or https address', ['webhook' => $webhook['id'] ?? '', 'event' => $event]);
			return true;
		}
		$where = self::describeUrl($url);

		$payload = [
			// Signed with the rest, so a replayed body cannot pass as another event (review of the 3a fixes).
			'id' => $eventId,
			// The same, under the name integrations know it by.
			'event_id' => $eventId,
			'event' => $event,
			'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
			// The id the external API knows the form by, so a receiver can fetch it or tell forms apart.
			'form' => [
				'id' => $fileId,
				'fileId' => $fileId,
				'title' => $form['title'] ?? '',
			],
			'data' => $this->sanitizer->response($data, $form),
		];
		// A link, never the PDF itself: fetching it takes an API key. Null says the PDF could not be kept (PO), or
		// that there is none to name, as for a deletion.
		$payload['pdf'] = $pdf;

		$jsonPayload = json_encode($payload);

		$secret = $this->secrets->open((string)($webhook['secret'] ?? ''));
		if ($secret === '') {
			// Never unsigned: without a secret the receiver cannot tell it came from this form.
			$this->logger->warning('FormVox webhook skipped: it has no secret that can be used', ['url' => $where, 'event' => $event]);
			return true;
		}
		$timestamp = time();

		$headers = [
			'Content-Type' => 'application/json',
			'User-Agent' => 'FormVox-Webhook/1.0',
			'X-FormVox-Event' => $event,
			// The same on every try, so a receiver can tell a repeat: sent at least once.
			'X-FormVox-Event-Id' => $eventId,
			'X-FormVox-Signature' => $this->calculateSignature($jsonPayload, $secret, $timestamp),
			'X-FormVox-Timestamp' => (string)$timestamp,
		];

		try {
			$client = $this->clientService->newClient();
			$client->post($url, [
				'headers' => $headers,
				'body' => $jsonPayload,
				'timeout' => 10,
				'connect_timeout' => 5,
			]);

			$this->logger->info('FormVox webhook sent', [
				'url' => $where,
				'event' => $event,
			]);
			return true;
		} catch (\Exception $e) {
			$this->logger->error('FormVox webhook failed', [
				'url' => $where,
				'event' => $event,
				'error' => $e->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Calculate HMAC signature for webhook payload
	 */
	public function calculateSignature(string $payload, string $secret, ?int $timestamp = null): string {
		if (empty($secret)) {
			return '';
		}

		// The same timestamp as the X-FormVox-Timestamp header, or verification fails across a second boundary.
		$signedPayload = ($timestamp ?? time()) . '.' . $payload;

		return 'v1=' . hash_hmac('sha256', $signedPayload, $secret);
	}

	/**
	 * Verify webhook signature (for testing/documentation)
	 */
	public function verifySignature(string $payload, string $signature, string $secret, int $timestamp): bool {
		if ($secret === '') {
			return false;
		}

		$signedPayload = $timestamp . '.' . $payload;
		$expectedSignature = 'v1=' . hash_hmac('sha256', $signedPayload, $secret);

		return hash_equals($expectedSignature, $signature);
	}

	/**
	 * Available webhook events
	 */
	public static function getAvailableEvents(): array {
		return [
			'response.created' => 'When a new response is submitted',
			'response.updated' => 'When a response is updated',
			'response.deleted' => 'When a response is deleted',
		];
	}
}
