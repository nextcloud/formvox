<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\FormUploadController;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\UploadService;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * FormUploadController once access is granted; FormAccessMiddleware owns authorization and error mapping.
 */
class FormUploadControllerTest extends TestCase {
	private FormAccessService $access;
	private UploadService $uploadService;
	private FormRepository $formRepository;
	private File $formFile;

	protected function setUp(): void {
		parent::setUp();
		$this->access = $this->createMock(FormAccessService::class);
		$this->uploadService = $this->createMock(UploadService::class);
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->formFile = $this->createMock(File::class);
		$this->access->method('getFile')->with(1)->willReturn($this->formFile);
	}

	private function controller(): FormUploadController {
		return new FormUploadController(
			$this->createMock(IRequest::class),
			$this->access,
			$this->uploadService,
			$this->formRepository
		);
	}

	// ---- downloadUpload() --------------------------------------------------

	public function testDownloadUploadReturnsFile(): void {
		$uploaded = $this->createMock(File::class);
		$uploaded->method('getContent')->willReturn('BINARY');
		$uploaded->method('getName')->willReturn('photo.jpg');
		$uploaded->method('getMimeType')->willReturn('image/jpeg');
		$this->uploadService->expects($this->once())
			->method('getUpload')->with($this->formFile, 'r1', 'photo.jpg')->willReturn($uploaded);
		$uploaded->expects($this->once())->method('getContent');

		// DataDownloadResponse needs Symfony classes this harness lacks.
		try {
			$this->controller()->downloadUpload(1, 'r1', 'photo.jpg');
		} catch (\Error $e) {
			$this->assertStringContainsString('Symfony\\Component\\', $e->getMessage());
		}
	}

	public function testDownloadUploadPropagatesNotFound(): void {
		$this->uploadService->method('getUpload')->willThrowException(new NotFoundException());

		$this->expectException(NotFoundException::class);
		$this->controller()->downloadUpload(1, 'r1', 'gone.jpg');
	}

	// ---- downloadAllUploads() ----------------------------------------------

	public function testDownloadAllUploadsReturnsZip(): void {
		$this->formRepository->expects($this->once())->method('loadForm')
			->with(1)->willReturn(['title' => 'My Form!']);
		$this->uploadService->expects($this->once())
			->method('createUploadsZip')->with($this->formFile)->willReturn('ZIPBYTES');

		// DataDownloadResponse needs Symfony classes this harness lacks.
		try {
			$this->controller()->downloadAllUploads(1);
		} catch (\Error $e) {
			$this->assertStringContainsString('Symfony\\Component\\', $e->getMessage());
		}
	}

	public function testDownloadAllUploadsPropagatesNotFound(): void {
		$this->formRepository->method('loadForm')->willReturn(['title' => 'T']);
		$this->uploadService->method('createUploadsZip')->willThrowException(new NotFoundException());

		$this->expectException(NotFoundException::class);
		$this->controller()->downloadAllUploads(1);
	}
}
