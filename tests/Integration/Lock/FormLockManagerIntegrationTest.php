<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Lock;

use OCA\FormVox\Service\DecodedForms;
use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Service\FormWriteGuard;
use OCA\FormVox\Service\OwnWrites;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Server;

/**
 * FormLockManager against a REAL Nextcloud: the read-modify-write round trip through File/Storage, one writer per form
 * through Nextcloud's own locking under a key of FormVox's own, a busy form waited for and then 503 busy, the lock
 * released on success and on failure (#7/#90/#97/#101), and a lock row left by an older FormVox no longer in the way.
 * Writers at once in separate processes: ConcurrentSubmissionsIntegrationTest.
 *
 * @group DB
 */
class FormLockManagerIntegrationTest extends IntegrationTestCase {
	private FormLockManager $manager;

	protected function setUp(): void {
		parent::setUp();
		$this->manager = $this->getService(FormLockManager::class);
	}

	/** Another request's lock, on whatever this Nextcloud has: the shared cache or the row. */
	private function otherLock(): FormLock {
		return new FormLock(Server::get(ICacheFactory::class), Server::get(IDBConnection::class), Server::get(\Psr\Log\LoggerInterface::class));
	}

	private function locked(File $file): bool {
		$probe = $this->otherLock();
		return (new \ReflectionMethod(FormLock::class, 'current'))->invoke($probe, 'formvox/form/' . $file->getId()) !== null;
	}

	/** A manager that gives up quickly, so a busy form does not keep the test waiting ten seconds. */
	private function impatientManager(): FormLockManager {
		return new FormLockManager(
			Server::get(IUserSession::class),
			Server::get(OwnWrites::class),
			Server::get(FormWriteGuard::class),
			new DecodedForms(),
			$this->otherLock(),
			0.3,
		);
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
	 * The mutator's in-place change is read-modify-written through File/Storage and modified_at is stamped.
	 */
	public function testMutatePersistsReadModifyWrite(): void {
		$file = $this->resolveFile($this->writeFormFile());

		$before = $this->readFormFromStorage($file);
		$this->assertSame([], $before['responses'] ?? null, 'fixture should start with no responses');

		$this->manager->mutateFormFileWithLock($file, function (array &$form): void {
			$form['responses'][] = ['id' => 'r1'];
		});

		$after = $this->readFormFromStorage($file);
		$this->assertCount(1, $after['responses']);
		$this->assertSame('r1', $after['responses'][0]['id']);
		$this->assertNotFalse(strtotime((string)($after['modified_at'] ?? '')));
	}

	/** The form is locked exactly while it is written: not before, during the mutator, not after. */
	public function testTheFormIsLockedOnlyWhileItIsWritten(): void {
		$file = $this->resolveFile($this->writeFormFile());
		$this->assertFalse($this->locked($file), 'not locked before');

		$lockedDuring = null;
		$this->manager->mutateFormFileWithLock($file, function (array &$form) use ($file, &$lockedDuring): void {
			$lockedDuring = $this->locked($file);
			$form['responses'][] = ['id' => 'lifecycle'];
		});

		$this->assertTrue($lockedDuring, 'locked while the mutator runs');
		$this->assertFalse($this->locked($file), 'released after');
	}

	/** Held by another writer: this one waits until its deadline and then says busy; once free, it writes. */
	public function testABusyFormIsWaitedForThenBusyThenWritten(): void {
		$file = $this->resolveFile($this->writeFormFile());
		$other = $this->otherLock();
		$token = $other->acquire($file->getId(), microtime(true) + 1);
		try {
			$this->impatientManager()->mutateFormFileWithLock($file, function (array &$form): void {
				$form['responses'][] = ['id' => 'while-busy'];
			});
			$this->fail('A form held by another writer was written');
		} catch (FormLockedException) {
		} finally {
			$other->release($file->getId(), $token);
		}
		$this->assertSame([], $this->readFormFromStorage($file)['responses']);

		$this->impatientManager()->mutateFormFileWithLock($file, function (array &$form): void {
			$form['responses'][] = ['id' => 'once-free'];
		});
		$this->assertSame(['once-free'], array_column($this->readFormFromStorage($file)['responses'], 'id'));
	}

	/** A lock row an older FormVox left behind (an update while a form was being written) no longer blocks anything. */
	public function testALockRowFromAnOlderVersionIsNoLongerInTheWay(): void {
		$file = $this->resolveFile($this->writeFormFile());
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->insert('preferences')->values([
			'userid' => $qb->createNamedParameter('__formvox_lock__'),
			'appid' => $qb->createNamedParameter('formvox'),
			'configkey' => $qb->createNamedParameter('formvox_response_' . $file->getId()),
			'configvalue' => $qb->createNamedParameter((string)time()),
		]);
		$qb->executeStatement();

		$this->impatientManager()->mutateFormFileWithLock($file, function (array &$form): void {
			$form['responses'][] = ['id' => 'after-upgrade'];
		});

		$this->assertSame(['after-upgrade'], array_column($this->readFormFromStorage($file)['responses'], 'id'));
	}

	/**
	 * A throwing mutator aborts the write (on-disk content unchanged) and the exception propagates, yet the lock is
	 * still released.
	 */
	public function testMutatorThrowAbortsWrite(): void {
		$file = $this->resolveFile($this->writeFormFile());
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
		$this->assertSame($contentBefore, $storage->file_get_contents($internalPath), 'a throwing mutator must not persist any write');
		$this->assertFalse($this->locked($file), 'lock must be released even when the mutator throws');
	}

	/**
	 * The row that holds a form where no shared cache is (APCu, none), on this database: one holder at a time, released
	 * only with its own value, taken over only when its holder is gone; `occ formvox:unlock` frees it (7a, review 3a-2).
	 */
	public function testTheRowLockOnARealDatabase(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocking')->willReturn(new \OC\Memcache\NullCache());
		$row = fn () => new FormLock($factory, Server::get(IDBConnection::class), Server::get(\Psr\Log\LoggerInterface::class));
		$fileId = random_int(1_000_000, 9_999_999);
		$current = fn (FormLock $lock) => (new \ReflectionMethod(FormLock::class, 'current'))->invoke($lock, 'formvox/form/' . $fileId);

		// Where it must succeed, time to spare: a slow database must not look like a held form.
		$a = $row();
		$tokenA = $a->acquire($fileId, microtime(true) + 10);
		try {
			$row()->acquire($fileId, microtime(true) + 0.2);
			$this->fail('A second writer got a form that was held');
		} catch (FormLockedException) {
		}

		// A lost row taken by a successor: the old holder's release frees nothing.
		$this->assertTrue($row()->unlock($fileId));
		$b = $row();
		$tokenB = $b->acquire($fileId, microtime(true) + 10);
		$a->release($fileId, $tokenA);
		$this->assertTrue($b->stillHeld($fileId, $tokenB));

		$b->release($fileId, $tokenB);
		$this->assertNull($current($row()));

		// A holder past its time is taken over.
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->insert('preferences')->values([
			'userid' => $qb->createNamedParameter('__formvox_lock__'),
			'appid' => $qb->createNamedParameter('formvox'),
			'configkey' => $qb->createNamedParameter('formvox/form/' . $fileId),
			'configvalue' => $qb->createNamedParameter((time() - 121) . ':dead:elsewhere:1'),
		])->executeStatement();
		$c = $row();
		$c->release($fileId, $c->acquire($fileId, microtime(true) + 10));
		$this->assertNull($current($row()));
	}
}
