<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Tests\Unit\Support\Fake\APCu;
use OCA\FormVox\Tests\Unit\Support\Fake\Redis;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * FormLock: one writer per form, waited for until a deadline, released only by its holder (spike M2/A2), taken over only
 * from a holder that is gone. Kept in a cache every process shares (Redis, Memcached), else in a row of oc_preferences
 * that web and cron both see (APCu is not shared between them).
 */
class FormLockTest extends TestCase {
	private Redis $redis;
	/** The fake preferences row of the fallback: null when there is none. */
	private ?string $row = null;
	/** @var list<string> statements on the fallback row, in order */
	private array $rowLog = [];
	/** @var list<int> what the database says to the next inserts of the row (OCP\DB\Exception reasons) */
	private array $insertFails = [];

	protected function setUp(): void {
		parent::setUp();
		$this->redis = new Redis();
		// One machine for the tests, wherever they run: macOS has no /proc.
		self::setHost('a1b2c3d4e5f60718');
	}

	protected function tearDown(): void {
		self::setHost(null);
		parent::tearDown();
	}

	private static function setHost(?string $host): void {
		(new \ReflectionProperty(FormLock::class, 'hostId'))->setValue(null, $host);
	}

	private function lock(?IMemcache $cache = null): FormLock {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocking')->willReturn($cache ?? $this->redis);
		return new FormLock($factory, $this->db(), $this->createMock(LoggerInterface::class));
	}

	/** APCu as Nextcloud names it: per process group, so the row is used. */
	private function apcu(): IMemcache {
		return new APCu();
	}

	/** The value a holder on this host leaves, as FormLock writes it. */
	private function value(int $since, string $token, ?int $pid = null, ?string $host = null): string {
		$host ??= (new \ReflectionMethod(FormLock::class, 'host'))->invoke(null);
		return $since . ':' . $token . ':' . $host . ':' . ($pid ?? getmypid());
	}

	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnArgument(0);
		$db->method('getQueryBuilder')->willReturnCallback(function (): IQueryBuilder {
			$mode = null;
			$values = [];
			$like = null;
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('eq')->willReturn('eq');
			$expr->method('like')->willReturnCallback(function (string $column, string $value) use (&$like): string {
				$like = $value;
				return 'like';
			});
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['from', 'where', 'andWhere'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnArgument(0);
			foreach (['select', 'insert', 'delete'] as $m) {
				$qb->method($m)->willReturnCallback(function () use ($qb, &$mode, $m) {
					$mode = $m;
					return $qb;
				});
			}
			$qb->method('values')->willReturnCallback(function (array $v) use ($qb, &$values) {
				$values = $v;
				return $qb;
			});
			$qb->method('executeQuery')->willReturnCallback(function () {
				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn($this->row ?? false);
				return $result;
			});
			$qb->method('executeStatement')->willReturnCallback(function () use (&$mode, &$values, &$like): int {
				$this->rowLog[] = $mode;
				if ($mode === 'insert') {
					$reason = array_shift($this->insertFails);
					if ($reason !== null) {
						$e = $this->createMock(DbException::class);
						$e->method('getReason')->willReturn($reason);
						throw $e;
					}
					if ($this->row !== null) {
						$e = $this->createMock(DbException::class);
						$e->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
						throw $e;
					}
					$this->row = $values['configvalue'];
				}
				// The row goes only when its value is the one named.
				if ($mode === 'delete' && $like === $this->row) {
					$this->row = null;
				}
				return 1;
			});
			return $qb;
		});
		return $db;
	}

	public function testOneHolderAtATimeAndOnlyItReleases(): void {
		$a = $this->lock();
		$b = $this->lock();
		$token = $a->acquire(7, microtime(true) + 1);

		try {
			$b->acquire(7, microtime(true) + 0.1);
			$this->fail('A second writer got a form that was held');
		} catch (FormLockedException) {
		}
		$a->release(7, 'not-the-token');
		$this->assertArrayHasKey('formvox/form/7', $this->redis->store, 'a wrong token releases nothing');

		$a->release(7, $token);
		$this->assertSame([], $this->redis->store);
		$b->release(7, $b->acquire(7, microtime(true) + 1));
	}

	public function testDifferentFormsAndPurposesDoNotWaitForEachOther(): void {
		$lock = $this->lock();
		$lock->acquire(1, microtime(true) + 0.1);
		$lock->acquire(2, microtime(true) + 0.1);
		$lock->acquire(1, microtime(true) + 0.1, 'effects');
		$this->assertSame(['formvox/form/1', 'formvox/form/2', 'formvox/form/effects/1'], array_keys($this->redis->store));
	}

	/**
	 * Spike M2/A2: a holder whose lock was lost (expired while it stalled) and taken by a successor frees nothing when it
	 * releases, and sees it no longer holds the form before it writes.
	 */
	public function testAHolderThatLostItsLockNeverFreesItsSuccessor(): void {
		$a = $this->lock();
		$tokenA = $a->acquire(7, microtime(true) + 1);
		$this->redis->remove('formvox/form/7');
		$b = $this->lock();
		$tokenB = $b->acquire(7, microtime(true) + 1);

		$this->assertFalse($a->stillHeld(7, $tokenA));
		$a->release(7, $tokenA);

		$this->assertTrue($b->stillHeld(7, $tokenB));
		try {
			$this->lock()->acquire(7, microtime(true) + 0.1);
			$this->fail('A third writer got in beside the successor');
		} catch (FormLockedException) {
		}
	}

	/** @return array<string, array{\Closure(self): string, bool}> */
	public static function holders(): array {
		return [
			'a holder past its time' => [fn (self $t) => $t->value(time() - 121, 'old'), true],
			'a live holder on another host' => [fn (self $t) => $t->value(time() - 30, 'other', 1, 'otherhost'), false],
			'a live holder in this process' => [fn (self $t) => $t->value(time() - 30, 'mine'), false],
			'a dead process on this host' => [fn (self $t) => $t->value(time() - 30, 'dead', 999999), true],
		];
	}

	/**
	 * A killed writer leaves its key: it is taken over when the holder is gone, never while it may still be writing.
	 *
	 * @dataProvider holders
	 * @param \Closure(self): string $holder
	 */
	public function testOnlyAHolderThatIsGoneIsTakenOver(\Closure $holder, bool $takenOver): void {
		if (!function_exists('posix_kill')) {
			$this->markTestSkipped('needs ext-posix');
		}
		$this->redis->store['formvox/form/7'] = $holder($this);

		try {
			$this->lock()->acquire(7, microtime(true) + 0.2);
			$this->assertTrue($takenOver, 'a holder that may still be writing lost its lock');
		} catch (FormLockedException) {
			$this->assertFalse($takenOver, 'a holder that is gone kept the form');
		}
	}

	public function testUnlockFreesWhoeverHoldsIt(): void {
		$this->redis->store['formvox/form/7'] = $this->value(time(), 'someone', 1, 'otherhost');

		$this->assertTrue($this->lock()->unlock(7));
		$this->assertFalse($this->lock()->unlock(7));
		$this->assertSame([], $this->redis->store);
	}

	/** APCu: web and cron do not share it, so the row is the lock, and it still excludes a second writer. */
	public function testWithoutASharedCacheARowExcludesASecondWriter(): void {
		$a = $this->lock($this->apcu());
		$token = $a->acquire(7, microtime(true) + 1);
		$this->assertNotNull($this->row);

		try {
			$this->lock($this->apcu())->acquire(7, microtime(true) + 0.1);
			$this->fail('A second writer got a form that was held');
		} catch (FormLockedException) {
		}

		$a->release(7, $token);
		$this->assertNull($this->row);
	}

	/** The row goes only with its own value: a successor's row stays when an old holder releases. */
	public function testARowIsReleasedOnlyWithItsOwnValue(): void {
		$lock = $this->lock($this->apcu());
		$token = $lock->acquire(7, microtime(true) + 1);
		$successor = $this->value(time(), 'successor', 1, 'otherhost');
		$this->row = $successor;

		$lock->release(7, $token);

		$this->assertSame($successor, $this->row);
		$this->assertContains('delete', $this->rowLog, 'the release was tried');
	}

	/**
	 * Two writers racing for the row on MySQL/MariaDB: the one InnoDB picks as a deadlock victim waits like any other and
	 * gets the form once it is free, never an error (review of the 3a fixes: it was a 500).
	 */
	public function testADeadlockVictimWaitsAndGetsTheForm(): void {
		$this->insertFails = [DbException::REASON_DEADLOCK, DbException::REASON_DEADLOCK];

		$token = $this->lock($this->apcu())->acquire(7, microtime(true) + 1);

		$this->assertStringContainsString(':' . $token . ':', (string)$this->row);
	}

	/** The victim of a race the other writer won leaves the winner's row alone and waits for it. */
	public function testADeadlockVictimLeavesTheWinnersRow(): void {
		$winner = $this->value(time(), 'winner', 1, 'otherhost');
		$this->row = $winner;
		$this->insertFails = [DbException::REASON_DEADLOCK];

		try {
			$this->lock($this->apcu())->acquire(7, microtime(true) + 0.2);
			$this->fail('The victim got the form the winner holds');
		} catch (FormLockedException) {
		}
		$this->assertSame($winner, $this->row);
	}

	/** Anything else the database says is no busy form: it is raised as it is. */
	public function testAnyOtherDatabaseErrorIsRaised(): void {
		$this->insertFails = [DbException::REASON_CONNECTION_LOST];

		$this->expectException(DbException::class);
		$this->lock($this->apcu())->acquire(7, microtime(true) + 1);
	}

	/**
	 * Whether another holder is saving decides between a broken form (500) and a busy one (503): this instance's own
	 * holding and a holder that is gone do not count, on either backend (review of the 3a fixes).
	 */
	public function testHeldByAnotherCountsOnlyALiveOtherHolder(): void {
		$lock = $this->lock();
		$this->assertFalse($lock->heldByAnother(7), 'free');

		$this->redis->store['formvox/form/7'] = $this->value(time(), 'other', 1, 'otherhost');
		$this->assertTrue($lock->heldByAnother(7), 'another live holder');

		$this->redis->store['formvox/form/7'] = $this->value(time() - 121, 'old', 1, 'otherhost');
		$this->assertFalse($lock->heldByAnother(7), 'a holder past its time');

		unset($this->redis->store['formvox/form/7']);
		$lock->acquire(7, microtime(true) + 1);
		$this->assertFalse($lock->heldByAnother(7), 'this instance itself, as the clean-up reads while it holds');
	}

	public function testHeldByAnotherSeesTheRowToo(): void {
		$this->row = $this->value(time(), 'other', 1, 'otherhost');

		$this->assertTrue($this->lock($this->apcu())->heldByAnother(7));
	}

	/** Where the machine cannot be told (no /proc), a holder's pid means nothing: only its time decides. */
	public function testWithoutAKnownMachineNoHolderIsDeclaredDeadByItsPid(): void {
		if (!function_exists('posix_kill')) {
			$this->markTestSkipped('needs ext-posix');
		}
		self::setHost('-');
		$this->redis->store['formvox/form/7'] = $this->value(time() - 30, 'elsewhere', 999999, '-');

		$this->expectException(FormLockedException::class);
		$this->lock()->acquire(7, microtime(true) + 0.2);
	}

	/**
	 * A lock wait timeout comes only after the database's own long wait: the form is busy at once, the log says why,
	 * and no takeover waits on the same stuck lock again (review of the 3a fixes).
	 */
	public function testALockWaitTimeoutIsLoggedAndNotWaitedOnAgain(): void {
		$this->row = $this->value(time() - 121, 'stale', 1, 'otherhost');
		$this->insertFails = [DbException::REASON_LOCK_WAIT_TIMEOUT];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('lock wait timeout'));
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocking')->willReturn($this->apcu());
		$lock = new FormLock($factory, $this->db(), $logger);

		try {
			$lock->acquire(7, microtime(true));
			$this->fail('A stuck row was taken');
		} catch (FormLockedException) {
		}
		$this->assertNotContains('delete', $this->rowLog, 'no takeover waited on the stuck lock');
	}

	public function testAStaleRowIsTakenOver(): void {
		$this->row = $this->value(time() - 121, 'dead', 1, 'otherhost');

		$this->lock($this->apcu())->acquire(7, microtime(true) + 1);

		$this->assertStringContainsString(':' . getmypid(), (string)$this->row);
	}

	/** Past its time a row is no longer trusted: anyone may take it over, as an expired key would be (review of 3b-3b). */
	public function testARowPastItsTimeIsNoLongerHeld(): void {
		$lock = $this->lock($this->apcu());
		$token = $lock->acquire(7, microtime(true) + 1);
		$this->assertTrue($lock->stillHeld(7, $token));

		$old = $this->value(time() - 121, $token);
		$this->row = $old;
		(new \ReflectionProperty(FormLock::class, 'held'))->setValue($lock, ['formvox/form/7' => $old]);

		$this->assertFalse($lock->stillHeld(7, $token));
	}
}
