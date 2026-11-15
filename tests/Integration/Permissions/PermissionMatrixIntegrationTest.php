<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Permissions;

use OCA\FormVox\Controller\BrandingController;
use OCA\FormVox\Controller\ExportController;
use OCA\FormVox\Controller\ExternalApiController;
use OCA\FormVox\Controller\FilePermissionController;
use OCA\FormVox\Controller\FormController;
use OCA\FormVox\Controller\FormUploadController;
use OCA\FormVox\Controller\IntegrationController;
use OCA\FormVox\Controller\PageController;
use OCA\FormVox\Controller\PresenceController;
use OCA\FormVox\Controller\ResponseController;
use OCA\FormVox\Middleware\ExceptionMiddleware;
use OCA\FormVox\Middleware\FormAccessMiddleware;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormCreatorService;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ResponseNotificationService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * FormVox rights are Nextcloud's rights, proven against real Groupfolders, ACL rules and shares:
 * the role, every endpoint's answer (through FormAccessMiddleware), what the UI is given,
 * and the creator rules of #146. CI sets FORMVOX_REQUIRE_GROUPFOLDERS so a missing app fails.
 *
 * @group DB
 */
class PermissionMatrixIntegrationTest extends IntegrationTestCase {
	private const FOLDER = 'FormVox Permission IT';
	private const SUBFOLDER = 'Forms';
	/** A folder in the creator's home: a share of it can carry delete, which a share of a file never does. */
	private const SHARED_FOLDER = 'Shared forms';
	private const WRITERS = 'fvperm-writers';
	private const READERS = 'fvperm-readers';

	/** Every account in the fixture and the groups it is in. */
	private const ACCOUNTS = [
		'fvperm-creator' => [self::WRITERS],
		'fvperm-writer' => [self::WRITERS],
		'fvperm-reader' => [self::READERS],
		'fvperm-aclread' => [self::WRITERS],
		'fvperm-aclgrant' => [self::READERS],
		'fvperm-acldeny' => [self::WRITERS],
		'fvperm-outsider' => [],
		'fvperm-share-read' => [],
		'fvperm-share-edit' => [],
		'fvperm-share-viewonly' => [],
		'fvperm-folder-nodl' => [],
	];

	private static ?int $folderId = null;
	private static int $teamFormId = 0;
	private static int $personalFormId = 0;
	private static int $folderFormId = 0;

	protected function setUp(): void {
		parent::setUp();
		if (self::$folderId === null) {
			$this->createFixture();
		}
	}

	public static function tearDownAfterClass(): void {
		try {
			if (self::$folderId !== null) {
				self::removeLeftoverFolder();
			}
		} catch (\Throwable $e) {
			// best effort; the next run removes leftovers before it starts
		}
		$users = Server::get(IUserManager::class);
		foreach (array_keys(self::ACCOUNTS) as $uid) {
			$users->get($uid)?->delete();
		}
		$groups = Server::get(IGroupManager::class);
		foreach ([self::WRITERS, self::READERS] as $gid) {
			$groups->get($gid)?->delete();
		}
		self::$folderId = null;
		parent::tearDownAfterClass();
	}

	// =====================================================================
	// (a) The role is Nextcloud's verdict
	// =====================================================================

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function roles(): array {
		return [
			'creator, in the writing group' => ['fvperm-creator', 'team', PermissionService::ROLE_ADMIN],
			'member of the writing group' => ['fvperm-writer', 'team', PermissionService::ROLE_ADMIN],
			'member of the read-only group' => ['fvperm-reader', 'team', PermissionService::ROLE_VIEWER],
			'writing group + ACL read-only' => ['fvperm-aclread', 'team', PermissionService::ROLE_VIEWER],
			'read-only group + ACL granting write (an ACL cannot exceed the group)' => ['fvperm-aclgrant', 'team', PermissionService::ROLE_VIEWER],
			'writing group + ACL without read' => ['fvperm-acldeny', 'team', PermissionService::ROLE_NONE],
			'not in the team folder' => ['fvperm-outsider', 'team', PermissionService::ROLE_NONE],
			'personal form, its owner' => ['fvperm-creator', 'personal', PermissionService::ROLE_ADMIN],
			'personal form, shared read-only' => ['fvperm-share-read', 'personal', PermissionService::ROLE_VIEWER],
			'personal form, shared with edit (a file share has no delete)' => ['fvperm-share-edit', 'personal', PermissionService::ROLE_EDITOR],
			'personal form, shared without download' => ['fvperm-share-viewonly', 'personal', PermissionService::ROLE_VIEWER],
			'personal form, not shared' => ['fvperm-outsider', 'personal', PermissionService::ROLE_NONE],
			'folder shared with full rights but no download' => ['fvperm-folder-nodl', 'folder', PermissionService::ROLE_ADMIN],
		];
	}

	/**
	 * @dataProvider roles
	 */
	public function testRoleIsNextcloudsVerdict(string $uid, string $form, string $expected): void {
		$this->loginAs($uid);

		try {
			$file = Server::get(FormFileLocator::class)->getFileById($this->formId($form));
			$role = Server::get(PermissionService::class)->getRoleFromFile($file);
		} catch (NotFoundException $e) {
			$role = PermissionService::ROLE_NONE; // not in the account's own view at all
		}

		$this->assertSame($expected, $role);
		$this->assertSame(
			$role === PermissionService::ROLE_NONE ? Http::STATUS_NOT_FOUND : $expected,
			$this->permissionsEndpointRole($form),
			'GET /api/permissions/{fileId} must report the same role'
		);
	}

	// =====================================================================
	// (b) What the API answers
	// =====================================================================

	/**
	 * 404 = not in the account's view, 403 = visible but not allowed.
	 * `delete` is null where it would delete the shared fixture; deleteForm is checked instead.
	 *
	 * @return array<string, array{string, string, array<string, int|null>}>
	 */
	public static function apiAnswers(): array {
		// 400 and 404 where the right is there but the request names nothing that exists: past the gate.
		$viewer = [
			'get' => 200, 'update' => 403, 'favorite' => 403, 'rebuildIndex' => 403, 'delete' => 403,
			'responses' => 200, 'deleteResponses' => 403, 'deleteResponse' => 403, 'creator' => 200, 'permissions' => 200,
			'results' => 200, 'presence' => 200, 'sendPresence' => 403,
			'exportCsv' => 200, 'exportJson' => 200, 'exportExcel' => 200, 'exportPdfs' => 200, 'responsePdf' => 404,
			'hasOdtTemplate' => 200, 'odtTemplate' => 404, 'uploadOdtTemplate' => 403, 'deleteOdtTemplate' => 403,
			'uploads' => 404, 'upload' => 404,
			'apiKey' => 403, 'deleteApiKey' => 403, 'createWebhook' => 403, 'updateWebhook' => 403, 'deleteWebhook' => 403,
			'rotateLink' => 403, 'uploadBrandingImage' => 403, 'deleteBrandingImage' => 403,
		];
		$editor = array_merge($viewer, [
			'update' => 200, 'favorite' => 200, 'rebuildIndex' => 200, 'deleteResponses' => 200, 'deleteResponse' => 404,
			'sendPresence' => 200, 'uploadOdtTemplate' => 400, 'deleteOdtTemplate' => 200,
			'uploadBrandingImage' => 400, 'deleteBrandingImage' => 200,
		]);
		// Full rights; rotating answers 400 because the fixture has no link yet, a webhook without a URL 400.
		$admin = array_merge($editor, [
			'delete' => null, 'apiKey' => 201, 'deleteApiKey' => 404,
			'createWebhook' => 400, 'updateWebhook' => 404, 'deleteWebhook' => 404, 'rotateLink' => 400,
		]);
		$noDownload = ['exportCsv' => 403, 'exportJson' => 403, 'exportExcel' => 403, 'exportPdfs' => 403, 'responsePdf' => 403, 'odtTemplate' => 403, 'uploads' => 403, 'upload' => 403];
		$viewOnly = array_merge($viewer, $noDownload);
		// Full rights, so a key or link may be made; a webhook sends the responses out, so it needs download like an export.
		$fullWithoutDownload = array_merge($admin, $noDownload, ['createWebhook' => 403]);
		$none = array_map(fn () => 404, $viewer);

		return [
			'member of the writing group' => ['fvperm-writer', 'team', $admin],
			'member of the read-only group' => ['fvperm-reader', 'team', $viewer],
			'writing group + ACL read-only' => ['fvperm-aclread', 'team', $viewer],
			'read-only group + ACL granting write' => ['fvperm-aclgrant', 'team', $viewer],
			'writing group + ACL without read' => ['fvperm-acldeny', 'team', $none],
			'not in the team folder' => ['fvperm-outsider', 'team', $none],
			'personal form, shared read-only' => ['fvperm-share-read', 'personal', $viewer],
			'personal form, shared without download' => ['fvperm-share-viewonly', 'personal', $viewOnly],
			'personal form, shared with edit (no delete, no reshare)' => ['fvperm-share-edit', 'personal', $editor],
			'folder shared with full rights but no download' => ['fvperm-folder-nodl', 'folder', $fullWithoutDownload],
		];
	}

	/**
	 * @dataProvider apiAnswers
	 * @param array<string, int|null> $expected
	 */
	public function testApiAnswersFollowNextcloudPermissions(string $uid, string $form, array $expected): void {
		$this->loginAs($uid);
		$fileId = $this->formId($form);
		$endpoints = [
			'get' => [FormController::class, 'get', [$fileId]],
			'update' => [FormController::class, 'update', [$fileId, null, 'Saved by ' . $uid]],
			'favorite' => [FormController::class, 'setFavorite', [$fileId, true]],
			'rebuildIndex' => [FormController::class, 'rebuildIndex', [$fileId]],
			'delete' => [FormController::class, 'delete', [$fileId]],
			'responses' => [ResponseController::class, 'getResponses', [$fileId]],
			'deleteResponses' => [ResponseController::class, 'deleteAllResponses', [$fileId]],
			'deleteResponse' => [ResponseController::class, 'deleteResponse', [$fileId, 'no-such-response']],
			'creator' => [FormController::class, 'getCreator', [$fileId]],
			'permissions' => [FilePermissionController::class, 'getPermissions', [$fileId]],
			'results' => [PageController::class, 'results', [$fileId]],
			'presence' => [PresenceController::class, 'getPresence', [$fileId]],
			'sendPresence' => [PresenceController::class, 'sendPresence', [$fileId]],
			'exportCsv' => [ExportController::class, 'exportCsv', [$fileId]],
			'exportJson' => [ExportController::class, 'exportJson', [$fileId]],
			'exportExcel' => [ExportController::class, 'exportExcel', [$fileId]],
			'exportPdfs' => [ExportController::class, 'exportPdfs', [$fileId]],
			'responsePdf' => [ResponseController::class, 'getResponsePdf', [$fileId, 'no-such-response']],
			'hasOdtTemplate' => [ExportController::class, 'hasOdtTemplate', [$fileId]],
			'odtTemplate' => [ExportController::class, 'downloadOdtTemplate', [$fileId]],
			'uploadOdtTemplate' => [ExportController::class, 'uploadOdtTemplate', [$fileId]],
			'deleteOdtTemplate' => [ExportController::class, 'deleteOdtTemplate', [$fileId]],
			'uploads' => [FormUploadController::class, 'downloadAllUploads', [$fileId]],
			'upload' => [FormUploadController::class, 'downloadUpload', [$fileId, '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', 'none.pdf']],
			'apiKey' => [IntegrationController::class, 'createApiKey', [$fileId, 'IT key', ['read_form']]],
			'deleteApiKey' => [IntegrationController::class, 'deleteApiKey', [$fileId, 'no-such-key']],
			'createWebhook' => [IntegrationController::class, 'createWebhook', [$fileId]],
			'updateWebhook' => [IntegrationController::class, 'updateWebhook', [$fileId, 'no-such-webhook']],
			'deleteWebhook' => [IntegrationController::class, 'deleteWebhook', [$fileId, 'no-such-webhook']],
			'rotateLink' => [FormController::class, 'rotateShareToken', [$fileId]],
			'uploadBrandingImage' => [BrandingController::class, 'uploadFormBlockImage', [$fileId, 'logo']],
			'deleteBrandingImage' => [BrandingController::class, 'deleteFormBlockImage', [$fileId, 'logo']],
		];

		$actual = [];
		foreach ($endpoints as $name => [$class, $method, $args]) {
			$actual[$name] = $expected[$name] === null ? null : $this->call($fileId, $class, $method, $args)->getStatus();
		}
		$this->assertSame($expected, $actual);

		if ($expected['get'] === Http::STATUS_OK) {
			$permissions = $this->call($fileId, FormController::class, 'get', [$fileId])->getData()['permissions'];
			$this->assertSame($expected['delete'] === null, $permissions['deleteForm'], 'deleteForm');
		}
	}

	// =====================================================================
	// (c) What the UI is given
	// =====================================================================

	/**
	 * @return array<string, array{string, string, array<string, bool>}>
	 */
	public static function uiPermissions(): array {
		$viewer = ['editQuestions' => false, 'editSettings' => false, 'deleteResponses' => false, 'deleteForm' => false, 'viewResponses' => true, 'canShare' => false, 'canDownload' => true];
		return [
			'member of the writing group' => ['fvperm-writer', 'team', ['editQuestions' => true, 'editSettings' => true, 'deleteResponses' => true, 'deleteForm' => true, 'viewResponses' => true, 'canShare' => true, 'canDownload' => true]],
			'member of the read-only group' => ['fvperm-reader', 'team', $viewer],
			'writing group + ACL read-only (reshare bit alone is not full rights)' => ['fvperm-aclread', 'team', $viewer],
			'personal form, shared read-only' => ['fvperm-share-read', 'personal', $viewer],
			'personal form, shared without download' => ['fvperm-share-viewonly', 'personal', array_merge($viewer, ['canDownload' => false])],
			'personal form, shared with edit' => ['fvperm-share-edit', 'personal', ['editQuestions' => true, 'editSettings' => true, 'deleteResponses' => true, 'deleteForm' => false, 'viewResponses' => true, 'canShare' => false, 'canDownload' => true]],
			'folder shared with full rights but no download' => ['fvperm-folder-nodl', 'folder', ['editQuestions' => true, 'editSettings' => true, 'deleteResponses' => true, 'deleteForm' => true, 'viewResponses' => true, 'canShare' => true, 'canDownload' => false]],
		];
	}

	/**
	 * Nextcloud's "allow preview even if download is disabled" turned off: a share without download may open the form
	 * but not see what it holds, as in Files, so the responses and their summary stay on the server.
	 */
	public function testWithoutPreviewsAShareWithoutDownloadGetsNoResponses(): void {
		$fileId = self::$personalFormId;
		$config = Server::get(\OCP\IAppConfig::class);
		$config->setValueBool('core', 'shareapi_allow_view_without_download', false);
		try {
			$this->newRequestAs('fvperm-share-viewonly');
			$this->assertSame(Http::STATUS_FORBIDDEN, $this->call($fileId, ResponseController::class, 'getResponses', [$fileId])->getStatus());
			$this->assertSame(Http::STATUS_FORBIDDEN, $this->call($fileId, ResponseController::class, 'getResponses', [$fileId, null, 50])->getStatus(), 'a page of them');
			$this->assertSame(Http::STATUS_FORBIDDEN, $this->call($fileId, PageController::class, 'results', [$fileId])->getStatus());

			$get = $this->call($fileId, FormController::class, 'get', [$fileId]);
			$this->assertSame(Http::STATUS_OK, $get->getStatus(), 'the form itself can still be opened');
			$this->assertFalse($get->getData()['permissions']['viewResponses']);
			$this->assertArrayNotHasKey('responses', $get->getData()['form']);

			$this->assertSame(Http::STATUS_OK, $this->call($fileId, PageController::class, 'editor', [$fileId])->getStatus());
			$this->assertFalse($this->initialState('permissions')['viewResponses']);
			$this->assertArrayNotHasKey('responses', $this->initialState('form'));

			$this->newRequestAs('fvperm-share-read');
			$this->assertSame(Http::STATUS_OK, $this->call($fileId, ResponseController::class, 'getResponses', [$fileId])->getStatus(), 'a share that may download is not affected');
		} finally {
			$config->deleteKey('core', 'shareapi_allow_view_without_download');
		}

		$this->newRequestAs('fvperm-share-viewonly');
		$this->assertSame(Http::STATUS_OK, $this->call($fileId, ResponseController::class, 'getResponses', [$fileId])->getStatus(), 'with previews allowed, as before');
	}

	/**
	 * A webhook sends every response out, as an export does, so it only works while its creator may download them:
	 * one made before the share lost its download permission is skipped, not retried, and the dialog says so.
	 */
	public function testAWebhookWorksOnlyWhileItsCreatorMayDownloadTheResponses(): void {
		$fileId = self::$folderFormId;
		$this->loginAs('fvperm-creator');
		$hook = fn (string $id, string $uid) => ['id' => $id, 'name' => 'IT', 'url' => 'http://127.0.0.1:9/hook', 'secret' => 'whsec', 'events' => [], 'enabled' => true, 'created_at' => date('c'), 'created_by' => $uid];
		Server::get(FormRepository::class)->mutateSettings($fileId, function (array $settings) use ($hook): array {
			$settings['webhooks'] = [$hook('wh_nodl', 'fvperm-folder-nodl'), $hook('wh_creator', 'fvperm-creator')];
			return $settings;
		});
		try {
			$this->newRequestAs('fvperm-creator');
			$form = Server::get(FormRepository::class)->loadForm($fileId);
			$failed = Server::get(\OCA\FormVox\Service\WebhookService::class)->trigger($fileId, $form, 'response.created', ['id' => 'r1']);
			$this->assertSame(['wh_creator'], $failed, 'only the creator\'s webhook was tried (and its receiver is down); the other is skipped for good');

			$status = array_column(Server::get(FormController::class)->get($fileId)->getData()['form']['settings']['webhooks'], 'creator_status', 'id');
			$this->assertSame(['wh_nodl' => \OCA\FormVox\Service\AccountAccess::STATUS_NO_ACCESS, 'wh_creator' => \OCA\FormVox\Service\AccountAccess::STATUS_OK], $status);
		} finally {
			$this->newRequestAs('fvperm-creator');
			Server::get(FormRepository::class)->mutateSettings($fileId, function (array $settings): array {
				$settings['webhooks'] = [];
				return $settings;
			});
		}
	}

	/**
	 * editQuestions=false is what shows the read-only banner (Editor.spec.js).
	 *
	 * @dataProvider uiPermissions
	 * @param array<string, bool> $expected
	 */
	public function testEditorPageGivesTheUiNextcloudsPermissions(string $uid, string $form, array $expected): void {
		$this->loginAs($uid);

		$this->call($this->formId($form), PageController::class, 'editor', [$this->formId($form)]);

		$actual = array_intersect_key($this->initialState('permissions'), $expected);
		ksort($actual);
		ksort($expected);
		$this->assertSame($expected, $actual);
	}

	public function testEditorPageIsNotFoundWithoutAccess(): void {
		$this->loginAs('fvperm-acldeny');

		$response = $this->call(self::$teamFormId, PageController::class, 'editor', [self::$teamFormId]);

		$this->assertInstanceOf(NotFoundResponse::class, $response);
	}

	public function testTheFormListOnlyOffersDeleteWhenNextcloudAllowsIt(): void {
		$canDelete = function (string $uid): ?bool {
			$this->loginAs($uid);
			foreach (Server::get(FormController::class)->list()->getData() as $form) {
				if ($form['fileId'] === self::$teamFormId) {
					return $form['canDelete'];
				}
			}
			return null;
		};

		$this->assertTrue($canDelete('fvperm-writer'));
		$this->assertFalse($canDelete('fvperm-reader'));
	}

	// =====================================================================
	// Creator rules (#146)
	// =====================================================================

	/**
	 * The creator hears about responses only while Nextcloud lets them open
	 * the form — checked by the server at every submission, whoever submits.
	 */
	public function testTheCreatorHearsOnlyWhileNextcloudLetsThemOpenTheForm(): void {
		$creators = Server::get(FormCreatorService::class);
		$recipients = Server::get(ResponseNotificationService::class);
		$writers = Server::get(IGroupManager::class)->get(self::WRITERS);
		$creator = Server::get(IUserManager::class)->get('fvperm-creator');

		$this->loginAs('fvperm-writer');
		$form = Server::get(FormRepository::class)->load(self::$teamFormId);
		$status = function () use ($creators, $form): string {
			$this->newRequestAs('fvperm-writer');
			return $creators->getCreator(self::$teamFormId, $form)['status'];
		};

		$this->assertSame(FormCreatorService::STATUS_OK, $status());
		$this->assertContains('fvperm-creator', $recipients->getRecipientIds(self::$teamFormId, $form));

		// Removed from the team — and never logs in again.
		$writers->removeUser($creator);
		try {
			$this->assertSame(FormCreatorService::STATUS_NO_ACCESS, $status());
			$this->assertNotContains('fvperm-creator', $recipients->getRecipientIds(self::$teamFormId, $form));
		} finally {
			$writers->addUser($creator);
		}
		$this->assertSame(FormCreatorService::STATUS_OK, $status());

		// An ACL rule that takes away read.
		$this->acl('fvperm-creator', ['-read']);
		try {
			$this->assertSame(FormCreatorService::STATUS_NO_ACCESS, $status());
		} finally {
			$this->acl('fvperm-creator', ['clear']);
		}
		$this->assertSame(FormCreatorService::STATUS_OK, $status());

		// A disabled account.
		$creator->setEnabled(false);
		try {
			$this->assertSame(FormCreatorService::STATUS_DISABLED, $status());
			$this->assertNotContains('fvperm-creator', $recipients->getRecipientIds(self::$teamFormId, $form));
		} finally {
			$creator->setEnabled(true);
		}
	}

	public function testTheFormRecordsWhoCreatedIt(): void {
		$this->loginAs('fvperm-reader');

		$data = Server::get(FormController::class)->getCreator(self::$teamFormId)->getData();

		$this->assertSame('fvperm-creator', $data['id']);
		$this->assertSame(FormCreatorService::STATUS_OK, $data['status']);
	}

	public function testTheApiCannotChangeTheCreator(): void {
		$this->loginAs('fvperm-writer');

		$response = Server::get(FormController::class)->update(
			fileId: self::$teamFormId,
			permissions: ['owner' => 'fvperm-writer', 'roles' => []],
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('fvperm-creator', Server::get(FormRepository::class)->load(self::$teamFormId)['permissions']['owner']);
	}

	public function testACopyBelongsToWhoeverCopiedIt(): void {
		$this->reconnectNodeEvents();
		$this->loginAs('fvperm-writer');
		$original = Server::get(FormFileLocator::class)->getFileById(self::$teamFormId);
		$home = $this->rootFolder->getUserFolder('fvperm-writer');

		$copy = $original->copy($home->getPath() . '/Copied team form.fvform');
		try {
			$this->assertInstanceOf(File::class, $copy);
			$this->assertSame('fvperm-writer', $this->readFormFromStorage($copy)['permissions']['owner']);
		} finally {
			$copy->delete();
		}
	}

	// =====================================================================
	// API keys follow their creator (H2)
	// =====================================================================

	public function testAnApiKeyWorksOnlyWhileItsCreatorMayUseTheForm(): void {
		$this->loginAs('fvperm-writer');
		$key = Server::get(IntegrationController::class)
			->createApiKey(self::$teamFormId, 'IT key ' . uniqid(), ['read_form', 'read_responses', 'write_responses'])
			->getData()['key'];
		$writers = Server::get(IGroupManager::class)->get(self::WRITERS);
		$writer = Server::get(IUserManager::class)->get('fvperm-writer');
		$read = fn () => $this->api($key)->getForm(self::$teamFormId)->getStatus();
		$write = fn () => $this->api($key, ['answers' => []])->createResponse(self::$teamFormId)->getStatus();

		$this->assertSame([Http::STATUS_OK, Http::STATUS_CREATED], [$read(), $write()]);

		$writers->removeUser($writer);
		try {
			$this->newRequestAs('fvperm-writer');
			$this->assertSame([Http::STATUS_UNAUTHORIZED, Http::STATUS_UNAUTHORIZED], [$read(), $write()], 'creator left the team');
		} finally {
			$writers->addUser($writer);
		}

		$this->acl('fvperm-writer', ['-write', '-create', '-delete']);
		try {
			$this->newRequestAs('fvperm-writer');
			$this->assertSame([Http::STATUS_OK, Http::STATUS_UNAUTHORIZED], [$read(), $write()], 'creator can only read now');
		} finally {
			$this->acl('fvperm-writer', ['clear']);
		}
	}

	/**
	 * A respondent without an account answers a team-folder form: the response and its file go to the database and app
	 * data, so a member who may read the form is enough, also when ACL rules take every member's write away.
	 */
	public function testAPublicSubmissionInATeamFolderNeedsOnlyAMemberWhoMayRead(): void {
		$this->loginAs('fvperm-creator');
		$repository = Server::get(FormRepository::class);
		$fileId = (int)$repository->create('Public team form ' . uniqid(), self::FOLDER . '/' . self::SUBFOLDER)['fileId'];
		$repository->update($fileId, [
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'settings' => ['anonymous' => true, 'allow_multiple' => true],
		]);
		$responses = Server::get(\OCA\FormVox\Service\ResponseService::class);
		$visitor = $this->createMock(IRequest::class);
		$visitor->method('getRemoteAddress')->willReturn('192.0.2.10');
		$visitor->method('getHeader')->willReturn('IT browser');
		$submit = function (string $answer) use ($responses, $repository, $fileId, $visitor): void {
			$this->newRequestAs('fvperm-outsider');
			Server::get(\OCP\IUserSession::class)->setUser(null);
			$responses->submitAnonymousWithForm($fileId, $repository->loadPublic($fileId), ['q' => $answer], $visitor);
		};
		$stored = function () use ($repository, $fileId): array {
			$this->newRequestAs('fvperm-creator');
			return array_column(array_column($repository->load($fileId)['responses'] ?? [], 'answers'), 'q');
		};

		$submit('first');
		$this->assertSame(['first'], $stored());

		// Every member who could write loses it by ACL: the response goes to the database, so reading the form is enough.
		$this->acl('fvperm-creator', ['-write', '-create', '-delete']);
		$this->acl('fvperm-writer', ['-write', '-create', '-delete']);
		try {
			$submit('second');
			$this->newRequestAs('fvperm-outsider');
			Server::get(\OCP\IUserSession::class)->setUser(null);
			$uploads = Server::get(\OCA\FormVox\Service\UploadService::class);
			$uploads->storeUploadPublic($fileId, '4d5e6f7a-8b9c-4d0e-9f1a-2b3c4d5e6f7a', $this->makeUploadedFile('cv.pdf', 'cv', 'application/pdf'));
			$this->assertSame(1, $uploads->countUploadsPublic($fileId, '4d5e6f7a-8b9c-4d0e-9f1a-2b3c4d5e6f7a'));
		} finally {
			$this->acl('fvperm-creator', ['clear']);
			$this->acl('fvperm-writer', ['clear']);
		}
		$this->assertSame(['first', 'second'], $stored());
	}

	/** A form moved into the team folder keeps its id, and its row moves to the team folder's storage. */
	public function testAFormMovedIntoTheTeamFolderTakesItsRowAlong(): void {
		$this->reconnectNodeEvents();
		$this->loginAs('fvperm-creator');
		$fileId = (int)Server::get(FormRepository::class)->create('Moving form ' . uniqid())['fileId'];
		$forms = Server::get(\OCA\FormVox\Store\FormStore::class);
		$home = $forms->byFileId($fileId)->storageId;
		$userFolder = $this->rootFolder->getUserFolder('fvperm-creator');
		$file = $userFolder->getFirstNodeById($fileId);

		$moved = $file->move($userFolder->get(self::FOLDER . '/' . self::SUBFOLDER)->getPath() . '/' . $file->getName());

		$this->assertSame($fileId, $moved->getId());
		$row = $forms->byFileId($fileId);
		$this->assertNotSame($home, $row?->storageId);
		$this->assertSame($forms->place($fileId), [$row?->storageId, $row?->pathHash]);
	}

	/**
	 * A colleague who points someone's webhook somewhere else takes it over: it leans on their access from then
	 * on and carries a new secret that only they were shown, stored encrypted (PO 3+).
	 */
	public function testChangingAWebhooksUrlHandsItToWhoeverChangedIt(): void {
		$this->loginAs('fvperm-writer');
		$integrations = Server::get(IntegrationController::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ['url' => 'https://writer.example.com/hook', 'name' => 'IT hook'][$key] ?? $default);
		$created = (new IntegrationController($request, Server::get(FormRepository::class), Server::get(\OCA\FormVox\Service\ApiKeyService::class), Server::get(\OCA\FormVox\Service\WebhookService::class), Server::get(\OCP\IUserSession::class), Server::get(FormAccessService::class)))
			->createWebhook(self::$teamFormId)->getData();
		$stored = fn () => array_values(array_filter(
			Server::get(FormRepository::class)->read(Server::get(FormFileLocator::class)->getFileById(self::$teamFormId))['settings']['webhooks'] ?? [],
			fn (array $hook) => $hook['id'] === $created['id'],
		))[0];
		$before = $stored();

		$this->newRequestAs('fvperm-creator');
		$changed = $integrations->updateWebhook(self::$teamFormId, $created['id'], 'https://creator.example.com/hook')->getData();
		try {
			$after = $stored();
			$this->assertSame('fvperm-writer', $before['created_by']);
			$this->assertSame('fvperm-creator', $after['created_by']);
			$this->assertSame('https://creator.example.com/hook', $after['url']);
			$this->assertNotSame($created['secret'], $changed['secret']);
			$this->assertStringStartsWith('enc:v1:', $after['secret']);
			$this->assertNotSame($before['secret'], $after['secret']);
		} finally {
			$integrations->deleteWebhook(self::$teamFormId, $created['id']);
		}
	}

	/**
	 * Someone who reaches a team form along two paths (writing through the team folder, read-only through a
	 * share, which Nextcloud tends to list first) gets the one with most rights, also where FormVox checks a
	 * creator or an API key (T33).
	 */
	public function testAFormReachableAlongTwoPathsIsOpenedThroughTheOneWithMostRights(): void {
		$uid = 'fvperm-twopaths';
		Server::get(IGroupManager::class)->get(self::WRITERS)->addUser($this->ensureUser($uid));
		$this->loginAs('fvperm-creator');
		$shares = Server::get(IShareManager::class);
		$share = $shares->newShare();
		$share->setNode($this->rootFolder->getUserFolder('fvperm-creator')->getFirstNodeById(self::$teamFormId))
			->setShareType(IShare::TYPE_USER)
			->setSharedWith($uid)
			->setSharedBy('fvperm-creator')
			->setPermissions(Constants::PERMISSION_READ);
		$share = $shares->createShare($share);
		$shares->acceptShare($share, $uid); // see share()
		try {
			$this->newRequestAs($uid);
			$this->assertGreaterThanOrEqual(2, count($this->rootFolder->getUserFolder($uid)->getById(self::$teamFormId)), 'reachable along two paths');
			$this->assertNotNull(Server::get(\OCA\FormVox\Service\AccountAccess::class)->fileFor($uid, self::$teamFormId, \OCA\FormVox\Security\FormPermission::EDIT));
			$this->assertTrue(Server::get(FormFileLocator::class)->getFileForUser($uid, self::$teamFormId)->isUpdateable());
		} finally {
			$shares->deleteShare($share);
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
	}

	/** The external API as a client calls it: no session, the key in the header. */
	private function api(string $key, array $params = []): ExternalApiController {
		Server::get(\OCP\IUserSession::class)->setUser(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => $name === 'X-FormVox-API-Key' ? $key : '');
		$request->method('getParam')->willReturnCallback(fn (string $name, $default = null) => $params[$name] ?? $default);
		return new ExternalApiController(
			$request,
			Server::get(FormRepository::class),
			Server::get(\OCA\FormVox\Service\ResponseService::class),
			Server::get(\OCA\FormVox\Service\ApiKeyService::class),
			Server::get(\OCA\FormVox\Service\AccountAccess::class),
			Server::get(\OCA\FormVox\Security\FormSanitizer::class),
			Server::get(\OCP\L10N\IFactory::class)->get('formvox'),
			Server::get(\OCA\FormVox\Pdf\ResponsePdf::class),
		);
	}

	/**
	 * A response's PDF through the API with a key that may read responses, once the background job kept it; the same
	 * document from the results, and all of them in one ZIP.
	 */
	public function testAResponsesPdfComesThroughTheApiAndTheResults(): void {
		$this->loginAs('fvperm-writer');
		$integrations = Server::get(IntegrationController::class);
		$key = $integrations->createApiKey(self::$teamFormId, 'IT pdf ' . uniqid(), ['read_form', 'read_responses', 'write_responses'])->getData()['key'];
		$formOnly = $integrations->createApiKey(self::$teamFormId, 'IT form ' . uniqid(), ['read_form'])->getData()['key'];
		$responseId = (string)$this->api($key, ['answers' => []])->createResponse(self::$teamFormId)->getData()['id'];

		try {
			$this->api($key)->getResponsePdf(self::$teamFormId, $responseId);
			$this->fail('The API made a PDF on demand');
		} catch (\OCA\FormVox\Exception\RequestException $e) {
			$this->assertSame([Http::STATUS_CONFLICT, 'pdf_not_ready'], [$e->getStatus(), $e->getReason()], 'not before the job kept it');
		}
		$this->newRequestAs('fvperm-writer');
		$job = Server::get(\OCA\FormVox\BackgroundJob\SideEffectsJob::class);
		$job->setArgument(['fileId' => self::$teamFormId]);
		$job->start(Server::get(\OCP\BackgroundJob\IJobList::class));

		$viaApi = $this->api($key)->getResponsePdf(self::$teamFormId, $responseId);
		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $viaApi);
		$pdf = $viaApi->render();
		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertSame('application/pdf', $viaApi->getHeaders()['Content-Type']);

		$this->newRequestAs('fvperm-writer');
		$this->assertSame($pdf, $this->call(self::$teamFormId, ResponseController::class, 'getResponsePdf', [self::$teamFormId, $responseId])->render(), 'the same document');
		$this->assertInstanceOf(\OCP\AppFramework\Http\ZipResponse::class, $this->call(self::$teamFormId, ExportController::class, 'exportPdfs', [self::$teamFormId]));

		try {
			$this->api($formOnly)->getResponsePdf(self::$teamFormId, $responseId);
			$this->fail('A key that may not read responses got a PDF of one');
		} catch (\OCA\FormVox\Exception\RequestException) {
		}
	}

	/**
	 * A team form looked up again after a write is looked up fresh: the node this request found before keeps the path
	 * it had, so a move would go unseen and a write would land at the old place.
	 */
	public function testAFreshLookupOfATeamFormSeesItsNewPlace(): void {
		$locator = Server::get(\OCA\FormVox\Service\FormFileLocator::class);
		$before = $locator->getFileByIdPublic(self::$teamFormId);
		$this->loginAs('fvperm-creator');
		$form = $this->rootFolder->getUserFolder('fvperm-creator')->getFirstNodeById(self::$teamFormId);
		$this->assertNotNull($form);
		$original = $form->getPath();
		$moved = $form->move(dirname($original) . '/Moved ' . $form->getName());
		try {
			$this->assertSame($before->getPath(), $locator->getFileByIdPublic(self::$teamFormId)->getPath(), 'the request\'s own node');
			$this->assertSame($moved->getInternalPath(), $locator->getFileByIdPublic(self::$teamFormId, false, true)->getInternalPath());
		} finally {
			$moved->move($original);
		}
	}

	/**
	 * A submission whose request found the team form before it moved is written where the form is now, once: the node
	 * it found still reads the old place.
	 */
	public function testASubmissionToATeamFormMovedMeanwhileLandsWhereItIsNow(): void {
		$this->loginAs('fvperm-creator');
		$fileId = (int)Server::get(FormRepository::class)->create('Moving form ' . uniqid(), self::FOLDER . '/' . self::SUBFOLDER)['fileId'];
		Server::get(\OCA\FormVox\Service\FormFileLocator::class)->getFileByIdPublic($fileId, true);
		$form = $this->rootFolder->getUserFolder('fvperm-creator')->getFirstNodeById($fileId);
		$this->assertNotNull($form);
		$form->move(dirname($form->getPath()) . '/Moved ' . $form->getName());
		// The node found before reads the old place first, where PHP warns that the file is gone.
		$previous = set_error_handler(function (int $no, string $message, string $file = '', int $line = 0) use (&$previous): bool {
			return str_contains($message, 'Failed to open stream') || ($previous !== null && (bool)$previous($no, $message, $file, $line));
		}, E_WARNING);
		try {
			try {
				Server::get(\OCA\FormVox\Service\ResponsePersistenceService::class)->appendResponsePublic($fileId, [
					'id' => bin2hex(random_bytes(16)), 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => [],
				]);
			} finally {
				restore_error_handler();
			}
			$this->assertSame(1, Server::get(FormRepository::class)->loadPublic($fileId)['_index']['response_count']);
		} finally {
			$form->delete();
		}
	}

	// =====================================================================
	// Fixture
	// =====================================================================

	private function createFixture(): void {
		$apps = Server::get(IAppManager::class);
		if (!$apps->isEnabledForAnyone('groupfolders')) {
			$message = 'The Groupfolders app is not enabled, so the team-folder/ACL permission matrix cannot run.';
			if (getenv('FORMVOX_REQUIRE_GROUPFOLDERS')) {
				$this->fail($message);
			}
			$this->markTestSkipped($message);
		}
		$apps->loadApp('groupfolders');

		$groups = Server::get(IGroupManager::class);
		foreach ([self::WRITERS, self::READERS] as $gid) {
			$groups->get($gid) ?? $groups->createGroup($gid);
		}
		foreach (self::ACCOUNTS as $uid => $memberOf) {
			$user = $this->ensureUser($uid);
			foreach ($memberOf as $gid) {
				$groups->get($gid)->addUser($user);
			}
		}

		self::removeLeftoverFolder();
		$folderId = (int)trim($this->occ(\OCA\GroupFolders\Command\Create::class, ['name' => self::FOLDER]));
		$this->assertGreaterThan(0, $folderId, 'groupfolders:create returned no folder id');
		self::$folderId = $folderId;
		$this->occ(\OCA\GroupFolders\Command\Group::class, [
			'folder_id' => (string)$folderId, 'group' => self::WRITERS, 'permissions' => ['read', 'write', 'share', 'delete'],
		]);
		$this->occ(\OCA\GroupFolders\Command\Group::class, ['folder_id' => (string)$folderId, 'group' => self::READERS]);
		$this->occ(\OCA\GroupFolders\Command\ACL::class, ['folder_id' => (string)$folderId, '--enable' => true]);

		// The creator makes both forms the way the app does.
		$this->loginAs('fvperm-creator');
		$this->rootFolder->getUserFolder('fvperm-creator')->get(self::FOLDER)->newFolder(self::SUBFOLDER);
		$repository = Server::get(FormRepository::class);
		self::$teamFormId = (int)$repository->create('Team form', self::FOLDER . '/' . self::SUBFOLDER)['fileId'];
		self::$personalFormId = (int)$repository->create('Personal form')['fileId'];
		self::$folderFormId = (int)$repository->create('Folder form', self::SHARED_FOLDER)['fileId'];
		// The PDF routes answer by right only on a form that keeps PDFs; the switch itself is tested with the PDFs.
		foreach ([self::$teamFormId, self::$personalFormId, self::$folderFormId] as $fileId) {
			$repository->update($fileId, ['settings' => ['keepResponsePdf' => true]]);
		}

		$this->acl('fvperm-aclread', ['-write', '-create', '-delete']);
		$this->acl('fvperm-acldeny', ['-read']);
		$this->acl('fvperm-aclgrant', ['+write', '+create', '+delete']);

		$this->share('fvperm-share-read', Constants::PERMISSION_READ);
		$this->share('fvperm-share-edit', Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$this->share('fvperm-share-viewonly', Constants::PERMISSION_READ, false);
		$this->share('fvperm-folder-nodl', Constants::PERMISSION_ALL, false, $this->rootFolder->getUserFolder('fvperm-creator')->get(self::SHARED_FOLDER));
	}

	/**
	 * Behave like the start of a new request as $uid. Each submission is its own
	 * request in production; this test is one process, so drop what Nextcloud
	 * and Groupfolders keep for the length of a request: the filesystem setup
	 * (loginAs tears it down) and Groupfolders' per-request ACL memo
	 * (ACLManagerFactory: "ACL and group changes take effect on the next request").
	 */
	private function newRequestAs(string $uid): void {
		try {
			$factory = Server::get(\OCA\GroupFolders\ACL\ACLManagerFactory::class);
			$managers = new \ReflectionProperty($factory, 'managers');
			$memo = $managers->getValue($factory);
			if ($memo instanceof \OCP\Cache\CappedMemoryCache) {
				$memo->clear();
			}
		} catch (\ReflectionException $e) {
			// A Groupfolders version without the memo: nothing to drop.
		}
		// FormVox's own per-request memo of the account a team-folder form is opened through.
		(new \ReflectionProperty(FormFileLocator::class, 'groupFolderFileCache'))->setValue(Server::get(FormFileLocator::class), []);
		$this->loginAs($uid);
	}

	private function formId(string $form): int {
		return match ($form) {
			'team' => self::$teamFormId,
			'folder' => self::$folderFormId,
			default => self::$personalFormId,
		};
	}

	/** Run a Groupfolders occ command in-process and return its output. */
	private function occ(string $command, array $input): string {
		[$code, $text] = self::runCommand($command, $input);
		$this->assertSame(0, $code, $command . ' failed: ' . $text);
		return $text;
	}

	/**
	 * @return array{int, string}
	 */
	private static function runCommand(string $command, array $input): array {
		$cmd = Server::get($command);
		// Outside the console application there is no HelperSet, and some
		// commands (groupfolders:delete) ask for the question helper even with --force.
		$cmd->setHelperSet(new HelperSet([new QuestionHelper()]));
		$output = new BufferedOutput();
		$code = $cmd->run(new ArrayInput($input), $output);
		return [$code, $output->fetch()];
	}

	/** Delete a team folder left behind by an aborted earlier run. */
	private static function removeLeftoverFolder(): void {
		$qb = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->select('folder_id')->from('group_folders')
			->where($qb->expr()->eq('mount_point', $qb->createNamedParameter(self::FOLDER)));
		$result = $qb->executeQuery();
		$ids = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();
		foreach ($ids as $id) {
			[$code] = self::runCommand(\OCA\GroupFolders\Command\Delete::class, ['folder_id' => (string)$id, '--force' => true]);
			if ($code !== 0) {
				Server::get(\OCA\GroupFolders\Folder\FolderManager::class)->removeFolder((int)$id);
			}
		}
	}

	/** Set an advanced-permission rule for $uid on the forms subfolder. */
	private function acl(string $uid, array $permissions): void {
		$this->occ(\OCA\GroupFolders\Command\ACL::class, [
			'folder_id' => (string)self::$folderId,
			'path' => self::SUBFOLDER,
			'--user' => $uid,
			'permissions' => $permissions,
		]);
	}

	/** Share the creator's personal form, or $node, with $uid. */
	private function share(string $uid, int $permissions, bool $download = true, ?\OCP\Files\Node $node = null): void {
		$node ??= $this->rootFolder->getUserFolder('fvperm-creator')->getFirstNodeById(self::$personalFormId);
		$shares = Server::get(IShareManager::class);
		$share = $shares->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedWith($uid)
			->setSharedBy('fvperm-creator')
			->setShareOwner('fvperm-creator')
			->setPermissions($permissions);
		if (!$download) {
			$share->setAttributes($share->newAttributes()->setAttribute('permissions', 'download', false));
		}
		// Nextcloud accepts a user share on creation; on Nextcloud 32 that listener no longer runs once another test class tore down.
		$shares->acceptShare($shares->createShare($share), $uid);
	}

	/** The role GET /api/permissions/{fileId} reports, or its status when it reports none. */
	private function permissionsEndpointRole(string $form): string|int {
		$response = $this->call($this->formId($form), FilePermissionController::class, 'getPermissions', [$this->formId($form)]);
		return $response->getStatus() === Http::STATUS_OK ? $response->getData()['role'] : $response->getStatus();
	}

	/** Call an endpoint the way Nextcloud's dispatcher does: middleware first, then the method. */
	private function call(int $fileId, string $class, string $method, array $args): Response {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $key === 'fileId' ? (string)$fileId : $default);
		$access = new FormAccessMiddleware($request, Server::get(FormAccessService::class));
		$errors = new ExceptionMiddleware($request, Server::get(\OCP\IConfig::class), Server::get(\OCP\L10N\IFactory::class)->get('formvox'), Server::get(LoggerInterface::class));
		/** @var Controller $controller */
		$controller = Server::get($class);
		try {
			$access->beforeController($controller, $method);
			return $controller->$method(...$args);
		} catch (\Exception $e) {
			return $errors->afterException($controller, $method, $e);
		}
	}

	/** Read back an initial state FormVox provided to the page. */
	private function initialState(string $key): array {
		$states = Server::get(\OC\InitialStateService::class)->getInitialStates();
		$value = $states['formvox-' . $key] ?? null;
		$this->assertNotNull($value, "initial state formvox-$key was not provided");
		return is_string($value) ? json_decode($value, true) : $value;
	}
}
