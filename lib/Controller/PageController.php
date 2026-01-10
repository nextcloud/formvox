<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\PermissionService;

class PageController extends Controller
{
    private FormService $formService;
    private PermissionService $permissionService;
    private IInitialState $initialState;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        FormService $formService,
        PermissionService $permissionService,
        IInitialState $initialState,
        ?string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->formService = $formService;
        $this->permissionService = $permissionService;
        $this->initialState = $initialState;
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
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function editor(int $fileId): TemplateResponse
    {
        $form = $this->formService->load($fileId);
        $role = $this->permissionService->getRole($form, $this->userId ?? '');
        $permissions = $this->permissionService->getPermissionsForRole($role);

        if (!$permissions['editQuestions']) {
            // Redirect to respond page if user can't edit
            return $this->respond($fileId);
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
     * Respond to form page (authenticated users)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function respond(int $fileId): TemplateResponse
    {
        $form = $this->formService->loadPublicData($fileId);

        // Provide initial state to JavaScript
        $this->initialState->provideInitialState('fileId', $fileId);
        $this->initialState->provideInitialState('form', $form);

        Util::addScript(Application::APP_ID, 'formvox-respond');
        Util::addStyle(Application::APP_ID, 'respond');

        return new TemplateResponse(Application::APP_ID, 'respond', [
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
