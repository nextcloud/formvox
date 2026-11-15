<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\PageController;
use OCA\FormVox\Security\FormNotFoundException;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Security\LinkPasswordPolicy;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\FormRevision;
use OCA\FormVox\Service\IntegrationStatus;
use OCA\FormVox\Service\MicrosoftFormsAuthService;
use OCA\FormVox\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\File;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for PageController: response, status and initial state for
 * a granted request. Access to editor/results is FormAccessMiddleware's job.
 */
class PageControllerTest extends TestCase {
	private IRequest $request;
	private FormRepository $formRepository;
	private FormAccessService $access;
	private PermissionService $permissionService;
	private BrandingService $brandingService;
	private MicrosoftFormsAuthService $msFormsAuthService;
	private IInitialState $initialState;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->access = $this->createMock(FormAccessService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->brandingService = $this->createMock(BrandingService::class);
		$this->msFormsAuthService = $this->createMock(MicrosoftFormsAuthService::class);
		$this->initialState = $this->createMock(IInitialState::class);

		// Sensible defaults so success paths don't crash on unmocked calls.
		$this->brandingService->method('getBranding')->willReturn(['color' => '#fff']);
		$this->msFormsAuthService->method('isConfigured')->willReturn(false);
	}

	private function controller(): PageController {
		return new PageController(
			$this->request,
			$this->formRepository,
			$this->access,
			$this->permissionService,
			$this->brandingService,
			$this->msFormsAuthService,
			$this->initialState,
			new FormSanitizer(),
			$this->createMock(LinkPasswordPolicy::class),
			$this->passThroughIntegrationStatus()
		);
	}

	private function passThroughIntegrationStatus(): IntegrationStatus {
		$status = $this->createMock(IntegrationStatus::class);
		$status->method('annotate')->willReturnArgument(1);
		return $status;
	}

	/** Capture every provideInitialState(key, value) into a keyed array. */
	private function captureInitialState(array &$captured): void {
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $value) use (&$captured): void {
				$captured[$key] = $value;
			});
	}

	// ======================================================================
	// index()
	// ======================================================================

	public function testIndexRendersTemplateWithOkStatus(): void {
		$resp = $this->controller()->index();
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testIndexProvidesMsFormsConfiguredFalse(): void {
		$this->msFormsAuthService = $this->createMock(MicrosoftFormsAuthService::class);
		$this->msFormsAuthService->method('isConfigured')->willReturn(false);

		$captured = [];
		$this->captureInitialState($captured);

		$this->controller()->index();
		$this->assertArrayHasKey('msFormsConfigured', $captured);
		$this->assertFalse($captured['msFormsConfigured']);
	}

	public function testIndexProvidesMsFormsConfiguredTrue(): void {
		$this->msFormsAuthService = $this->createMock(MicrosoftFormsAuthService::class);
		$this->msFormsAuthService->method('isConfigured')->willReturn(true);

		$captured = [];
		$this->captureInitialState($captured);

		$this->controller()->index();
		$this->assertTrue($captured['msFormsConfigured']);
	}

	public function testIndexTemplateParamsExposeAppId(): void {
		$resp = $this->controller()->index();
		$params = $resp->getParams();
		$this->assertSame('formvox', $params['appId']);
	}

	public function testIndexTemplateNameIsIndex(): void {
		$resp = $this->controller()->index();
		$this->assertSame('index', $resp->getTemplateName());
	}

	public function testIndexDoesNotTouchFormOrPermissionServices(): void {
		$this->formRepository->expects($this->never())->method('loadForm');
		$this->access->expects($this->never())->method('getFile');
		$this->permissionService->expects($this->never())->method('getRoleFromFile');

		$this->controller()->index();
	}

	// ======================================================================
	// editor()
	// ======================================================================

	private function wireForm(string $role, array $permissions, array $form = ['title' => 'T']): void {
		$this->access->method('getFile')->willReturn($this->createMock(File::class));
		$this->formRepository->method('loadForm')->willReturn($form);
		$this->permissionService->method('getRoleFromFile')->willReturn($role);
		$this->permissionService->method('getPermissionsForFile')->willReturn($permissions);
	}

	public function testEditorRendersEditorTemplate(): void {
		$this->wireForm(PermissionService::ROLE_VIEWER, ['viewResponses' => true]);
		$resp = $this->controller()->editor(1);
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame('editor', $resp->getTemplateName());
		$this->assertSame('formvox', $resp->getParams()['appId']);
	}

	public function testEditorProvidesInitialStateOnSuccess(): void {
		$form = ['title' => 'My Form', 'questions' => []];
		$this->wireForm(PermissionService::ROLE_EDITOR, ['viewResponses' => true, 'canDownload' => true], $form);

		$captured = [];
		$this->captureInitialState($captured);

		$this->controller()->editor(42);

		$this->assertSame(42, $captured['fileId']);
		$this->assertSame($form + ['revision' => FormRevision::of($form)], $captured['form']);
		$this->assertSame(PermissionService::ROLE_EDITOR, $captured['role']);
		$this->assertSame(['viewResponses' => true, 'canDownload' => true], $captured['permissions']);
		$this->assertSame(['color' => '#fff'], $captured['adminBranding']);
	}

	public function testEditorStateHoldsNoSecretsEvenForReadOnlyUsers(): void {
		$form = ['title' => 'T', 'settings' => [
			'public_token' => 'TOK',
			'share_password_hash' => 'enc:v1:pw',
			'api_keys' => [['id' => 'k1', 'hash' => 'enc:v1:key']],
			'webhooks' => [['id' => 'w1', 'secret' => 'enc:v1:wh']],
			'allow_multiple' => true,
		]];
		$this->wireForm(PermissionService::ROLE_VIEWER, ['viewResponses' => true, 'editSettings' => false, 'canShare' => false], $form);

		$captured = [];
		$this->captureInitialState($captured);

		$this->controller()->editor(1);
		$settings = $captured['form']['settings'];
		$this->assertSame(['TOK', true, true, [['id' => 'k1']], [['id' => 'w1']]], [
			$settings['public_token'], $settings['allow_multiple'], $settings['has_share_password'], $settings['api_keys'], $settings['webhooks'],
		]);
		$this->assertStringNotContainsString('enc:v1:', json_encode($captured['form']));
	}

	public function testEditorTakesRoleAndPermissionsFromTheFormFile(): void {
		$file = $this->createMock(File::class);
		$this->access->method('getFile')->with(1)->willReturn($file);
		$this->formRepository->method('loadForm')->willReturn(['title' => 'T']);
		$this->permissionService->expects($this->once())->method('getRoleFromFile')
			->with($this->identicalTo($file))->willReturn(PermissionService::ROLE_VIEWER);
		$this->permissionService->expects($this->once())->method('getPermissionsForFile')
			->with($this->identicalTo($file))->willReturn(['canShare' => false]);

		$captured = [];
		$this->captureInitialState($captured);

		$this->controller()->editor(1);
		$this->assertSame(['canShare' => false], $captured['permissions']);
	}

	public function testEditorPropagatesFormNotFound(): void {
		// The middleware turns this into the 404 page; the controller must not swallow it.
		$this->access->method('getFile')->willThrowException(new FormNotFoundException('Form not found'));

		$this->expectException(FormNotFoundException::class);
		$this->controller()->editor(404);
	}

	// ======================================================================
	// results()
	// ======================================================================

	/** The editor opens with read; the results page shows the responses, so it needs Nextcloud's leave to see the content. */
	public function testTheResultsPageNeedsTheRightToSeeTheContentAndTheEditorOnlyRead(): void {
		$required = fn (string $method) => (new \ReflectionMethod(PageController::class, $method))->getAttributes(RequireFormPermission::class)[0]->newInstance()->permission;

		$this->assertSame([FormPermission::VIEW_CONTENT, FormPermission::READ], [$required('results'), $required('editor')]);
	}

	public function testResultsRendersResultsTemplate(): void {
		$this->wireForm(PermissionService::ROLE_EDITOR, ['viewResponses' => true]);
		$resp = $this->controller()->results(5);
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame('results', $resp->getTemplateName());
		$this->assertSame('formvox', $resp->getParams()['appId']);
	}

	public function testResultsProvidesInitialStateOnSuccess(): void {
		$form = ['title' => 'Survey', 'questions' => [['id' => 'q1']]];
		$this->wireForm(PermissionService::ROLE_ADMIN, ['viewResponses' => true], $form);

		$captured = [];
		$this->captureInitialState($captured);

		$this->controller()->results(11);

		$this->assertSame(11, $captured['fileId']);
		$this->assertSame($form + ['revision' => FormRevision::of($form)], $captured['form']);
		$this->assertSame(PermissionService::ROLE_ADMIN, $captured['role']);
		$this->assertSame(['viewResponses' => true], $captured['permissions']);
		// adminBranding is only for the editor's preview fallback.
		$this->assertArrayNotHasKey('adminBranding', $captured);
	}

	public function testResultsTakesRoleAndPermissionsFromTheFormFile(): void {
		$file = $this->createMock(File::class);
		$this->access->method('getFile')->with(1)->willReturn($file);
		$this->formRepository->method('loadForm')->willReturn(['title' => 'T']);
		$this->permissionService->expects($this->once())->method('getRoleFromFile')
			->with($this->identicalTo($file))->willReturn(PermissionService::ROLE_EDITOR);
		$this->permissionService->expects($this->once())->method('getPermissionsForFile')
			->with($this->identicalTo($file))->willReturn(['canDownload' => false]);

		$captured = [];
		$this->captureInitialState($captured);

		$this->controller()->results(1);
		$this->assertSame(['canDownload' => false], $captured['permissions']);
	}

	public function testResultsPropagatesFormNotFound(): void {
		$this->access->method('getFile')->willThrowException(new FormNotFoundException('Form not found'));

		$this->expectException(FormNotFoundException::class);
		$this->controller()->results(404);
	}
}
