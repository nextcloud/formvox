<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Support\Subscription\IRegistry;
use Psr\Log\LoggerInterface;

class LicenseService {
	private const FREE_FORMS_LIMIT = 25;
	private const FREE_USERS_LIMIT = 50;
	/**
	 * Above this many users the interface suggests a support subscription.
	 *
	 * Not a limit and not enforced anywhere -- the app behaves identically on
	 * either side of it. It marks where paid subscriptions begin in the price
	 * list, so below it there is genuinely nothing to suggest.
	 */
	private const SUPPORT_NUDGE_USER_THRESHOLD = 100;
	private const LICENSE_SERVER_URL = 'https://licenses.voxcloud.nl';

	public function __construct(
		private IClientService $httpClient,
		private IConfig $config,
		private StatisticsService $statisticsService,
		private LoggerInterface $logger,
		private ?IRegistry $subscriptionRegistry = null,
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
		$this->config->deleteAppValue(Application::APP_ID, 'license_reason');
	}

	public function getLicenseServerUrl(): string {
		return $this->config->getAppValue(Application::APP_ID, 'license_server_url', self::LICENSE_SERVER_URL);
	}

	/**
	 * SHA-256 of the instance URL, so the licence server never sees the URL
	 * itself.
	 *
	 * The URL is hashed as a full URL (scheme + host) to match how IntraVox and
	 * IntroVox identify the instance, so licence data lines up across apps.
	 *
	 * The source must be request-context-independent: the daily cron job and an
	 * admin web request both compute this hash, and if they disagreed the server
	 * would see two instances for one customer and freeze the seat count. We
	 * therefore use overwrite.cli.url when set, otherwise trusted_domains[0]
	 * promoted to a full URL — both are identical from cron and web. We do NOT
	 * use getAbsoluteURL(), whose result derives from the current request host
	 * and so differs between web and CLI.
	 */
	public function getInstanceUrlHash(): string {
		return hash('sha256', $this->normalizedInstanceUrl());
	}

	/**
	 * Request-independent instance URL, lower-cased and without a trailing
	 * slash. overwrite.cli.url wins; otherwise trusted_domains[0] is promoted
	 * to https:// so it is a full URL rather than a bare hostname.
	 */
	private function normalizedInstanceUrl(): string {
		$url = $this->config->getSystemValue('overwrite.cli.url', '');
		if (empty($url)) {
			$domain = $this->config->getSystemValue('trusted_domains', ['localhost'])[0] ?? 'localhost';
			// Promote a bare hostname to a full URL; leave an already-qualified
			// value (someone put a scheme in trusted_domains) untouched.
			$url = preg_match('#^https?://#i', $domain) ? $domain : 'https://' . $domain;
		}
		return strtolower(rtrim($url, '/'));
	}

	/**
	 * The bare-hostname hash this app used to send before the full-URL change,
	 * so the server can recognise the instance across the change instead of
	 * treating it as a second one — which would be refused, freezing the seat
	 * count at its pre-update value.
	 *
	 * Returns '' when overwrite.cli.url is set (the hash never changed for those
	 * instances) or when the legacy hash equals the current one (nothing to
	 * migrate). Otherwise it keeps returning the legacy hash: we have no local
	 * signal that the server has adopted the new hash, so we keep sending it —
	 * the server is idempotent and ignores it once adopted.
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
	 * Includes previousInstanceUrlHash while the legacy hash differs from the
	 * current one, so the server can adopt the new hash. The field is omitted
	 * for instances whose hash never changed (overwrite.cli.url set).
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
				$this->config->deleteAppValue(Application::APP_ID, 'license_reason');
				return $data;
			}

			// The server distinguishes expired from not-found, already-in-use
			// and inactive; getStats() reads from cache, so keep the reason or
			// the admin only ever learns that something is wrong.
			$this->config->setAppValue(Application::APP_ID, 'license_valid', 'false');
			$this->config->setAppValue(
				Application::APP_ID,
				'license_reason',
				(string)($data['reason'] ?? '')
			);
			// Retains validUntil, so an expired licence can still name its date.
			$this->config->setAppValue(Application::APP_ID, 'license_info', json_encode($data));
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
		$licenseReason = '';
		$licenseValidUntil = null;
		if ($hasLicense) {
			$cachedValid = $this->config->getAppValue(Application::APP_ID, 'license_valid', '');
			$licenseValid = $cachedValid === 'true';
			$licenseInfo = json_decode(
				$this->config->getAppValue(Application::APP_ID, 'license_info', '{}'),
				true
			) ?: [];
			if (!$licenseValid) {
				$licenseReason = $this->config->getAppValue(Application::APP_ID, 'license_reason', '');
			}
			// A valid response nests the dates under 'license'; a refused one
			// carries only validUntil, and only when the key expired. Either
			// way the admin sees the date it lapsed, not just that it did.
			$licenseValidUntil = $licenseInfo['license']['validUntil']
				?? $licenseInfo['validUntil']
				?? null;
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
			'supportNudgeUserThreshold' => self::SUPPORT_NUDGE_USER_THRESHOLD,
			'hasValidSubscription' => $this->hasValidSubscription(),
			'hasExtendedSupport' => $this->hasExtendedSupport(),
			'activeUsers30d' => $stats['activeUsers30d'],
			'hasLicense' => $hasLicense,
			'licenseValid' => $licenseValid,
			'licenseInfo' => $licenseInfo,
			'licenseReason' => $licenseReason,
			'licenseValidUntil' => $licenseValidUntil,
			'licenseKeyMasked' => $maskedKey,
			'needsLicense' => $this->needsLicense(),
			'freeFormsLimit' => self::FREE_FORMS_LIMIT,
			'freeUsersLimit' => self::FREE_USERS_LIMIT,
		];
	}

	private function getAppVersion(): string {
		return $this->config->getAppValue(Application::APP_ID, 'installed_version', '0.0.0');
	}

	/**
	 * Whether the host Nextcloud has a valid Enterprise subscription.
	 *
	 * Asks IRegistry rather than OCP\Util::hasExtendedSupport(), which answers a
	 * different question: that helper reports the paid Extended Support add-on, so
	 * an ordinary Enterprise customer without it answers false and looks like
	 * Community. It also falls back to the `extendedSupport` system config value
	 * when the registry is missing, which an admin can set by hand.
	 *
	 * Mirrors TelemetryService, so the settings page and the report sent to the
	 * licence server cannot disagree about the same instance.
	 */
	private function hasValidSubscription(): bool {
		try {
			return $this->subscriptionRegistry?->delegateHasValidSubscription() ?? false;
		} catch (\Throwable $e) {
			$this->logger->debug('LicenseService: delegateHasValidSubscription() check failed', [
				'error' => $e->getMessage()
			]);
		}
		return false;
	}

	/**
	 * Whether that subscription also carries the Extended Support add-on. A strict
	 * subset of hasValidSubscription(), reported separately so the two signals stay
	 * distinguishable.
	 */
	private function hasExtendedSupport(): bool {
		try {
			return $this->subscriptionRegistry?->delegateHasExtendedSupport() ?? false;
		} catch (\Throwable $e) {
			$this->logger->debug('LicenseService: delegateHasExtendedSupport() check failed', [
				'error' => $e->getMessage()
			]);
		}
		return false;
	}

}
