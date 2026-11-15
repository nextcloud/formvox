<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Pdf\PdfMailPolicy;
use OCA\FormVox\Service\AiFormGeneratorService;
use OCA\FormVox\Service\MicrosoftFormsAuthService;
use OCA\FormVox\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private IConfig $config,
		private MicrosoftFormsAuthService $msFormsAuthService,
		private AiFormGeneratorService $aiService,
		private TemplateService $templateService,
		private PdfMailPolicy $pdfMailPolicy,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List admin-managed templates (admin only) — #100
	 */
	public function listAdminTemplates(): DataResponse {
		return new DataResponse(['templates' => $this->templateService->listTemplates()]);
	}

	/**
	 * Add a new template — `form` is the full form JSON snapshot (admin only)
	 */
	public function addAdminTemplate(string $title, string $description, array $form): DataResponse {
		return new DataResponse(['template' => $this->templateService->addTemplate($title, $description, $form)]);
	}

	/**
	 * Delete a template by id (admin only)
	 */
	public function deleteAdminTemplate(string $id): DataResponse {
		if (!$this->templateService->removeTemplate($id)) {
			// TRANSLATORS: Error shown when an admin removes a template that is already gone
			throw RequestException::notFound(Translatable::t('Template not found'));
		}
		return new DataResponse(['success' => true]);
	}

	/**
	 * List templates available to the user (admin-managed). Non-admin endpoint.
	 */
	#[NoAdminRequired]
	public function listAvailableTemplates(): DataResponse {
		return new DataResponse(['templates' => $this->templateService->listTemplates()]);
	}

	/** Whether anonymous respondents get their PDF with the confirmation email too (admin only); not without a shared cache. */
	public function savePdf(bool $anonymous): DataResponse {
		$anonymous = $anonymous && $this->pdfMailPolicy->sharedCache();
		$this->config->setAppValue(Application::APP_ID, PdfMailPolicy::ANONYMOUS_SETTING, $anonymous ? '1' : '0');
		return new DataResponse(['anonymous' => $anonymous]);
	}

	/**
	 * Save embed settings (admin only)
	 */
	public function saveEmbed(string $allowedDomains): DataResponse {
		$this->config->setAppValue(Application::APP_ID, 'embed_allowed_domains', $allowedDomains);

		return new DataResponse([
			'success' => true,
			'allowedDomains' => $allowedDomains,
		]);
	}

	/**
	 * Get AI settings (admin only). Includes a read-only `providerAvailable`
	 * so the UI can disable the "enabled" toggle when no TaskProcessing
	 * provider is installed.
	 */
	public function getAi(): DataResponse {
		return new DataResponse([
			'success' => true,
			'settings' => $this->loadAiSettings(),
		]);
	}

	/**
	 * Save AI settings (admin only). Rejects enabling when no provider is available.
	 */
	public function saveAi(
		bool $enabled,
		int $maxQuestions = 12,
		int $maxDocSizeMb = 8,
		bool $allowSourceUpload = true,
		bool $allowConditional = true,
	): DataResponse {
		if ($enabled && !$this->aiService->isProviderAvailable()) {
			return new DataResponse(
				// TRANSLATORS: %s is the technical name of a Nextcloud app, "integration_openai"; keep it as is
				['error' => $this->l->t('No AI text-to-text provider is configured on this instance. Install an AI provider (for example %s) first.', ['integration_openai'])],
				Http::STATUS_CONFLICT
			);
		}
		$maxQuestions = max(3, min(20, $maxQuestions));
		$maxDocSizeMb = max(1, min(25, $maxDocSizeMb));

		$this->config->setAppValue(Application::APP_ID, 'ai_enabled', $enabled ? '1' : '0');
		$this->config->setAppValue(Application::APP_ID, 'ai_max_questions', (string)$maxQuestions);
		$this->config->setAppValue(Application::APP_ID, 'ai_max_doc_size_mb', (string)$maxDocSizeMb);
		$this->config->setAppValue(Application::APP_ID, 'ai_allow_source_upload', $allowSourceUpload ? '1' : '0');
		$this->config->setAppValue(Application::APP_ID, 'ai_allow_conditional', $allowConditional ? '1' : '0');

		return new DataResponse(['success' => true, 'settings' => $this->loadAiSettings()]);
	}

	/**
	 * Non-admin-facing AI status. Merges the admin-toggle with the live
	 * provider check so the frontend only needs one call.
	 */
	#[NoAdminRequired]
	public function getAiStatus(): DataResponse {
		$providerAvailable = $this->aiService->isProviderAvailable();
		$enabled = $this->config->getAppValue(Application::APP_ID, 'ai_enabled', '') === '1';
		// Default-on behaviour: if the admin never touched the setting AND a
		// provider is available, treat AI as enabled so the feature works
		// out-of-the-box after the first provider installation.
		if ($this->config->getAppValue(Application::APP_ID, 'ai_enabled', '__unset__') === '__unset__') {
			$enabled = $providerAvailable;
		}

		return new DataResponse([
			'available' => $enabled && $providerAvailable,
			'allowSourceUpload' => $this->config->getAppValue(Application::APP_ID, 'ai_allow_source_upload', '1') === '1',
		]);
	}

	private function loadAiSettings(): array {
		$providerAvailable = $this->aiService->isProviderAvailable();
		$rawEnabled = $this->config->getAppValue(Application::APP_ID, 'ai_enabled', '__unset__');
		$enabled = $rawEnabled === '__unset__' ? $providerAvailable : $rawEnabled === '1';

		return [
			'enabled' => $enabled,
			'providerAvailable' => $providerAvailable,
			'providerTaskType' => $providerAvailable ? $this->aiService->resolvedTaskTypeOrNull() : null,
			'maxQuestions' => (int)$this->config->getAppValue(Application::APP_ID, 'ai_max_questions', '12'),
			'maxDocSizeMb' => (int)$this->config->getAppValue(Application::APP_ID, 'ai_max_doc_size_mb', '8'),
			'allowSourceUpload' => $this->config->getAppValue(Application::APP_ID, 'ai_allow_source_upload', '1') === '1',
			'allowConditional' => $this->config->getAppValue(Application::APP_ID, 'ai_allow_conditional', '1') === '1',
		];
	}

	/**
	 * Save Microsoft Forms settings (admin only)
	 */
	public function saveMsForms(string $clientId, string $tenantId = 'common', ?string $clientSecret = null): DataResponse {
		$this->msFormsAuthService->setClientId($clientId);
		$this->msFormsAuthService->setTenantId($tenantId);

		if ($clientSecret !== null && $clientSecret !== '') {
			$this->msFormsAuthService->setClientSecret($clientSecret);
		}

		return new DataResponse([
			'success' => true,
			'isConfigured' => $this->msFormsAuthService->isConfigured(),
		]);
	}
}
