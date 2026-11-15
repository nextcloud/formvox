<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

use OCA\FormVox\AppInfo\Application;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/** The respondent types the address in unchecked: only signed-in respondents get the PDF, unless the admin allows it within limits. */
class PdfMailPolicy {
	public const ANONYMOUS_SETTING = 'pdf_confirmation_anonymous';
	public const PER_ADDRESS_PER_DAY = 3;
	public const PER_FORM_PER_HOUR = 200;
	/** Caches every process shares: web requests and the background job that sends the email count together. */
	private const SHARED_CACHES = ['OC\\Memcache\\Redis', 'OC\\Memcache\\Memcached', 'OC\\Memcache\\KeyValueCache'];

	public function __construct(
		private IConfig $config,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	public function anonymousAllowed(): bool {
		return $this->config->getAppValue(Application::APP_ID, self::ANONYMOUS_SETTING, '0') === '1' && $this->sharedCache();
	}

	/**
	 * Whether the limits can hold. Read from the configuration as Nextcloud picks its cache, not from the cache object,
	 * which a profiler or logger wraps; without one Nextcloud hands out a cache that counts nothing.
	 */
	public function sharedCache(): bool {
		$class = (string)$this->config->getSystemValue('memcache.distributed', '') ?: (string)$this->config->getSystemValue('memcache.local', '');
		return in_array(ltrim($class, '\\'), self::SHARED_CACHES, true);
	}

	/** Takes a place when it says yes; give it back with release() when the email does not go out. */
	public function allows(int $fileId, string $address, bool $signedIn): bool {
		if ($signedIn) {
			return true;
		}
		if (!$this->anonymousAllowed()) {
			return false;
		}
		$cache = $this->cache();
		if ($cache === null) {
			// Without a shared count the limits cannot hold.
			return false;
		}
		$keys = self::keys($fileId, $address);
		try {
			foreach ($keys as $key => [$limit, $seconds]) {
				$cache->add($key, 0, $seconds);
				if ((int)$cache->get($key) >= $limit) {
					$this->logger->info('FormVox: a confirmation email of form ' . $fileId . ' goes without its PDF: the limit for anonymous respondents is reached', ['app' => Application::APP_ID]);
					return false;
				}
			}
			foreach (array_keys($keys) as $key) {
				$cache->inc($key);
			}
		} catch (\Throwable) {
			return false;
		}
		return true;
	}

	/** An email that failed is tried again later: its place must not be used up by the failure. */
	public function release(int $fileId, string $address, bool $signedIn): void {
		if ($signedIn || ($cache = $this->cache()) === null) {
			return;
		}
		try {
			foreach (array_keys(self::keys($fileId, $address)) as $key) {
				if ((int)$cache->get($key) > 0) {
					$cache->dec($key);
				}
			}
		} catch (\Throwable) {
		}
	}

	private function cache(): ?IMemcache {
		if (!$this->sharedCache()) {
			return null;
		}
		try {
			$cache = $this->cacheFactory->createDistributed('formvox_pdfmail');
		} catch (\Throwable) {
			return null;
		}
		return $cache instanceof IMemcache ? $cache : null;
	}

	/** @return array<string, array{int, int}> key => [limit, seconds] */
	private static function keys(int $fileId, string $address): array {
		return [
			'address/' . hash('sha256', mb_strtolower(trim($address))) => [self::PER_ADDRESS_PER_DAY, 86400],
			'form/' . $fileId => [self::PER_FORM_PER_HOUR, 3600],
		];
	}
}
