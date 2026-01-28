<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCA\FormVox\AppInfo\Application;

class SettingsController extends Controller
{
    private IConfig $config;

    public function __construct(
        IRequest $request,
        IConfig $config
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->config = $config;
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
}
