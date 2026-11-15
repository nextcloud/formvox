<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

class FormUploadController extends Controller {
	public function __construct(
		IRequest $request,
		private FormAccessService $access,
		private UploadService $uploadService,
		private FormRepository $formRepository,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireFormPermission(FormPermission::DOWNLOAD)]
	public function downloadUpload(int $fileId, string $responseId, string $filename): DataDownloadResponse {
		$upload = $this->uploadService->getUpload($this->access->getFile($fileId), $responseId, $filename);
		return new DataDownloadResponse($upload->getContent(), $upload->getName(), $upload->getMimeType());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireFormPermission(FormPermission::DOWNLOAD)]
	public function downloadAllUploads(int $fileId): DataDownloadResponse {
		$title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->formRepository->loadForm($fileId)['title'] ?? 'form');
		return new DataDownloadResponse(
			$this->uploadService->createUploadsZip($this->access->getFile($fileId)),
			$title . '-uploads.zip',
			'application/zip'
		);
	}
}
