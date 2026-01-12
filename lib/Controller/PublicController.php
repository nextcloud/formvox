<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\ResponseService;

class PublicController extends Controller
{
    private IUserSession $userSession;
    private IURLGenerator $urlGenerator;
    private FormService $formService;
    private ResponseService $responseService;
    private IInitialState $initialState;

    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        FormService $formService,
        ResponseService $responseService,
        IInitialState $initialState
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->formService = $formService;
        $this->responseService = $responseService;
        $this->initialState = $initialState;
    }

    /**
     * Load form by fileId and validate the public token
     * Returns form data if valid, null otherwise
     */
    private function loadAndValidateForm(int $fileId, string $token): ?array
    {
        try {
            $form = $this->formService->loadPublic($fileId);

            // Validate token matches
            $storedToken = $form['settings']['public_token'] ?? null;
            if ($storedToken === null || $storedToken !== $token) {
                return null;
            }

            return $form;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Show public form for anonymous submission
     * @return TemplateResponse|RedirectResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function showForm(int $fileId, string $token)
    {
        try {
            $form = $this->loadAndValidateForm($fileId, $token);

            if ($form === null) {
                return $this->errorResponse('Form not found', Http::STATUS_NOT_FOUND);
            }

            // Check if form has expired
            if (!empty($form['settings']['share_expires_at'])) {
                $expiresAt = new \DateTime($form['settings']['share_expires_at']);
                if ($expiresAt < new \DateTime()) {
                    return $this->errorResponse('This form has expired', Http::STATUS_GONE);
                }
            }

            // Check if form is password protected
            if (!empty($form['settings']['share_password_hash'])) {
                $providedPassword = $this->request->getParam('password');
                if (empty($providedPassword)) {
                    // Show password form
                    return $this->showPasswordForm($fileId, $token, $form['title'] ?? 'Protected Form');
                }
                if (!password_verify($providedPassword, $form['settings']['share_password_hash'])) {
                    return $this->showPasswordForm($fileId, $token, $form['title'] ?? 'Protected Form', 'Incorrect password');
                }
            }

            // Check if form requires login
            if ($form['settings']['require_login'] ?? false) {
                $user = $this->userSession->getUser();
                if ($user === null) {
                    // Redirect to login page with redirect back to this form
                    $currentUrl = $this->urlGenerator->linkToRoute('formvox.public.showForm', [
                        'fileId' => $fileId,
                        'token' => $token
                    ]);
                    $loginUrl = $this->urlGenerator->linkToRoute('core.login.showLoginForm', ['redirect_url' => $currentUrl]);
                    return new RedirectResponse($loginUrl);
                }
            }

            // Remove sensitive data for public view
            unset($form['responses']);
            unset($form['_index']);
            unset($form['permissions']);
            unset($form['settings']['share_password_hash']);
            unset($form['settings']['share_password']);

            // Provide initial state to JavaScript
            $this->initialState->provideInitialState('fileId', $fileId);
            $this->initialState->provideInitialState('token', $token);
            $this->initialState->provideInitialState('form', $form);

            Util::addScript(Application::APP_ID, 'formvox-public');
            Util::addStyle(Application::APP_ID, 'public');

            return new TemplateResponse(
                Application::APP_ID,
                'public/respond',
                [
                    'appId' => Application::APP_ID,
                ],
                'public'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Submit response (anonymous or authenticated based on form settings)
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 100, period: 3600)]
    #[BruteForceProtection(action: 'formvox_submit')]
    public function submit(int $fileId, string $token, array $answers): DataResponse
    {
        try {
            $form = $this->loadAndValidateForm($fileId, $token);

            if ($form === null) {
                $response = new DataResponse(
                    ['error' => 'Form not found'],
                    Http::STATUS_NOT_FOUND
                );
                $response->throttle();
                return $response;
            }

            // Check if form requires login
            if ($form['settings']['require_login'] ?? false) {
                $user = $this->userSession->getUser();
                if ($user === null) {
                    return new DataResponse(
                        ['error' => 'This form requires you to be logged in'],
                        Http::STATUS_FORBIDDEN
                    );
                }
                // Submit as authenticated user
                $response = $this->responseService->submitAuthenticated(
                    $fileId,
                    $answers,
                    $user->getUID(),
                    $user->getDisplayName()
                );
            } else {
                // Submit as anonymous
                $response = $this->responseService->submitAnonymousWithForm(
                    $fileId,
                    $form,
                    $answers,
                    $this->request,
                    $token
                );
            }

            // Prepare result
            $responseResult = [
                'success' => true,
                'response' => [
                    'id' => $response['id'],
                    'submitted_at' => $response['submitted_at'],
                ],
            ];

            // Include score if quiz mode
            if (isset($response['score'])) {
                $responseResult['score'] = $response['score'];
            }

            // Include redirect to results if enabled
            $showResults = $form['settings']['show_results'] ?? 'never';
            if ($showResults === 'after_submit' || $showResults === 'always') {
                $responseResult['showResults'] = true;
            }

            return new DataResponse($responseResult, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Authenticate password-protected form (POST handler)
     * @return TemplateResponse|RedirectResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'formvox_password')]
    public function authenticate(int $fileId, string $token)
    {
        try {
            $form = $this->loadAndValidateForm($fileId, $token);

            if ($form === null) {
                return $this->errorResponse('Form not found', Http::STATUS_NOT_FOUND);
            }

            // Check if form has expired
            if (!empty($form['settings']['share_expires_at'])) {
                $expiresAt = new \DateTime($form['settings']['share_expires_at']);
                if ($expiresAt < new \DateTime()) {
                    return $this->errorResponse('This form has expired', Http::STATUS_GONE);
                }
            }

            // Check password
            if (!empty($form['settings']['share_password_hash'])) {
                $providedPassword = $this->request->getParam('password');
                if (empty($providedPassword) || !password_verify($providedPassword, $form['settings']['share_password_hash'])) {
                    $response = $this->showPasswordForm($fileId, $token, $form['title'] ?? 'Protected Form', 'Incorrect password');
                    $response->throttle();
                    return $response;
                }
            }

            // Password correct - show the form
            // Remove sensitive data for public view
            unset($form['responses']);
            unset($form['_index']);
            unset($form['permissions']);
            unset($form['settings']['share_password_hash']);
            unset($form['settings']['share_password']);

            // Provide initial state to JavaScript
            $this->initialState->provideInitialState('fileId', $fileId);
            $this->initialState->provideInitialState('token', $token);
            $this->initialState->provideInitialState('form', $form);

            Util::addScript(Application::APP_ID, 'formvox-public');
            Util::addStyle(Application::APP_ID, 'public');

            return new TemplateResponse(
                Application::APP_ID,
                'public/respond',
                [
                    'appId' => Application::APP_ID,
                ],
                'public'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Show public results (if enabled)
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function showResults(int $fileId, string $token): TemplateResponse
    {
        try {
            $form = $this->loadAndValidateForm($fileId, $token);

            if ($form === null) {
                return $this->errorResponse('Form not found', Http::STATUS_NOT_FOUND);
            }

            // Check if results are visible
            $showResults = $form['settings']['show_results'] ?? 'never';
            if ($showResults === 'never') {
                return $this->errorResponse('Results are not available for this form', Http::STATUS_FORBIDDEN);
            }

            // Get summary (use public method - no user context)
            $summary = $this->responseService->getSummaryPublic($fileId);

            // Provide initial state to JavaScript
            $this->initialState->provideInitialState('fileId', $fileId);
            $this->initialState->provideInitialState('token', $token);
            $this->initialState->provideInitialState('form', [
                'title' => $form['title'],
                'description' => $form['description'],
                'questions' => $form['questions'],
            ]);
            $this->initialState->provideInitialState('summary', $summary);

            Util::addScript(Application::APP_ID, 'formvox-results');
            Util::addStyle(Application::APP_ID, 'results');

            return new TemplateResponse(
                Application::APP_ID,
                'public/results',
                [
                    'appId' => Application::APP_ID,
                ],
                'public'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Show password form for protected forms
     */
    private function showPasswordForm(int $fileId, string $token, string $title, ?string $error = null): TemplateResponse
    {
        return new TemplateResponse(
            Application::APP_ID,
            'public/password',
            [
                'appId' => Application::APP_ID,
                'fileId' => $fileId,
                'token' => $token,
                'title' => $title,
                'error' => $error,
            ],
            'public'
        );
    }

    /**
     * Return an error template response
     */
    private function errorResponse(string $message, int $status = Http::STATUS_BAD_REQUEST): TemplateResponse
    {
        $response = new TemplateResponse(
            Application::APP_ID,
            'error',
            ['message' => $message],
            'public'
        );
        $response->setStatus($status);
        return $response;
    }
}
