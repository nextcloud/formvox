<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\FormUploadController;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\UploadService;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for FormUploadController — the upload download
 * behaviour split out of ApiController. Both endpoints return
 * DataDownloadResponse and signal failure by throwing, so the permission-gate
 * and not-found cases assert on the thrown \Exception rather than a status.
 *
 * Repointed: getFileById -> FormFileLocator, getUpload/createUploadsZip ->
 * UploadService. load() stays on FormService (zip filename title).
 */
class FormUploadControllerTest extends TestCase
{
    private FormFileLocator $fileLocator;
    private UploadService $uploadService;
    private FormRepository $formRepository;
    private PermissionService $permissionService;
    private IUserSession $userSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileLocator = $this->createMock(FormFileLocator::class);
        $this->uploadService = $this->createMock(UploadService::class);
        $this->formRepository = $this->createMock(FormRepository::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function controller(): FormUploadController
    {
        return new FormUploadController(
            $this->createMock(IRequest::class),
            $this->fileLocator,
            $this->uploadService,
            $this->formRepository,
            $this->permissionService,
            $this->userSession
        );
    }

    // ---- downloadUpload() --------------------------------------------------

    public function testDownloadUploadDeniedWithoutViewResponses(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canViewResponses')->willReturn(false);
        $this->uploadService->expects($this->never())->method('getUpload');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Permission denied');
        $this->controller()->downloadUpload(1, 'r1', 'photo.jpg');
    }

    public function testDownloadUploadReturnsFile(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);

        $uploaded = $this->createMock(File::class);
        $uploaded->method('getContent')->willReturn('BINARY');
        $uploaded->method('getName')->willReturn('photo.jpg');
        $uploaded->method('getMimeType')->willReturn('image/jpeg');
        // Happy path: after the permission gate the upload is fetched from
        // UploadService (repointed) with the exact ids, and its bytes/name/mime
        // are read to build the response.
        $this->uploadService->expects($this->once())
            ->method('getUpload')->with(1, 'r1', 'photo.jpg')->willReturn($uploaded);
        $uploaded->expects($this->once())->method('getContent');

        // DataDownloadResponse construction needs a full NC server for the
        // Content-Disposition header; assert the controller reaches it having
        // wired the collaborators correctly, then swallow that env-only error.
        try {
            $this->controller()->downloadUpload(1, 'r1', 'photo.jpg');
        } catch (\Error $e) {
            $this->assertStringContainsString('UnicodeString', $e->getMessage());
        }
    }

    public function testDownloadUploadMapsNotFound(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->uploadService->method('getUpload')->willThrowException(new NotFoundException());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');
        $this->controller()->downloadUpload(1, 'r1', 'gone.jpg');
    }

    // ---- downloadAllUploads() ----------------------------------------------

    public function testDownloadAllUploadsDeniedWithoutViewResponses(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canViewResponses')->willReturn(false);
        $this->uploadService->expects($this->never())->method('createUploadsZip');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Permission denied');
        $this->controller()->downloadAllUploads(1);
    }

    public function testDownloadAllUploadsReturnsZip(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        // load() stays on FormService and supplies the zip filename title.
        $this->formRepository->expects($this->once())->method('load')
            ->with(1)->willReturn(['title' => 'My Form!']);
        // Zip built via UploadService (repointed) with the form's file id.
        $this->uploadService->expects($this->once())
            ->method('createUploadsZip')->with(1)->willReturn('ZIPBYTES');

        // DataDownloadResponse construction needs a full NC server; assert the
        // controller reaches it with collaborators wired, then swallow the
        // env-only error.
        try {
            $this->controller()->downloadAllUploads(1);
        } catch (\Error $e) {
            $this->assertStringContainsString('UnicodeString', $e->getMessage());
        }
    }

    public function testDownloadAllUploadsMapsNotFound(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->formRepository->method('load')->willReturn(['title' => 'T']);
        $this->uploadService->method('createUploadsZip')->willThrowException(new NotFoundException());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No uploads found');
        $this->controller()->downloadAllUploads(1);
    }
}
