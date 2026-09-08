<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\ApiController;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\ShareTokenService;
use OCA\FormVox\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for ApiController — the HTTP behaviour that must NOT
 * regress when the controller is split into Form/Response/Export/Upload
 * controllers. Pins: permission gating (403), not-found mapping (404), the
 * view-responses stripping, delete gating, and the #135 token endpoints.
 *
 * Everything is mocked; the controller runs against ocp stubs with no server.
 * These tests move with their methods when the controller is split, proving the
 * split preserved behaviour.
 */
class ApiControllerTest extends TestCase
{
    private FormService $formService;
    private ResponseService $responseService;
    private PermissionService $permissionService;
    private IndexService $indexService;
    private TemplateService $templateService;
    private IUserSession $userSession;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private ShareTokenService $shareTokenService;
    private INotificationManager $notificationManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formService = $this->createMock(FormService::class);
        $this->responseService = $this->createMock(ResponseService::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->templateService = $this->createMock(TemplateService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->shareTokenService = $this->createMock(ShareTokenService::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function controller(): ApiController
    {
        return new ApiController(
            $this->createMock(IRequest::class),
            $this->formService,
            $this->responseService,
            $this->permissionService,
            $this->indexService,
            $this->templateService,
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->shareTokenService,
            $this->notificationManager
        );
    }

    // ---- get() -------------------------------------------------------------

    public function testGetDeniesWhenRoleNone(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'T', 'responses' => []]);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_NONE);

        $resp = $this->controller()->get(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testGetStripsResponsesWhenNotAllowedToView(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn([
            'title' => 'T', 'responses' => [['id' => 'r1']], '_index' => ['x' => 1],
        ]);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_RESPONDENT);
        $this->permissionService->method('getPermissionsForRole')->willReturn(['viewResponses' => false]);

        $resp = $this->controller()->get(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $data = $resp->getData();
        $this->assertArrayNotHasKey('responses', $data['form']);
        $this->assertArrayNotHasKey('_index', $data['form']);
        $this->assertSame(PermissionService::ROLE_RESPONDENT, $data['role']);
    }

    public function testGetKeepsResponsesWhenAllowed(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'T', 'responses' => [['id' => 'r1']]]);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('getPermissionsForRole')->willReturn(['viewResponses' => true]);

        $data = $this->controller()->get(1)->getData();
        $this->assertSame([['id' => 'r1']], $data['form']['responses']);
    }

    public function testGetMapsNotFoundTo404(): void
    {
        $this->formService->method('getFileById')->willThrowException(new NotFoundException());
        $resp = $this->controller()->get(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    // ---- delete() ----------------------------------------------------------

    public function testDeleteDeniedWhenCannotDeleteForm(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canDeleteForm')->willReturn(false);
        $this->formService->expects($this->never())->method('delete');

        $resp = $this->controller()->delete(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testDeleteProceedsWhenAllowed(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canDeleteForm')->willReturn(true);
        $this->formService->expects($this->once())->method('delete')->with(1);

        $resp = $this->controller()->delete(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    // ---- deleteResponse() --------------------------------------------------

    public function testDeleteResponseDeniedWithoutPermission(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canDeleteResponses')->willReturn(false);
        $this->formService->expects($this->never())->method('deleteResponse');

        $resp = $this->controller()->deleteResponse(1, 'r1');
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    // ---- rotateShareToken() (#135) -----------------------------------------

    public function testRotateShareTokenDeniedWithoutSettingsPermission(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canEditSettings')->willReturn(false);
        $this->shareTokenService->expects($this->never())->method('rotate');

        $resp = $this->controller()->rotateShareToken(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testRotateShareTokenReturns400WhenNoLink(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canEditSettings')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn(['settings' => []]);
        // Service throws when there's no link to replace.
        $this->shareTokenService->method('rotate')
            ->willThrowException(new \DomainException('This form has no share link to replace'));

        $resp = $this->controller()->rotateShareToken(1);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testRotateShareTokenSucceeds(): void
    {
        $this->formService->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canEditSettings')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn(['settings' => ['public_token' => 'OLD']]);
        $this->shareTokenService->method('rotate')->willReturn(['public_token' => 'NEW']);
        $this->formService->expects($this->once())->method('update')
            ->with(1, ['settings' => ['public_token' => 'NEW']])
            ->willReturn(['settings' => ['public_token' => 'NEW']]);

        $resp = $this->controller()->rotateShareToken(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }
}
