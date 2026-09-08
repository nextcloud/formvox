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
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\OdtTemplateService;
use OCA\FormVox\Service\PermissionService;

class ExportController extends Controller
{
    private FormService $formService;
    private FormFileLocator $fileLocator;
    private ResponseService $responseService;
    private OdtTemplateService $odtTemplateService;
    private PermissionService $permissionService;
    private IUserSession $userSession;

    public function __construct(
        IRequest $request,
        FormService $formService,
        FormFileLocator $fileLocator,
        ResponseService $responseService,
        OdtTemplateService $odtTemplateService,
        PermissionService $permissionService,
        IUserSession $userSession
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->formService = $formService;
        $this->fileLocator = $fileLocator;
        $this->responseService = $responseService;
        $this->odtTemplateService = $odtTemplateService;
        $this->permissionService = $permissionService;
        $this->userSession = $userSession;
    }

    /**
     * Export to CSV
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportCsv(int $fileId): DataDownloadResponse
    {
        $file = $this->fileLocator->getFileById($fileId);
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
        $file = $this->fileLocator->getFileById($fileId);
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
        $file = $this->fileLocator->getFileById($fileId);
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
            $file = $this->fileLocator->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                return new DataResponse(['error' => 'Permission denied'], Http::STATUS_FORBIDDEN);
            }

            $uploadedFile = $this->request->getUploadedFile('template');
            if (!$uploadedFile || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
                return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
            }

            $this->odtTemplateService->storeOdtTemplate($fileId, $uploadedFile);
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
        $file = $this->fileLocator->getFileById($fileId);
        $userId = $this->userSession->getUser()?->getUID() ?? '';
        $role = $this->permissionService->getRoleFromFile($file, $userId);

        if (!$this->permissionService->canViewResponses($role)) {
            throw new \Exception('Permission denied');
        }

        $template = $this->odtTemplateService->getOdtTemplate($fileId);
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
            $file = $this->fileLocator->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                return new DataResponse(['error' => 'Permission denied'], Http::STATUS_FORBIDDEN);
            }

            $this->odtTemplateService->deleteOdtTemplate($fileId);
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
            $file = $this->fileLocator->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($file, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                return new DataResponse(['error' => 'Permission denied'], Http::STATUS_FORBIDDEN);
            }

            return new DataResponse(['hasTemplate' => $this->odtTemplateService->hasOdtTemplate($fileId)]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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
