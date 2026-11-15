<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Security\LinkPasswordPolicy;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\IntegrationStatus;
use OCA\FormVox\Service\MicrosoftFormsAuthService;
use OCA\FormVox\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private FormRepository $formRepository,
		private FormAccessService $access,
		private PermissionService $permissionService,
		private BrandingService $brandingService,
		private MicrosoftFormsAuthService $msFormsAuthService,
		private IInitialState $initialState,
		private FormSanitizer $sanitizer,
		private LinkPasswordPolicy $linkPasswords,
		private IntegrationStatus $integrations,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Main app page - shows list of forms
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		// Provide MS Forms import availability
		$this->initialState->provideInitialState('msFormsConfigured', $this->msFormsAuthService->isConfigured());

		Util::addScript(Application::APP_ID, 'formvox-main');
		Util::addStyle(Application::APP_ID, 'main');

		return new TemplateResponse(Application::APP_ID, 'index', [
			'appId' => Application::APP_ID,
		]);
	}

	/**
	 * Editor page; read-only users get it in read-only mode.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireFormPermission(FormPermission::READ)]
	public function editor(int $fileId): TemplateResponse {
		$this->provideFormState($fileId);
		$this->initialState->provideInitialState('adminBranding', $this->brandingService->getBranding());

		Util::addScript(Application::APP_ID, 'formvox-editor');
		Util::addStyle(Application::APP_ID, 'editor');

		return new TemplateResponse(Application::APP_ID, 'editor', [
			'appId' => Application::APP_ID,
		]);
	}

	/**
	 * The responses themselves: a share Nextcloud lets look but not see the content of gets its 403 page.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireFormPermission(FormPermission::VIEW_CONTENT)]
	public function results(int $fileId): TemplateResponse {
		$this->provideFormState($fileId);

		Util::addScript(Application::APP_ID, 'formvox-results');
		Util::addStyle(Application::APP_ID, 'results');

		return new TemplateResponse(Application::APP_ID, 'results', [
			'appId' => Application::APP_ID,
		]);
	}

	private function provideFormState(int $fileId): void {
		$file = $this->access->getFile($fileId);
		$permissions = $this->permissionService->getPermissionsForFile($file);
		$this->initialState->provideInitialState('fileId', $fileId);
		$this->initialState->provideInitialState('form', $this->integrations->annotate($fileId, $this->sanitizer->forUser($this->formRepository->loadForm($fileId), $permissions)));
		$this->initialState->provideInitialState('role', $this->permissionService->getRoleFromFile($file));
		$this->initialState->provideInitialState('permissions', $permissions);
		$this->initialState->provideInitialState('linkPasswordEnforced', $this->linkPasswords->isEnforced());
	}
}
