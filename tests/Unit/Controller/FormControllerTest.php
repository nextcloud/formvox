<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\FormController;
use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Security\FormAccessDeniedException;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Security\LinkPasswordPolicy;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormCreatorService;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\FormRevision;
use OCA\FormVox\Service\IntegrationStatus;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ShareTokenService;
use OCA\FormVox\Service\TemplateLimitException;
use OCA\FormVox\Service\TemplateService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Characterization tests for FormController — the HTTP behaviour extracted from
 * the former God-controller ApiController. Access to the form is granted by
 * FormAccessMiddleware before these methods run, so this pins what they do
 * once in: the view-responses stripping, the sharing-rights and link-policy
 * rules on update, the #135 token endpoints, and 500s that never leak the
 * exception text. Everything is mocked; the controller runs against ocp stubs
 * with no server.
 */
class FormControllerTest extends TestCase {
	private FormRepository $formRepository;
	private FormAccessService $access;
	private File $file;
	private PermissionService $permissionService;
	private ResponsePersistenceService $responsePersistence;
	private TemplateService $templateService;
	private ShareTokenService $shareTokenService;
	private IUserSession $userSession;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private INotificationManager $notificationManager;
	private FormCreatorService $creators;
	private IShareManager $shareManager;
	private LoggerInterface $logger;
	private LinkPasswordPolicy $linkPasswords;

	protected function setUp(): void {
		parent::setUp();
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->access = $this->createMock(FormAccessService::class);
		$this->file = $this->createMock(File::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->responsePersistence = $this->createMock(ResponsePersistenceService::class);
		$this->templateService = $this->createMock(TemplateService::class);
		$this->shareTokenService = $this->createMock(ShareTokenService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->creators = $this->createMock(FormCreatorService::class);
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->linkPasswords = $this->createMock(LinkPasswordPolicy::class);

		$this->access->method('getFile')->willReturn($this->file);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	/** The controller leaves the answer to ExceptionMiddleware: it throws one of our own errors. */
	private function assertRefused(callable $call, int $status, string $reason, ?string $message = null): void {
		try {
			$call();
			$this->fail('expected a refusal');
		} catch (ClientErrorException $e) {
			$this->assertSame([$status, $reason], [$e->getStatus(), $e->getReason()]);
			if ($message !== null) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}

	private function controller(): FormController {
		return new FormController(
			$this->createMock(IRequest::class),
			$this->formRepository,
			$this->access,
			$this->permissionService,
			$this->responsePersistence,
			$this->templateService,
			$this->shareTokenService,
			$this->userSession,
			$this->userManager,
			$this->groupManager,
			$this->notificationManager,
			$this->creators,
			$this->shareManager,
			new FormSanitizer(),
			$this->linkPasswords,
			$this->passThroughIntegrationStatus(),
			$this->logger,
			new EnglishL10N(),
		);
	}

	private function passThroughIntegrationStatus(): IntegrationStatus {
		$status = $this->createMock(IntegrationStatus::class);
		$status->method('annotate')->willReturnArgument(1);
		return $status;
	}

	private function storedSettings(array $settings): void {
		$this->formRepository->method('readForm')->with($this->file)->willReturn(['title' => 'T', 'settings' => $settings]);
	}

	private function linkPolicy(bool $allowLinks, bool $sharingDisabled = false): void {
		$this->shareManager->method('shareApiAllowLinks')->willReturn($allowLinks);
		$this->shareManager->method('sharingDisabledForUser')->with('alice')->willReturn($sharingDisabled);
	}

	// ---- get() -------------------------------------------------------------

	public function testGetStripsResponsesWhenNotAllowedToView(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'title' => 'T', 'responses' => [['id' => 'r1']], '_index' => ['x' => 1],
		]);
		$this->permissionService->method('getRoleFromFile')->with($this->file)->willReturn(PermissionService::ROLE_RESPONDENT);
		$this->permissionService->method('getPermissionsForFile')->with($this->file)->willReturn(['viewResponses' => false]);

		$resp = $this->controller()->get(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertArrayNotHasKey('responses', $data['form']);
		$this->assertArrayNotHasKey('_index', $data['form']);
		$this->assertSame(PermissionService::ROLE_RESPONDENT, $data['role']);
		$this->assertSame(['viewResponses' => false], $data['permissions']);
	}

	public function testGetKeepsResponsesWhenAllowed(): void {
		$this->formRepository->method('loadForm')->willReturn(['title' => 'T', 'responses' => [['id' => 'r1']]]);
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
		$this->permissionService->method('getPermissionsForFile')->willReturn(['viewResponses' => true]);

		$data = $this->controller()->get(1)->getData();
		$this->assertSame([['id' => 'r1']], $data['form']['responses']);
	}

	public function testGetIncludesTheCreatorAndTheirStatus(): void {
		$form = ['title' => 'T', 'permissions' => ['owner' => 'anna']];
		$this->formRepository->method('loadForm')->willReturn($form);
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
		$this->permissionService->method('getPermissionsForFile')->willReturn(['viewResponses' => true]);
		$creator = ['id' => 'anna', 'displayName' => 'Anna', 'status' => 'no_access'];
		$this->creators->expects($this->once())->method('getCreator')->with(1, $form)->willReturn($creator);

		$this->assertSame($creator, $this->controller()->get(1)->getData()['creator']);
	}

	// ---- getCreator() ------------------------------------------------------

	public function testGetCreatorReturnsTheCreatorStatus(): void {
		$form = ['title' => 'T', 'permissions' => ['owner' => 'anna']];
		$this->formRepository->method('loadForm')->with(3)->willReturn($form);
		$creator = ['id' => 'anna', 'displayName' => 'Anna', 'status' => 'ok'];
		$this->creators->method('getCreator')->with(3, $form)->willReturn($creator);

		$resp = $this->controller()->getCreator(3);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame($creator, $resp->getData());
	}

	// ---- delete() ----------------------------------------------------------

	public function testDeleteRemovesTheForm(): void {
		$this->formRepository->expects($this->once())->method('delete')->with(1);

		$resp = $this->controller()->delete(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	// ---- rotateShareToken() (#135) -----------------------------------------

	public function testRotateShareTokenRefusesWhenThereIsNoLink(): void {
		$this->linkPolicy(true);
		$this->formRepository->method('loadForm')->willReturn(['settings' => []]);
		$this->shareTokenService->method('rotate')->willThrowException(RequestException::invalid('This form has no share link to replace'));
		$this->formRepository->expects($this->never())->method('update');

		$this->assertRefused(fn () => $this->controller()->rotateShareToken(1), Http::STATUS_BAD_REQUEST, 'invalid_input', 'This form has no share link to replace');
	}

	public function testRotateShareTokenSucceeds(): void {
		$this->linkPolicy(true);
		$this->formRepository->method('loadForm')->willReturn(['settings' => ['public_token' => 'OLD']]);
		$this->shareTokenService->method('rotate')->with(['public_token' => 'OLD'])->willReturn(['public_token' => 'NEW']);
		$this->formRepository->expects($this->once())->method('update')
			->with(1, ['settings' => ['public_token' => 'NEW']])
			->willReturn(['settings' => ['public_token' => 'NEW']]);

		$this->permissionService->method('getPermissionsForFile')->willReturn(['viewResponses' => true, 'editSettings' => true, 'canShare' => true]);

		$resp = $this->controller()->rotateShareToken(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['form' => ['settings' => ['public_token' => 'NEW', 'has_share_password' => false], 'revision' => FormRevision::of(['settings' => []])]], $resp->getData());
	}

	/**
	 * @return array<string, array{bool, bool}>
	 */
	public static function linksNotAllowed(): array {
		return [
			'public links disabled' => [false, false],
			'sharing disabled for the user' => [true, true],
		];
	}

	/**
	 * @dataProvider linksNotAllowed
	 */
	public function testRotateShareTokenDeniedWhenLinksAreNotAllowed(bool $allowLinks, bool $sharingDisabled): void {
		$this->linkPolicy($allowLinks, $sharingDisabled);
		$this->shareTokenService->expects($this->never())->method('rotate');
		$this->formRepository->expects($this->never())->method('update');

		$this->assertRefused(fn () => $this->controller()->rotateShareToken(1), Http::STATUS_FORBIDDEN, 'turned_off', 'Public links are not allowed');
	}

	// ---- list() ------------------------------------------------------------

	public function testListReturnsForms(): void {
		$this->formRepository->method('listForms')->willReturn([['id' => 1]]);
		$resp = $this->controller()->list();
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame([['id' => 1]], $resp->getData());
	}

	/** No catch of its own: ExceptionMiddleware logs it and answers without the text (ExceptionMiddlewareTest). */
	public function testListLeavesErrorsToTheExceptionMiddleware(): void {
		$this->formRepository->method('listForms')->willThrowException(new \Exception('/data/alice/files secret'));

		$this->expectExceptionMessage('/data/alice/files secret');
		$this->controller()->list();
	}

	// ---- create() ----------------------------------------------------------

	public function testCreateReturns201(): void {
		$this->formRepository->method('create')->willReturn(['fileId' => 5]);
		$resp = $this->controller()->create('Title');
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$this->assertSame(['fileId' => 5], $resp->getData());
	}

	/** No catch of its own: ExceptionMiddleware logs it and answers without the text (ExceptionMiddlewareTest). */
	public function testCreateLeavesErrorsToTheExceptionMiddleware(): void {
		$this->formRepository->method('create')->willThrowException(new \Exception('/data/alice/files secret'));

		$this->expectExceptionMessage('/data/alice/files secret');
		$this->controller()->create('Title');
	}

	public function testCreateSendsNotificationWhenNotifyOnReady(): void {
		$this->formRepository->method('create')->willReturn(['fileId' => 7]);
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

	public function testCreatePullsAdminTemplateIntoPrefilled(): void {
		$this->templateService->method('getTemplate')->willReturn([
			'description' => 'Tpl desc',
			'questions' => [['q' => 1]],
			'pages' => [['id' => 'p1']],
			'settings' => ['allow_multiple' => true],
		]);
		$this->formRepository->expects($this->once())->method('create')
			->with('Title', '', null, [
				'description' => 'Tpl desc',
				'questions' => [['q' => 1]],
				'pages' => [['id' => 'p1']],
				'settings' => ['allow_multiple' => true],
			])
			->willReturn(['fileId' => 9]);

		$resp = $this->controller()->create('Title', '', null, [], false, 'tpl-1');
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
	}

	/** Otherwise a request could create a form with its own API key or webhook built in. */
	public function testCreateTakesNoSettingsOrPagesFromTheRequest(): void {
		$this->formRepository->expects($this->once())->method('create')
			->with('Title', '', null, ['questions' => [['q' => 1]]])
			->willReturn(['fileId' => 9]);

		$this->controller()->create('Title', '', null, [
			'questions' => [['q' => 1]],
			'settings' => ['api_keys' => [['id' => 'k', 'hash' => 'known']]],
			'pages' => [['id' => 'p']],
		]);
	}

	// ---- saveAsTemplate() --------------------------------------------------

	public function testSaveAsTemplateSucceeds(): void {
		$form = ['title' => 'Orig', 'description' => 'D'];
		$this->formRepository->method('loadForm')->willReturn($form);
		$this->templateService->expects($this->once())->method('addTemplate')
			->with('Orig', 'D', $form)
			->willReturn(['id' => 'tpl-1']);

		$resp = $this->controller()->saveAsTemplate(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['template' => ['id' => 'tpl-1']], $resp->getData());
	}

	public function testSaveAsTemplateAtTheLimitIsRefused(): void {
		$this->formRepository->method('loadForm')->willReturn(['title' => 'Orig']);
		$this->templateService->method('addTemplate')->willThrowException(TemplateLimitException::reached());

		$this->assertRefused(fn () => $this->controller()->saveAsTemplate(1), Http::STATUS_CONFLICT, 'template_limit');
	}

	// ---- setFavorite() -----------------------------------------------------

	public function testSetFavoriteSucceeds(): void {
		$this->formRepository->expects($this->once())->method('update')->with(1, ['favorite' => true]);

		$resp = $this->controller()->setFavorite(1, true);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true, 'favorite' => true], $resp->getData());
	}

	// ---- update() ----------------------------------------------------------

	public function testUpdateRefusesASaveWithNothingInIt(): void {
		$this->formRepository->expects($this->never())->method('update');

		$this->assertRefused(fn () => $this->controller()->update(1), Http::STATUS_BAD_REQUEST, 'invalid_input', 'No data provided');
	}

	/** Review: the editor sent it on every save and it was dropped, so a chosen alignment never stuck. */
	public function testUpdateForwardsTheDescriptionAlignment(): void {
		$this->formRepository->expects($this->once())->method('update')->with(1, ['descriptionAlign' => 'center'])->willReturn(['descriptionAlign' => 'center']);

		$this->controller()->update(1, descriptionAlign: 'center');
	}

	public function testUpdateSucceeds(): void {
		$this->formRepository->expects($this->once())->method('update')
			->with(1, ['title' => 'New title'])
			->willReturn(['title' => 'New title']);

		$resp = $this->controller()->update(1, 'New title');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['form' => ['title' => 'New title', 'revision' => FormRevision::of(['title' => 'New title'])]], $resp->getData());
	}

	/**
	 * @return array<string, array{array, array}>
	 */
	public static function sharingChanges(): array {
		return [
			'revoke the link' => [['public_token' => 'TOK'], ['public_token' => null]],
			'create a link' => [[], ['public_token' => 'new']],
			'create a link with "0", which the token service takes as a request too' => [[], ['public_token' => '0']],
			'create a link with a number' => [['public_token' => null], ['public_token' => 0]],
			'set a share password' => [['public_token' => 'TOK'], ['public_token' => 'TOK', 'share_password' => 'pw']],
			'remove the share password' => [['public_token' => 'TOK', 'share_password_hash' => 'enc:v1:pw'], ['share_password' => null]],
			'remove the share password with an empty string' => [['public_token' => 'TOK', 'share_password_hash' => 'enc:v1:pw'], ['share_password' => '']],
			'change the expiry' => [['public_token' => 'TOK'], ['public_token' => 'TOK', 'share_expires_at' => '2030-01-01T00:00:00Z']],
		];
	}

	/** A save cannot change them (FormRepository ignores them), so it needs no extra rights for them either. */
	public function testUpdateLeavesApiKeysAndWebhooksToTheirOwnEndpoints(): void {
		$incoming = ['api_keys' => [['id' => 'k1', 'hash' => '[hidden]']], 'webhooks' => [['id' => 'w1', 'secret' => '[hidden]']]];
		$this->storedSettings([]);
		$this->access->expects($this->never())->method('allows');
		$this->shareTokenService->method('resolveTokenForUpdate')->willReturnArgument(0);
		$this->formRepository->expects($this->once())->method('update')->willReturn(['settings' => []]);

		$resp = $this->controller()->update(1, settings: $incoming);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	/**
	 * @dataProvider sharingChanges
	 */
	public function testUpdateDeniesSharingChangesWithoutManageSharing(array $stored, array $incoming): void {
		$this->storedSettings($stored);
		$this->access->method('allows')->with($this->file, FormPermission::MANAGE_SHARING)->willReturn(false);
		$this->formRepository->expects($this->never())->method('update');

		$this->expectException(FormAccessDeniedException::class);
		$this->controller()->update(1, 'T', settings: $incoming);
	}

	/**
	 * @return array<string, array{array}>
	 */
	public static function unchangedSharing(): array {
		$stored = [
			'public_token' => 'TOK',
			'share_password_hash' => '$2y$10$hash',
			'api_keys' => [['id' => 'k1', 'key' => 'secret']],
			'allow_multiple' => false,
		];
		return [
			'sent back as stored' => [array_merge($stored, ['allow_multiple' => true])],
			'left out' => [['allow_multiple' => true]],
			'the dates as they are' => [['share_expires_at' => null, 'share_starts_at' => null]],
		];
	}

	/** Without a password there is nothing to remove, so an editor's save that says so changes nothing. */
	public function testAnEditorMaySendNoPasswordWhileTheLinkHasNone(): void {
		$this->storedSettings(['public_token' => 'TOK']);
		$this->access->method('allows')->willReturn(false);
		$this->shareTokenService->method('resolveTokenForUpdate')->willReturnArgument(0);
		$this->formRepository->expects($this->once())->method('update')->willReturn(['settings' => []]);

		$resp = $this->controller()->update(1, settings: ['share_password' => null]);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	/** The value is only an intent marker; "0" asks for a link like "new" does, with everything that comes with one. */
	public function testAZeroAsksForALinkLikeAnyOtherValue(): void {
		$this->storedSettings([]);
		$this->access->method('allows')->with($this->file, FormPermission::MANAGE_SHARING)->willReturn(true);
		$this->linkPolicy(true);
		$this->linkPasswords->method('isEnforced')->willReturn(true);
		$this->linkPasswords->method('generate')->willReturn('Gen-Pass-123');
		$this->shareTokenService->method('resolveTokenForUpdate')
			->with(['public_token' => '0', 'share_password' => 'Gen-Pass-123'], true, null)
			->willReturn(['public_token' => 'MINTED', 'share_password' => 'Gen-Pass-123']);
		$this->formRepository->expects($this->once())->method('update')
			->with(1, ['settings' => ['public_token' => 'MINTED', 'share_password' => 'Gen-Pass-123']])
			->willReturn(['settings' => ['public_token' => 'MINTED']]);

		$resp = $this->controller()->update(1, settings: ['public_token' => '0']);

		$this->assertSame('Gen-Pass-123', $resp->getData()['generatedSharePassword']);
	}

	public function testAZeroIsRefusedWhereLinksAreNotAllowed(): void {
		$this->storedSettings([]);
		$this->access->method('allows')->willReturn(true);
		$this->linkPolicy(false);
		$this->formRepository->expects($this->never())->method('update');

		$this->assertRefused(fn () => $this->controller()->update(1, settings: ['public_token' => '0']), Http::STATUS_FORBIDDEN, 'turned_off', 'Public links are not allowed');
	}

	/**
	 * @dataProvider unchangedSharing
	 */
	public function testUpdateLetsAnEditorSaveWithUnchangedSharingSettings(array $incoming): void {
		$this->storedSettings([
			'public_token' => 'TOK',
			'share_password_hash' => '$2y$10$hash',
			'api_keys' => [['id' => 'k1', 'key' => 'secret']],
			'allow_multiple' => false,
		]);
		$this->access->method('allows')->willReturn(false);
		$this->shareTokenService->expects($this->once())->method('resolveTokenForUpdate')
			->with($incoming, true, 'TOK')
			->willReturnArgument(0);
		$this->formRepository->expects($this->once())->method('update')
			->with(1, ['title' => 'T', 'settings' => $incoming])
			->willReturn(['title' => 'T', 'settings' => $incoming]);

		$resp = $this->controller()->update(1, 'T', settings: $incoming);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	/**
	 * @dataProvider linksNotAllowed
	 */
	public function testUpdateDeniesCreatingALinkWhenLinksAreNotAllowed(bool $allowLinks, bool $sharingDisabled): void {
		$this->storedSettings(['public_token' => null]);
		$this->access->method('allows')->willReturn(true);
		$this->linkPolicy($allowLinks, $sharingDisabled);
		$this->formRepository->expects($this->never())->method('update');

		$this->assertRefused(fn () => $this->controller()->update(1, settings: ['public_token' => 'new']), Http::STATUS_FORBIDDEN, 'turned_off', 'Public links are not allowed');
	}

	public function testUpdateCreatesALinkWithFullRightsWhenLinksAreAllowed(): void {
		$this->storedSettings([]);
		$this->access->method('allows')->with($this->file, FormPermission::MANAGE_SHARING)->willReturn(true);
		$this->linkPolicy(true);
		$this->shareTokenService->method('resolveTokenForUpdate')
			->with(['public_token' => 'new'], true, null)
			->willReturn(['public_token' => 'MINTED']);
		$this->formRepository->expects($this->once())->method('update')
			->with(1, ['settings' => ['public_token' => 'MINTED']])
			->willReturn(['settings' => ['public_token' => 'MINTED']]);

		$resp = $this->controller()->update(1, settings: ['public_token' => 'new']);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	// ---- Nextcloud's link-password rules (M39) ----

	public function testAPasswordNextcloudRefusesIsNotSaved(): void {
		$this->storedSettings(['public_token' => 'TOK']);
		$this->access->method('allows')->willReturn(true);
		$this->linkPasswords->method('rejectionFor')->with('short')->willReturn('Password needs to be at least 10 characters long.');
		$this->formRepository->expects($this->never())->method('update');

		$this->assertRefused(fn () => $this->controller()->update(1, settings: ['share_password' => 'short']), Http::STATUS_BAD_REQUEST, 'invalid_input', 'Password needs to be at least 10 characters long.');
	}

	public function testWhereAPasswordIsRequiredANewLinkGetsAGeneratedOneShownOnce(): void {
		$this->storedSettings([]);
		$this->access->method('allows')->willReturn(true);
		$this->linkPolicy(true);
		$this->linkPasswords->method('isEnforced')->willReturn(true);
		$this->linkPasswords->method('generate')->willReturn('Gen-Pass-123');
		$this->shareTokenService->method('resolveTokenForUpdate')->willReturnCallback(fn (array $settings) => ['public_token' => 'MINTED'] + $settings);
		$this->formRepository->expects($this->once())->method('update')
			->with(1, ['settings' => ['public_token' => 'MINTED', 'share_password' => 'Gen-Pass-123']])
			->willReturn(['settings' => ['public_token' => 'MINTED']]);

		$resp = $this->controller()->update(1, settings: ['public_token' => 'new']);

		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame('Gen-Pass-123', $resp->getData()['generatedSharePassword']);
	}

	public function testANewLinkWithItsOwnPasswordNeedsNoGeneratedOne(): void {
		$this->storedSettings([]);
		$this->access->method('allows')->willReturn(true);
		$this->linkPolicy(true);
		$this->linkPasswords->method('isEnforced')->willReturn(true);
		$this->linkPasswords->expects($this->never())->method('generate');
		$this->shareTokenService->method('resolveTokenForUpdate')->willReturnArgument(0);
		$this->formRepository->method('update')->willReturn(['settings' => []]);

		$resp = $this->controller()->update(1, settings: ['public_token' => 'new', 'share_password' => 'my own long password']);

		$this->assertArrayNotHasKey('generatedSharePassword', $resp->getData());
	}

	public function testWhereAPasswordIsRequiredItCannotBeRemoved(): void {
		$this->storedSettings(['public_token' => 'TOK', 'share_password_hash' => 'enc:v1:pw']);
		$this->access->method('allows')->willReturn(true);
		$this->linkPasswords->method('isEnforced')->willReturn(true);
		$this->formRepository->expects($this->never())->method('update');

		$this->assertRefused(fn () => $this->controller()->update(1, settings: ['share_password' => null]), Http::STATUS_BAD_REQUEST, 'invalid_input', 'A password is required for public links');
	}

	/**
	 * @return array<string, array{array}>
	 */
	public static function changesAllowedWhilePasswordsAreRequired(): array {
		return [
			'deleting the link with its password' => [['public_token' => null, 'share_password' => null]],
			'saving other settings of a link from before enforcement' => [['allow_multiple' => true]],
			'changing only the expiry' => [['share_expires_at' => '2030-01-01T00:00:00Z']],
		];
	}

	/**
	 * @dataProvider changesAllowedWhilePasswordsAreRequired
	 */
	public function testOnlyCreatingALinkOrRemovingItsPasswordIsChecked(array $incoming): void {
		$this->storedSettings(['public_token' => 'TOK']);
		$this->access->method('allows')->willReturn(true);
		$this->linkPasswords->method('isEnforced')->willReturn(true);
		$this->shareTokenService->method('resolveTokenForUpdate')->willReturnArgument(0);
		$this->formRepository->expects($this->once())->method('update')->willReturn(['settings' => []]);

		$resp = $this->controller()->update(1, settings: $incoming);

		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	/** Withdrawing link sharing must not trap existing links. */
	public function testUpdateRevokesALinkEvenWhenLinksAreDisabled(): void {
		$this->storedSettings(['public_token' => 'TOK']);
		$this->access->method('allows')->willReturn(true);
		$this->linkPolicy(false);
		$this->shareTokenService->method('resolveTokenForUpdate')->willReturnArgument(0);
		$this->formRepository->expects($this->once())->method('update')
			->with(1, ['settings' => ['public_token' => null]]);

		$resp = $this->controller()->update(1, settings: ['public_token' => null]);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	/** Another session rotated the token; an editor's autosave of the old one changes nothing (#135). */
	public function testUpdateLetsAnEditorSaveAStaleToken(): void {
		$this->storedSettings(['public_token' => 'rotated-token']);
		$this->access->method('allows')->willReturn(false);
		$this->shareTokenService->method('resolveTokenForUpdate')->willReturn(['public_token' => 'rotated-token']);
		$this->formRepository->expects($this->once())->method('update');

		$resp = $this->controller()->update(1, settings: ['public_token' => 'old-token']);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	/**
	 * A form that stays unreadable fails the save with a 503 to retry: its settings are neither saved unchecked nor
	 * silently dropped while the rest is saved (#135, 7a 3a-3).
	 */
	public function testASettingsSaveOfAFormThatStaysUnreadableFailsAsAWhole(): void {
		$this->formRepository->method('readForm')->willThrowException(new FormLockedException('Form 1 stayed unreadable'));
		$this->formRepository->expects($this->never())->method('update');

		$this->expectException(FormLockedException::class);
		$this->controller()->update(1, 'T', settings: ['allow_multiple' => true]);
	}

	// ---- rebuildIndex() ----------------------------------------------------

	public function testRebuildIndexSucceeds(): void {
		// Under the lock, on the fresh form (L21), not load-then-update.
		$this->responsePersistence->expects($this->once())->method('rebuildIndex')->with(1);
		$this->formRepository->expects($this->never())->method('update');

		$resp = $this->controller()->rebuildIndex(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	// ---- searchSharees() ---------------------------------------------------

	public function testSearchShareesReturnsUsersAndGroups(): void {
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

	/** No catch of its own: ExceptionMiddleware logs it and answers without the text (ExceptionMiddlewareTest). */
	public function testSearchShareesLeavesErrorsToTheExceptionMiddleware(): void {
		$this->userManager->method('search')->willThrowException(new \Exception('/data/alice/files secret'));

		$this->expectExceptionMessage('/data/alice/files secret');
		$this->controller()->searchSharees('x');
	}
}
