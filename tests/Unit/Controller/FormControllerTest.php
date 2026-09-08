<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\FormController;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\PermissionService;
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
 * Characterization tests for FormController — the HTTP behaviour extracted from
 * the former God-controller ApiController. Pins: permission gating (403),
 * not-found mapping (404), the view-responses stripping, delete gating, and the
 * #135 token endpoints. Everything is mocked; the controller runs against ocp
 * stubs with no server.
 */
class FormControllerTest extends TestCase
{
    private FormService $formService;
    private FormFileLocator $fileLocator;
    private PermissionService $permissionService;
    private IndexService $indexService;
    private TemplateService $templateService;
    private ShareTokenService $shareTokenService;
    private IUserSession $userSession;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private INotificationManager $notificationManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formService = $this->createMock(FormService::class);
        $this->fileLocator = $this->createMock(FormFileLocator::class);
        $this->permissionService = $this->createMock(PermissionService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->templateService = $this->createMock(TemplateService::class);
        $this->shareTokenService = $this->createMock(ShareTokenService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function controller(): FormController
    {
        return new FormController(
            $this->createMock(IRequest::class),
            $this->formService,
            $this->fileLocator,
            $this->permissionService,
            $this->indexService,
            $this->templateService,
            $this->shareTokenService,
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->notificationManager
        );
    }

    // ---- get() -------------------------------------------------------------

    public function testGetDeniesWhenRoleNone(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'T', 'responses' => []]);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_NONE);

        $resp = $this->controller()->get(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testGetStripsResponsesWhenNotAllowedToView(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
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
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['title' => 'T', 'responses' => [['id' => 'r1']]]);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('getPermissionsForRole')->willReturn(['viewResponses' => true]);

        $data = $this->controller()->get(1)->getData();
        $this->assertSame([['id' => 'r1']], $data['form']['responses']);
    }

    public function testGetMapsNotFoundTo404(): void
    {
        $this->fileLocator->method('getFileById')->willThrowException(new NotFoundException());
        $resp = $this->controller()->get(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    // ---- delete() ----------------------------------------------------------

    public function testDeleteDeniedWhenCannotDeleteForm(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canDeleteForm')->willReturn(false);
        $this->formService->expects($this->never())->method('delete');

        $resp = $this->controller()->delete(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testDeleteProceedsWhenAllowed(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canDeleteForm')->willReturn(true);
        $this->formService->expects($this->once())->method('delete')->with(1);

        $resp = $this->controller()->delete(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testDeleteMapsNotFoundTo404(): void
    {
        $this->fileLocator->method('getFileById')->willThrowException(new NotFoundException());
        $resp = $this->controller()->delete(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    // ---- rotateShareToken() (#135) -----------------------------------------

    public function testRotateShareTokenDeniedWithoutSettingsPermission(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canEditSettings')->willReturn(false);
        $this->shareTokenService->expects($this->never())->method('rotate');

        $resp = $this->controller()->rotateShareToken(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testRotateShareTokenReturns400WhenNoLink(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
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
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
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

    // ---- list() ------------------------------------------------------------

    public function testListReturnsForms(): void
    {
        $this->formService->method('listForms')->willReturn([['id' => 1]]);
        $resp = $this->controller()->list();
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame([['id' => 1]], $resp->getData());
    }

    public function testListMapsErrorTo500(): void
    {
        $this->formService->method('listForms')->willThrowException(new \Exception('boom'));
        $resp = $this->controller()->list();
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
    }

    // ---- create() ----------------------------------------------------------

    public function testCreateReturns201(): void
    {
        $this->formService->method('create')->willReturn(['fileId' => 5]);
        $resp = $this->controller()->create('Title');
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
        $this->assertSame(['fileId' => 5], $resp->getData());
    }

    public function testCreateMapsErrorTo500(): void
    {
        $this->formService->method('create')->willThrowException(new \Exception('boom'));
        $resp = $this->controller()->create('Title');
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
    }

    public function testCreateSendsNotificationWhenNotifyOnReady(): void
    {
        $this->formService->method('create')->willReturn(['fileId' => 7]);
        $notification = $this->createMock(\OCP\Notification\INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify')->with($notification);

        $resp = $this->controller()->create('Title', '', null, [], true);
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
    }

    public function testCreatePullsAdminTemplateIntoPrefilled(): void
    {
        $this->templateService->method('getTemplate')->willReturn([
            'description' => 'Tpl desc',
            'questions' => [['q' => 1]],
        ]);
        $this->formService->expects($this->once())->method('create')
            ->with('Title', '', null, [
                'description' => 'Tpl desc',
                'questions' => [['q' => 1]],
            ])
            ->willReturn(['fileId' => 9]);

        $resp = $this->controller()->create('Title', '', null, [], false, 'tpl-1');
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
    }

    // ---- saveAsTemplate() --------------------------------------------------

    public function testSaveAsTemplateSucceeds(): void
    {
        $this->formService->method('load')->willReturn(['title' => 'Orig', 'description' => 'D']);
        $this->templateService->method('addTemplate')->willReturn(['id' => 'tpl-1']);
        $resp = $this->controller()->saveAsTemplate(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['template' => ['id' => 'tpl-1']], $resp->getData());
    }

    public function testSaveAsTemplateMapsErrorTo400(): void
    {
        $this->formService->method('load')->willThrowException(new \RuntimeException('nope'));
        $resp = $this->controller()->saveAsTemplate(1);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    // ---- setFavorite() -----------------------------------------------------

    public function testSetFavoriteDeniedWhenRoleNone(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_NONE);
        $this->formService->expects($this->never())->method('update');

        $resp = $this->controller()->setFavorite(1, true);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testSetFavoriteSucceeds(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->formService->expects($this->once())->method('update')->with(1, ['favorite' => true]);

        $resp = $this->controller()->setFavorite(1, true);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['success' => true, 'favorite' => true], $resp->getData());
    }

    public function testSetFavoriteMapsNotFoundTo404(): void
    {
        $this->fileLocator->method('getFileById')->willThrowException(new NotFoundException());
        $resp = $this->controller()->setFavorite(999, true);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    // ---- update() ----------------------------------------------------------

    public function testUpdateReturns400WhenNoData(): void
    {
        $resp = $this->controller()->update(1);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testUpdateDeniedWhenCannotEditQuestions(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canEditQuestions')->willReturn(false);
        $this->formService->expects($this->never())->method('update');

        $resp = $this->controller()->update(1, 'New title');
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testUpdateSucceeds(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
        $this->permissionService->method('canEditQuestions')->willReturn(true);
        $this->formService->expects($this->once())->method('update')
            ->with(1, ['title' => 'New title'])
            ->willReturn(['title' => 'New title']);

        $resp = $this->controller()->update(1, 'New title');
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['form' => ['title' => 'New title']], $resp->getData());
    }

    public function testUpdateMapsNotFoundTo404(): void
    {
        $this->fileLocator->method('getFileById')->willThrowException(new NotFoundException());
        $resp = $this->controller()->update(999, 'New title');
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    // ---- rebuildIndex() ----------------------------------------------------

    public function testRebuildIndexDeniedWithoutSettingsPermission(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['_index' => []]);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
        $this->permissionService->method('canEditSettings')->willReturn(false);
        $this->indexService->expects($this->never())->method('rebuildIndex');

        $resp = $this->controller()->rebuildIndex(1);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testRebuildIndexSucceeds(): void
    {
        $this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
        $this->formService->method('load')->willReturn(['_index' => ['old' => 1]]);
        $this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
        $this->permissionService->method('canEditSettings')->willReturn(true);
        $this->indexService->expects($this->once())->method('rebuildIndex');
        $this->formService->expects($this->once())->method('update');

        $resp = $this->controller()->rebuildIndex(1);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['success' => true], $resp->getData());
    }

    // ---- searchSharees() ---------------------------------------------------

    public function testSearchShareesReturnsUsersAndGroups(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $user->method('getDisplayName')->willReturn('Bob');
        $this->userManager->method('search')->willReturn([$user]);

        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn('team');
        $group->method('getDisplayName')->willReturn('Team');
        $this->groupManager->method('search')->willReturn([$group]);

        $resp = $this->controller()->searchSharees('b');
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $data = $resp->getData();
        $this->assertSame([['id' => 'bob', 'displayName' => 'Bob']], $data['users']);
        $this->assertSame([['id' => 'team', 'displayName' => 'Team']], $data['groups']);
    }

    public function testSearchShareesMapsErrorTo500(): void
    {
        $this->userManager->method('search')->willThrowException(new \Exception('boom'));
        $resp = $this->controller()->searchSharees('x');
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
    }
}
