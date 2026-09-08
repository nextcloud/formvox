<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Controller;

use OCA\FormVox\Controller\FormUploadController;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Http\DataDownloadResponse;

/**
 * Integration coverage for FormUploadController's download endpoints against a
 * REAL Nextcloud + DB + filesystem. Uploads are stored through the real
 * UploadService::storeUpload first, then read back through the controller.
 *
 * The logged-in owner (from IntegrationTestCase) owns the .fvform file it
 * writes into its own home folder, so PermissionService::getRoleFromFile
 * returns ROLE_OWNER and canViewResponses() passes on the always-run path.
 *
 * @group DB
 */
class FormUploadControllerIntegrationTest extends IntegrationTestCase {
	private const RESPONSE_ID = 'resp-1';
	private const UPLOAD_MIME = 'image/png';
	private const UPLOAD_BYTES = "\x89PNG\r\n\x1a\nformvox-integration-upload-bytes";

	/**
	 * downloadUpload returns a DataDownloadResponse carrying the exact stored
	 * bytes, the (sanitized) stored name and the file's real mimetype.
	 */
	public function testDownloadUploadReturnsFile(): void {
		$formFile = $this->writeFormFile($this->userFolder, 'Upload Download Form');
		$fileId = $formFile->getId();

		$uploadService = $this->getService(UploadService::class);
		$meta = $uploadService->storeUpload(
			$fileId,
			self::RESPONSE_ID,
			$this->makeUploadedFile('photo.png', self::UPLOAD_BYTES, self::UPLOAD_MIME)
		);
		$storedName = $meta['filename'];

		/** @var FormUploadController $controller */
		$controller = $this->getService(FormUploadController::class);
		$response = $controller->downloadUpload($fileId, self::RESPONSE_ID, $storedName);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(self::UPLOAD_BYTES, $response->render());
		// The mimetype is derived from the stored file by Nextcloud, not from the
		// $_FILES 'type' we passed in; assert on what the real file reports.
		$storedFile = $uploadService->getUpload($fileId, self::RESPONSE_ID, $storedName);
		$this->assertSame($storedFile->getMimeType(), $response->getHeaders()['Content-Type']);
	}

	/**
	 * downloadAllUploads returns a DataDownloadResponse whose body is a real ZIP
	 * archive (ZIP local-file-header magic 'PK').
	 */
	public function testDownloadAllUploadsReturnsZip(): void {
		$formFile = $this->writeFormFile($this->userFolder, 'Upload Zip Form');
		$fileId = $formFile->getId();

		$uploadService = $this->getService(UploadService::class);
		$uploadService->storeUpload(
			$fileId,
			self::RESPONSE_ID,
			$this->makeUploadedFile('doc.png', self::UPLOAD_BYTES, self::UPLOAD_MIME)
		);

		/** @var FormUploadController $controller */
		$controller = $this->getService(FormUploadController::class);
		$response = $controller->downloadAllUploads($fileId);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$body = $response->render();
		$this->assertNotSame('', $body);
		$this->assertStringStartsWith('PK', $body);
		$this->assertSame('application/zip', $response->getHeaders()['Content-Type']);
	}

	/**
	 * downloadUpload on a missing filename: UploadService::getUpload throws
	 * \OCP\Files\NotFoundException, which the controller catches and re-throws as
	 * a plain \Exception('File not found'). Assert that real behaviour.
	 */
	public function testDownloadUploadNotFound(): void {
		$formFile = $this->writeFormFile($this->userFolder, 'Upload Missing Form');
		$fileId = $formFile->getId();

		// Store one real upload so the uploads folder exists; then ask for a
		// filename that was never stored.
		$uploadService = $this->getService(UploadService::class);
		$uploadService->storeUpload(
			$fileId,
			self::RESPONSE_ID,
			$this->makeUploadedFile('present.png', self::UPLOAD_BYTES, self::UPLOAD_MIME)
		);

		/** @var FormUploadController $controller */
		$controller = $this->getService(FormUploadController::class);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('File not found');
		$controller->downloadUpload($fileId, self::RESPONSE_ID, 'nope.jpg');
	}
}
