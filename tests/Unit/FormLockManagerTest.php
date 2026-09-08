<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Versions\IFormVersionCleaner;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for FormLockManager — the concurrency core.
 *
 * Pins the behaviour that MUST NOT regress (#3/#7/#90/#97/#101):
 *  - the mutator runs under the lock against freshly-read on-disk content;
 *  - modified_at is stamped;
 *  - #97: a short (truncated) write throws;
 *  - #90/#101: a refused putContent is wrapped with write-context diagnostics;
 *  - the version cleaner is invoked after a successful write;
 *  - the lock row is always released (finally), even when the mutator throws;
 *  - #7: a stale lock is reclaimed before acquisition;
 *  - a unique-constraint violation on acquire retries rather than failing.
 *
 * All mocked against ocp stubs; no running server. Storage byte counts are
 * driven so the happy path writes the exact payload length.
 */
class FormLockManagerTest extends TestCase {
	private IDBConnection $db;
	private IUserSession $userSession;
	private IFormVersionCleaner $versionCleaner;

	/** Recording of DB statements executed, in order: 'insert'|'select'|'delete'. */
	private array $statements = [];
	/** Rows the next select (reclaim probe) should return. */
	private ?array $reclaimRow = null;
	/** If set, the Nth insert throws this (1-based); used to simulate lock contention. */
	private array $insertThrows = [];
	private int $insertCount = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->versionCleaner = $this->createMock(IFormVersionCleaner::class);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$this->db->method('getQueryBuilder')->willReturnCallback(function () use ($expr): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['insert', 'delete', 'select', 'from', 'where', 'andWhere', 'values', 'setMaxResults', 'orderBy'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnArgument(0);

			// insert()->executeStatement() acquires the lock.
			// select()->executeQuery()->fetch() is the reclaim probe.
			// delete()->executeStatement() releases (or reclaims) the lock.
			$qb->method('insert')->willReturnCallback(function () use ($qb) {
				$this->statements[] = 'insert';
				return $qb;
			});
			$qb->method('select')->willReturnCallback(function () use ($qb) {
				$this->statements[] = 'select';
				return $qb;
			});
			$qb->method('delete')->willReturnCallback(function () use ($qb) {
				$this->statements[] = 'delete';
				return $qb;
			});

			$qb->method('executeStatement')->willReturnCallback(function (): int {
				// The insert is the only executeStatement that can throw for
				// contention; deletes just succeed. Distinguish by last stmt.
				$last = end($this->statements);
				if ($last === 'insert') {
					$this->insertCount++;
					if (in_array($this->insertCount, $this->insertThrows, true)) {
						throw $this->uniqueViolation();
					}
				}
				return 1;
			});

			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(fn () => $this->reclaimRow ?? false);
			$result->method('closeCursor')->willReturn(true);
			$qb->method('executeQuery')->willReturn($result);

			return $qb;
		});
	}

	private function uniqueViolation(): DbException {
		// getMessage() is final on \Exception and can't be stubbed; the lock
		// code checks getReason() first (the typed path), so only stub that.
		$e = $this->createMock(DbException::class);
		$e->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		return $e;
	}

	private function manager(): FormLockManager {
		return new FormLockManager($this->db, $this->userSession, $this->versionCleaner);
	}

	/**
	 * A File whose storage returns $content on read and, on write, records the
	 * payload and reports $reportedSize (default: exact payload length).
	 */
	private function fileWithStorage(string $content, ?int $reportedSizeOverride = null, ?\Throwable $putThrows = null, array &$written = []): File {
		// Stateful reported size: starts at the on-disk content length, updates
		// to the written size after putContent(). A single willReturnCallback
		// reads this holder, so there is no double-stub conflict.
		$reportedSize = strlen($content);

		$storage = $this->createMock(IStorage::class);
		$storage->method('file_get_contents')->willReturn($content);
		$storage->method('getPermissions')->willReturn(31);
		$storage->method('free_space')->willReturn(1000000);
		$storage->method('filesize')->willReturnCallback(function () use (&$reportedSize) {
			return $reportedSize;
		});

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/form.fvform');
		$file->method('isUpdateable')->willReturn(true);

		$file->method('putContent')->willReturnCallback(function (string $payload) use ($putThrows, &$written, &$reportedSize, $reportedSizeOverride) {
			if ($putThrows !== null) {
				throw $putThrows;
			}
			$written[] = $payload;
			// After the write, filesize() reflects the written size (or an
			// override to simulate a truncated/short write).
			$reportedSize = $reportedSizeOverride ?? strlen($payload);
		});

		return $file;
	}

	public function testHappyPathRunsMutatorStampsModifiedAndReleasesLock(): void {
		$written = [];
		$file = $this->fileWithStorage('{"responses":[]}', null, null, $written);

		$seenInsideMutator = null;
		$ret = $this->manager()->mutateFormFileWithLock($file, function (array &$form) use (&$seenInsideMutator) {
			$seenInsideMutator = $form;          // proves fresh on-disk content is passed
			$form['responses'][] = ['a' => 1];
			return 'result-value';
		});

		$this->assertSame('result-value', $ret);
		$this->assertSame(['responses' => []], $seenInsideMutator);

		// The written payload has the mutation + a modified_at stamp.
		$this->assertCount(1, $written);
		$decoded = json_decode($written[0], true);
		$this->assertSame([['a' => 1]], $decoded['responses']);
		$this->assertArrayHasKey('modified_at', $decoded);

		// Version cleanup ran once; lock inserted then deleted (released).
		// (reclaim probe select returns no row, so no extra delete from reclaim.)
		$this->assertContains('insert', $this->statements);
		$this->assertContains('delete', $this->statements);
	}

	public function testVersionCleanerInvokedAfterSuccessfulWrite(): void {
		$written = [];
		$file = $this->fileWithStorage('{"responses":[]}', null, null, $written);
		$this->versionCleaner->expects($this->once())->method('deleteVersionsForFile')->with($file);

		$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
			$form['x'] = 1;
		});
	}

	public function testShortWriteThrows(): void {
		// Report a filesize SMALLER than the payload => #97 short-write guard.
		$written = [];
		$file = $this->fileWithStorage('{"responses":[]}', 3, null, $written);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Short write/');
		$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
			$form['responses'][] = ['big' => str_repeat('x', 100)];
		});
	}

	public function testRefusedWriteIsWrappedWithContext(): void {
		$written = [];
		$file = $this->fileWithStorage('{"a":1}', null, new \RuntimeException('permission denied'), $written);

		try {
			$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
				$form['a'] = 2;
			});
			$this->fail('expected RuntimeException');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Could not write form file', $e->getMessage());
			// #90/#101: diagnostics captured (storage class + updatable flag).
			$this->assertStringContainsString('updatable=', $e->getMessage());
			$this->assertStringContainsString('permission denied', $e->getMessage());
		}
	}

	public function testInvalidJsonOnDiskThrows(): void {
		$written = [];
		$file = $this->fileWithStorage('not json at all', null, null, $written);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Invalid form file format/');
		$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
			$form['x'] = 1;
		});
	}

	public function testLockReleasedEvenWhenMutatorThrows(): void {
		$written = [];
		$file = $this->fileWithStorage('{"a":1}', null, null, $written);

		try {
			$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
				throw new \DomainException('abort the write');
			});
			$this->fail('expected DomainException');
		} catch (\DomainException $e) {
			// nothing written, but the lock delete (release) still ran.
		}

		$this->assertSame([], $written, 'mutator throw must persist nothing');
		$this->assertContains('delete', $this->statements, 'lock must be released via finally');
	}

	public function testStaleLockIsReclaimedBeforeAcquire(): void {
		// reclaim probe finds an OLD timestamp => a delete (reclaim) happens
		// before the insert.
		$this->reclaimRow = ['configvalue' => (string)(time() - 999)];
		$written = [];
		$file = $this->fileWithStorage('{"a":1}', null, null, $written);

		$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
			$form['a'] = 2;
		});

		// Sequence begins: select (probe) -> delete (reclaim) -> insert (acquire).
		$firstThree = array_slice($this->statements, 0, 3);
		$this->assertSame(['select', 'delete', 'insert'], $firstThree);
	}

	public function testFreshLockIsNotReclaimed(): void {
		// A recent timestamp => probe returns, no reclaim delete before insert.
		$this->reclaimRow = ['configvalue' => (string)time()];
		$written = [];
		$file = $this->fileWithStorage('{"a":1}', null, null, $written);

		$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
			$form['a'] = 2;
		});

		// select (probe) -> insert (acquire), no delete between them.
		$this->assertSame('select', $this->statements[0]);
		$this->assertSame('insert', $this->statements[1]);
	}

	public function testUniqueViolationRetriesThenSucceeds(): void {
		// First insert throws a unique violation (lock held) => retry; second succeeds.
		$this->insertThrows = [1];
		$written = [];
		$file = $this->fileWithStorage('{"a":1}', null, null, $written);

		$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
			$form['a'] = 2;
		});

		$this->assertCount(1, $written, 'the retry eventually wrote once');
		$this->assertGreaterThanOrEqual(2, $this->insertCount, 'insert was attempted at least twice');
	}
}
