<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\IntegrationController;
use OCA\FormVox\Security\FormAccessDeniedException;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\WebhookService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Access and error mapping live in FormAccessMiddleware; these pin what the endpoints do once let through. */
class IntegrationControllerTest extends TestCase {
	private IRequest $request;
	private FormRepository $formRepository;
	private ApiKeyService $apiKeyService;
	private WebhookService $webhookService;
	private IUserSession $userSession;
	private FormAccessService $access;
	/** Whether the user may download the form's responses: full rights without it are a folder share with download off. */
	private bool $canDownload = true;
	/** Settings the last mutateSettings() call wrote; null while nothing was written. */
	private ?array $written = null;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->apiKeyService = $this->createMock(ApiKeyService::class);
		$this->webhookService = $this->createMock(WebhookService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->access = $this->createMock(FormAccessService::class);
		$this->access->method('getFile')->willReturn($this->createMock(File::class));
		$this->access->method('allows')->willReturnCallback(fn (File $file, string $permission) => $permission === FormPermission::DOWNLOAD ? $this->canDownload : true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function controller(): IntegrationController {
		return new IntegrationController(
			$this->request,
			$this->formRepository,
			$this->apiKeyService,
			$this->webhookService,
			$this->userSession,
			$this->access
		);
	}

	/** Runs the mutator against $settings as FormRepository::mutateSettings does: null writes nothing. */
	private function givenStoredSettings(array $settings): void {
		$this->formRepository->method('mutateSettings')->willReturnCallback(
			function (int $fileId, callable $mutator) use ($settings): bool {
				$this->assertSame(1, $fileId);
				$this->written = $mutator($settings);
				return $this->written !== null;
			}
		);
	}

	private function givenMutateSettingsFails(string $message): void {
		$this->formRepository->method('mutateSettings')->willThrowException(new \RuntimeException($message));
	}

	// ---- createApiKey() ----------------------------------------------------

	public function testCreateApiKeyLetsExceptionsPropagate(): void {
		$this->apiKeyService->method('generateKey')->willReturn(['id' => 'kid', 'hash' => 'h', 'key' => 'plain']);
		$this->givenMutateSettingsFails('boom');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('boom');
		$this->controller()->createApiKey(1, 'k', []);
	}

	public function testCreateApiKeySuccessReturnsPlainKeyOnceAndPersists(): void {
		$this->apiKeyService->method('generateKey')
			->willReturn(['id' => 'kid1', 'hash' => 'HASH', 'key' => 'PLAINKEY']);
		$this->givenStoredSettings(['api_keys' => [], 'title_color' => 'red']);

		$resp = $this->controller()->createApiKey(1, 'MyKey', ['read_form']);

		$keys = $this->written['api_keys'];
		$this->assertCount(1, $keys);
		$this->assertSame('kid1', $keys[0]['id']);
		$this->assertSame('MyKey', $keys[0]['name']);
		$this->assertSame('HASH', $keys[0]['hash']);
		$this->assertSame(['read_form'], $keys[0]['permissions']);
		$this->assertSame('alice', $keys[0]['created_by']);
		$this->assertArrayHasKey('created_at', $keys[0]);
		$this->assertArrayNotHasKey('key', $keys[0]);
		$this->assertSame('red', $this->written['title_color'], 'other settings stay as stored');

		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame('kid1', $data['id']);
		$this->assertSame('MyKey', $data['name']);
		$this->assertSame('PLAINKEY', $data['key']);
		$this->assertSame(['read_form'], $data['permissions']);
		$this->assertArrayHasKey('message', $data);
	}

	public function testCreateApiKeyAppendsToExistingKeys(): void {
		$this->apiKeyService->method('generateKey')
			->willReturn(['id' => 'kid2', 'hash' => 'H2', 'key' => 'K2']);
		$this->givenStoredSettings(['api_keys' => [['id' => 'existing']]]);

		$resp = $this->controller()->createApiKey(1, 'k', []);

		$this->assertSame(['existing', 'kid2'], array_column($this->written['api_keys'], 'id'));
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
	}

	// ---- deleteApiKey() ----------------------------------------------------

	public function testDeleteApiKeyReturns404WhenKeyMissing(): void {
		$this->givenStoredSettings(['api_keys' => [['id' => 'other']]]);

		$resp = $this->controller()->deleteApiKey(1, 'missing');

		$this->assertNull($this->written, 'nothing is written');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'API key not found'], $resp->getData());
	}

	public function testDeleteApiKeySuccessRemovesKeyAndPersists(): void {
		$this->givenStoredSettings(['api_keys' => [['id' => 'keep'], ['id' => 'gone']]]);

		$resp = $this->controller()->deleteApiKey(1, 'gone');

		$this->assertSame([['id' => 'keep']], $this->written['api_keys']);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testDeleteApiKeyLetsExceptionsPropagate(): void {
		$this->givenMutateSettingsFails('kaboom');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('kaboom');
		$this->controller()->deleteApiKey(1, 'kid');
	}

	// ---- createWebhook() ---------------------------------------------------

	private function stubWebhookParams(string $url, string $name = 'Webhook', $events = []): void {
		$this->request->method('getParam')->willReturnCallback(
			function ($key, $default = null) use ($url, $name, $events) {
				return match ($key) {
					'url' => $url,
					'name' => $name,
					'events' => $events,
					default => $default,
				};
			}
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function urlsAWebhookCannotUse(): array {
		return [
			'not a url' => ['not-a-url'],
			'a file' => ['file:///etc/passwd'],
			'ftp' => ['ftp://example.com/hook'],
			'script' => ['javascript:alert(1)'],
			'gopher' => ['gopher://example.com:70/1'],
		];
	}

	/**
	 * A webhook delivers to http or https only.
	 *
	 * @dataProvider urlsAWebhookCannotUse
	 */
	public function testCreateWebhookRejectsAnythingButHttpOrHttps(string $url): void {
		$this->stubWebhookParams($url);
		$this->formRepository->expects($this->never())->method('mutateSettings');

		$resp = $this->controller()->createWebhook(1);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame(['error' => 'Invalid URL: use an http or https address'], $resp->getData());
	}

	public function testCreateWebhookSuccessReturnsSecretOnceAndPersists(): void {
		$this->stubWebhookParams('https://example.com/hook', 'MyHook', ['response.created']);
		$this->webhookService->method('generateId')->willReturn('wh1');
		$this->webhookService->method('generateSecret')->willReturn('SECRET');
		$this->givenStoredSettings(['webhooks' => []]);

		$resp = $this->controller()->createWebhook(1);

		$hooks = $this->written['webhooks'];
		$this->assertCount(1, $hooks);
		$this->assertSame('wh1', $hooks[0]['id']);
		$this->assertSame('MyHook', $hooks[0]['name']);
		$this->assertSame('https://example.com/hook', $hooks[0]['url']);
		$this->assertSame('SECRET', $hooks[0]['secret']);
		$this->assertSame(['response.created'], $hooks[0]['events']);
		$this->assertTrue($hooks[0]['enabled']);
		$this->assertSame('alice', $hooks[0]['created_by']);
		$this->assertArrayHasKey('created_at', $hooks[0]);

		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame('wh1', $data['id']);
		$this->assertSame('MyHook', $data['name']);
		$this->assertSame('https://example.com/hook', $data['url']);
		$this->assertSame('SECRET', $data['secret']);
		$this->assertSame(['response.created'], $data['events']);
		$this->assertArrayHasKey('message', $data);
	}

	public function testCreateWebhookDefaultsNameWhenEmpty(): void {
		$this->stubWebhookParams('https://example.com/hook', '', []);
		$this->webhookService->method('generateId')->willReturn('wh2');
		$this->webhookService->method('generateSecret')->willReturn('S2');
		$this->givenStoredSettings([]);

		$resp = $this->controller()->createWebhook(1);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$this->assertSame('Webhook', $resp->getData()['name']);
	}

	public function testCreateWebhookCoercesNonArrayEventsToEmpty(): void {
		$this->stubWebhookParams('https://example.com/hook', 'H', 'not-an-array');
		$this->webhookService->method('generateId')->willReturn('wh3');
		$this->webhookService->method('generateSecret')->willReturn('S3');
		$this->givenStoredSettings([]);

		$resp = $this->controller()->createWebhook(1);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$this->assertSame([], $resp->getData()['events']);
	}

	public function testCreateWebhookLetsExceptionsPropagate(): void {
		$this->stubWebhookParams('https://example.com/hook');
		$this->webhookService->method('generateId')->willReturn('wh');
		$this->webhookService->method('generateSecret')->willReturn('s');
		$this->givenMutateSettingsFails('explode');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('explode');
		$this->controller()->createWebhook(1);
	}

	/** A webhook sends every response out, like an export: full rights on a share with download off are not enough. */
	public function testCreateWebhookNeedsTheRightToDownloadTheResponses(): void {
		$this->canDownload = false;
		$this->stubWebhookParams('https://example.com/hook');
		$this->formRepository->expects($this->never())->method('mutateSettings');

		$this->expectException(FormAccessDeniedException::class);
		$this->controller()->createWebhook(1);
	}

	// ---- updateWebhook() ---------------------------------------------------

	/**
	 * @dataProvider urlsAWebhookCannotUse
	 */
	public function testUpdateWebhookRejectsAnythingButHttpOrHttps(string $url): void {
		$this->formRepository->expects($this->never())->method('mutateSettings');

		$resp = $this->controller()->updateWebhook(1, 'wh1', $url);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame(['error' => 'Invalid URL: use an http or https address'], $resp->getData());
	}

	public function testUpdateWebhookReturns404WhenWebhookMissing(): void {
		$this->givenStoredSettings(['webhooks' => [['id' => 'other']]]);

		$resp = $this->controller()->updateWebhook(1, 'missing', null, 'newname');

		$this->assertNull($this->written, 'nothing is written');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'Webhook not found'], $resp->getData());
	}

	public function testUpdateWebhookAppliesOnlyProvidedFieldsAndSetsUpdatedAt(): void {
		$this->givenStoredSettings(['webhooks' => [[
			'id' => 'wh1',
			'name' => 'Old',
			'url' => 'https://old.example.com',
			'secret' => 'enc:v1:sealed',
			'events' => ['response.created'],
			'enabled' => true,
		]]]);

		$resp = $this->controller()->updateWebhook(1, 'wh1', null, 'New', null, false);

		$hook = $this->written['webhooks'][0];
		// name + enabled provided; url, events and secret left untouched.
		$this->assertSame('New', $hook['name']);
		$this->assertFalse($hook['enabled']);
		$this->assertSame('https://old.example.com', $hook['url']);
		$this->assertSame(['response.created'], $hook['events']);
		$this->assertSame('enc:v1:sealed', $hook['secret']);
		$this->assertArrayHasKey('updated_at', $hook);
		$this->assertSame('alice', $hook['updated_by']);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	/**
	 * A new destination is the changer's webhook: it leans on their access and gets a secret only they see (PO).
	 */
	public function testANewUrlMakesTheChangerTheCreatorWithANewSecretShownOnce(): void {
		$this->givenStoredSettings(['webhooks' => [[
			'id' => 'wh1',
			'url' => 'https://anna.example.com/hook',
			'secret' => 'enc:v1:annas',
			'created_by' => 'anna',
			'events' => [],
		]]]);
		$this->webhookService->method('generateSecret')->willReturn('whsec_new');

		$resp = $this->controller()->updateWebhook(1, 'wh1', 'https://alice.example.com/hook', null, ['response.deleted'], null);

		$hook = $this->written['webhooks'][0];
		$this->assertSame('https://alice.example.com/hook', $hook['url']);
		$this->assertSame(['response.deleted'], $hook['events']);
		$this->assertSame('alice', $hook['created_by']);
		$this->assertSame('alice', $hook['updated_by']);
		$this->assertSame('whsec_new', $hook['secret']);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame('whsec_new', $resp->getData()['secret']);
	}

	/** Pointing it elsewhere makes it lean on the changer's access, so they must be allowed to download the responses. */
	public function testANewUrlNeedsTheRightToDownloadTheResponses(): void {
		$this->canDownload = false;
		$this->givenStoredSettings(['webhooks' => [['id' => 'wh1', 'url' => 'https://anna.example.com/hook', 'secret' => 'enc:v1:annas', 'created_by' => 'anna']]]);
		$this->webhookService->expects($this->never())->method('generateSecret');

		try {
			$this->controller()->updateWebhook(1, 'wh1', 'https://alice.example.com/hook');
			$this->fail('the webhook was pointed elsewhere');
		} catch (FormAccessDeniedException) {
		}
		$this->assertNull($this->written, 'nothing is written');
	}

	/** Without a new destination it stays the creator's webhook; renaming or pausing it is full rights only. */
	public function testWithoutANewUrlAnEditOfAWebhookNeedsNoDownloadRight(): void {
		$this->canDownload = false;
		$this->givenStoredSettings(['webhooks' => [['id' => 'wh1', 'url' => 'https://anna.example.com/hook', 'secret' => 'enc:v1:annas', 'created_by' => 'anna']]]);

		$resp = $this->controller()->updateWebhook(1, 'wh1', 'https://anna.example.com/hook', 'Renamed', null, false);

		$this->assertSame(['anna', 'Renamed', false], [$this->written['webhooks'][0]['created_by'], $this->written['webhooks'][0]['name'], $this->written['webhooks'][0]['enabled']]);
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testTheSameUrlAgainKeepsCreatorAndSecret(): void {
		$this->givenStoredSettings(['webhooks' => [[
			'id' => 'wh1',
			'url' => 'https://anna.example.com/hook',
			'secret' => 'enc:v1:annas',
			'created_by' => 'anna',
		]]]);
		$this->webhookService->expects($this->never())->method('generateSecret');

		$resp = $this->controller()->updateWebhook(1, 'wh1', 'https://anna.example.com/hook');

		$this->assertSame('anna', $this->written['webhooks'][0]['created_by']);
		$this->assertSame('enc:v1:annas', $this->written['webhooks'][0]['secret']);
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testUpdateWebhookLetsExceptionsPropagate(): void {
		$this->givenMutateSettingsFails('nope');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('nope');
		$this->controller()->updateWebhook(1, 'wh1', null, 'x');
	}

	// ---- deleteWebhook() ---------------------------------------------------

	public function testDeleteWebhookReturns404WhenWebhookMissing(): void {
		$this->givenStoredSettings(['webhooks' => [['id' => 'other']]]);

		$resp = $this->controller()->deleteWebhook(1, 'missing');

		$this->assertNull($this->written, 'nothing is written');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'Webhook not found'], $resp->getData());
	}

	public function testDeleteWebhookSuccessRemovesAndPersists(): void {
		$this->givenStoredSettings(['webhooks' => [['id' => 'keep'], ['id' => 'gone']]]);

		$resp = $this->controller()->deleteWebhook(1, 'gone');

		$this->assertSame([['id' => 'keep']], $this->written['webhooks']);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testDeleteWebhookLetsExceptionsPropagate(): void {
		$this->givenMutateSettingsFails('argh');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('argh');
		$this->controller()->deleteWebhook(1, 'wh1');
	}

	// ---- getWebhookEvents() ------------------------------------------------

	public function testGetWebhookEventsReturnsAvailableEvents(): void {
		$resp = $this->controller()->getWebhookEvents();
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(WebhookService::getAvailableEvents(), $resp->getData());
	}

	// ---- getApiPermissions() -----------------------------------------------

	public function testGetApiPermissionsReturnsPermissionMap(): void {
		$resp = $this->controller()->getApiPermissions();
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame([
			'read_form' => 'Read form definition (questions, settings)',
			'read_responses' => 'Read all responses',
			'write_responses' => 'Create and update responses',
			'delete_responses' => 'Delete responses',
		], $resp->getData());
	}
}
