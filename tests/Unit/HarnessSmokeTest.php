<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCP\Files\File;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Proves the test harness is wired correctly:
 *  - the OCA\FormVox\Tests\ autoload maps to tests/
 *  - OCP interfaces resolve (from nextcloud/ocp) and are mockable
 * If this is green, the harness is ready for the real characterization tests.
 */
class HarnessSmokeTest extends TestCase {
	public function testOcpInterfacesAreAutoloadable(): void {
		$this->assertTrue(interface_exists(IDBConnection::class));
		$this->assertTrue(interface_exists(IGroupManager::class));
		$this->assertTrue(interface_exists(File::class));
	}

	public function testOcpInterfacesAreMockable(): void {
		$db = $this->createMock(IDBConnection::class);
		$this->assertInstanceOf(IDBConnection::class, $db);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);
		$this->assertNull($groupManager->get('nonexistent'));
	}
}
