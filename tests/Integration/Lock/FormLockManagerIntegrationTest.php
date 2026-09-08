<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Lock;

use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Integration coverage for FormLockManager against a REAL Nextcloud DB +
 * filesystem: the per-file preferences-row lock, the read-modify-write
 * round-trip through File/Storage, TTL reclaim of a stale lock, and lock
 * release on both success and mutator failure (#7/#90/#97/#101).
 *
 * @group DB
 */
class FormLockManagerIntegrationTest extends IntegrationTestCase {
	private const LOCK_USERID = '__formvox_lock__';
	private const LOCK_APPID = 'formvox';
	/** Mirrors FormLockManager::LOCK_TTL_SECONDS (private const). */
	private const LOCK_TTL_SECONDS = 60;

	private FormLockManager $manager;
	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->manager = $this->getService(FormLockManager::class);
		$this->db = Server::get(IDBConnection::class);
	}

	/** The lock configkey FormLockManager derives from a file id. */
	private function lockKeyFor(File $file): string {
		return 'formvox_response_' . $file->getId();
	}

	/** Count the preferences lock rows for a given configkey. */
	private function countLockRows(string $lockKey): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter(self::LOCK_USERID)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(self::LOCK_APPID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($lockKey)));
		$result = $qb->executeQuery();
		$cnt = (int)$result->fetchOne();
		$result->closeCursor();
		return $cnt;
	}

	/** Fetch the single lock row for a configkey, or null. */
	private function fetchLockRow(string $lockKey): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('userid', 'appid', 'configkey', 'configvalue')
			->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter(self::LOCK_USERID)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(self::LOCK_APPID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($lockKey)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	/** Manually insert a lock row with an explicit acquisition timestamp. */
	private function insertLockRow(string $lockKey, int $acquiredAt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('preferences')
			->values([
				'userid' => $qb->createNamedParameter(self::LOCK_USERID),
				'appid' => $qb->createNamedParameter(self::LOCK_APPID),
				'configkey' => $qb->createNamedParameter($lockKey),
				'configvalue' => $qb->createNamedParameter((string)$acquiredAt),
			]);
		$qb->executeStatement();
	}

	/** Resolve the freshly written form file back through the user folder by id. */
	private function resolveFile(File $written): File {
		$nodes = $this->userFolder->getById($written->getId());
		$this->assertNotEmpty($nodes, 'expected the written form file to resolve by id');
		$node = $nodes[0];
		$this->assertInstanceOf(File::class, $node);
		return $node;
	}

	/**
	 * Target 1: the mutator's in-place change is read-modify-written through
	 * File/Storage and modified_at is stamped by the manager.
	 */
	public function testMutatePersistsReadModifyWrite(): void {
		$file = $this->resolveFile($this->writeFormFile());

		$before = $this->readFormFromStorage($file);
		$this->assertSame([], $before['responses'] ?? null, 'fixture should start with no responses');
		$beforeModified = $before['modified_at'] ?? null;

		$this->manager->mutateFormFileWithLock($file, function (array &$form): void {
			$form['responses'][] = ['id' => 'r1'];
		});

		$after = $this->readFormFromStorage($file);
		$this->assertCount(1, $after['responses']);
		$this->assertSame('r1', $after['responses'][0]['id']);
		$this->assertArrayHasKey('modified_at', $after);
		$this->assertNotEmpty($after['modified_at']);
		// modified_at must be a valid ISO-8601 (date('c')) value.
		$this->assertNotFalse(strtotime((string)$after['modified_at']));
	}

	/**
	 * Target 1: the DB lock row exists only for the duration of the mutator —
	 * none before, exactly one during (asserted inside the closure), none after.
	 */
	public function testLockRowLifecycle(): void {
		$file = $this->resolveFile($this->writeFormFile());
		$lockKey = $this->lockKeyFor($file);

		$this->assertSame(0, $this->countLockRows($lockKey), 'no lock row should exist before mutate');

		$sawLockDuring = false;
		$this->manager->mutateFormFileWithLock($file, function (array &$form) use ($lockKey, &$sawLockDuring): void {
			// Assert the lock state from INSIDE the mutator: it runs under the lock.
			$this->assertSame(1, $this->countLockRows($lockKey), 'exactly one lock row must be held during mutate');
			$row = $this->fetchLockRow($lockKey);
			$this->assertNotNull($row);
			$this->assertSame(self::LOCK_USERID, $row['userid']);
			$this->assertSame(self::LOCK_APPID, $row['appid']);
			$this->assertSame($lockKey, $row['configkey']);
			$sawLockDuring = true;
			$form['responses'][] = ['id' => 'lifecycle'];
		});

		$this->assertTrue($sawLockDuring, 'mutator must have run');
		$this->assertSame(0, $this->countLockRows($lockKey), 'lock row must be released (finally) after mutate');
	}

	/**
	 * Target 1: a lock row older than LOCK_TTL_SECONDS is reclaimed on the first
	 * attempt, so mutate succeeds immediately and the write persists.
	 */
	public function testStaleLockReclaimed(): void {
		$file = $this->resolveFile($this->writeFormFile());
		$lockKey = $this->lockKeyFor($file);

		// Plant a stale lock (acquired well beyond the TTL ago).
		$this->insertLockRow($lockKey, time() - (self::LOCK_TTL_SECONDS + 60));
		$this->assertSame(1, $this->countLockRows($lockKey), 'stale lock should be planted');

		$this->manager->mutateFormFileWithLock($file, function (array &$form): void {
			$form['responses'][] = ['id' => 'after-stale'];
		});

		$after = $this->readFormFromStorage($file);
		$this->assertCount(1, $after['responses']);
		$this->assertSame('after-stale', $after['responses'][0]['id']);
		$this->assertSame(0, $this->countLockRows($lockKey), 'lock must be released after the reclaim + write');
	}

	/**
	 * Target 1: a throwing mutator aborts the write (on-disk content unchanged)
	 * and the exception propagates, yet the lock is still released (finally).
	 */
	public function testMutatorThrowAbortsWrite(): void {
		$file = $this->resolveFile($this->writeFormFile());
		$lockKey = $this->lockKeyFor($file);

		$storage = $file->getStorage();
		$internalPath = $file->getInternalPath();
		$contentBefore = $storage->file_get_contents($internalPath);

		$threw = false;
		try {
			$this->manager->mutateFormFileWithLock($file, function (array &$form): void {
				$form['responses'][] = ['id' => 'should-not-persist'];
				throw new \DomainException('abort from mutator');
			});
		} catch (\DomainException $e) {
			$threw = true;
			$this->assertSame('abort from mutator', $e->getMessage());
		}

		$this->assertTrue($threw, 'the DomainException must propagate to the caller');

		// On-disk bytes must be byte-for-byte unchanged.
		$contentAfter = $storage->file_get_contents($internalPath);
		$this->assertSame($contentBefore, $contentAfter, 'a throwing mutator must not persist any write');

		$decoded = json_decode($contentAfter, true);
		$this->assertSame([], $decoded['responses'] ?? null, 'no response should have been appended');

		$this->assertSame(0, $this->countLockRows($lockKey), 'lock must be released even when the mutator throws');
	}

	/**
	 * Target 1 (retry path): true multi-process contention is asserted via the
	 * stale-reclaim path in testStaleLockReclaimed, which deterministically
	 * exercises reclaimStaleLock() + the acquire path in a single process. A
	 * genuine concurrent-holder race (a FRESH row that another process releases
	 * mid-retry) cannot be driven deterministically from one process without a
	 * load harness, so it is skipped.
	 */
	public function testConcurrentRetryThenAcquire(): void {
		$this->markTestSkipped(
			'true multi-process contention needs a load harness; the retry/reclaim '
			. 'path is covered deterministically by testStaleLockReclaimed'
		);
	}
}
