<?php

declare(strict_types=1);

namespace OCA\FormVox\Settings;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Pdf\PdfMailPolicy;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\MicrosoftFormsAuthService;
use OCA\FormVox\Service\StatisticsService;
use OCA\FormVox\Service\TelemetryService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IConfig $config,
		private IInitialState $initialState,
		private BrandingService $brandingService,
		private StatisticsService $statisticsService,
		private TelemetryService $telemetryService,
		private MicrosoftFormsAuthService $msFormsAuthService,
		private PdfMailPolicy $pdfMailPolicy,
	) {
	}

	public function getForm(): TemplateResponse {
		$branding = $this->brandingService->getBranding();
		$this->initialState->provideInitialState('branding', $branding);

		// Statistics are loaded async from the frontend to avoid blocking page load
		$this->initialState->provideInitialState('telemetry', $this->telemetryService->getStatus());

		// Provide embed settings
		$this->initialState->provideInitialState('embedSettings', [
			'allowedDomains' => $this->config->getAppValue(Application::APP_ID, 'embed_allowed_domains', '*'),
		]);

		$this->initialState->provideInitialState('pdfSettings', [
			'anonymous' => $this->pdfMailPolicy->anonymousAllowed(),
			'sharedCache' => $this->pdfMailPolicy->sharedCache(),
		]);

		// Provide Microsoft Forms import settings
		$this->initialState->provideInitialState('msFormsSettings', [
			'clientId' => $this->msFormsAuthService->getClientId(),
			'tenantId' => $this->msFormsAuthService->getTenantId(),
			'isConfigured' => $this->msFormsAuthService->isConfigured(),
			'redirectUri' => $this->msFormsAuthService->getRedirectUri(),
		]);

		Util::addScript(Application::APP_ID, 'formvox-admin');
		Util::addStyle(Application::APP_ID, 'admin');

		return new TemplateResponse(Application::APP_ID, 'admin', []);
	}

	public function getSection(): string {
		return 'formvox';
	}

	public function getPriority(): int {
		return 50;
	}
}
