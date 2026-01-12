<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUserManager;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\IndexService;

class ApiController extends Controller
{
    private FormService $formService;
    private ResponseService $responseService;
    private PermissionService $permissionService;
    private IndexService $indexService;
    private IUserSession $userSession;
    private IUserManager $userManager;

    public function __construct(
        IRequest $request,
        FormService $formService,
        ResponseService $responseService,
        PermissionService $permissionService,
        IndexService $indexService,
        IUserSession $userSession,
        IUserManager $userManager
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->formService = $formService;
        $this->responseService = $responseService;
        $this->permissionService = $permissionService;
        $this->indexService = $indexService;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
    }

    /**
     * List all forms accessible to the current user
     */
    #[NoAdminRequired]
    public function list(): DataResponse
    {
        try {
            $forms = $this->formService->listForms();
            return new DataResponse($forms);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Create a new form
     */
    #[NoAdminRequired]
    public function create(string $title, string $path = '', ?string $template = null): DataResponse
    {
        try {
            $result = $this->formService->create($title, $path, $template);
            return new DataResponse($result, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get a form by file ID
     */
    #[NoAdminRequired]
    public function get(int $fileId): DataResponse
    {
        try {
            $form = $this->formService->load($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRole($form, $userId);
            $permissions = $this->permissionService->getPermissionsForRole($role);

            // Remove responses if user can't view them
            if (!$permissions['viewResponses']) {
                unset($form['responses']);
                unset($form['_index']);
            }

            return new DataResponse([
                'form' => $form,
                'role' => $role,
                'permissions' => $permissions,
            ]);
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
     * Update a form
     */
    #[NoAdminRequired]
    public function update(
        int $fileId,
        ?string $title = null,
        ?string $description = null,
        ?array $questions = null,
        ?array $settings = null,
        ?array $pages = null,
        ?array $permissions = null,
        ?array $branding = null
    ): DataResponse
    {
        // Build data array from individual parameters
        $data = [];
        if ($title !== null) $data['title'] = $title;
        if ($description !== null) $data['description'] = $description;
        if ($questions !== null) $data['questions'] = $questions;
        if ($settings !== null) $data['settings'] = $settings;
        if ($pages !== null) $data['pages'] = $pages;
        if ($permissions !== null) $data['permissions'] = $permissions;
        // Branding can be null (use admin defaults) or an array (custom branding)
        // Check the raw request body to see if branding was explicitly sent
        $requestBody = file_get_contents('php://input');
        $requestData = json_decode($requestBody, true) ?? [];
        if (array_key_exists('branding', $requestData)) {
            // Use the value from request data since the parameter might not capture null correctly
            $data['branding'] = $requestData['branding'];
        }

        if (empty($data)) {
            return new DataResponse(
                ['error' => 'No data provided'],
                Http::STATUS_BAD_REQUEST
            );
        }
        try {
            $form = $this->formService->load($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRole($form, $userId);

            if (!$this->permissionService->canEditQuestions($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Check settings permission separately
            if (isset($data['settings']) && !$this->permissionService->canEditSettings($role)) {
                unset($data['settings']);
            }

            $updatedForm = $this->formService->update($fileId, $data);
            return new DataResponse(['form' => $updatedForm]);
        } catch (\OCP\Files\NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Form not found'],
                Http::STATUS_NOT_FOUND
            );
        } catch (\RuntimeException $e) {
            // Lock conflicts and other runtime errors
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_CONFLICT
            );
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete a form
     */
    #[NoAdminRequired]
    public function delete(int $fileId): DataResponse
    {
        try {
            $form = $this->formService->load($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRole($form, $userId);

            if (!$this->permissionService->canDeleteForm($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            $this->formService->delete($fileId);
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
     * Get responses for a form
     */
    #[NoAdminRequired]
    public function getResponses(int $fileId, ?string $date = null): DataResponse
    {
        try {
            $form = $this->formService->load($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRole($form, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            $responses = $this->responseService->getResponses($fileId, $date);
            $summary = $this->responseService->getSummary($fileId);

            return new DataResponse([
                'responses' => $responses,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete a response
     */
    #[NoAdminRequired]
    public function deleteResponse(int $fileId, string $responseId): DataResponse
    {
        try {
            $form = $this->formService->load($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRole($form, $userId);

            if (!$this->permissionService->canDeleteResponses($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            $this->formService->deleteResponse($fileId, $responseId);
            return new DataResponse(['success' => true]);
        } catch (\OCP\Files\NotFoundException $e) {
            return new DataResponse(
                ['error' => 'Response not found'],
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
     * Export to CSV
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportCsv(int $fileId): DataDownloadResponse
    {
        $form = $this->formService->load($fileId);
        $userId = $this->userSession->getUser()?->getUID() ?? '';
        $role = $this->permissionService->getRole($form, $userId);

        if (!$this->permissionService->canViewResponses($role)) {
            throw new \Exception('Permission denied');
        }

        $csv = $this->responseService->exportCsv($fileId);
        $filename = $this->sanitizeFilename($form['title']) . '-responses.csv';

        return new DataDownloadResponse($csv, $filename, 'text/csv');
    }

    /**
     * Export to JSON
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportJson(int $fileId): DataDownloadResponse
    {
        $form = $this->formService->load($fileId);
        $userId = $this->userSession->getUser()?->getUID() ?? '';
        $role = $this->permissionService->getRole($form, $userId);

        if (!$this->permissionService->canViewResponses($role)) {
            throw new \Exception('Permission denied');
        }

        $json = $this->responseService->exportJson($fileId);
        $filename = $this->sanitizeFilename($form['title']) . '-responses.json';

        return new DataDownloadResponse($json, $filename, 'application/json');
    }

    /**
     * Rebuild form index
     */
    #[NoAdminRequired]
    public function rebuildIndex(int $fileId): DataResponse
    {
        try {
            $form = $this->formService->load($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRole($form, $userId);

            if (!$this->permissionService->canEditSettings($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            // Rebuild index
            $this->indexService->rebuildIndex($form);

            // Save updated form
            $this->formService->update($fileId, ['_index' => $form['_index']]);

            return new DataResponse(['success' => true]);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Sanitize filename
     */
    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[\/\\\\:*?"<>|]/', '', $name);
        $name = preg_replace('/\s+/', '-', $name);
        $name = strtolower($name);
        return substr($name, 0, 50);
    }
}
