<?php

declare(strict_types=1);

namespace OCA\FormVox\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\BrandingService;

class AdminSettings implements ISettings
{
    private IConfig $config;
    private IInitialState $initialState;
    private BrandingService $brandingService;

    public function __construct(
        IConfig $config,
        IInitialState $initialState,
        BrandingService $brandingService
    ) {
        $this->config = $config;
        $this->initialState = $initialState;
        $this->brandingService = $brandingService;
    }

    public function getForm(): TemplateResponse
    {
        $branding = $this->brandingService->getBranding();
        $this->initialState->provideInitialState('branding', $branding);

        Util::addScript(Application::APP_ID, 'formvox-admin');
        Util::addStyle(Application::APP_ID, 'admin');

        return new TemplateResponse(Application::APP_ID, 'admin', []);
    }

    public function getSection(): string
    {
        return 'formvox';
    }

    public function getPriority(): int
    {
        return 50;
    }
}
