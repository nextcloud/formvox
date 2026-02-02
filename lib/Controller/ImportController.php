<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\MicrosoftFormsAuthService;
use OCA\FormVox\Service\MicrosoftFormsApiClient;
use OCA\FormVox\Service\MSFormsImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class ImportController extends Controller
{
    private MicrosoftFormsAuthService $authService;
    private ?MicrosoftFormsApiClient $apiClient;
    private ?MSFormsImportService $importService;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        MicrosoftFormsAuthService $authService,
        ?MicrosoftFormsApiClient $apiClient,
        ?MSFormsImportService $importService,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger,
        ?string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->authService = $authService;
        $this->apiClient = $apiClient;
        $this->importService = $importService;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->userId = $userId;
    }

    /**
     * Get the Microsoft OAuth authorization URL
     *
     * @NoAdminRequired
     */
    public function getMsAuthUrl(): DataResponse
    {
        if (!$this->authService->isConfigured()) {
            return new DataResponse([
                'error' => 'Microsoft Forms is not configured. Ask your administrator to configure it.',
            ], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $authUrl = $this->authService->getAuthorizationUrl($this->userId);

        return new DataResponse([
            'authUrl' => $authUrl,
        ]);
    }

    /**
     * Handle OAuth callback from Microsoft
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function msAuthCallback(?string $code = null, ?string $state = null, ?string $error = null): RedirectResponse
    {
        $baseUrl = $this->urlGenerator->linkToRouteAbsolute('formvox.page.index');

        if ($error !== null) {
            $this->logger->error('MS OAuth error', ['error' => $error]);
            return new RedirectResponse($baseUrl . '?ms_auth_error=' . urlencode($error));
        }

        if ($code === null || $state === null) {
            return new RedirectResponse($baseUrl . '?ms_auth_error=missing_params');
        }

        if (!$this->authService->validateState($this->userId, $state)) {
            return new RedirectResponse($baseUrl . '?ms_auth_error=invalid_state');
        }

        try {
            $tokenData = $this->authService->exchangeCodeForToken($code);
            $this->authService->saveUserTokens(
                $this->userId,
                $tokenData['access_token'],
                $tokenData['refresh_token'],
                $tokenData['expires_in']
            );

            return new RedirectResponse($baseUrl . '?ms_auth_success=1');
        } catch (\Exception $e) {
            $this->logger->error('Failed to exchange code for token', ['exception' => $e]);
            return new RedirectResponse($baseUrl . '?ms_auth_error=token_exchange_failed');
        }
    }

    /**
     * Check if user is connected to Microsoft
     *
     * @NoAdminRequired
     */
    public function checkMsConnection(): DataResponse
    {
        return new DataResponse([
            'configured' => $this->authService->isConfigured(),
            'connected' => $this->authService->isUserConnected($this->userId),
        ]);
    }

    /**
     * Disconnect from Microsoft
     *
     * @NoAdminRequired
     */
    public function disconnectMs(): DataResponse
    {
        $this->authService->deleteUserTokens($this->userId);

        return new DataResponse([
            'success' => true,
        ]);
    }

    /**
     * List user's Microsoft Forms
     *
     * @NoAdminRequired
     */
    public function listMsForms(): DataResponse
    {
        if ($this->apiClient === null) {
            return new DataResponse([
                'error' => 'API client not available',
            ], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $accessToken = $this->authService->getValidAccessToken($this->userId);
        if ($accessToken === null) {
            return new DataResponse([
                'error' => 'Not connected to Microsoft. Please connect first.',
                'needsAuth' => true,
            ], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $forms = $this->apiClient->listForms($accessToken);
            return new DataResponse([
                'forms' => $forms,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to list MS Forms', ['exception' => $e]);
            return new DataResponse([
                'error' => 'Failed to fetch forms: ' . $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Preview a Microsoft Form (get structure before import)
     *
     * @NoAdminRequired
     */
    public function previewMsForm(string $msFormId): DataResponse
    {
        if ($this->apiClient === null) {
            return new DataResponse([
                'error' => 'API client not available',
            ], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $accessToken = $this->authService->getValidAccessToken($this->userId);
        if ($accessToken === null) {
            return new DataResponse([
                'error' => 'Not connected to Microsoft',
                'needsAuth' => true,
            ], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $formData = $this->apiClient->getForm($accessToken, $msFormId);
            $questions = $this->apiClient->getQuestions($accessToken, $msFormId);

            // Map question types for preview
            $mappedQuestions = [];
            foreach ($questions as $question) {
                $mappedQuestions[] = $this->importService->mapQuestionType($question);
            }

            return new DataResponse([
                'form' => $formData,
                'questions' => $mappedQuestions,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to preview MS Form', ['exception' => $e]);
            return new DataResponse([
                'error' => 'Failed to load form: ' . $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Import a Microsoft Form
     *
     * @NoAdminRequired
     */
    public function importMsForm(
        string $msFormId,
        string $path = '/',
        bool $includeResponses = true
    ): DataResponse {
        if ($this->importService === null) {
            return new DataResponse([
                'error' => 'Import service not available',
            ], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $accessToken = $this->authService->getValidAccessToken($this->userId);
        if ($accessToken === null) {
            return new DataResponse([
                'error' => 'Not connected to Microsoft',
                'needsAuth' => true,
            ], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $result = $this->importService->importForm(
                $this->userId,
                $accessToken,
                $msFormId,
                $path,
                $includeResponses
            );

            return new DataResponse([
                'success' => true,
                'fileId' => $result['fileId'],
                'title' => $result['title'],
                'questionsImported' => $result['questionsImported'],
                'responsesImported' => $result['responsesImported'],
                'warnings' => $result['warnings'] ?? [],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to import MS Form', ['exception' => $e]);
            return new DataResponse([
                'error' => 'Failed to import form: ' . $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
