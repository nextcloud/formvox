<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Store;

use OCA\FormVox\Store\StoreKey;
use PHPUnit\Framework\TestCase;

class StoreKeyTest extends TestCase {
	public function testAShortKeyIsKept(): void {
		$this->assertSame('u:alice', StoreKey::fit('u:alice', 128));
	}

	public function testALongKeyBecomesAHashThatFits(): void {
		$long = 'f:v2:' . str_repeat('ab', 64);

		$fitted = StoreKey::fit($long, 128);

		$this->assertLessThanOrEqual(128, strlen($fitted));
		$this->assertSame($fitted, StoreKey::fit($long, 128));
		$this->assertNotSame($fitted, StoreKey::fit($long . 'x', 128));
		$this->assertLessThanOrEqual(64, strlen(StoreKey::fit($long, 64)));
	}

	public function testAKeyCannotPassItselfOffAsAnotherKeysHash(): void {
		$long = str_repeat('x', 200);

		$this->assertNotSame(StoreKey::fit($long, 128), StoreKey::fit(StoreKey::fit($long, 128), 128));
	}
}
