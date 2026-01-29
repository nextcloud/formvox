<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\WebhookService;

/**
 * Controller for managing API keys and webhooks
 */
class IntegrationController extends Controller
{
    private FormService $formService;
    private PermissionService $permissionService;
    private ApiKeyService $apiKeyService;
    private WebhookService $webhookService;
    private IUserSession $userSession;

    public function __construct(
        IRequest $request,
        FormService $formService,
        PermissionService $permissionService,
        ApiKeyService $apiKeyService,
        WebhookService $webhookService,
        IUserSession $userSession
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->formService = $formService;
        $this->permissionService = $permissionService;
        $this->apiKeyService = $apiKeyService;
        $this->webhookService = $webhookService;
        $this->userSession = $userSession;
    }

    /**
     * Create a new API key for a form
     */
    #[NoAdminRequired]
    public function createApiKey(int $fileId, string $name, array $permissions): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canEditSettings($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Generate new key
            $keyData = $this->apiKeyService->generateKey();

            // Load form and add key
            $form = $this->formService->load($fileId);
            $apiKeys = $form['settings']['api_keys'] ?? [];

            $newKeyConfig = [
                'id' => $keyData['id'],
                'name' => $name,
                'hash' => $keyData['hash'],
                'permissions' => $permissions,
                'created_at' => (new \DateTime())->format(\DateTime::ATOM),
                'created_by' => $userId,
            ];

            $apiKeys[] = $newKeyConfig;

            // Update form
            $form['settings']['api_keys'] = $apiKeys;
            $this->formService->update($fileId, ['settings' => $form['settings']]);

            // Return the plain key (only shown once!)
            return new DataResponse([
                'id' => $keyData['id'],
                'name' => $name,
                'key' => $keyData['key'],  // Only time the key is visible!
                'permissions' => $permissions,
                'message' => 'Save this key now - it will not be shown again!',
            ], Http::STATUS_CREATED);

        } catch (\OCP\Files\NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Form not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete an API key
     */
    #[NoAdminRequired]
    public function deleteApiKey(int $fileId, string $keyId): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canEditSettings($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Load form and remove key
            $form = $this->formService->load($fileId);
            $apiKeys = $form['settings']['api_keys'] ?? [];

            $found = false;
            foreach ($apiKeys as $i => $key) {
                if (($key['id'] ?? '') === $keyId) {
                    array_splice($apiKeys, $i, 1);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return new DataResponse(
                    ['error' => 'API key not found'],
                    Http::STATUS_NOT_FOUND
                );
            }

            // Update form
            $form['settings']['api_keys'] = $apiKeys;
            $this->formService->update($fileId, ['settings' => $form['settings']]);

            return new DataResponse(['success' => true]);

        } catch (\OCP\Files\NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Form not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Create a new webhook for a form
     */
    #[NoAdminRequired]
    public function createWebhook(int $fileId, string $url, string $name = '', array $events = []): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canEditSettings($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Validate URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return new DataResponse(
                    ['error' => 'Invalid URL'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Generate webhook ID and secret
            $webhookId = $this->webhookService->generateId();
            $secret = $this->webhookService->generateSecret();

            // Load form and add webhook
            $form = $this->formService->load($fileId);
            $webhooks = $form['settings']['webhooks'] ?? [];

            $newWebhook = [
                'id' => $webhookId,
                'name' => $name ?: 'Webhook',
                'url' => $url,
                'secret' => $secret,
                'events' => $events,
                'enabled' => true,
                'created_at' => (new \DateTime())->format(\DateTime::ATOM),
                'created_by' => $userId,
            ];

            $webhooks[] = $newWebhook;

            // Update form
            $form['settings']['webhooks'] = $webhooks;
            $this->formService->update($fileId, ['settings' => $form['settings']]);

            // Return the webhook config (including secret - only shown once!)
            return new DataResponse([
                'id' => $webhookId,
                'name' => $newWebhook['name'],
                'url' => $url,
                'secret' => $secret,  // Only time the secret is visible!
                'events' => $events,
                'message' => 'Save this secret now - it will not be shown again!',
            ], Http::STATUS_CREATED);

        } catch (\OCP\Files\NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Form not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Update a webhook
     */
    #[NoAdminRequired]
    public function updateWebhook(
        int $fileId,
        string $webhookId,
        ?string $url = null,
        ?string $name = null,
        ?array $events = null,
        ?bool $enabled = null
    ): DataResponse {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canEditSettings($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Validate URL if provided
            if ($url !== null && !filter_var($url, FILTER_VALIDATE_URL)) {
                return new DataResponse(
                    ['error' => 'Invalid URL'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Load form and update webhook
            $form = $this->formService->load($fileId);
            $webhooks = $form['settings']['webhooks'] ?? [];

            $found = false;
            foreach ($webhooks as $i => $webhook) {
                if (($webhook['id'] ?? '') === $webhookId) {
                    if ($url !== null) {
                        $webhooks[$i]['url'] = $url;
                    }
                    if ($name !== null) {
                        $webhooks[$i]['name'] = $name;
                    }
                    if ($events !== null) {
                        $webhooks[$i]['events'] = $events;
                    }
                    if ($enabled !== null) {
                        $webhooks[$i]['enabled'] = $enabled;
                    }
                    $webhooks[$i]['updated_at'] = (new \DateTime())->format(\DateTime::ATOM);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return new DataResponse(
                    ['error' => 'Webhook not found'],
                    Http::STATUS_NOT_FOUND
                );
            }

            // Update form
            $form['settings']['webhooks'] = $webhooks;
            $this->formService->update($fileId, ['settings' => $form['settings']]);

            return new DataResponse(['success' => true]);

        } catch (\OCP\Files\NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Form not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete a webhook
     */
    #[NoAdminRequired]
    public function deleteWebhook(int $fileId, string $webhookId): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canEditSettings($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Load form and remove webhook
            $form = $this->formService->load($fileId);
            $webhooks = $form['settings']['webhooks'] ?? [];

            $found = false;
            foreach ($webhooks as $i => $webhook) {
                if (($webhook['id'] ?? '') === $webhookId) {
                    array_splice($webhooks, $i, 1);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return new DataResponse(
                    ['error' => 'Webhook not found'],
                    Http::STATUS_NOT_FOUND
                );
            }

            // Update form
            $form['settings']['webhooks'] = $webhooks;
            $this->formService->update($fileId, ['settings' => $form['settings']]);

            return new DataResponse(['success' => true]);

        } catch (\OCP\Files\NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Form not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get available webhook events
     */
    #[NoAdminRequired]
    public function getWebhookEvents(): DataResponse
    {
        return new DataResponse(WebhookService::getAvailableEvents());
    }

    /**
     * Get available API permissions
     */
    #[NoAdminRequired]
    public function getApiPermissions(): DataResponse
    {
        return new DataResponse([
            'read_form' => 'Read form definition (questions, settings)',
            'read_responses' => 'Read all responses',
            'write_responses' => 'Create and update responses',
            'delete_responses' => 'Delete responses',
        ]);
    }
}
