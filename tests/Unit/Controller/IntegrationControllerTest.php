<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\IntegrationController;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\WebhookService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for IntegrationController — pins the HTTP behaviour of
 * API key + webhook management: permission gating (403), not-found mapping
 * (404), bad-request validation (400), success payload shapes, and error
 * mapping (500). Everything is mocked; runs against ocp stubs with no server.
 */
class IntegrationControllerTest extends TestCase {
	private IRequest $request;
	private FormRepository $formRepository;
	private FormFileLocator $fileLocator;
	private PermissionService $permissionService;
	private ApiKeyService $apiKeyService;
	private WebhookService $webhookService;
	private IUserSession $userSession;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->fileLocator = $this->createMock(FormFileLocator::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->apiKeyService = $this->createMock(ApiKeyService::class);
		$this->webhookService = $this->createMock(WebhookService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function controller(): IntegrationController {
		return new IntegrationController(
			$this->request,
			$this->formRepository,
			$this->fileLocator,
			$this->permissionService,
			$this->apiKeyService,
			$this->webhookService,
			$this->userSession
		);
	}

	/** Convenience: make getFileById return a File mock. */
	private function withFile(): void {
		$this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
	}

	// ---- createApiKey() ----------------------------------------------------

	public function testCreateApiKeyDeniedWithoutSettingsPermission(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
		$this->permissionService->method('canEditSettings')->willReturn(false);
		$this->apiKeyService->expects($this->never())->method('generateKey');
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->createApiKey(1, 'k', ['read_form']);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame(['error' => 'Permission denied'], $resp->getData());
	}

	public function testCreateApiKeyMapsNotFoundTo404(): void {
		$this->fileLocator->method('getFileById')->willThrowException(new NotFoundException());

		$resp = $this->controller()->createApiKey(999, 'k', []);
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'Form not found'], $resp->getData());
	}

	public function testCreateApiKeyMapsGenericExceptionTo500(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->apiKeyService->method('generateKey')->willReturn(['id' => 'kid', 'hash' => 'h', 'key' => 'plain']);
		$this->formRepository->method('load')->willThrowException(new \RuntimeException('boom'));

		$resp = $this->controller()->createApiKey(1, 'k', []);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
		$this->assertSame(['error' => 'boom'], $resp->getData());
	}

	public function testCreateApiKeySuccessReturnsPlainKeyOnceAndPersists(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->apiKeyService->method('generateKey')
			->willReturn(['id' => 'kid1', 'hash' => 'HASH', 'key' => 'PLAINKEY']);
		$this->formRepository->method('load')->willReturn(['settings' => ['api_keys' => []]]);

		// Verify the persisted config appends a key with hash (not plain key).
		$this->formRepository->expects($this->once())->method('update')
			->with(1, $this->callback(function ($payload) {
				$keys = $payload['settings']['api_keys'];
				$this->assertCount(1, $keys);
				$this->assertSame('kid1', $keys[0]['id']);
				$this->assertSame('MyKey', $keys[0]['name']);
				$this->assertSame('HASH', $keys[0]['hash']);
				$this->assertSame(['read_form'], $keys[0]['permissions']);
				$this->assertSame('alice', $keys[0]['created_by']);
				$this->assertArrayHasKey('created_at', $keys[0]);
				$this->assertArrayNotHasKey('key', $keys[0]);
				return true;
			}));

		$resp = $this->controller()->createApiKey(1, 'MyKey', ['read_form']);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame('kid1', $data['id']);
		$this->assertSame('MyKey', $data['name']);
		$this->assertSame('PLAINKEY', $data['key']);
		$this->assertSame(['read_form'], $data['permissions']);
		$this->assertArrayHasKey('message', $data);
	}

	public function testCreateApiKeyAppendsToExistingKeys(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->apiKeyService->method('generateKey')
			->willReturn(['id' => 'kid2', 'hash' => 'H2', 'key' => 'K2']);
		$this->formRepository->method('load')
			->willReturn(['settings' => ['api_keys' => [['id' => 'existing']]]]);

		$this->formRepository->expects($this->once())->method('update')
			->with(1, $this->callback(function ($payload) {
				$keys = $payload['settings']['api_keys'];
				$this->assertCount(2, $keys);
				$this->assertSame('existing', $keys[0]['id']);
				$this->assertSame('kid2', $keys[1]['id']);
				return true;
			}));

		$resp = $this->controller()->createApiKey(1, 'k', []);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
	}

	// ---- deleteApiKey() ----------------------------------------------------

	public function testDeleteApiKeyDeniedWithoutSettingsPermission(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
		$this->permissionService->method('canEditSettings')->willReturn(false);
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->deleteApiKey(1, 'kid');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame(['error' => 'Permission denied'], $resp->getData());
	}

	public function testDeleteApiKeyMapsNotFoundTo404(): void {
		$this->fileLocator->method('getFileById')->willThrowException(new NotFoundException());

		$resp = $this->controller()->deleteApiKey(999, 'kid');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'Form not found'], $resp->getData());
	}

	public function testDeleteApiKeyReturns404WhenKeyMissing(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')
			->willReturn(['settings' => ['api_keys' => [['id' => 'other']]]]);
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->deleteApiKey(1, 'missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'API key not found'], $resp->getData());
	}

	public function testDeleteApiKeySuccessRemovesKeyAndPersists(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')->willReturn([
			'settings' => ['api_keys' => [['id' => 'keep'], ['id' => 'gone']]],
		]);

		$this->formRepository->expects($this->once())->method('update')
			->with(1, $this->callback(function ($payload) {
				$keys = array_values($payload['settings']['api_keys']);
				$this->assertCount(1, $keys);
				$this->assertSame('keep', $keys[0]['id']);
				return true;
			}));

		$resp = $this->controller()->deleteApiKey(1, 'gone');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testDeleteApiKeyMapsGenericExceptionTo500(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')->willThrowException(new \RuntimeException('kaboom'));

		$resp = $this->controller()->deleteApiKey(1, 'kid');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
		$this->assertSame(['error' => 'kaboom'], $resp->getData());
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

	public function testCreateWebhookDeniedWithoutSettingsPermission(): void {
		$this->stubWebhookParams('https://example.com/hook');
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
		$this->permissionService->method('canEditSettings')->willReturn(false);
		$this->webhookService->expects($this->never())->method('generateId');

		$resp = $this->controller()->createWebhook(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame(['error' => 'Permission denied'], $resp->getData());
	}

	public function testCreateWebhookMapsNotFoundTo404(): void {
		$this->stubWebhookParams('https://example.com/hook');
		$this->fileLocator->method('getFileById')->willThrowException(new NotFoundException());

		$resp = $this->controller()->createWebhook(999);
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'Form not found'], $resp->getData());
	}

	public function testCreateWebhookRejectsInvalidUrl(): void {
		$this->stubWebhookParams('not-a-url');
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->createWebhook(1);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame(['error' => 'Invalid URL'], $resp->getData());
	}

	public function testCreateWebhookSuccessReturnsSecretOnceAndPersists(): void {
		$this->stubWebhookParams('https://example.com/hook', 'MyHook', ['response.created']);
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->webhookService->method('generateId')->willReturn('wh1');
		$this->webhookService->method('generateSecret')->willReturn('SECRET');
		$this->formRepository->method('load')->willReturn(['settings' => ['webhooks' => []]]);

		$this->formRepository->expects($this->once())->method('update')
			->with(1, $this->callback(function ($payload) {
				$hooks = $payload['settings']['webhooks'];
				$this->assertCount(1, $hooks);
				$this->assertSame('wh1', $hooks[0]['id']);
				$this->assertSame('MyHook', $hooks[0]['name']);
				$this->assertSame('https://example.com/hook', $hooks[0]['url']);
				$this->assertSame('SECRET', $hooks[0]['secret']);
				$this->assertSame(['response.created'], $hooks[0]['events']);
				$this->assertTrue($hooks[0]['enabled']);
				$this->assertSame('alice', $hooks[0]['created_by']);
				$this->assertArrayHasKey('created_at', $hooks[0]);
				return true;
			}));

		$resp = $this->controller()->createWebhook(1);
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
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->webhookService->method('generateId')->willReturn('wh2');
		$this->webhookService->method('generateSecret')->willReturn('S2');
		$this->formRepository->method('load')->willReturn(['settings' => []]);
		$this->formRepository->method('update');

		$resp = $this->controller()->createWebhook(1);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$this->assertSame('Webhook', $resp->getData()['name']);
	}

	public function testCreateWebhookCoercesNonArrayEventsToEmpty(): void {
		$this->stubWebhookParams('https://example.com/hook', 'H', 'not-an-array');
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->webhookService->method('generateId')->willReturn('wh3');
		$this->webhookService->method('generateSecret')->willReturn('S3');
		$this->formRepository->method('load')->willReturn(['settings' => []]);
		$this->formRepository->method('update');

		$resp = $this->controller()->createWebhook(1);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$this->assertSame([], $resp->getData()['events']);
	}

	public function testCreateWebhookMapsGenericExceptionTo500(): void {
		$this->stubWebhookParams('https://example.com/hook');
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->webhookService->method('generateId')->willReturn('wh');
		$this->webhookService->method('generateSecret')->willReturn('s');
		$this->formRepository->method('load')->willThrowException(new \RuntimeException('explode'));

		$resp = $this->controller()->createWebhook(1);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
		$this->assertSame(['error' => 'explode'], $resp->getData());
	}

	// ---- updateWebhook() ---------------------------------------------------

	public function testUpdateWebhookDeniedWithoutSettingsPermission(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
		$this->permissionService->method('canEditSettings')->willReturn(false);
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->updateWebhook(1, 'wh1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame(['error' => 'Permission denied'], $resp->getData());
	}

	public function testUpdateWebhookMapsNotFoundTo404(): void {
		$this->fileLocator->method('getFileById')->willThrowException(new NotFoundException());

		$resp = $this->controller()->updateWebhook(999, 'wh1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'Form not found'], $resp->getData());
	}

	public function testUpdateWebhookRejectsInvalidUrlWhenProvided(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->updateWebhook(1, 'wh1', 'bad-url');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame(['error' => 'Invalid URL'], $resp->getData());
	}

	public function testUpdateWebhookReturns404WhenWebhookMissing(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')
			->willReturn(['settings' => ['webhooks' => [['id' => 'other']]]]);
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->updateWebhook(1, 'missing', null, 'newname');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'Webhook not found'], $resp->getData());
	}

	public function testUpdateWebhookAppliesOnlyProvidedFieldsAndSetsUpdatedAt(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')->willReturn([
			'settings' => ['webhooks' => [[
				'id' => 'wh1',
				'name' => 'Old',
				'url' => 'https://old.example.com',
				'events' => ['response.created'],
				'enabled' => true,
			]]],
		]);

		$this->formRepository->expects($this->once())->method('update')
			->with(1, $this->callback(function ($payload) {
				$hook = $payload['settings']['webhooks'][0];
				// name + enabled provided; url + events left untouched.
				$this->assertSame('New', $hook['name']);
				$this->assertFalse($hook['enabled']);
				$this->assertSame('https://old.example.com', $hook['url']);
				$this->assertSame(['response.created'], $hook['events']);
				$this->assertArrayHasKey('updated_at', $hook);
				return true;
			}));

		$resp = $this->controller()->updateWebhook(1, 'wh1', null, 'New', null, false);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testUpdateWebhookUpdatesUrlAndEventsWhenProvided(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')->willReturn([
			'settings' => ['webhooks' => [[
				'id' => 'wh1',
				'url' => 'https://old.example.com',
				'events' => [],
			]]],
		]);

		$this->formRepository->expects($this->once())->method('update')
			->with(1, $this->callback(function ($payload) {
				$hook = $payload['settings']['webhooks'][0];
				$this->assertSame('https://new.example.com', $hook['url']);
				$this->assertSame(['response.deleted'], $hook['events']);
				return true;
			}));

		$resp = $this->controller()->updateWebhook(
			1,
			'wh1',
			'https://new.example.com',
			null,
			['response.deleted'],
			null
		);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testUpdateWebhookMapsGenericExceptionTo500(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')->willThrowException(new \RuntimeException('nope'));

		$resp = $this->controller()->updateWebhook(1, 'wh1', null, 'x');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
		$this->assertSame(['error' => 'nope'], $resp->getData());
	}

	// ---- deleteWebhook() ---------------------------------------------------

	public function testDeleteWebhookDeniedWithoutSettingsPermission(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
		$this->permissionService->method('canEditSettings')->willReturn(false);
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->deleteWebhook(1, 'wh1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame(['error' => 'Permission denied'], $resp->getData());
	}

	public function testDeleteWebhookMapsNotFoundTo404(): void {
		$this->fileLocator->method('getFileById')->willThrowException(new NotFoundException());

		$resp = $this->controller()->deleteWebhook(999, 'wh1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'Form not found'], $resp->getData());
	}

	public function testDeleteWebhookReturns404WhenWebhookMissing(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')
			->willReturn(['settings' => ['webhooks' => [['id' => 'other']]]]);
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->deleteWebhook(1, 'missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame(['error' => 'Webhook not found'], $resp->getData());
	}

	public function testDeleteWebhookSuccessRemovesAndPersists(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')->willReturn([
			'settings' => ['webhooks' => [['id' => 'keep'], ['id' => 'gone']]],
		]);

		$this->formRepository->expects($this->once())->method('update')
			->with(1, $this->callback(function ($payload) {
				$hooks = array_values($payload['settings']['webhooks']);
				$this->assertCount(1, $hooks);
				$this->assertSame('keep', $hooks[0]['id']);
				return true;
			}));

		$resp = $this->controller()->deleteWebhook(1, 'gone');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testDeleteWebhookMapsGenericExceptionTo500(): void {
		$this->withFile();
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canEditSettings')->willReturn(true);
		$this->formRepository->method('load')->willThrowException(new \RuntimeException('argh'));

		$resp = $this->controller()->deleteWebhook(1, 'wh1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
		$this->assertSame(['error' => 'argh'], $resp->getData());
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
