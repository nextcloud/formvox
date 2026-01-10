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
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Util;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\ResponseService;

class PublicController extends Controller
{
    private IRootFolder $rootFolder;
    private IUserManager $userManager;
    private IUserSession $userSession;
    private IURLGenerator $urlGenerator;
    private FormService $formService;
    private ResponseService $responseService;
    private IInitialState $initialState;

    public function __construct(
        IRequest $request,
        IRootFolder $rootFolder,
        IUserManager $userManager,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        FormService $formService,
        ResponseService $responseService,
        IInitialState $initialState
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->rootFolder = $rootFolder;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->formService = $formService;
        $this->responseService = $responseService;
        $this->initialState = $initialState;
    }

    /**
     * Find a form by its public token
     * Searches all users' files for a form with the matching token
     */
    private function findFormByToken(string $token): ?array
    {
        // Search through all users' files for forms with this token
        $users = $this->userManager->search('');

        foreach ($users as $user) {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $result = $this->searchFormsInFolder($userFolder, $token);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Recursively search for forms with a specific token
     */
    private function searchFormsInFolder($folder, string $token): ?array
    {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof \OCP\Files\File && $node->getExtension() === Application::FILE_EXTENSION) {
                try {
                    $content = $node->getContent();
                    $form = json_decode($content, true);
                    if ($form !== null && ($form['settings']['public_token'] ?? null) === $token) {
                        return [
                            'fileId' => $node->getId(),
                            'form' => $form,
                        ];
                    }
                } catch (\Exception $e) {
                    // Skip invalid files
                }
            } elseif ($node instanceof \OCP\Files\Folder) {
                $result = $this->searchFormsInFolder($node, $token);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Show public form for anonymous submission
     * @return TemplateResponse|RedirectResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function showForm(string $token)
    {
        try {
            $result = $this->findFormByToken($token);

            if ($result === null) {
                return $this->errorResponse('Form not found', Http::STATUS_NOT_FOUND);
            }

            $fileId = $result['fileId'];
            $form = $result['form'];

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
                    return $this->showPasswordForm($token);
                }
                if (!password_verify($providedPassword, $form['settings']['share_password_hash'])) {
                    return $this->showPasswordForm($token, 'Incorrect password');
                }
            }

            // Check if form requires login
            if ($form['settings']['require_login'] ?? false) {
                $user = $this->userSession->getUser();
                if ($user === null) {
                    // Redirect to login page with redirect back to this form
                    // Use relative URL for redirect_url parameter
                    $currentUrl = $this->urlGenerator->linkToRoute('formvox.public.showForm', ['token' => $token]);
                    $loginUrl = $this->urlGenerator->linkToRoute('core.login.showLoginForm', ['redirect_url' => $currentUrl]);
                    return new RedirectResponse($loginUrl);
                }
                // User is logged in, continue to show the form
            }

            // Remove sensitive data for public view
            unset($form['responses']);
            unset($form['_index']);
            unset($form['permissions']);
            unset($form['settings']['share_password_hash']);
            unset($form['settings']['share_password']);

            // Provide initial state to JavaScript
            $this->initialState->provideInitialState('token', $token);
            $this->initialState->provideInitialState('fileId', $fileId);
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
    public function submit(string $token, array $answers): DataResponse
    {
        try {
            $result = $this->findFormByToken($token);

            if ($result === null) {
                $response = new DataResponse(
                    ['error' => 'Form not found'],
                    Http::STATUS_NOT_FOUND
                );
                $response->throttle();
                return $response;
            }

            $fileId = $result['fileId'];
            $form = $result['form'];

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
    public function authenticate(string $token)
    {
        try {
            $result = $this->findFormByToken($token);

            if ($result === null) {
                return $this->errorResponse('Form not found', Http::STATUS_NOT_FOUND);
            }

            $fileId = $result['fileId'];
            $form = $result['form'];

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
                    $response = $this->showPasswordForm($token, 'Incorrect password');
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
            $this->initialState->provideInitialState('token', $token);
            $this->initialState->provideInitialState('fileId', $fileId);
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
    public function showResults(string $token): TemplateResponse
    {
        try {
            $result = $this->findFormByToken($token);

            if ($result === null) {
                return $this->errorResponse('Form not found', Http::STATUS_NOT_FOUND);
            }

            $fileId = $result['fileId'];
            $form = $result['form'];

            // Check if results are visible
            $showResults = $form['settings']['show_results'] ?? 'never';
            if ($showResults === 'never') {
                return $this->errorResponse('Results are not available for this form', Http::STATUS_FORBIDDEN);
            }

            // Get summary (use public method - no user context)
            $summary = $this->responseService->getSummaryPublic($fileId);

            // Provide initial state to JavaScript
            $this->initialState->provideInitialState('token', $token);
            $this->initialState->provideInitialState('fileId', $fileId);
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
    private function showPasswordForm(string $token, ?string $error = null): TemplateResponse
    {
        // Try to get form title for display
        $title = 'Protected Form';
        $result = $this->findFormByToken($token);
        if ($result !== null) {
            $title = $result['form']['title'] ?? $title;
        }

        return new TemplateResponse(
            Application::APP_ID,
            'public/password',
            [
                'appId' => Application::APP_ID,
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
