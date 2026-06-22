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
    private LicenseService $licenseService;

    public function __construct(
        IClientService $httpClient,
        IConfig $config,
        LoggerInterface $logger,
        IUserManager $userManager,
        StatisticsService $statisticsService,
        LicenseService $licenseService
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->logger = $logger;
        $this->userManager = $userManager;
        $this->statisticsService = $statisticsService;
        $this->licenseService = $licenseService;
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
        return $this->sendReportWithDetails()['success'];
    }

    /**
     * Send telemetry report to the server with detailed error info
     * @return array{success: bool, reason?: string, message?: string}
     */
    public function sendReportWithDetails(): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'reason' => 'disabled'];
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

                return ['success' => true];
            }

            return ['success' => false, 'reason' => 'server_error', 'message' => 'HTTP ' . $statusCode];
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $body = (string) $e->getResponse()->getBody();
                $json = json_decode($body, true);
                if (isset($json['error'])) {
                    $message = $json['error'];
                } elseif (!empty($body) && strlen($body) < 200) {
                    $message = $body;
                }
            }
            $this->logger->warning('TelemetryService: Failed to send report: ' . $message);
            return ['success' => false, 'reason' => 'error', 'message' => $message];
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
                'totalUsers' => $stats['totalUsers'],
                'activeUsers30d' => $stats['activeUsers30d'],
            ],
            'countryCode' => $this->getCountryCode(),
            'databaseType' => $this->config->getSystemValue('dbtype', 'sqlite'),
            'defaultLanguage' => $this->config->getSystemValue('default_language', 'en'),
            'defaultTimezone' => $this->getDefaultTimezone(),
            'osFamily' => PHP_OS_FAMILY,
            'webServer' => $this->getWebServer(),
            'isDocker' => $this->isDocker(),
            'hasExtendedSupport' => $this->hasExtendedSupport(),
            // Sent so the license server can verify hasExtendedSupport claims —
            // the boolean alone is unauthenticated and could be spoofed by anyone
            // posting to the telemetry endpoint. The server only honors the claim
            // when this key + the instance hash match an active license_usage row.
            // Empty string for community instances (no license) — server treats
            // those as 'never Enterprise' which is correct.
            'licenseKey' => $this->licenseService->getLicenseKey() ?? '',
        ];
    }

    /**
     * Detect whether the host Nextcloud has an Extended Support / Enterprise
     * subscription. Uses Nextcloud's public API (OCP\Util::hasExtendedSupport,
     * available since NC 17). Returns false on any failure so a Community
     * instance is never reported as Enterprise.
     */
    private function hasExtendedSupport(): bool
    {
        try {
            if (class_exists(\OCP\Util::class) && method_exists(\OCP\Util::class, 'hasExtendedSupport')) {
                return \OCP\Util::hasExtendedSupport();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('TelemetryService: hasExtendedSupport() check failed', [
                'error' => $e->getMessage()
            ]);
        }
        return false;
    }

    /**
     * Get SHA-256 hash of instance URL for privacy.
     * Delegates to LicenseService so the telemetry instanceHash is byte-for-byte
     * identical to license_usage.instance_url_hash — required for the license
     * server's enterprise-claim validation join.
     */
    private function getInstanceHash(): string
    {
        return $this->licenseService->getInstanceUrlHash();
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
     * Best-effort ISO-3166-1 alpha-2 country code from Nextcloud's
     * `default_phone_region` system setting. Server falls back to a
     * timezone→country lookup when this is null.
     */
    private function getCountryCode(): ?string
    {
        $region = $this->config->getSystemValue('default_phone_region', '');
        if (!empty($region) && preg_match('/^[A-Z]{2}$/', strtoupper($region))) {
            return strtoupper($region);
        }
        return null;
    }

    /**
     * Default timezone — Nextcloud config first, then PHP, fallback UTC.
     */
    private function getDefaultTimezone(): string
    {
        $tz = $this->config->getSystemValue('default_timezone', '');
        if (!empty($tz) && $tz !== 'UTC') {
            return $tz;
        }
        $phpTz = date_default_timezone_get();
        if (!empty($phpTz) && $phpTz !== 'UTC') {
            return $phpTz;
        }
        return 'UTC';
    }

    /**
     * Detect web server from SERVER_SOFTWARE header.
     */
    private function getWebServer(): ?string
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? null;
        if ($software === null) {
            return null;
        }
        if (stripos($software, 'apache') !== false) {
            return 'Apache';
        }
        if (stripos($software, 'nginx') !== false) {
            return 'nginx';
        }
        return explode('/', $software)[0];
    }

    /**
     * Detect Docker container by /.dockerenv or cgroup hint.
     */
    private function isDocker(): bool
    {
        if (file_exists('/.dockerenv')) {
            return true;
        }
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = @file_get_contents('/proc/1/cgroup');
            if ($cgroup !== false && str_contains($cgroup, 'docker')) {
                return true;
            }
        }
        return false;
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
