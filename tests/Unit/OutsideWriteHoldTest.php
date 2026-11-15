<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\OutsideWriteHold;
use OCA\FormVox\Tests\Unit\Support\Fake\Redis;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** One hold per request for a write from outside FormVox: taken once, waited for briefly, released once (3b-3). */
class OutsideWriteHoldTest extends TestCase {
	private Redis $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->cache = new Redis();
	}

	private function hold(): OutsideWriteHold {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocking')->willReturn($this->cache);
		return new OutsideWriteHold(new FormLock($factory, $this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class)), 0.2);
	}

	public function testTakenOnceAndReleasedOnce(): void {
		$hold = $this->hold();

		$hold->take(7);
		$hold->take(7);
		$this->assertTrue($hold->holds(7));
		$this->assertArrayHasKey('formvox/form/7', $this->cache->store);

		$hold->release(7);
		$this->assertFalse($hold->holds(7));
		$this->assertArrayNotHasKey('formvox/form/7', $this->cache->store);
		$hold->release(7);
	}

	public function testAFormVoxSaveIsWaitedForBrieflyOnly(): void {
		$this->cache->store['formvox/form/7'] = time() . ':other:elsewhere:1';
		$started = microtime(true);

		try {
			$this->hold()->take(7);
			$this->fail('A held form was taken');
		} catch (FormLockedException) {
		}
		$this->assertLessThan(1.0, microtime(true) - $started);
	}
}
