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
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ISecureRandom;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\TemplateService;
class ApiController extends Controller
{
    private FormService $formService;
    private ResponseService $responseService;
    private PermissionService $permissionService;
    private IndexService $indexService;
    private TemplateService $templateService;
    private IUserSession $userSession;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private ISecureRandom $secureRandom;
    private INotificationManager $notificationManager;

    public function __construct(
        IRequest $request,
        FormService $formService,
        ResponseService $responseService,
        PermissionService $permissionService,
        IndexService $indexService,
        TemplateService $templateService,
        IUserSession $userSession,
        IUserManager $userManager,
        IGroupManager $groupManager,
        ISecureRandom $secureRandom,
        INotificationManager $notificationManager
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->formService = $formService;
        $this->responseService = $responseService;
        $this->permissionService = $permissionService;
        $this->indexService = $indexService;
        $this->templateService = $templateService;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->secureRandom = $secureRandom;
        $this->notificationManager = $notificationManager;
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
    /**
     * Save an existing form as an admin-managed template (admin only). #100
     */
    public function saveAsTemplate(int $fileId, string $title = '', string $description = ''): DataResponse
    {
        try {
            $form = $this->formService->load($fileId);
            $entry = $this->templateService->addTemplate(
                $title !== '' ? $title : ($form['title'] ?? 'Untitled template'),
                $description !== '' ? $description : ($form['description'] ?? ''),
                $form
            );
            return new DataResponse(['template' => $entry]);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function create(string $title, string $path = '', ?string $template = null, array $prefilled = [], bool $notifyOnReady = false, ?string $adminTemplateId = null): DataResponse
    {
        try {
            // If an admin template id is supplied, load its structure and
            // pass it as prefilled content so the new form starts as a copy
            // of the admin template (#100).
            if ($adminTemplateId !== null && $adminTemplateId !== '') {
                $tplForm = $this->templateService->getTemplate($adminTemplateId);
                if ($tplForm !== null) {
                    if (!empty($tplForm['description']) && !isset($prefilled['description'])) {
                        $prefilled['description'] = $tplForm['description'];
                    }
                    if (!empty($tplForm['questions']) && !isset($prefilled['questions'])) {
                        $prefilled['questions'] = $tplForm['questions'];
                    }
                }
            }

            $result = $this->formService->create($title, $path, $template, $prefilled);

            if ($notifyOnReady) {
                $this->sendAiReadyNotification($result, $title);
            }

            return new DataResponse($result, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function sendAiReadyNotification(array $result, string $title): void
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return;
        }
        $fileId = (int)($result['fileId'] ?? 0);
        if ($fileId <= 0) {
            return;
        }
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('form', (string)$fileId)
                ->setSubject('ai_form_ready', [
                    'formTitle' => $title,
                    'fileId' => $fileId,
                ]);
            $this->notificationManager->notify($notification);
        } catch (\Exception $e) {
            // Notifications are best-effort; never block form creation
        }
    }

    /**
     * Get a form by file ID
     */
    #[NoAdminRequired]
    public function get(int $fileId): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $form = $this->formService->load($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            // Deny access if user has no permissions
            if ($role === PermissionService::ROLE_NONE) {
                return new DataResponse(
                    ['error' => 'Access denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

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
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

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

            // The share token is server-owned: once a link exists its URL must stay
            // valid until someone deliberately revokes it. A generic save can never
            // change it, however stale the client's copy of `settings` is (#135).
            //
            // This mirrors Nextcloud core, which mints a token only when there is
            // none (Share20\Manager::createShare) and never touches it in
            // updateShare — changing a share's password, expiry or permissions
            // keeps the same link.
            //
            // Note this runs whenever settings are written, not only when the
            // client includes the key: FormService::update() replaces `settings`
            // wholesale, so a save that merely omits public_token would drop the
            // link just as effectively as one sending a stale value.
            if (isset($data['settings'])) {
                // Read the current token from the file we already fetched above
                // ($file, user-scoped and permission-checked) rather than a second
                // loadPublic() lookup, which resolves through a different path and
                // could fail independently of this save succeeding.
                $existing = null;
                $readOk = false;
                try {
                    $currentForm = json_decode($file->getContent(), true);
                    if (is_array($currentForm)) {
                        $existing = $currentForm['settings']['public_token'] ?? null;
                        $readOk = true;
                    }
                } catch (\Throwable $e) {
                    // Could not read the current form — fall through to fail-closed.
                }
                $hasExisting = is_string($existing) && $existing !== '';

                $sentKey = array_key_exists('public_token', $data['settings']);
                $incoming = $sentKey ? $data['settings']['public_token'] : null;
                $isRevoke = $sentKey && ($incoming === null || $incoming === '');

                if (!$readOk) {
                    // Fail closed: we couldn't read the current form, so we can't
                    // tell whether a link exists. FormService::update() replaces
                    // settings wholesale, so letting this save proceed would drop
                    // the token. Skip the settings write entirely (same escape
                    // hatch as the permission check above) — every other field
                    // still saves, and the stored settings, including the link,
                    // stay intact (#135). A genuine settings change can retry
                    // once the read succeeds.
                    unset($data['settings']);
                } elseif ($isRevoke) {
                    // Revoking the link: explicit and deliberate.
                    $data['settings']['public_token'] = null;
                } elseif ($hasExisting) {
                    // A link exists — keep it, whatever the client sent or omitted.
                    // This is the case that used to silently rotate the URL and
                    // break every link already handed out.
                    $data['settings']['public_token'] = $existing;
                } elseif ($sentKey) {
                    // No link yet and the client asked for one. Its value is only
                    // an intent marker; the token is always minted here.
                    $data['settings']['public_token'] = $this->generateShareToken();
                }
                // No link and no request for one: leave it absent.
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
     * Set favorite status for a form
     */
    #[NoAdminRequired]
    public function setFavorite(int $fileId, bool $favorite): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            // Any user with at least view permission can favorite a form
            if ($role === PermissionService::ROLE_NONE) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            $this->formService->update($fileId, ['favorite' => $favorite]);
            return new DataResponse(['success' => true, 'favorite' => $favorite]);
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
     * Delete a form
     */
    #[NoAdminRequired]
    public function delete(int $fileId): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

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
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

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
     * Delete all responses
     */
    #[NoAdminRequired]
    public function deleteAllResponses(int $fileId): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canDeleteResponses($role)) {
                return new DataResponse(
                    ['error' => 'Permission denied'],
                    Http::STATUS_FORBIDDEN
                );
            }

            $this->formService->deleteAllResponses($fileId);
            return new DataResponse(['success' => true]);
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
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

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
        $file = $this->formService->getFileById($fileId);
        $form = $this->formService->load($fileId);
        $userId = $this->userSession->getUser()?->getUID() ?? '';
        $role = $this->permissionService->getRoleFromFile($file, $userId);

        if (!$this->permissionService->canViewResponses($role)) {
            throw new \Exception('Permission denied');
        }

        $csv = $this->responseService->exportCsv($fileId);
        $filename = $this->sanitizeFilename($form['title']) . '-responses.csv';

        return new DataDownloadResponse($csv, $filename, 'text/csv; charset=utf-8');
    }

    /**
     * Export to XLSX (real Excel spreadsheet — no CSV encoding/separator
     * ambiguity, so umlauts and columns are correct in every locale, #114).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportExcel(int $fileId): DataDownloadResponse
    {
        $file = $this->formService->getFileById($fileId);
        $form = $this->formService->load($fileId);
        $userId = $this->userSession->getUser()?->getUID() ?? '';
        $role = $this->permissionService->getRoleFromFile($file, $userId);

        if (!$this->permissionService->canViewResponses($role)) {
            throw new \Exception('Permission denied');
        }

        $xlsx = $this->responseService->exportXlsx($fileId);
        $filename = $this->sanitizeFilename($form['title']) . '-responses.xlsx';

        return new DataDownloadResponse(
            $xlsx,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /**
     * Export to JSON
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportJson(int $fileId): DataDownloadResponse
    {
        $file = $this->formService->getFileById($fileId);
        $form = $this->formService->load($fileId);
        $userId = $this->userSession->getUser()?->getUID() ?? '';
        $role = $this->permissionService->getRoleFromFile($file, $userId);

        if (!$this->permissionService->canViewResponses($role)) {
            throw new \Exception('Permission denied');
        }

        $json = $this->responseService->exportJson($fileId);
        $filename = $this->sanitizeFilename($form['title']) . '-responses.json';

        return new DataDownloadResponse($json, $filename, 'application/json');
    }

    /**
     * Upload ODT template
     */
    #[NoAdminRequired]
    public function uploadOdtTemplate(int $fileId): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                return new DataResponse(['error' => 'Permission denied'], Http::STATUS_FORBIDDEN);
            }

            $uploadedFile = $this->request->getUploadedFile('template');
            if (!$uploadedFile || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
                return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
            }

            $this->formService->storeOdtTemplate($fileId, $uploadedFile);
            return new DataResponse(['success' => true]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download ODT template
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadOdtTemplate(int $fileId): DataDownloadResponse
    {
        $file = $this->formService->getFileById($fileId);
        $userId = $this->userSession->getUser()?->getUID() ?? '';
        $role = $this->permissionService->getRoleFromFile($file, $userId);

        if (!$this->permissionService->canViewResponses($role)) {
            throw new \Exception('Permission denied');
        }

        $template = $this->formService->getOdtTemplate($fileId);
        return new DataDownloadResponse(
            $template->getContent(),
            'template.odt',
            'application/vnd.oasis.opendocument.text'
        );
    }

    /**
     * Delete ODT template
     */
    #[NoAdminRequired]
    public function deleteOdtTemplate(int $fileId): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                return new DataResponse(['error' => 'Permission denied'], Http::STATUS_FORBIDDEN);
            }

            $this->formService->deleteOdtTemplate($fileId);
            return new DataResponse(['success' => true]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check if ODT template exists
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function hasOdtTemplate(int $fileId): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                return new DataResponse(['error' => 'Permission denied'], Http::STATUS_FORBIDDEN);
            }

            return new DataResponse(['hasTemplate' => $this->formService->hasOdtTemplate($fileId)]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Rebuild form index
     */
    #[NoAdminRequired]
    public function rebuildIndex(int $fileId): DataResponse
    {
        try {
            $file = $this->formService->getFileById($fileId);
            $form = $this->formService->load($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

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

    /**
     * Search users and groups for access restriction picker
     */
    #[NoAdminRequired]
    public function searchSharees(string $search = '', int $limit = 10): DataResponse
    {
        try {
            $users = [];
            foreach ($this->userManager->search($search, $limit) as $user) {
                $users[] = [
                    'id' => $user->getUID(),
                    'displayName' => $user->getDisplayName(),
                ];
            }

            $groups = [];
            foreach ($this->groupManager->search($search, $limit) as $group) {
                $groups[] = [
                    'id' => $group->getGID(),
                    'displayName' => $group->getDisplayName(),
                ];
            }

            return new DataResponse([
                'users' => $users,
                'groups' => $groups,
            ]);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Download an uploaded file from a form response
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadUpload(int $fileId, string $responseId, string $filename): DataDownloadResponse
    {
        try {
            $formFile = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($formFile, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                throw new \Exception('Permission denied');
            }

            $uploadedFile = $this->formService->getUpload($fileId, $responseId, $filename);

            return new DataDownloadResponse(
                $uploadedFile->getContent(),
                $uploadedFile->getName(),
                $uploadedFile->getMimeType()
            );
        } catch (\OCP\Files\NotFoundException $e) {
            throw new \Exception('File not found');
        }
    }

    /**
     * Download all uploads for a form as a ZIP file
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadAllUploads(int $fileId): DataDownloadResponse
    {
        try {
            $formFile = $this->formService->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($formFile, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                throw new \Exception('Permission denied');
            }

            $form = $this->formService->load($fileId);
            $formTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $form['title'] ?? 'form');

            // Create ZIP file
            $zipContent = $this->formService->createUploadsZip($fileId);

            return new DataDownloadResponse(
                $zipContent,
                $formTitle . '-uploads.zip',
                'application/zip'
            );
        } catch (\OCP\Files\NotFoundException $e) {
            throw new \Exception('No uploads found');
        }
    }

    /**
     * Replace the share link with a freshly minted one.
     *
     * Rotating a link invalidates the URL everyone already has, so it must be a
     * deliberate act with its own endpoint — never a side effect of saving the
     * form (#135). Password, expiry and access restrictions are left untouched;
     * only the token changes.
     */
    #[NoAdminRequired]
    public function rotateShareToken(int $fileId): DataResponse
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

            $form = $this->formService->loadPublic($fileId);
            $existing = $form['settings']['public_token'] ?? null;
            if (!is_string($existing) || $existing === '') {
                return new DataResponse(
                    ['error' => 'This form has no share link to replace'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $settings = $form['settings'];
            $settings['public_token'] = $this->generateShareToken();

            $updatedForm = $this->formService->update($fileId, ['settings' => $settings]);
            return new DataResponse(['form' => $updatedForm]);
        } catch (\OCP\Files\NotFoundException $e) {
            return new DataResponse(['error' => 'Form not found'], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
    }

    /**
     * Mint a share token.
     *
     * CHAR_HUMAN_READABLE matches what Nextcloud core uses for share tokens: it
     * drops the characters people misread when a link is dictated or copied off
     * a screen. 32 chars of that alphabet still leaves far more entropy than a
     * guessing attack can cover.
     */
    private function generateShareToken(): string
    {
        return $this->secureRandom->generate(32, ISecureRandom::CHAR_HUMAN_READABLE);
    }

}
