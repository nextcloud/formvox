<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\SetupCheck;

use OCA\FormVox\SetupCheck\DatabaseCheck;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class DatabaseCheckTest extends TestCase {
	private function check(string $provider): DatabaseCheck {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($provider);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToDocs')->willReturnCallback(fn (string $key) => 'https://docs.example/' . $key);
		return new DatabaseCheck($db, new EnglishL10N(), $urls);
	}

	public function testOnSqliteTheAdminIsWarnedWhereToMove(): void {
		$result = $this->check(IDBConnection::PLATFORM_SQLITE)->run();

		$this->assertSame('warning', $result->getSeverity());
		$this->assertStringContainsString('does not fully support SQLite', (string)$result->getDescription());
		$this->assertSame('https://docs.example/admin-db-conversion', $result->getLinkToDoc());
	}

	public function testOtherDatabasesAreFine(): void {
		foreach ([IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB, IDBConnection::PLATFORM_POSTGRES, IDBConnection::PLATFORM_ORACLE] as $provider) {
			$this->assertSame('success', $this->check($provider)->run()->getSeverity(), $provider);
		}
	}
}
