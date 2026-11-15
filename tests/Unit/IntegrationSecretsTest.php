<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\AccountAccess;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\WebhookService;
use OCA\FormVox\Tests\Unit\Support\ReversibleCrypto;
use OCP\Files\File;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * API keys and webhooks keep working once their secrets are stored encrypted, and with values from before that.
 */
class IntegrationSecretsTest extends TestCase {
	private function apiKeys(): ApiKeyService {
		return new ApiKeyService($this->createMock(ISecureRandom::class), ReversibleCrypto::secrets());
	}

	public function testAnApiKeyMatchesItsEncryptedHash(): void {
		$keys = [['id' => 'k1', 'hash' => ReversibleCrypto::secrets()->seal(password_hash('fvx_right', PASSWORD_BCRYPT))]];

		$this->assertSame('k1', $this->apiKeys()->findValidKey('fvx_right', $keys)['id'] ?? null);
		$this->assertNull($this->apiKeys()->findValidKey('fvx_wrong', $keys));
	}

	public function testAnApiKeyFromBeforeEncryptionStillWorks(): void {
		$keys = [['id' => 'k1', 'hash' => password_hash('fvx_right', PASSWORD_BCRYPT)]];

		$this->assertSame('k1', $this->apiKeys()->findValidKey('fvx_right', $keys)['id'] ?? null);
	}

	public function testTheClientPlaceholderIsNeverAKey(): void {
		$this->assertNull($this->apiKeys()->findValidKey('[hidden]', [['id' => 'k1', 'hash' => '[hidden]']]));
	}

	/**
	 * @return array{0: WebhookService, 1: \ArrayObject}
	 */
	private function webhooks(?LoggerInterface $logger = null): array {
		$sent = new \ArrayObject();
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options) use ($sent) {
			if (str_contains($url, 'down.example.com')) {
				throw new \RuntimeException('cURL error 7: connection refused');
			}
			$sent[] = $options + ['url' => $url];
			return $this->createMock(\OCP\Http\Client\IResponse::class);
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$access = $this->createMock(AccountAccess::class);
		// "reader" may open the form but not download it: as much as a webhook needs, since it sends the responses out.
		$access->method('fileFor')->willReturnCallback(function (?string $uid, int $fileId, string $permission) {
			if (in_array($uid, [null, 'gone'], true) || ($uid === 'reader' && $permission === FormPermission::DOWNLOAD)) {
				return null;
			}
			return $this->createMock(File::class);
		});
		$service = new WebhookService($clients, $this->createMock(ISecureRandom::class), $logger ?? $this->createMock(LoggerInterface::class), ReversibleCrypto::secrets(), $access, new FormSanitizer());
		return [$service, $sent];
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function storedWebhookSecrets(): array {
		return [
			'encrypted' => [ReversibleCrypto::secrets()->seal('whsec_plain')],
			'from before encryption' => ['whsec_plain'],
		];
	}

	/**
	 * @dataProvider storedWebhookSecrets
	 */
	public function testAWebhookIsSignedWithTheDecryptedSecret(string $stored): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['title' => 'T', 'settings' => ['webhooks' => [['url' => 'https://example.com/hook', 'secret' => $stored, 'created_by' => 'anna']]]], 'response.created', ['id' => 'r1']);

		$this->assertCount(1, $sent);
		$headers = $sent[0]['headers'];
		$this->assertTrue($service->verifySignature($sent[0]['body'], $headers['X-FormVox-Signature'], 'whsec_plain', (int)$headers['X-FormVox-Timestamp']));
	}

	public function testAWebhookWhoseSecretCannotBeDecryptedIsNotSentUnsigned(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');
		[$service, $sent] = $this->webhooks($logger);

		$service->trigger(1, ['settings' => ['webhooks' => [['url' => 'https://example.com/hook', 'secret' => 'enc:v1:foreign', 'created_by' => 'anna']]]], 'response.created', []);

		$this->assertCount(0, $sent);
	}

	/** A webhook sends responses out, so it stops when its creator can no longer read the form (M29). */
	public function testAWebhookWhoseCreatorLostAccessIsNotSent(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['settings' => ['webhooks' => [
			['id' => 'w1', 'url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'gone'],
			['id' => 'w2', 'url' => 'https://example.com/b', 'secret' => 'whsec'],
			['id' => 'w3', 'url' => 'https://example.com/c', 'secret' => 'whsec', 'created_by' => 'anna'],
		]]], 'response.created', []);

		$this->assertCount(1, $sent, 'only the webhook whose creator still has access');
	}

	/** Like an export: a creator whose share lost its download permission may no longer have the responses sent out. */
	public function testAWebhookWhoseCreatorMayNotDownloadIsNotSent(): void {
		[$service, $sent] = $this->webhooks();

		$failed = $service->trigger(1, ['settings' => ['webhooks' => [
			['id' => 'w1', 'url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'reader'],
			['id' => 'w2', 'url' => 'https://example.com/b', 'secret' => 'whsec', 'created_by' => 'anna'],
		]]], 'response.deleted', ['id' => 'r1']);

		$this->assertSame(['https://example.com/b'], array_column((array)$sent, 'url'));
		$this->assertSame([], $failed, 'not to be tried again either');
	}

	/** Never unsigned: without a secret the receiver cannot tell it came from this form. */
	public function testAWebhookWithoutASecretIsNotSent(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['settings' => ['webhooks' => [
			['id' => 'w1', 'url' => 'https://example.com/a', 'created_by' => 'anna'],
			['id' => 'w2', 'url' => 'https://example.com/b', 'secret' => '', 'created_by' => 'anna'],
		]]], 'response.created', []);

		$this->assertCount(0, $sent);
		$this->assertFalse($service->verifySignature('{}', '', '', time()), 'no secret verifies nothing');
	}

	public function testAWebhookWithAStoredUrlThatIsNotHttpIsNotSent(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['settings' => ['webhooks' => [['id' => 'w1', 'url' => 'file:///etc/passwd', 'secret' => 'whsec', 'created_by' => 'anna']]]], 'response.created', []);

		$this->assertCount(0, $sent);
	}

	/** A query string or credentials in the URL often carry a token; the log shows where, not that. */
	public function testTheLogNamesTheReceiverWithoutItsToken(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message, array $context) use (&$logged) {
			$logged[] = $context['url'] ?? null;
		});
		[$service] = $this->webhooks($logger);

		$service->trigger(1, ['settings' => ['webhooks' => [['url' => 'https://user:pass@hooks.example.com:8443/in?token=s3cret#x', 'secret' => 'whsec', 'created_by' => 'anna']]]], 'response.created', []);

		$this->assertSame(['https://hooks.example.com:8443/in'], $logged);
	}

	public function testAWebhookDoesNotSendTheFingerprint(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['settings' => ['webhooks' => [['url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna']]]], 'response.created',
			['id' => 'r1', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'v2:abc']]);

		$this->assertStringNotContainsString('v2:abc', $sent[0]['body']);
	}

	/**
	 * Sent from a background job, at least once: a receiver that cannot be reached is reported, to try again; one that
	 * may not be sent to (no secret, creator gone) is no failure (7a, 3a-4).
	 */
	public function testAWebhookThatCannotBeReachedIsReportedForAnotherTry(): void {
		[$service, $sent] = $this->webhooks();

		$failed = $service->trigger(1, ['settings' => ['webhooks' => [
			['id' => 'w1', 'url' => 'https://down.example.com/a', 'secret' => 'whsec', 'created_by' => 'anna'],
			['id' => 'w2', 'url' => 'https://example.com/b', 'secret' => 'whsec', 'created_by' => 'anna'],
			['id' => 'w3', 'url' => 'https://example.com/c', 'created_by' => 'anna'],
			['id' => 'w4', 'url' => 'https://example.com/d', 'secret' => 'whsec', 'created_by' => 'gone'],
		]]], 'response.created', ['id' => 'r1']);

		$this->assertSame(['w1'], $failed);
		$this->assertCount(1, $sent);
	}

	public function testATryAgainGoesOnlyToTheWebhooksThatFailed(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['settings' => ['webhooks' => [
			['id' => 'w1', 'url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna'],
			['id' => 'w2', 'url' => 'https://example.com/b', 'secret' => 'whsec', 'created_by' => 'anna'],
		]]], 'response.created', ['id' => 'r1'], ['w2']);

		$this->assertSame(['https://example.com/b'], array_column((array)$sent, 'url'));
	}

	/** The same event id on every try, so a receiver can tell a repeat. */
	public function testEveryTryCarriesTheSameEventId(): void {
		[$service, $sent] = $this->webhooks();
		$form = ['settings' => ['webhooks' => [['id' => 'w1', 'url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna']]]];

		$service->trigger(1, $form, 'response.created', ['id' => 'r1']);
		$service->trigger(1, $form, 'response.created', ['id' => 'r1']);

		$this->assertSame(['r1:response.created', 'r1:response.created'], array_map(fn (array $o) => $o['headers']['X-FormVox-Event-Id'], (array)$sent));
	}

	/** The job gives every event its own id; a receiver tells a repeat from the next update by it (review 3a-4). */
	public function testTheEventIdTheJobGivesIsSent(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['settings' => ['webhooks' => [['id' => 'w1', 'url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna']]]], 'response.updated', ['id' => 'r1'], ['w1'], 'r1:response.updated:0a1b2c3d');

		$this->assertSame('r1:response.updated:0a1b2c3d', $sent[0]['headers']['X-FormVox-Event-Id']);
	}

	/** The event id is in the signed body too: a replayed body cannot pass as another event (review of the 3a fixes). */
	public function testTheEventIdIsSigned(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['settings' => ['webhooks' => [['id' => 'w1', 'url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna']]]], 'response.updated', ['id' => 'r1'], ['w1'], 'r1:response.updated:0a1b2c3d');

		$this->assertSame('r1:response.updated:0a1b2c3d', json_decode($sent[0]['body'], true)['id']);
		$this->assertTrue($service->verifySignature($sent[0]['body'], $sent[0]['headers']['X-FormVox-Signature'], 'whsec', (int)$sent[0]['headers']['X-FormVox-Timestamp']));
	}

	/** The form by the id the external API knows it by, so a receiver can fetch it or tell its forms apart. */
	public function testAWebhookNamesItsFormByItsFileId(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(42, ['title' => 'Intake', 'settings' => ['webhooks' => [['url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna']]]], 'response.created', ['id' => 'r1']);

		$this->assertSame(['id' => 42, 'fileId' => 42, 'title' => 'Intake'], json_decode($sent[0]['body'], true)['form']);
	}

	/** The event id under both names, the same as the header, so an integration finds it by the name it knows. */
	public function testTheEventIdIsSentUnderBothNames(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['settings' => ['webhooks' => [['id' => 'w1', 'url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna']]]], 'response.updated', ['id' => 'r1'], ['w1'], 'r1:response.updated:0a1b2c3d');

		$payload = json_decode($sent[0]['body'], true);
		$this->assertSame('r1:response.updated:0a1b2c3d', $payload['event_id']);
		$this->assertSame([$payload['id'], $sent[0]['headers']['X-FormVox-Event-Id']], [$payload['event_id'], $payload['event_id']]);
	}

	/**
	 * A receiver is told which answers the respondent was shown: a question hidden by a condition and one on a page
	 * routing skipped are not applicable; `answers` itself stays as it was stored.
	 */
	public function testAWebhookSaysWhichAnswersApplied(): void {
		[$service, $sent] = $this->webhooks();
		$form = [
			'questions' => [
				['id' => 'q1', 'type' => 'choice', 'options' => [['value' => 'yes'], ['value' => 'skip']]],
				['id' => 'q2', 'type' => 'text', 'showIf' => ['questionId' => 'q1', 'operator' => 'equals', 'value' => 'yes']],
				['id' => 'q3', 'type' => 'text'],
				['id' => 'q4', 'type' => 'text'],
			],
			'pages' => [
				['id' => 'p1', 'questions' => ['q1', 'q2'], 'routing' => [['questionId' => 'q1', 'operator' => 'equals', 'value' => 'skip', 'targetPageId' => 'p3']]],
				['id' => 'p2', 'questions' => ['q3']],
				['id' => 'p3', 'questions' => ['q4']],
			],
			'settings' => ['webhooks' => [['url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna']]],
		];
		$answers = ['q1' => 'skip', 'q2' => 'typed before changing q1', 'q3' => 'typed before going back', 'q4' => 'z'];

		$service->trigger(1, $form, 'response.created', ['id' => 'r1', 'answers' => $answers]);

		$data = json_decode($sent[0]['body'], true)['data'];
		$this->assertSame($answers, $data['answers'], 'answers stay as stored');
		$this->assertSame(['q1' => 'skip', 'q4' => 'z'], $data['applicable_answers']);
		$this->assertSame(['q2', 'q3'], $data['not_applicable']);
	}

	/** A deletion says which response is gone, nothing more (PO). */
	public function testADeletionCarriesOnlyTheResponsesId(): void {
		[$service, $sent] = $this->webhooks();

		$service->trigger(1, ['questions' => [['id' => 'q1', 'type' => 'text']], 'settings' => ['webhooks' => [['url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna']]]], 'response.deleted', ['id' => 'r1']);

		$payload = json_decode($sent[0]['body'], true);
		$this->assertSame(['id' => 'r1'], $payload['data']);
		$this->assertNull($payload['pdf']);
	}

	/** Where its PDF is, in the signed body; never the PDF itself. */
	public function testAWebhookNamesTheResponsesPdf(): void {
		[$service, $sent] = $this->webhooks();
		$pdf = ['url' => 'https://cloud.example/api/v1/external/forms/1/responses/r1/pdf', 'sha256' => str_repeat('a', 64), 'size' => 1234, 'filename' => 'T_2026-11-14_r1.pdf', 'status' => 'stored', 'generated_at' => '2026-11-14T10:00:00+01:00'];

		$service->trigger(1, ['settings' => ['webhooks' => [['url' => 'https://example.com/a', 'secret' => 'whsec', 'created_by' => 'anna']]]], 'response.created', ['id' => 'r1'], null, null, $pdf);

		$this->assertSame($pdf, json_decode($sent[0]['body'], true)['pdf']);
	}
}
