<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\ExportController;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\OdtTemplateService;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ResponseService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for ExportController — the export (CSV/Excel/JSON) and
 * ODT-template HTTP behaviour split out of ApiController. Pins: permission
 * gating (403 for DataResponse endpoints, thrown exception for the download
 * endpoints), happy-path payloads and the not-found error mapping.
 *
 * Everything is mocked; the controller runs against ocp stubs with no server.
 */
class ExportControllerTest extends TestCase
{
    private FormService $formService;
    private FormFileLocator $fileLocator;
    private ResponseService $responseService;
    private OdtTemplateService $odtTemplateService;
    private PermissionService $permissionService;
    private IUserSession $userSession;
    private IRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formService = $this->createMock(FormService::class);
        $this->fileLocator = $this->createMock(FormFileLocator::class);
        $this->responseService = $this->createMock(ResponseService::class);
        $this->odtTemplateService = $this->createMock(OdtTemplateService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->request = $this->createMock(IRequest::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function controller(): ExportController
    {
        return new ExportController(
            $this->request,
            $this->formService,
            $this->fileLocator,
            $this->responseService,
            $this->odtTemplateService,
            $this->permissionService,
            $this->userSession
        );
    }

    /**
     * Invoke a download endpoint whose only observable output is a
     * DataDownloadResponse. That class cannot be instantiated in the Unit env
     * (symfony/string is absent), so the controller reaches the response
     * constructor and throws a missing-class Error *after* all the mocked
     * service work is done. Swallow only that specific Error — every other
     * assertion (the ->expects() matchers) still runs at tearDown.
     */
    private function buildDownload(callable $fn): void
    {
        try {
            $fn();
        } catch (\Error $e) {
            if (!str_contains($e->getMessage(), 'Symfony\\Component\\String\\UnicodeString')) {
                throw $e;
            }
        }
        $this->addToAssertionCount(1);
    }

    // ---- exportCsv() -------------------------------------------------------

    public function testExportCsvDeniedWithoutViewResponses(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'T']);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_RESPONDENT);
        $this->permissionService->method('canViewResponses')->willReturn(false);
        $this->responseService->expects($this->never())->method('exportCsv');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Permission denied');
        $this->controller()->exportCsv(1);
    }

    public function testExportCsvBuildsViaResponseServiceWhenAllowed(): void
    {
        // The Unit test env lacks symfony/string, so DataDownloadResponse cannot
        // be instantiated; pin the meaningful behaviour (the CSV is built via
        // ResponseService for the right form) and tolerate the response
        // construction failing on the missing class.
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'My Form']);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->responseService->expects($this->once())->method('exportCsv')->with(1)->willReturn('a,b,c');

        $this->buildDownload(fn () => $this->controller()->exportCsv(1));
    }

    // ---- exportExcel() -----------------------------------------------------

    public function testExportExcelDeniedWithoutViewResponses(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'T']);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_RESPONDENT);
        $this->permissionService->method('canViewResponses')->willReturn(false);
        $this->responseService->expects($this->never())->method('exportXlsx');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Permission denied');
        $this->controller()->exportExcel(1);
    }

    public function testExportExcelBuildsViaResponseServiceWhenAllowed(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'My Form']);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->responseService->expects($this->once())->method('exportXlsx')->with(1)->willReturn('XLSXBYTES');

        $this->buildDownload(fn () => $this->controller()->exportExcel(1));
    }

    // ---- exportJson() ------------------------------------------------------

    public function testExportJsonDeniedWithoutViewResponses(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'T']);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_RESPONDENT);
        $this->permissionService->method('canViewResponses')->willReturn(false);
        $this->responseService->expects($this->never())->method('exportJson');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Permission denied');
        $this->controller()->exportJson(1);
    }

    public function testExportJsonBuildsViaResponseServiceWhenAllowed(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'My Form']);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->responseService->expects($this->once())->method('exportJson')->with(1)->willReturn('{"ok":true}');

        $this->buildDownload(fn () => $this->controller()->exportJson(1));
    }

    // ---- uploadOdtTemplate() -----------------------------------------------

    public function testUploadOdtTemplateDeniedWithoutViewResponses(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_RESPONDENT);
        $this->permissionService->method('canViewResponses')->willReturn(false);
        $this->odtTemplateService->expects($this->never())->method('storeOdtTemplate');

        $resp = $this->controller()->uploadOdtTemplate(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testUploadOdtTemplateBadRequestWhenNoFile(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->request->method('getUploadedFile')->willReturn(null);
        $this->odtTemplateService->expects($this->never())->method('storeOdtTemplate');

        $resp = $this->controller()->uploadOdtTemplate(1);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testUploadOdtTemplateStoresWhenAllowed(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->request->method('getUploadedFile')->willReturn([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '/tmp/x',
            'name' => 't.odt',
        ]);
        $this->odtTemplateService->expects($this->once())->method('storeOdtTemplate')->with(1, $this->anything());

        $resp = $this->controller()->uploadOdtTemplate(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['success' => true], $resp->getData());
    }

    // ---- downloadOdtTemplate() ---------------------------------------------

    public function testDownloadOdtTemplateDeniedWithoutViewResponses(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_RESPONDENT);
        $this->permissionService->method('canViewResponses')->willReturn(false);
        $this->odtTemplateService->expects($this->never())->method('getOdtTemplate');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Permission denied');
        $this->controller()->downloadOdtTemplate(1);
    }

    public function testDownloadOdtTemplateFetchesViaOdtServiceWhenAllowed(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $template = $this->createMock(File::class);
        $template->method('getContent')->willReturn('ODTBYTES');
        $this->odtTemplateService->expects($this->once())->method('getOdtTemplate')->with(1)->willReturn($template);

        $this->buildDownload(fn () => $this->controller()->downloadOdtTemplate(1));
    }

    // ---- deleteOdtTemplate() -----------------------------------------------

    public function testDeleteOdtTemplateDeniedWithoutViewResponses(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_RESPONDENT);
        $this->permissionService->method('canViewResponses')->willReturn(false);
        $this->odtTemplateService->expects($this->never())->method('deleteOdtTemplate');

        $resp = $this->controller()->deleteOdtTemplate(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testDeleteOdtTemplateProceedsWhenAllowed(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->odtTemplateService->expects($this->once())->method('deleteOdtTemplate')->with(1);

        $resp = $this->controller()->deleteOdtTemplate(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['success' => true], $resp->getData());
    }

    public function testDeleteOdtTemplateMapsErrorTo500(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->odtTemplateService->method('deleteOdtTemplate')
            ->willThrowException(new \Exception('boom'));

        $resp = $this->controller()->deleteOdtTemplate(1);
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
    }

    // ---- hasOdtTemplate() --------------------------------------------------

    public function testHasOdtTemplateDeniedWithoutViewResponses(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_RESPONDENT);
        $this->permissionService->method('canViewResponses')->willReturn(false);
        $this->odtTemplateService->expects($this->never())->method('hasOdtTemplate');

        $resp = $this->controller()->hasOdtTemplate(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testHasOdtTemplateReturnsFlagWhenAllowed(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canViewResponses')->willReturn(true);
        $this->odtTemplateService->method('hasOdtTemplate')->willReturn(true);

        $resp = $this->controller()->hasOdtTemplate(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['hasTemplate' => true], $resp->getData());
    }
}
