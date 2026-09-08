<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\PageController;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\MicrosoftFormsAuthService;
use OCA\FormVox\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for PageController — the three server-rendered page
 * entry points (index / editor / results). These pin the CURRENT observable
 * behaviour:
 *
 *  - index() always renders the app shell (TemplateResponse, STATUS_OK) and
 *    seeds the msFormsConfigured initial state.
 *  - editor() gates on role: any role above ROLE_NONE renders the editor;
 *    ROLE_NONE triggers `throw new NotFoundResponse()`. NotFoundResponse
 *    extends TemplateResponse and is NOT Throwable, so PHP raises a fatal
 *    \Error("Cannot throw objects that do not implement Throwable"). We pin
 *    that exact current behaviour rather than pretending it is a 404 response.
 *  - results() gates on the computed permissions['viewResponses'] flag with the
 *    same throw-a-non-throwable pattern.
 *
 * Everything is mocked against the ocp stubs; no Nextcloud server runs. We
 * assert on response class + HTTP status + the initial state handed to JS,
 * never on rendered HTML.
 */
class PageControllerTest extends TestCase
{
    private IRequest $request;
    private FormService $formService;
    private PermissionService $permissionService;
    private BrandingService $brandingService;
    private MicrosoftFormsAuthService $msFormsAuthService;
    private IInitialState $initialState;
    private IURLGenerator $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->formService = $this->createMock(FormService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->brandingService = $this->createMock(BrandingService::class);
        $this->msFormsAuthService = $this->createMock(MicrosoftFormsAuthService::class);
        $this->initialState = $this->createMock(IInitialState::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);

        // Sensible defaults so success paths don't crash on unmocked calls.
        $this->brandingService->method('getBranding')->willReturn(['color' => '#fff']);
        $this->msFormsAuthService->method('isConfigured')->willReturn(false);
    }

    private function controller(?string $userId = 'alice'): PageController
    {
        return new PageController(
            $this->request,
            $this->formService,
            $this->permissionService,
            $this->brandingService,
            $this->msFormsAuthService,
            $this->initialState,
            $this->urlGenerator,
            $userId
        );
    }

    /** Capture every provideInitialState(key, value) into a keyed array. */
    private function captureInitialState(array &$captured): void
    {
        $this->initialState->method('provideInitialState')
            ->willReturnCallback(function (string $key, $value) use (&$captured): void {
                $captured[$key] = $value;
            });
    }

    // ======================================================================
    // index()
    // ======================================================================

    public function testIndexRendersTemplateWithOkStatus(): void
    {
        $resp = $this->controller()->index();
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testIndexProvidesMsFormsConfiguredFalse(): void
    {
        $this->msFormsAuthService = $this->createMock(MicrosoftFormsAuthService::class);
        $this->msFormsAuthService->method('isConfigured')->willReturn(false);

        $captured = [];
        $this->captureInitialState($captured);

        $this->controller()->index();
        $this->assertArrayHasKey('msFormsConfigured', $captured);
        $this->assertFalse($captured['msFormsConfigured']);
    }

    public function testIndexProvidesMsFormsConfiguredTrue(): void
    {
        $this->msFormsAuthService = $this->createMock(MicrosoftFormsAuthService::class);
        $this->msFormsAuthService->method('isConfigured')->willReturn(true);

        $captured = [];
        $this->captureInitialState($captured);

        $this->controller()->index();
        $this->assertTrue($captured['msFormsConfigured']);
    }

    public function testIndexTemplateParamsExposeAppId(): void
    {
        $resp = $this->controller()->index();
        $params = $resp->getParams();
        $this->assertSame('formvox', $params['appId']);
    }

    public function testIndexTemplateNameIsIndex(): void
    {
        $resp = $this->controller()->index();
        $this->assertSame('index', $resp->getTemplateName());
    }

    public function testIndexDoesNotTouchFormOrPermissionServices(): void
    {
        $this->formService->expects($this->never())->method('load');
        $this->formService->expects($this->never())->method('getFileById');
        $this->permissionService->expects($this->never())->method('getRoleFromFile');

        $this->controller()->index();
    }

    // ======================================================================
    // editor()
    // ======================================================================

    /**
     * Wire up the collaborators editor()/results() call in order:
     * getFileById → load → getRoleFromFile → canShareFromFile → getPermissionsForRole.
     */
    private function wireForm(
        string $role,
        array $permissions,
        array $form = ['title' => 'T'],
        bool $canShare = false
    ): void {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn($form);
        $this->permissionService->method('getRoleFromFile')->willReturn($role);
        $this->permissionService->method('canShareFromFile')->willReturn($canShare);
        $this->permissionService->method('getPermissionsForRole')->willReturn($permissions);
    }

    public function testEditorRendersForViewerRole(): void
    {
        $this->wireForm(PermissionService::ROLE_VIEWER, ['viewResponses' => false]);
        $resp = $this->controller()->editor(1);
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame('editor', $resp->getTemplateName());
        $this->assertSame('formvox', $resp->getParams()['appId']);
    }

    public function testEditorRendersForEditorRole(): void
    {
        $this->wireForm(PermissionService::ROLE_EDITOR, ['viewResponses' => true]);
        $resp = $this->controller()->editor(7);
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testEditorRendersForOwnerRole(): void
    {
        $this->wireForm(PermissionService::ROLE_OWNER, ['viewResponses' => true], ['title' => 'X'], true);
        $resp = $this->controller()->editor(3);
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testEditorRendersForRespondentRole(): void
    {
        // Any role above ROLE_NONE passes the gate, including respondent.
        $this->wireForm(PermissionService::ROLE_RESPONDENT, ['viewResponses' => false]);
        $resp = $this->controller()->editor(9);
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testEditorProvidesInitialStateOnSuccess(): void
    {
        $form = ['title' => 'My Form', 'questions' => []];
        $this->wireForm(PermissionService::ROLE_EDITOR, ['viewResponses' => true, 'edit' => true], $form, true);

        $captured = [];
        $this->captureInitialState($captured);

        $this->controller()->editor(42);

        $this->assertSame(42, $captured['fileId']);
        $this->assertSame($form, $captured['form']);
        $this->assertSame(PermissionService::ROLE_EDITOR, $captured['role']);
        $this->assertSame(['viewResponses' => true, 'edit' => true], $captured['permissions']);
        $this->assertSame(['color' => '#fff'], $captured['adminBranding']);
    }

    public function testEditorPassesUserIdToPermissionLookup(): void
    {
        $file = $this->createMock(File::class);
        $this->formService->method('getFileById')->willReturn($file);
        $this->formService->method('load')->willReturn(['title' => 'T']);
        $this->permissionService->expects($this->once())->method('getRoleFromFile')
            ->with($file, 'bob')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canShareFromFile')->with($file, 'bob')->willReturn(false);
        $this->permissionService->method('getPermissionsForRole')->willReturn(['viewResponses' => false]);

        $resp = $this->controller('bob')->editor(1);
        $this->assertInstanceOf(TemplateResponse::class, $resp);
    }

    public function testEditorNullUserIdCoercedToEmptyString(): void
    {
        // userId === null → the controller passes '' to the permission lookups.
        $file = $this->createMock(File::class);
        $this->formService->method('getFileById')->willReturn($file);
        $this->formService->method('load')->willReturn(['title' => 'T']);
        $this->permissionService->expects($this->once())->method('getRoleFromFile')
            ->with($file, '')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canShareFromFile')->with($file, '')->willReturn(false);
        $this->permissionService->method('getPermissionsForRole')->willReturn(['viewResponses' => false]);

        $resp = $this->controller(null)->editor(1);
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testEditorRoleNoneThrowsNonThrowableError(): void
    {
        // The gate does `throw new NotFoundResponse()`, but NotFoundResponse
        // extends TemplateResponse and is not Throwable, so PHP raises a fatal
        // \Error. This is the current (buggy) behaviour and we pin it exactly.
        $this->wireForm(PermissionService::ROLE_NONE, ['viewResponses' => false]);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot throw objects that do not implement Throwable');
        $this->controller()->editor(1);
    }

    public function testEditorPropagatesNotFoundExceptionFromGetFileById(): void
    {
        // If getFileById throws (e.g. file missing), editor() does not catch it.
        $this->formService->method('getFileById')
            ->willThrowException(new \OCP\Files\NotFoundException('gone'));

        $this->expectException(\OCP\Files\NotFoundException::class);
        $this->controller()->editor(404);
    }

    // ======================================================================
    // results()
    // ======================================================================

    public function testResultsRendersWhenViewResponsesTrue(): void
    {
        $this->wireForm(PermissionService::ROLE_EDITOR, ['viewResponses' => true]);
        $resp = $this->controller()->results(5);
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame('results', $resp->getTemplateName());
        $this->assertSame('formvox', $resp->getParams()['appId']);
    }

    public function testResultsProvidesInitialStateOnSuccess(): void
    {
        $form = ['title' => 'Survey', 'questions' => [['id' => 'q1']]];
        $this->wireForm(PermissionService::ROLE_OWNER, ['viewResponses' => true], $form, true);

        $captured = [];
        $this->captureInitialState($captured);

        $this->controller()->results(11);

        $this->assertSame(11, $captured['fileId']);
        $this->assertSame($form, $captured['form']);
        $this->assertSame(PermissionService::ROLE_OWNER, $captured['role']);
        $this->assertSame(['viewResponses' => true], $captured['permissions']);
        // results() does NOT provide adminBranding (editor-only).
        $this->assertArrayNotHasKey('adminBranding', $captured);
    }

    public function testResultsViewResponsesFalseThrowsNonThrowableError(): void
    {
        // results() gates on permissions['viewResponses']; false → throw a
        // NotFoundResponse, which (not being Throwable) fatals with \Error.
        $this->wireForm(PermissionService::ROLE_VIEWER, ['viewResponses' => false]);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot throw objects that do not implement Throwable');
        $this->controller()->results(1);
    }

    public function testResultsRoleNoneWithoutViewResponsesThrows(): void
    {
        // ROLE_NONE naturally yields viewResponses=false → same fatal gate.
        $this->wireForm(PermissionService::ROLE_NONE, ['viewResponses' => false]);

        $this->expectException(\Error::class);
        $this->controller()->results(1);
    }

    public function testResultsPassesUserIdToPermissionLookup(): void
    {
        $file = $this->createMock(File::class);
        $this->formService->method('getFileById')->willReturn($file);
        $this->formService->method('load')->willReturn(['title' => 'T']);
        $this->permissionService->expects($this->once())->method('getRoleFromFile')
            ->with($file, 'carol')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canShareFromFile')->willReturn(false);
        $this->permissionService->method('getPermissionsForRole')->willReturn(['viewResponses' => true]);

        $resp = $this->controller('carol')->results(1);
        $this->assertInstanceOf(TemplateResponse::class, $resp);
    }

    public function testResultsNullUserIdCoercedToEmptyString(): void
    {
        $file = $this->createMock(File::class);
        $this->formService->method('getFileById')->willReturn($file);
        $this->formService->method('load')->willReturn(['title' => 'T']);
        $this->permissionService->expects($this->once())->method('getRoleFromFile')
            ->with($file, '')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canShareFromFile')->with($file, '')->willReturn(false);
        $this->permissionService->method('getPermissionsForRole')->willReturn(['viewResponses' => true]);

        $resp = $this->controller(null)->results(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testResultsPropagatesNotFoundExceptionFromGetFileById(): void
    {
        $this->formService->method('getFileById')
            ->willThrowException(new \OCP\Files\NotFoundException('gone'));

        $this->expectException(\OCP\Files\NotFoundException::class);
        $this->controller()->results(404);
    }
}
