<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Service\MicrosoftFormsApiClient;
use OCA\FormVox\Service\MicrosoftFormsAuthService;
use OCA\FormVox\Service\MSFormsImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class ImportController extends Controller {
	public function __construct(
		IRequest $request,
		private MicrosoftFormsAuthService $authService,
		private ?MicrosoftFormsApiClient $apiClient,
		private ?MSFormsImportService $importService,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Get the Microsoft OAuth authorization URL
	 */
	#[NoAdminRequired]
	public function getMsAuthUrl(): DataResponse {
		if (!$this->authService->isConfigured()) {
			// TRANSLATORS: Error shown when the admin has not set up the Microsoft Forms import
			throw RequestException::unavailable(Translatable::t('Microsoft Forms is not configured. Ask your administrator to configure it.'));
		}

		$authUrl = $this->authService->getAuthorizationUrl($this->userId);

		return new DataResponse([
			'authUrl' => $authUrl,
		]);
	}

	/**
	 * Handle OAuth callback from Microsoft
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function msAuthCallback(?string $code = null, ?string $state = null, ?string $error = null): RedirectResponse {
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
	 */
	#[NoAdminRequired]
	public function checkMsConnection(): DataResponse {
		return new DataResponse([
			'configured' => $this->authService->isConfigured(),
			'connected' => $this->authService->isUserConnected($this->userId),
		]);
	}

	/**
	 * Disconnect from Microsoft
	 *
	 */
	#[NoAdminRequired]
	public function disconnectMs(): DataResponse {
		$this->authService->deleteUserTokens($this->userId);

		return new DataResponse([
			'success' => true,
		]);
	}

	/**
	 * List user's Microsoft Forms
	 *
	 */
	#[NoAdminRequired]
	public function listMsForms(): DataResponse {
		if ($this->apiClient === null) {
			// TRANSLATORS: Error shown when the Microsoft Forms import is not set up on this server
			throw RequestException::unavailable(Translatable::t('The Microsoft Forms import is not available'));
		}

		$accessToken = $this->authService->getValidAccessToken($this->userId);
		if ($accessToken === null) {
			throw RequestException::notConnected();
		}

		$forms = $this->apiClient->listForms($accessToken);
		return new DataResponse([
			'forms' => $forms,
		]);
	}

	/**
	 * Preview a Microsoft Form (get structure before import)
	 *
	 */
	#[NoAdminRequired]
	public function previewMsForm(string $msFormId): DataResponse {
		if ($this->apiClient === null) {
			// TRANSLATORS: Error shown when the Microsoft Forms import is not set up on this server
			throw RequestException::unavailable(Translatable::t('The Microsoft Forms import is not available'));
		}

		$accessToken = $this->authService->getValidAccessToken($this->userId);
		if ($accessToken === null) {
			throw RequestException::notConnected();
		}

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
	}

	/**
	 * Import a Microsoft Form
	 *
	 */
	#[NoAdminRequired]
	public function importMsForm(
		string $msFormId,
		string $path = '/',
		bool $includeResponses = true,
	): DataResponse {
		if ($this->importService === null) {
			throw RequestException::unavailable(Translatable::t('The Microsoft Forms import is not available'));
		}

		$accessToken = $this->authService->getValidAccessToken($this->userId);
		if ($accessToken === null) {
			throw RequestException::notConnected();
		}

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
			'responsesSkipped' => $result['responsesSkipped'] ?? 0,
			'warnings' => $result['warnings'] ?? [],
		]);
	}
}
