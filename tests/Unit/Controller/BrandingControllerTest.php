<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\BrandingController;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for BrandingController. Pins: admin/settings permission
 * gating (per-form endpoints), image upload validation (missing file, bad type,
 * too large), the DataDisplayResponse image-serving path (instanceof + status),
 * and error mapping (401/403/404/500). Everything is mocked against ocp stubs.
 */
class BrandingControllerTest extends TestCase
{
    private IRequest $request;
    private BrandingService $brandingService;
    private FormService $formService;
    private PermissionService $permissionService;
    private IUserSession $userSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->brandingService = $this->createMock(BrandingService::class);
        $this->formService = $this->createMock(FormService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function controller(): BrandingController
    {
        return new BrandingController(
            $this->request,
            $this->brandingService,
            $this->formService,
            $this->permissionService,
            $this->userSession
        );
    }

    /** A valid uploaded-file array as the controller expects it. */
    private function validFile(string $type = 'image/png', int $size = 1024): array
    {
        return [
            'name' => 'logo.png',
            'type' => $type,
            'tmp_name' => '/tmp/phpupload',
            'error' => UPLOAD_ERR_OK,
            'size' => $size,
        ];
    }

    // ---- get() -------------------------------------------------------------

    public function testGetReturnsBranding(): void
    {
        $this->brandingService->method('getBranding')->willReturn(['layout' => ['blocks' => []]]);
        $resp = $this->controller()->get();
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['layout' => ['blocks' => []]], $resp->getData());
    }

    // ---- saveLayout() ------------------------------------------------------

    public function testSaveLayoutReturnsSavedBranding(): void
    {
        $layout = ['blocks' => [['id' => 'b1']]];
        $this->brandingService->expects($this->once())->method('saveLayout')
            ->with($layout)->willReturn(['layout' => $layout]);
        $resp = $this->controller()->saveLayout($layout);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['layout' => $layout], $resp->getData());
    }

    // ---- saveStyles() ------------------------------------------------------

    public function testSaveStylesReturnsSavedBranding(): void
    {
        $styles = ['color' => '#fff'];
        $this->brandingService->expects($this->once())->method('saveGlobalStyles')
            ->with($styles)->willReturn(['globalStyles' => $styles]);
        $resp = $this->controller()->saveStyles($styles);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['globalStyles' => $styles], $resp->getData());
    }

    // ---- uploadBlockImage() ------------------------------------------------

    public function testUploadBlockImageBadRequestWhenNoFile(): void
    {
        $this->request->method('getUploadedFile')->with('image')->willReturn(null);
        $this->brandingService->expects($this->never())->method('saveBlockImage');
        $resp = $this->controller()->uploadBlockImage('b1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame(['error' => 'No file uploaded'], $resp->getData());
    }

    public function testUploadBlockImageBadRequestWhenUploadError(): void
    {
        $file = $this->validFile();
        $file['error'] = UPLOAD_ERR_PARTIAL;
        $this->request->method('getUploadedFile')->willReturn($file);
        $this->brandingService->expects($this->never())->method('saveBlockImage');
        $resp = $this->controller()->uploadBlockImage('b1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame(['error' => 'No file uploaded'], $resp->getData());
    }

    public function testUploadBlockImageRejectsInvalidType(): void
    {
        $this->request->method('getUploadedFile')->willReturn($this->validFile('application/pdf'));
        $this->brandingService->expects($this->never())->method('saveBlockImage');
        $resp = $this->controller()->uploadBlockImage('b1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertStringContainsString('Invalid file type', $resp->getData()['error']);
    }

    public function testUploadBlockImageRejectsTooLarge(): void
    {
        $this->request->method('getUploadedFile')->willReturn($this->validFile('image/png', 2 * 1024 * 1024 + 1));
        $this->brandingService->expects($this->never())->method('saveBlockImage');
        $resp = $this->controller()->uploadBlockImage('b1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertStringContainsString('too large', strtolower($resp->getData()['error']));
    }

    public function testUploadBlockImageSucceeds(): void
    {
        $file = $this->validFile('image/svg+xml', 500);
        $this->request->method('getUploadedFile')->willReturn($file);
        $this->brandingService->expects($this->once())->method('saveBlockImage')
            ->with('b1', $file['tmp_name'], 'image/svg+xml')->willReturn('img-123');
        $resp = $this->controller()->uploadBlockImage('b1');
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['imageId' => 'img-123'], $resp->getData());
    }

    public function testUploadBlockImageAcceptsExactMaxSize(): void
    {
        $file = $this->validFile('image/jpeg', 2 * 1024 * 1024);
        $this->request->method('getUploadedFile')->willReturn($file);
        $this->brandingService->method('saveBlockImage')->willReturn('img-max');
        $resp = $this->controller()->uploadBlockImage('b1');
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['imageId' => 'img-max'], $resp->getData());
    }

    // ---- deleteBlockImage() ------------------------------------------------

    public function testDeleteBlockImageSucceeds(): void
    {
        $this->brandingService->expects($this->once())->method('deleteBlockImage')->with('b1');
        $resp = $this->controller()->deleteBlockImage('b1');
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['success' => true], $resp->getData());
    }

    // ---- blockImage() (DataDisplayResponse) --------------------------------

    public function testBlockImageReturns404WhenMissing(): void
    {
        $this->brandingService->method('getBlockImage')->with('b1')->willReturn(null);
        $resp = $this->controller()->blockImage('b1');
        $this->assertInstanceOf(DataDisplayResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    public function testBlockImageServesImage(): void
    {
        $this->brandingService->method('getBlockImage')
            ->with('b1')->willReturn(['content' => 'BINARY', 'mimeType' => 'image/png']);
        $resp = $this->controller()->blockImage('b1');
        $this->assertInstanceOf(DataDisplayResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame('image/png', $resp->getHeaders()['Content-Type'] ?? null);
    }

    // ---- uploadFormBlockImage() gating -------------------------------------

    public function testUploadFormBlockImageUnauthenticatedWhenNoUser(): void
    {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $controller = new BrandingController(
            $this->request, $this->brandingService, $this->formService,
            $this->permissionService, $session
        );
        $resp = $controller->uploadFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
        $this->assertSame(['error' => 'Not authenticated'], $resp->getData());
    }

    public function testUploadFormBlockImageForbiddenWhenCannotEditSettings(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canEditSettings')->willReturn(false);
        $this->brandingService->expects($this->never())->method('saveFormBlockImage');
        $resp = $this->controller()->uploadFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
        $this->assertSame(['error' => 'Permission denied'], $resp->getData());
    }

    public function testUploadFormBlockImageNotFoundWhenFormMissing(): void
    {
        $this->formService->method('getFileById')->willThrowException(new NotFoundException());
        $resp = $this->controller()->uploadFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
        $this->assertSame(['error' => 'Form not found'], $resp->getData());
    }

    public function testUploadFormBlockImageMapsGenericExceptionTo500(): void
    {
        $this->formService->method('getFileById')->willThrowException(new \RuntimeException('boom'));
        $resp = $this->controller()->uploadFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
        $this->assertSame(['error' => 'boom'], $resp->getData());
    }

    public function testUploadFormBlockImageBadRequestWhenNoFile(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canEditSettings')->willReturn(true);
        $this->request->method('getUploadedFile')->willReturn(null);
        $resp = $this->controller()->uploadFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame(['error' => 'No file uploaded'], $resp->getData());
    }

    public function testUploadFormBlockImageRejectsInvalidType(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canEditSettings')->willReturn(true);
        $this->request->method('getUploadedFile')->willReturn($this->validFile('text/plain'));
        $resp = $this->controller()->uploadFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertStringContainsString('Invalid file type', $resp->getData()['error']);
    }

    public function testUploadFormBlockImageRejectsTooLarge(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canEditSettings')->willReturn(true);
        $this->request->method('getUploadedFile')->willReturn($this->validFile('image/png', 2 * 1024 * 1024 + 1));
        $resp = $this->controller()->uploadFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertStringContainsString('too large', strtolower($resp->getData()['error']));
    }

    public function testUploadFormBlockImageSucceeds(): void
    {
        $file = $this->validFile('image/webp', 800);
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canEditSettings')->willReturn(true);
        $this->request->method('getUploadedFile')->willReturn($file);
        $this->brandingService->expects($this->once())->method('saveFormBlockImage')
            ->with(7, 'b1', $file['tmp_name'], 'image/webp')->willReturn('fimg-9');
        $resp = $this->controller()->uploadFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['imageId' => 'fimg-9'], $resp->getData());
    }

    public function testUploadFormBlockImageMapsSaveExceptionTo500(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canEditSettings')->willReturn(true);
        $this->request->method('getUploadedFile')->willReturn($this->validFile());
        $this->brandingService->method('saveFormBlockImage')
            ->willThrowException(new \Exception('disk full'));
        $resp = $this->controller()->uploadFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
        $this->assertSame(['error' => 'disk full'], $resp->getData());
    }

    // ---- deleteFormBlockImage() --------------------------------------------

    public function testDeleteFormBlockImageForbiddenWhenCannotEditSettings(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canEditSettings')->willReturn(false);
        $this->brandingService->expects($this->never())->method('deleteFormBlockImage');
        $resp = $this->controller()->deleteFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testDeleteFormBlockImageNotFoundWhenFormMissing(): void
    {
        $this->formService->method('getFileById')->willThrowException(new NotFoundException());
        $resp = $this->controller()->deleteFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    public function testDeleteFormBlockImageSucceeds(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canEditSettings')->willReturn(true);
        $this->brandingService->expects($this->once())->method('deleteFormBlockImage')->with(7, 'b1');
        $resp = $this->controller()->deleteFormBlockImage(7, 'b1');
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['success' => true], $resp->getData());
    }

    // ---- formBlockImage() (DataDisplayResponse) ----------------------------

    public function testFormBlockImageReturns404WhenMissing(): void
    {
        $this->brandingService->method('getFormBlockImage')->with(7, 'b1')->willReturn(null);
        $resp = $this->controller()->formBlockImage(7, 'b1');
        $this->assertInstanceOf(DataDisplayResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    public function testFormBlockImageServesImage(): void
    {
        $this->brandingService->method('getFormBlockImage')
            ->with(7, 'b1')->willReturn(['content' => 'BYTES', 'mimeType' => 'image/gif']);
        $resp = $this->controller()->formBlockImage(7, 'b1');
        $this->assertInstanceOf(DataDisplayResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame('image/gif', $resp->getHeaders()['Content-Type'] ?? null);
    }
}
