<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\MicrosoftFormsAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller
{
    private IConfig $config;
    private MicrosoftFormsAuthService $msFormsAuthService;

    public function __construct(
        IRequest $request,
        IConfig $config,
        MicrosoftFormsAuthService $msFormsAuthService
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->config = $config;
        $this->msFormsAuthService = $msFormsAuthService;
    }

    /**
     * Save embed settings (admin only)
     */
    public function saveEmbed(string $allowedDomains): DataResponse
    {
        $this->config->setAppValue(Application::APP_ID, 'embed_allowed_domains', $allowedDomains);

        return new DataResponse([
            'success' => true,
            'allowedDomains' => $allowedDomains,
        ]);
    }

    /**
     * Save Microsoft Forms settings (admin only)
     */
    public function saveMsForms(string $clientId, string $tenantId = 'common', ?string $clientSecret = null): DataResponse
    {
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
