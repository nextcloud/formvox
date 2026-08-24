<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class LicenseService {
	private const FREE_FORMS_LIMIT = 25;
	private const FREE_USERS_LIMIT = 50;
	private const LICENSE_SERVER_URL = 'https://licenses.voxcloud.nl';

	public function __construct(
		private IClientService $httpClient,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
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

	/**
	 * SHA-256 of the instance URL, so the licence server never sees the URL
	 * itself.
	 *
	 * Falls back to the absolute app URL rather than trusted_domains[0]: that
	 * yielded a bare hostname where IntraVox and IntroVox hashed a full URL, so
	 * the same server produced a different hash per app and the licence data
	 * could not be joined to telemetry.
	 */
	public function getInstanceUrlHash(): string {
		$instanceUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		if (empty($instanceUrl)) {
			$instanceUrl = $this->urlGenerator->getAbsoluteURL('/');
		}
		return hash('sha256', strtolower(rtrim($instanceUrl, '/')));
	}

	/**
	 * The hash this app used to send, so the server can recognise the instance
	 * across the change instead of treating it as a second one — which would
	 * be refused, freezing the seat count at its pre-update value.
	 *
	 * Returns an empty string when nothing changed (overwrite.cli.url set),
	 * so we only send it when it actually differs.
	 */
	public function getPreviousInstanceUrlHash(): string {
		if (!empty($this->config->getSystemValue('overwrite.cli.url', ''))) {
			return '';
		}

		$legacy = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
		$hash = hash('sha256', strtolower(rtrim($legacy, '/')));

		return $hash === $this->getInstanceUrlHash() ? '' : $hash;
	}

	/**
	 * Adds previousInstanceUrlHash only while the two actually differ, so the
	 * field disappears from the payload once the server has adopted the new
	 * hash and nothing is sent needlessly.
	 */
	private function hashMigrationPayload(): array {
		$previous = $this->getPreviousInstanceUrlHash();

		return $previous === '' ? [] : ['previousInstanceUrlHash' => $previous];
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
				] + $this->hashMigrationPayload(),
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
					'appType' => 'formvox',
					'currentForms' => $stats['totalForms'],
					'totalResponses' => $stats['totalResponses'],
					'currentUsers' => $stats['totalUsers'],
					'activeUsers30d' => $stats['activeUsers30d'],
					'disabledUsers' => $stats['disabledUsers'],
					// Tells the server how the count was taken, so readings from
					// releases that counted unreliably stay out of the averages
					// a contract is measured against.
					'countMethod' => StatisticsService::COUNT_METHOD,
					'appVersion' => $this->getAppVersion(),
				] + $this->hashMigrationPayload(),
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
