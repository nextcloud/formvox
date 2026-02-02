<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\MicrosoftFormsAuthService;

class PageController extends Controller
{
    private FormService $formService;
    private PermissionService $permissionService;
    private BrandingService $brandingService;
    private MicrosoftFormsAuthService $msFormsAuthService;
    private IInitialState $initialState;
    private IURLGenerator $urlGenerator;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        FormService $formService,
        PermissionService $permissionService,
        BrandingService $brandingService,
        MicrosoftFormsAuthService $msFormsAuthService,
        IInitialState $initialState,
        IURLGenerator $urlGenerator,
        ?string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->formService = $formService;
        $this->permissionService = $permissionService;
        $this->brandingService = $brandingService;
        $this->msFormsAuthService = $msFormsAuthService;
        $this->initialState = $initialState;
        $this->urlGenerator = $urlGenerator;
        $this->userId = $userId;
    }

    /**
     * Main app page - shows list of forms
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        // Provide MS Forms import availability
        $this->initialState->provideInitialState('msFormsConfigured', $this->msFormsAuthService->isConfigured());

        Util::addScript(Application::APP_ID, 'formvox-main');
        Util::addStyle(Application::APP_ID, 'main');

        return new TemplateResponse(Application::APP_ID, 'index', [
            'appId' => Application::APP_ID,
        ]);
    }

    /**
     * Form editor page
     * Users with read access can view the form in read-only mode
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function editor(int $fileId): TemplateResponse
    {
        $file = $this->formService->getFileById($fileId);
        $form = $this->formService->load($fileId);
        $role = $this->permissionService->getRoleFromFile($file, $this->userId ?? '');
        $canShare = $this->permissionService->canShareFromFile($file, $this->userId ?? '');
        $permissions = $this->permissionService->getPermissionsForRole($role, $canShare);

        // User needs at least read access (viewer role or higher)
        if ($role === PermissionService::ROLE_NONE) {
            throw new \OCP\AppFramework\Http\NotFoundResponse();
        }

        // Get admin branding for preview fallback
        $adminBranding = $this->brandingService->getBranding();

        // Provide initial state to JavaScript
        $this->initialState->provideInitialState('fileId', $fileId);
        $this->initialState->provideInitialState('form', $form);
        $this->initialState->provideInitialState('role', $role);
        $this->initialState->provideInitialState('permissions', $permissions);
        $this->initialState->provideInitialState('adminBranding', $adminBranding);

        Util::addScript(Application::APP_ID, 'formvox-editor');
        Util::addStyle(Application::APP_ID, 'editor');

        return new TemplateResponse(Application::APP_ID, 'editor', [
            'appId' => Application::APP_ID,
        ]);
    }

    /**
     * Results page
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function results(int $fileId): TemplateResponse
    {
        $file = $this->formService->getFileById($fileId);
        $form = $this->formService->load($fileId);
        $role = $this->permissionService->getRoleFromFile($file, $this->userId ?? '');
        $canShare = $this->permissionService->canShareFromFile($file, $this->userId ?? '');
        $permissions = $this->permissionService->getPermissionsForRole($role, $canShare);

        if (!$permissions['viewResponses']) {
            throw new \OCP\AppFramework\Http\NotFoundResponse();
        }

        // Provide initial state to JavaScript
        $this->initialState->provideInitialState('fileId', $fileId);
        $this->initialState->provideInitialState('form', $form);
        $this->initialState->provideInitialState('role', $role);
        $this->initialState->provideInitialState('permissions', $permissions);

        Util::addScript(Application::APP_ID, 'formvox-results');
        Util::addStyle(Application::APP_ID, 'results');

        return new TemplateResponse(Application::APP_ID, 'results', [
            'appId' => Application::APP_ID,
        ]);
    }
}
