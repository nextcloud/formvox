<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Service for anonymous telemetry data collection and reporting
 * This is an opt-out feature that helps improve FormVox
 */
class TelemetryService
{
    private const TELEMETRY_URL = 'https://licenses.voxcloud.nl/api/telemetry/formvox';

    private IClientService $httpClient;
    private IConfig $config;
    private LoggerInterface $logger;
    private IUserManager $userManager;
    private StatisticsService $statisticsService;

    public function __construct(
        IClientService $httpClient,
        IConfig $config,
        LoggerInterface $logger,
        IUserManager $userManager,
        StatisticsService $statisticsService
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->logger = $logger;
        $this->userManager = $userManager;
        $this->statisticsService = $statisticsService;
    }

    /**
     * Check if telemetry is enabled
     * Default is true (opt-out)
     */
    public function isEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'telemetry_enabled', 'true') === 'true';
    }

    /**
     * Enable or disable telemetry
     */
    public function setEnabled(bool $enabled): void
    {
        $this->config->setAppValue(Application::APP_ID, 'telemetry_enabled', $enabled ? 'true' : 'false');
        $this->logger->info('TelemetryService: Telemetry ' . ($enabled ? 'enabled' : 'disabled'));
    }

    /**
     * Get the telemetry server URL
     */
    public function getTelemetryUrl(): string
    {
        return $this->config->getAppValue(
            Application::APP_ID,
            'telemetry_url',
            self::TELEMETRY_URL
        );
    }

    /**
     * Send telemetry report to the server
     * @return bool Success status
     */
    public function sendReport(): bool
    {
        if (!$this->isEnabled()) {
            $this->logger->debug('TelemetryService: Telemetry is disabled, skipping report');
            return false;
        }

        try {
            $data = $this->collectData();

            $client = $this->httpClient->newClient();
            $response = $client->post($this->getTelemetryUrl(), [
                'json' => $data,
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'FormVox/' . $this->getAppVersion(),
                    'Content-Type' => 'application/json'
                ]
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('TelemetryService: Report sent successfully', [
                    'totalForms' => $data['stats']['totalForms'],
                    'totalResponses' => $data['stats']['totalResponses']
                ]);

                // Store last report time
                $this->config->setAppValue(
                    Application::APP_ID,
                    'telemetry_last_report',
                    (string)time()
                );

                return true;
            }

            // Silent fail - server may not be ready yet
            // TODO v1.3: Add proper error logging once server is stable
            return false;
        } catch (\Exception $e) {
            // Silent fail - server may not be available
            // TODO v1.3: Add proper error logging once server is stable
            return false;
        }
    }

    /**
     * Collect telemetry data
     */
    public function collectData(): array
    {
        $stats = $this->statisticsService->getStatistics();

        return [
            'app' => 'formvox',
            'instanceHash' => $this->getInstanceHash(),
            'version' => $this->getAppVersion(),
            'nextcloudVersion' => $this->getNextcloudVersion(),
            'phpVersion' => PHP_VERSION,
            'stats' => [
                'totalForms' => $stats['totalForms'],
                'totalResponses' => $stats['totalResponses'],
                'activeUsers30d' => $stats['activeUsers30d'],
            ],
        ];
    }

    /**
     * Get SHA-256 hash of instance URL for privacy
     */
    private function getInstanceHash(): string
    {
        $instanceUrl = $this->config->getSystemValue('overwrite.cli.url', '');
        if (empty($instanceUrl)) {
            $instanceUrl = $this->config->getSystemValue('instanceid', '');
        }
        return hash('sha256', $instanceUrl);
    }

    /**
     * Get the FormVox app version
     */
    private function getAppVersion(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'installed_version', 'unknown');
    }

    /**
     * Get the Nextcloud version
     */
    private function getNextcloudVersion(): string
    {
        return $this->config->getSystemValue('version', 'unknown');
    }

    /**
     * Get the last report timestamp
     */
    public function getLastReportTime(): ?int
    {
        $time = $this->config->getAppValue(Application::APP_ID, 'telemetry_last_report', '');
        return empty($time) ? null : (int)$time;
    }

    /**
     * Check if a report should be sent (not sent in last 24 hours)
     */
    public function shouldSendReport(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $lastReport = $this->getLastReportTime();
        if ($lastReport === null) {
            return true;
        }

        // Send report if more than 24 hours since last report
        return (time() - $lastReport) > (24 * 60 * 60);
    }

    /**
     * Get telemetry status for admin panel
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'lastReport' => $this->getLastReportTime(),
            'telemetryUrl' => $this->getTelemetryUrl()
        ];
    }
}
