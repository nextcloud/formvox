<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use OCA\FormVox\Pdf\PdfMailPolicy;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PdfMailPolicyTest extends TestCase {
	/** @var array<string, int> */
	private array $counts = [];

	/**
	 * @param array<string, string> $system the memory cache configuration
	 */
	private function policy(array $system, bool $switchedOn = true): PdfMailPolicy {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(fn (string $key, $default = '') => $system[$key] ?? $default);
		$config->method('getAppValue')->willReturn($switchedOn ? '1' : '0');
		// A cache that counts, whatever the configuration says: the policy must not lean on the object.
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturnCallback(function (string $key, $value): bool {
			$this->counts[$key] ??= (int)$value;
			return true;
		});
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->counts[$key] ?? null);
		$cache->method('inc')->willReturnCallback(fn (string $key) => ++$this->counts[$key]);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		return new PdfMailPolicy($config, $factory, $this->createMock(LoggerInterface::class));
	}

	/**
	 * @return array<string, array{array<string, string>, bool}>
	 */
	public static function caches(): array {
		return [
			'no memory cache' => [[], false],
			'APCu, which web requests and the background job do not share' => [['memcache.local' => '\OC\Memcache\APCu'], false],
			'APCu as the distributed cache too' => [['memcache.local' => '\OC\Memcache\APCu', 'memcache.distributed' => '\OC\Memcache\APCu'], false],
			'Redis' => [['memcache.local' => '\OC\Memcache\APCu', 'memcache.distributed' => '\OC\Memcache\Redis'], true],
			'Memcached' => [['memcache.distributed' => '\OC\Memcache\Memcached'], true],
			'Redis without the leading backslash' => [['memcache.distributed' => 'OC\Memcache\Redis'], true],
			'Redis as the only cache' => [['memcache.local' => '\OC\Memcache\Redis'], true],
			'the key-value cache of Nextcloud 34+' => [['memcache.distributed' => '\OC\Memcache\KeyValueCache'], true],
		];
	}

	/**
	 * @dataProvider caches
	 * @param array<string, string> $system
	 */
	public function testAnonymousRespondentsGetThePdfOnlyWhereTheLimitsHold(array $system, bool $shared): void {
		$policy = $this->policy($system);

		$this->assertSame($shared, $policy->sharedCache());
		$this->assertSame($shared, $policy->anonymousAllowed());
		$this->assertSame($shared, $policy->allows(7, 'someone@example.com', false));
	}

	public function testSignedInRespondentsAlwaysGetIt(): void {
		$this->assertTrue($this->policy([])->allows(7, 'anna@example.com', true));
	}

	public function testNotWhenTheAdministratorLeftItOff(): void {
		$policy = $this->policy(['memcache.distributed' => '\OC\Memcache\Redis'], false);

		$this->assertFalse($policy->anonymousAllowed());
		$this->assertFalse($policy->allows(7, 'someone@example.com', false));
	}

	public function testTheLimitPerAddressHolds(): void {
		$policy = $this->policy(['memcache.distributed' => '\OC\Memcache\Redis']);

		$sent = array_map(fn () => $policy->allows(7, 'Someone@Example.com ', false), range(1, PdfMailPolicy::PER_ADDRESS_PER_DAY + 1));

		$this->assertSame([true, true, true, false], $sent);
	}
}
