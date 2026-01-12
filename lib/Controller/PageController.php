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

class PageController extends Controller
{
    private FormService $formService;
    private PermissionService $permissionService;
    private IInitialState $initialState;
    private IURLGenerator $urlGenerator;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        FormService $formService,
        PermissionService $permissionService,
        IInitialState $initialState,
        IURLGenerator $urlGenerator,
        ?string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->formService = $formService;
        $this->permissionService = $permissionService;
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
        Util::addScript(Application::APP_ID, 'formvox-main');
        Util::addStyle(Application::APP_ID, 'main');

        return new TemplateResponse(Application::APP_ID, 'index', [
            'appId' => Application::APP_ID,
        ]);
    }

    /**
     * Form editor page
     * @return TemplateResponse|RedirectResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function editor(int $fileId)
    {
        $form = $this->formService->load($fileId);
        $role = $this->permissionService->getRole($form, $this->userId ?? '');
        $permissions = $this->permissionService->getPermissionsForRole($role);

        if (!$permissions['editQuestions']) {
            // Redirect to public form URL if user can't edit
            $token = $form['settings']['public_token'] ?? null;
            if ($token) {
                $publicUrl = $this->urlGenerator->linkToRoute('formvox.public.showForm', [
                    'fileId' => $fileId,
                    'token' => $token,
                ]);
                return new RedirectResponse($publicUrl);
            }
            // No public token - show error
            throw new \OCP\AppFramework\Http\NotFoundResponse();
        }

        // Provide initial state to JavaScript
        $this->initialState->provideInitialState('fileId', $fileId);
        $this->initialState->provideInitialState('form', $form);
        $this->initialState->provideInitialState('role', $role);
        $this->initialState->provideInitialState('permissions', $permissions);

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
        $form = $this->formService->load($fileId);
        $role = $this->permissionService->getRole($form, $this->userId ?? '');
        $permissions = $this->permissionService->getPermissionsForRole($role);

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
