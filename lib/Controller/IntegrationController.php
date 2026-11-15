<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Security\FormAccessDeniedException;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\WebhookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API keys and webhooks. Both reach outside Nextcloud, so they need full rights on the form; a webhook also sends
 * the responses out, so whoever points it somewhere must be allowed to download them.
 */
class IntegrationController extends Controller {
	public function __construct(
		IRequest $request,
		private FormRepository $formRepository,
		private ApiKeyService $apiKeyService,
		private WebhookService $webhookService,
		private IUserSession $userSession,
		private FormAccessService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::MANAGE_SHARING)]
	public function createApiKey(int $fileId, string $name, array $permissions): DataResponse {
		$keyData = $this->apiKeyService->generateKey();
		$key = [
			'id' => $keyData['id'],
			'name' => $name,
			'hash' => $keyData['hash'],
			'permissions' => $permissions,
			'created_at' => (new \DateTime())->format(\DateTime::ATOM),
			'created_by' => $this->userSession->getUser()?->getUID() ?? '',
		];
		$this->formRepository->mutateSettings($fileId, function (array $settings) use ($key): array {
			$settings['api_keys'] = array_merge($settings['api_keys'] ?? [], [$key]);
			return $settings;
		});

		// The plain key is only ever shown in this response.
		return new DataResponse([
			'id' => $keyData['id'],
			'name' => $name,
			'key' => $keyData['key'],
			'permissions' => $permissions,
			'message' => 'Save this key now - it will not be shown again!',
		], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::MANAGE_SHARING)]
	public function deleteApiKey(int $fileId, string $keyId): DataResponse {
		$found = $this->removeById($fileId, 'api_keys', $keyId);
		return $found
			? new DataResponse(['success' => true])
			: new DataResponse(['error' => 'API key not found'], Http::STATUS_NOT_FOUND);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::MANAGE_SHARING)]
	public function createWebhook(int $fileId): DataResponse {
		$this->requireDownload($fileId);
		$url = $this->request->getParam('url', '');
		$name = $this->request->getParam('name', 'Webhook');
		$events = $this->request->getParam('events', []);
		if (!is_array($events)) {
			$events = [];
		}
		if (!is_string($url) || !WebhookService::isAllowedUrl($url)) {
			return new DataResponse(['error' => 'Invalid URL: use an http or https address'], Http::STATUS_BAD_REQUEST);
		}

		$webhookId = $this->webhookService->generateId();
		$secret = $this->webhookService->generateSecret();

		$webhook = [
			'id' => $webhookId,
			'name' => $name ?: 'Webhook',
			'url' => $url,
			'secret' => $secret,
			'events' => $events,
			'enabled' => true,
			'created_at' => (new \DateTime())->format(\DateTime::ATOM),
			'created_by' => $this->userSession->getUser()?->getUID() ?? '',
		];
		$this->formRepository->mutateSettings($fileId, function (array $settings) use ($webhook): array {
			$settings['webhooks'] = array_merge($settings['webhooks'] ?? [], [$webhook]);
			return $settings;
		});

		// The secret is only ever shown in this response.
		return new DataResponse([
			'id' => $webhookId,
			'name' => $name ?: 'Webhook',
			'url' => $url,
			'secret' => $secret,
			'events' => $events,
			'message' => 'Save this secret now - it will not be shown again!',
		], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::MANAGE_SHARING)]
	public function updateWebhook(
		int $fileId,
		string $webhookId,
		?string $url = null,
		?string $name = null,
		?array $events = null,
		?bool $enabled = null,
	): DataResponse {
		if ($url !== null && !WebhookService::isAllowedUrl($url)) {
			return new DataResponse(['error' => 'Invalid URL: use an http or https address'], Http::STATUS_BAD_REQUEST);
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$changes = array_filter(['url' => $url, 'name' => $name, 'events' => $events, 'enabled' => $enabled], fn ($v) => $v !== null);
		$newSecret = null;
		$found = $this->formRepository->mutateSettings($fileId, function (array $settings) use ($fileId, $webhookId, $changes, $uid, &$newSecret): ?array {
			foreach ($settings['webhooks'] ?? [] as $i => $webhook) {
				if (($webhook['id'] ?? '') !== $webhookId) {
					continue;
				}
				$changes['updated_at'] = (new \DateTime())->format(\DateTime::ATOM);
				$changes['updated_by'] = $uid;
				if (isset($changes['url']) && $changes['url'] !== ($webhook['url'] ?? null)) {
					// A new destination is the changer's webhook: it leans on their access, with a secret only they get (PO).
					$this->requireDownload($fileId);
					$newSecret = $this->webhookService->generateSecret();
					$changes['secret'] = $newSecret;
					$changes['created_by'] = $uid;
				}
				$settings['webhooks'][$i] = array_merge($webhook, $changes);
				return $settings;
			}
			return null;
		});
		if (!$found) {
			return new DataResponse(['error' => 'Webhook not found'], Http::STATUS_NOT_FOUND);
		}
		if ($newSecret === null) {
			return new DataResponse(['success' => true]);
		}
		// The new secret is only ever shown in this response.
		return new DataResponse([
			'success' => true,
			'secret' => $newSecret,
			'message' => 'The URL changed, so this webhook now has a new secret: save it now - it will not be shown again!',
		]);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::MANAGE_SHARING)]
	public function deleteWebhook(int $fileId, string $webhookId): DataResponse {
		$found = $this->removeById($fileId, 'webhooks', $webhookId);
		return $found
			? new DataResponse(['success' => true])
			: new DataResponse(['error' => 'Webhook not found'], Http::STATUS_NOT_FOUND);
	}

	/**
	 * @throws FormAccessDeniedException
	 */
	private function requireDownload(int $fileId): void {
		if (!$this->access->allows($this->access->getFile($fileId), FormPermission::DOWNLOAD)) {
			throw new FormAccessDeniedException();
		}
	}

	private function removeById(int $fileId, string $list, string $id): bool {
		return $this->formRepository->mutateSettings($fileId, function (array $settings) use ($list, $id): ?array {
			/** @var list<array<string, mixed>> $items */
			$items = is_array($settings[$list] ?? null) ? array_values($settings[$list]) : [];
			$kept = array_values(array_filter($items, fn (array $item) => ($item['id'] ?? '') !== $id));
			if (count($kept) === count($items)) {
				return null;
			}
			$settings[$list] = $kept;
			return $settings;
		});
	}

	#[NoAdminRequired]
	public function getWebhookEvents(): DataResponse {
		return new DataResponse(WebhookService::getAvailableEvents());
	}

	#[NoAdminRequired]
	public function getApiPermissions(): DataResponse {
		return new DataResponse([
			'read_form' => 'Read form definition (questions, settings)',
			'read_responses' => 'Read all responses',
			'write_responses' => 'Create and update responses',
			'delete_responses' => 'Delete responses',
		]);
	}
}
