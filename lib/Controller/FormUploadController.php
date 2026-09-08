<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\UploadService;

class FormUploadController extends Controller
{
    private FormFileLocator $fileLocator;
    private UploadService $uploadService;
    private FormService $formService;
    private PermissionService $permissionService;
    private IUserSession $userSession;

    public function __construct(
        IRequest $request,
        FormFileLocator $fileLocator,
        UploadService $uploadService,
        FormService $formService,
        PermissionService $permissionService,
        IUserSession $userSession
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->fileLocator = $fileLocator;
        $this->uploadService = $uploadService;
        $this->formService = $formService;
        $this->permissionService = $permissionService;
        $this->userSession = $userSession;
    }

    /**
     * Download an uploaded file from a form response
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadUpload(int $fileId, string $responseId, string $filename): DataDownloadResponse
    {
        try {
            $formFile = $this->fileLocator->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($formFile, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                throw new \Exception('Permission denied');
            }

            $uploadedFile = $this->uploadService->getUpload($fileId, $responseId, $filename);

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
            $formFile = $this->fileLocator->getFileById($fileId);
            $userId = $this->userSession->getUser()?->getUID() ?? '';
            $role = $this->permissionService->getRoleFromFile($formFile, $userId);

            if (!$this->permissionService->canViewResponses($role)) {
                throw new \Exception('Permission denied');
            }

            $form = $this->formService->load($fileId);
            $formTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $form['title'] ?? 'form');

            // Create ZIP file
            $zipContent = $this->uploadService->createUploadsZip($fileId);

            return new DataDownloadResponse(
                $zipContent,
                $formTitle . '-uploads.zip',
                'application/zip'
            );
        } catch (\OCP\Files\NotFoundException $e) {
            throw new \Exception('No uploads found');
        }
    }
}
