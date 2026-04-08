<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class LicenseService {
	private const FREE_FORMS_LIMIT = 25;
	private const FREE_USERS_LIMIT = 50;
	private const LICENSE_SERVER_URL = 'https://licenses.voxcloud.nl';

	public function __construct(
		private IClientService $httpClient,
		private IConfig $config,
		private StatisticsService $statisticsService,
		private LoggerInterface $logger,
	) {
	}

	// --- License key management ---

	public function getLicenseKey(): string {
		return $this->config->getAppValue(Application::APP_ID, 'license_key', '');
	}

	public function setLicenseKey(string $key): void {
		$this->config->setAppValue(Application::APP_ID, 'license_key', trim($key));
		// Clear cached validation when key changes
		$this->config->deleteAppValue(Application::APP_ID, 'license_valid');
		$this->config->deleteAppValue(Application::APP_ID, 'license_info');
		$this->config->deleteAppValue(Application::APP_ID, 'license_limits');
	}

	public function getLicenseServerUrl(): string {
		return $this->config->getAppValue(Application::APP_ID, 'license_server_url', self::LICENSE_SERVER_URL);
	}

	public function getInstanceUrlHash(): string {
		$instanceUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		if (empty($instanceUrl)) {
			$instanceUrl = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
		}
		return hash('sha256', strtolower(rtrim($instanceUrl, '/')));
	}

	// --- License validation ---

	public function validateLicense(): array {
		$licenseKey = $this->getLicenseKey();
		if (empty($licenseKey)) {
			return ['valid' => false, 'reason' => 'No license key configured', 'isFree' => true];
		}

		try {
			$client = $this->httpClient->newClient();
			$response = $client->post($this->getLicenseServerUrl() . '/api/licenses/validate', [
				'json' => [
					'licenseKey' => $licenseKey,
					'instanceUrlHash' => $this->getInstanceUrlHash(),
					'appType' => 'formvox',
				],
				'timeout' => 10,
				'headers' => [
					'User-Agent' => 'FormVox/' . $this->getAppVersion(),
				],
			]);

			$data = json_decode($response->getBody(), true);

			if ($data['valid'] ?? false) {
				$this->config->setAppValue(Application::APP_ID, 'license_valid', 'true');
				$this->config->setAppValue(Application::APP_ID, 'license_info', json_encode($data));
				$this->config->setAppValue(Application::APP_ID, 'license_last_check', (string)time());
				return $data;
			}

			$this->config->setAppValue(Application::APP_ID, 'license_valid', 'false');
			return $data;
		} catch (\Exception $e) {
			$this->logger->warning('LicenseService: Failed to validate license', [
				'error' => $e->getMessage(),
			]);

			// Fallback to cached validation
			$cachedValid = $this->config->getAppValue(Application::APP_ID, 'license_valid', '');
			if ($cachedValid === 'true') {
				$cachedInfo = json_decode(
					$this->config->getAppValue(Application::APP_ID, 'license_info', '{}'),
					true
				);
				return array_merge($cachedInfo, ['valid' => true, 'cached' => true]);
			}

			return ['valid' => false, 'reason' => 'Could not connect to license server', 'cached' => false];
		}
	}

	// --- Usage reporting ---

	public function updateUsage(): array {
		$licenseKey = $this->getLicenseKey();
		if (empty($licenseKey)) {
			return ['success' => false, 'reason' => 'No license key configured'];
		}

		try {
			$stats = $this->statisticsService->getStatistics();
			$client = $this->httpClient->newClient();
			$response = $client->post($this->getLicenseServerUrl() . '/api/licenses/usage', [
				'json' => [
					'licenseKey' => $licenseKey,
					'instanceUrlHash' => $this->getInstanceUrlHash(),
					'instanceName' => $this->config->getAppValue(Application::APP_ID, 'organization_name', ''),
					'appType' => 'formvox',
					'currentForms' => $stats['totalForms'],
					'totalResponses' => $stats['totalResponses'],
					'currentUsers' => $stats['totalUsers'],
				],
				'timeout' => 15,
				'headers' => [
					'User-Agent' => 'FormVox/' . $this->getAppVersion(),
				],
			]);

			$data = json_decode($response->getBody(), true);

			if (isset($data['limits'])) {
				$this->config->setAppValue(Application::APP_ID, 'license_limits', json_encode($data['limits']));
			}

			return $data;
		} catch (\Exception $e) {
			$this->logger->warning('LicenseService: Failed to update usage', [
				'error' => $e->getMessage(),
			]);
			return ['success' => false, 'reason' => 'Could not connect to license server'];
		}
	}

	// --- Limit checking ---

	public function needsLicense(): bool {
		$stats = $this->statisticsService->getStatistics();
		return $stats['totalForms'] > self::FREE_FORMS_LIMIT
			|| $stats['totalUsers'] > self::FREE_USERS_LIMIT;
	}

	// --- Statistics for admin UI ---

	public function getStats(): array {
		$stats = $this->statisticsService->getStatistics();
		$licenseKey = $this->getLicenseKey();
		$hasLicense = !empty($licenseKey);

		$licenseValid = false;
		$licenseInfo = [];
		if ($hasLicense) {
			$cachedValid = $this->config->getAppValue(Application::APP_ID, 'license_valid', '');
			$licenseValid = $cachedValid === 'true';
			$licenseInfo = json_decode(
				$this->config->getAppValue(Application::APP_ID, 'license_info', '{}'),
				true
			);
		}

		// Mask license key for frontend display
		$maskedKey = '';
		if ($hasLicense) {
			$key = $this->getLicenseKey();
			if (strlen($key) > 9) {
				$maskedKey = substr($key, 0, 9) . '-••••-••••-' . substr($key, -4);
			} else {
				$maskedKey = '••••••••';
			}
		}

		return [
			'totalForms' => $stats['totalForms'],
			'totalResponses' => $stats['totalResponses'],
			'totalUsers' => $stats['totalUsers'],
			'activeUsers30d' => $stats['activeUsers30d'],
			'hasLicense' => $hasLicense,
			'licenseValid' => $licenseValid,
			'licenseInfo' => $licenseInfo,
			'licenseKeyMasked' => $maskedKey,
			'needsLicense' => $this->needsLicense(),
			'freeFormsLimit' => self::FREE_FORMS_LIMIT,
			'freeUsersLimit' => self::FREE_USERS_LIMIT,
		];
	}

	private function getAppVersion(): string {
		return $this->config->getAppValue(Application::APP_ID, 'installed_version', '0.0.0');
	}
}
