<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\BooleanType;
use OCA\FormVox\Migration\Version00010Date20260420000000;
use OCA\FormVox\Migration\Version00011Date20261104000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The app's tables keep Nextcloud's migration rules for every supported database, Oracle included: there is no Oracle
 * lane in CI, so these rules are checked here (NC 32 MigrationService::ensureOracleConstraints and unique names).
 */
class SchemaNamesTest extends TestCase {
	private const MAX_TABLE = 22;
	private const MAX_NAME = 30;
	private const MAX_STRING = 4000;

	private Schema $schema;

	protected function setUp(): void {
		$this->schema = new Schema();
		$wrapper = new class($this->schema) implements ISchemaWrapper {
			public function __construct(
				private Schema $schema,
			) {
			}
			public function getTable($tableName) {
				return $this->schema->getTable($tableName);
			}
			public function hasTable($tableName) {
				return $this->schema->hasTable($tableName);
			}
			public function createTable($tableName) {
				return $this->schema->createTable($tableName);
			}
			public function dropTable($tableName) {
				$this->schema->dropTable($tableName);
				return $this;
			}
			public function getTables() {
				return $this->schema->getTables();
			}
			public function getTableNames() {
				return array_map(fn ($t) => $t->getName(), $this->schema->getTables());
			}
			public function getTableNamesWithoutPrefix() {
				return $this->getTableNames();
			}
			public function getDatabasePlatform() {
				return new OraclePlatform();
			}
		};
		$output = $this->createMock(IOutput::class);
		foreach ([new Version00010Date20260420000000(), new Version00011Date20261104000000()] as $step) {
			$step->changeSchema($output, fn () => $wrapper, []);
		}
	}

	public function testTheResponseTablesAreCreated(): void {
		foreach (['formvox_forms', 'formvox_resp', 'formvox_keys', 'formvox_counts', 'formvox_outbox', 'formvox_secrets'] as $name) {
			$this->assertTrue($this->schema->hasTable($name), $name);
		}
	}

	public function testNamesFitOracle(): void {
		$indexNames = [];
		foreach ($this->schema->getTables() as $table) {
			$name = $table->getName();
			$this->assertLessThanOrEqual(self::MAX_TABLE, strlen($name), "table $name: an unnamed primary key needs a short table name");
			$primary = array_filter($table->getIndexes(), fn ($index) => $index->isPrimary());
			$this->assertCount(1, $primary, "table $name has a primary key");
			foreach ($table->getColumns() as $column) {
				$where = "$name.{$column->getName()}";
				$this->assertLessThanOrEqual(self::MAX_NAME, strlen($column->getName()), $where);
				$this->assertFalse($column->getNotnull() && $column->getType() instanceof BooleanType, "$where: NOT NULL boolean");
				$this->assertLessThanOrEqual(self::MAX_STRING, $column->getLength() ?? 0, $where);
				$this->assertFalse($column->getNotnull() && $column->getDefault() === '', "$where: '' is NULL on Oracle");
			}
			foreach ($table->getIndexes() as $index) {
				if ($index->isPrimary()) {
					continue;
				}
				$this->assertLessThanOrEqual(self::MAX_NAME, strlen($index->getName()), $index->getName());
				$this->assertArrayNotHasKey($index->getName(), $indexNames, 'index names are unique across the schema');
				$indexNames[$index->getName()] = true;
			}
		}
	}
}
