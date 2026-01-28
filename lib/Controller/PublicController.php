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
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Util;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\BrandingService;

class PublicController extends Controller
{
    private IConfig $config;
    private IUserSession $userSession;
    private IURLGenerator $urlGenerator;
    private IGroupManager $groupManager;
    private FormService $formService;
    private ResponseService $responseService;
    private BrandingService $brandingService;
    private IInitialState $initialState;

    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        IGroupManager $groupManager,
        FormService $formService,
        ResponseService $responseService,
        BrandingService $brandingService,
        IInitialState $initialState
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->config = $config;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->groupManager = $groupManager;
        $this->formService = $formService;
        $this->responseService = $responseService;
        $this->brandingService = $brandingService;
        $this->initialState = $initialState;
    }

    /**
     * Check if user is allowed to access form based on user/group restrictions
     */
    private function isUserAllowed(array $form, ?IUser $user): bool
    {
        $allowedUsers = $form['settings']['allowed_users'] ?? [];
        $allowedGroups = $form['settings']['allowed_groups'] ?? [];

        // No restrictions = everyone allowed
        if (empty($allowedUsers) && empty($allowedGroups)) {
            return true;
        }

        // Restrictions exist but no user = not allowed
        if ($user === null) {
            return false;
        }

        $userId = $user->getUID();

        // Check if user is directly allowed
        if (in_array($userId, $allowedUsers, true)) {
            return true;
        }

        // Check if user is in any allowed group
        foreach ($allowedGroups as $groupId) {
            if ($this->groupManager->isInGroup($userId, $groupId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Show unauthorized access page
     */
    private function showUnauthorized(string $title): TemplateResponse
    {
        $response = new TemplateResponse(
            Application::APP_ID,
            'public/unauthorized',
            [
                'appId' => Application::APP_ID,
                'title' => $title,
            ],
            'public'
        );
        $response->setStatus(Http::STATUS_FORBIDDEN);
        return $response;
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

            // Check if form requires login FIRST (before password check)
            // If require_login is set, user must be logged in regardless of password
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

            // Check user/group access restrictions
            $hasRestrictions = !empty($form['settings']['allowed_users'] ?? [])
                            || !empty($form['settings']['allowed_groups'] ?? []);

            if ($hasRestrictions) {
                $user = $this->userSession->getUser();

                // If restrictions exist, require login first
                if ($user === null) {
                    $currentUrl = $this->urlGenerator->linkToRoute('formvox.public.showForm', [
                        'fileId' => $fileId,
                        'token' => $token
                    ]);
                    $loginUrl = $this->urlGenerator->linkToRoute('core.login.showLoginForm', ['redirect_url' => $currentUrl]);
                    return new RedirectResponse($loginUrl);
                }

                // Check if user is allowed
                if (!$this->isUserAllowed($form, $user)) {
                    return $this->showUnauthorized($form['title'] ?? 'Form');
                }
            }

            // Check if form is password protected (only check after login requirement is satisfied)
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

            // Get branding settings - use form-specific if set, otherwise admin defaults
            $branding = $this->getEffectiveBranding($form);

            // Remove sensitive data for public view
            unset($form['responses']);
            unset($form['_index']);
            unset($form['permissions']);
            unset($form['settings']['share_password_hash']);
            unset($form['settings']['share_password']);
            unset($form['branding']); // Don't expose branding in form data

            // Provide initial state to JavaScript
            $this->initialState->provideInitialState('fileId', $fileId);
            $this->initialState->provideInitialState('token', $token);
            $this->initialState->provideInitialState('form', $form);
            $this->initialState->provideInitialState('branding', $branding);

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
     * Get effective branding for a form (form-specific or admin defaults)
     */
    private function getEffectiveBranding(array $form): array
    {
        // If form has custom branding, use it
        if (!empty($form['branding'])) {
            return $form['branding'];
        }
        // Otherwise use admin defaults
        return $this->brandingService->getBranding();
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

            // Check user/group access restrictions
            $hasRestrictions = !empty($form['settings']['allowed_users'] ?? [])
                            || !empty($form['settings']['allowed_groups'] ?? []);

            if ($hasRestrictions) {
                $user = $this->userSession->getUser();
                if ($user === null || !$this->isUserAllowed($form, $user)) {
                    return new DataResponse(
                        ['error' => 'You do not have permission to submit this form'],
                        Http::STATUS_FORBIDDEN
                    );
                }
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
            }

            // Check if anonymous collection is enabled
            $isAnonymous = $form['settings']['anonymous'] ?? true;

            if ($isAnonymous) {
                // Submit as anonymous (even if user is logged in)
                $response = $this->responseService->submitAnonymousWithForm(
                    $fileId,
                    $form,
                    $answers,
                    $this->request,
                    $token
                );
            } else if ($hasRestrictions || ($form['settings']['require_login'] ?? false)) {
                // User/group restrictions or require_login with non-anonymous = authenticated submission
                $user = $this->userSession->getUser();
                $response = $this->responseService->submitAuthenticated(
                    $fileId,
                    $answers,
                    $user->getUID(),
                    $user->getDisplayName()
                );
            } else {
                // No login required and anonymous enabled (default)
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
     * Show embeddable form (allows framing in iframes)
     * @return TemplateResponse|RedirectResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function embedForm(int $fileId, string $token)
    {
        // Use the same logic as showForm, but return with X-Frame-Options disabled
        $response = $this->showForm($fileId, $token);

        // Set embed headers based on allowed domains
        $this->setEmbedHeaders($response);

        return $response;
    }

    /**
     * Authenticate password-protected form in embed mode (POST handler)
     * @return TemplateResponse|RedirectResponse
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'formvox_password')]
    public function embedAuthenticate(int $fileId, string $token)
    {
        // Use the same logic as authenticate, but return with X-Frame-Options disabled
        $response = $this->authenticate($fileId, $token);

        // Set embed headers based on allowed domains
        $this->setEmbedHeaders($response);

        return $response;
    }

    /**
     * Set appropriate headers for embed responses based on allowed domains setting
     */
    private function setEmbedHeaders($response): void
    {
        if (!($response instanceof TemplateResponse)) {
            return;
        }

        // Get allowed domains from config
        $allowedDomains = $this->config->getAppValue(Application::APP_ID, 'embed_allowed_domains', '*');
        $allowedDomains = trim($allowedDomains);

        // Build frame-ancestors CSP value
        if ($allowedDomains === '' || $allowedDomains === '*') {
            // Allow all domains
            $response->addHeader('X-Frame-Options', 'ALLOWALL');
            $response->addHeader('Content-Security-Policy', "frame-ancestors *");
        } else {
            // Parse domain list (comma, space, or newline separated)
            $domains = preg_split('/[\s,]+/', $allowedDomains, -1, PREG_SPLIT_NO_EMPTY);

            if (empty($domains)) {
                // No valid domains = allow all (fallback)
                $response->addHeader('X-Frame-Options', 'ALLOWALL');
                $response->addHeader('Content-Security-Policy', "frame-ancestors *");
            } else {
                // Build frame-ancestors with specific domains
                // Always include 'self' for same-origin embedding
                $frameAncestors = "'self'";

                foreach ($domains as $domain) {
                    $domain = trim($domain);
                    if (empty($domain)) {
                        continue;
                    }

                    // Normalize domain format for CSP
                    // Support: example.com, *.example.com, https://example.com
                    if (!str_starts_with($domain, 'http://') && !str_starts_with($domain, 'https://')) {
                        // Add both http and https variants, or use wildcard scheme
                        $frameAncestors .= " https://{$domain}";
                    } else {
                        $frameAncestors .= " {$domain}";
                    }
                }

                // X-Frame-Options doesn't support multiple domains, so we remove it
                // and rely solely on Content-Security-Policy frame-ancestors
                $response->addHeader('X-Frame-Options', 'SAMEORIGIN');
                $response->addHeader('Content-Security-Policy', "frame-ancestors {$frameAncestors}");
            }
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
            // Get branding settings - use form-specific if set, otherwise admin defaults
            $branding = $this->getEffectiveBranding($form);

            // Remove sensitive data for public view
            unset($form['responses']);
            unset($form['_index']);
            unset($form['permissions']);
            unset($form['settings']['share_password_hash']);
            unset($form['settings']['share_password']);
            unset($form['branding']); // Don't expose branding in form data

            // Provide initial state to JavaScript
            $this->initialState->provideInitialState('fileId', $fileId);
            $this->initialState->provideInitialState('token', $token);
            $this->initialState->provideInitialState('form', $form);
            $this->initialState->provideInitialState('branding', $branding);

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

    /**
     * Upload a file for a form response
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 50, period: 3600)]
    public function uploadFile(int $fileId, string $token): DataResponse
    {
        try {
            $form = $this->loadAndValidateForm($fileId, $token);

            if ($form === null) {
                return new DataResponse(
                    ['error' => 'Form not found'],
                    Http::STATUS_NOT_FOUND
                );
            }

            // Check if form has expired
            if (!empty($form['settings']['share_expires_at'])) {
                $expiresAt = new \DateTime($form['settings']['share_expires_at']);
                if ($expiresAt < new \DateTime()) {
                    return new DataResponse(
                        ['error' => 'This form has expired'],
                        Http::STATUS_GONE
                    );
                }
            }

            // Check user/group access restrictions
            $hasRestrictions = !empty($form['settings']['allowed_users'] ?? [])
                            || !empty($form['settings']['allowed_groups'] ?? []);

            if ($hasRestrictions) {
                $user = $this->userSession->getUser();
                if ($user === null || !$this->isUserAllowed($form, $user)) {
                    return new DataResponse(
                        ['error' => 'You do not have permission to upload files to this form'],
                        Http::STATUS_FORBIDDEN
                    );
                }
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
            }

            // Get question ID from request
            $questionId = $this->request->getParam('questionId');
            if (empty($questionId)) {
                return new DataResponse(
                    ['error' => 'Question ID is required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Find the question
            $question = $this->findQuestion($form, $questionId);
            if ($question === null || $question['type'] !== 'file') {
                return new DataResponse(
                    ['error' => 'Invalid question'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Get uploaded file
            $uploadedFile = $this->request->getUploadedFile('file');
            if ($uploadedFile === null || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = $this->getUploadErrorMessage($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
                return new DataResponse(
                    ['error' => $errorMessage],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Validate file size
            $maxSizeMB = $question['maxFileSize'] ?? 10;
            $maxSizeBytes = $maxSizeMB * 1024 * 1024;
            if ($uploadedFile['size'] > $maxSizeBytes) {
                return new DataResponse(
                    ['error' => "File is too large. Maximum size is {$maxSizeMB} MB"],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Validate file type
            if (!$this->isAllowedFileType($uploadedFile, $question)) {
                return new DataResponse(
                    ['error' => 'This file type is not allowed'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Generate temporary response ID for grouping files
            $tempResponseId = $this->request->getParam('tempResponseId');
            if (empty($tempResponseId)) {
                $tempResponseId = bin2hex(random_bytes(16));
            }

            // Store the file
            $fileMetadata = $this->formService->storeUpload($fileId, $tempResponseId, $uploadedFile);
            $fileMetadata['tempResponseId'] = $tempResponseId;

            return new DataResponse($fileMetadata, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Find a question in the form by ID
     */
    private function findQuestion(array $form, string $questionId): ?array
    {
        foreach ($form['questions'] ?? [] as $question) {
            if (($question['id'] ?? '') === $questionId) {
                return $question;
            }
        }
        return null;
    }

    /**
     * Check if uploaded file type is allowed for the question
     */
    private function isAllowedFileType(array $uploadedFile, array $question): bool
    {
        $allowedTypes = $question['allowedTypes'] ?? [];

        // If no restrictions, allow all (except dangerous types)
        if (empty($allowedTypes) || in_array('*/*', $allowedTypes)) {
            // Block dangerous file types
            $dangerousTypes = [
                'application/x-executable',
                'application/x-msdownload',
                'application/x-msdos-program',
                'application/x-sh',
                'application/x-php',
            ];
            $dangerousExtensions = ['exe', 'bat', 'cmd', 'sh', 'php', 'phar', 'ps1', 'vbs', 'js'];

            $mimeType = $uploadedFile['type'] ?? '';
            $extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

            if (in_array($mimeType, $dangerousTypes) || in_array($extension, $dangerousExtensions)) {
                return false;
            }
            return true;
        }

        $mimeType = $uploadedFile['type'] ?? '';
        $extension = '.' . strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

        foreach ($allowedTypes as $allowed) {
            // Check exact MIME type match
            if ($mimeType === $allowed) {
                return true;
            }

            // Check wildcard MIME type (e.g., image/*)
            if (str_ends_with($allowed, '/*')) {
                $prefix = substr($allowed, 0, -1);
                if (str_starts_with($mimeType, $prefix)) {
                    return true;
                }
            }

            // Check extension (e.g., .pdf)
            if (str_starts_with($allowed, '.') && $extension === strtolower($allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get human-readable upload error message
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The file exceeds the maximum upload size',
            UPLOAD_ERR_FORM_SIZE => 'The file exceeds the maximum size allowed',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: missing temp folder',
            UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write file',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension',
        ];

        return $messages[$errorCode] ?? 'Unknown upload error';
    }
}
