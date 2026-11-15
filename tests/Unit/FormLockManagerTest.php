<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Service\DecodedForms;
use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Service\FormWriteGuard;
use OCA\FormVox\Service\OwnWrites;
use OCA\FormVox\Store\SecretOverlay;
use OCA\FormVox\Store\StoredForm;
use OCA\FormVox\Tests\Unit\Support\Fake\Redis;
use OCP\Encryption\IManager;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * FormLockManager — the concurrency core. Pins what must not regress (#3/#7/#90/#97/#101): the mutator runs under the
 * form's lock against what is on disk now; modified_at is stamped; a short write throws (#97); a refused write is wrapped
 * with diagnostics (#90/#101); the lock is always released; a busy form is waited for, up to a deadline, then 503 busy.
 */
class FormLockManagerTest extends TestCase {
	private IUserSession $userSession;
	private OwnWrites $ownWrites;
	/** The form's lock: its log ('acquire'|'refused'|'release') and refusals (PHP_INT_MAX: never granted). */
	private Redis $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->ownWrites = new OwnWrites();

		$this->cache = new Redis();
	}

	private function manager(?DecodedForms $decoded = null, float $wait = 0.3, ?SecretOverlay $secrets = null): FormLockManager {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createLocking')->willReturn($this->cache);
		$lock = new FormLock($cacheFactory, $this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
		$guard = new FormWriteGuard($this->createMock(IManager::class), $this->createMock(\OCP\IConfig::class), $this->createMock(LoggerInterface::class));
		return new FormLockManager($this->userSession, $this->ownWrites, $guard, $decoded ?? new DecodedForms(), $lock, $wait, $secrets);
	}

	/** The overlay of a form in the database: its row, and secrets that pass through unchanged. */
	private function overlayOf(StoredForm $stored): SecretOverlay {
		$secrets = $this->createMock(SecretOverlay::class);
		$secrets->method('stored')->willReturn($stored);
		$secrets->method('add')->willReturnArgument(1);
		$secrets->method('split')->willReturnCallback(fn (?StoredForm $stored, array $form) => [$form, null, null]);
		return $secrets;
	}

	/**
	 * A File whose storage returns $content on read and, on write, records the payload and reports the written size
	 * (or $reportedSizeOverride, for a short write).
	 */
	private function fileWithStorage(string $content, ?int $reportedSizeOverride = null, ?\Throwable $putThrows = null, array &$written = []): File {
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
			$reportedSize = $reportedSizeOverride ?? strlen($payload);
		});

		return $file;
	}

	public function testHappyPathRunsMutatorStampsModifiedAndReleasesLock(): void {
		$written = [];
		$file = $this->fileWithStorage('{"responses":[]}', null, null, $written);

		$seenInsideMutator = null;
		$ret = $this->manager()->mutateFormFileWithLock($file, function (array &$form) use (&$seenInsideMutator) {
			$seenInsideMutator = $form;
			$form['responses'][] = ['a' => 1];
			return 'result-value';
		});

		$this->assertSame('result-value', $ret);
		$this->assertSame(['responses' => []], $seenInsideMutator);
		$this->assertCount(1, $written);
		$decoded = json_decode($written[0], true);
		$this->assertSame([['a' => 1]], $decoded['responses']);
		$this->assertArrayHasKey('modified_at', $decoded);
		$this->assertSame(['acquire', 'release'], $this->cache->log);
	}

	/** PO 5: a response leaves no version; a change to the form does, and its history is no longer wiped. */
	public function testAWriteLeavesNoVersionUnlessItChangesTheForm(): void {
		$seen = [];
		foreach ([false, true] as $version) {
			$storage = $this->createMock(IStorage::class);
			$storage->method('file_get_contents')->willReturn('{"responses":[]}');
			$storage->method('filesize')->willReturn(false);
			$file = $this->createMock(File::class);
			$file->method('getStorage')->willReturn($storage);
			$file->method('getInternalPath')->willReturn('files/form.fvform');
			$file->method('putContent')->willReturnCallback(function () use (&$seen) {
				$seen[] = [$this->ownWrites->active(), $this->ownWrites->withoutVersion()];
			});
			$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
				$form['x'] = 1;
			}, $version);
		}

		$this->assertSame([[true, true], [true, false]], $seen);
	}

	public function testShortWriteThrows(): void {
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
			$this->assertStringContainsString('updatable=', $e->getMessage());
			$this->assertStringContainsString('permission denied', $e->getMessage());
		}
		$this->assertSame(['acquire', 'release'], $this->cache->log);
	}

	public function testInvalidJsonOnDiskThrows(): void {
		$file = $this->fileWithStorage('not json at all');

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
		} catch (\DomainException) {
		}

		$this->assertSame([], $written, 'mutator throw must persist nothing');
		$this->assertSame(['acquire', 'release'], $this->cache->log);
	}

	/** A form that does not fit is not written at all: the old one stays whole, and the caller is told why. */
	public function testAFormThatDoesNotFitIsNotWrittenAndSaysTheStorageIsFull(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('file_get_contents')->willReturn('{"responses":[]}');
		$storage->method('free_space')->willReturn(10);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/form.fvform');
		$file->expects($this->never())->method('putContent');

		try {
			$this->manager()->mutateFormFileWithLock($file, function (array &$form): void {
				$form['responses'][] = ['id' => 'r1', 'answers' => ['q1' => str_repeat('x', 100)]];
			});
			$this->fail('A form that does not fit was written');
		} catch (RequestException $e) {
			$this->assertSame([507, 'storage_full'], [$e->getStatus(), $e->getReason()]);
		}
		$this->assertSame(['acquire', 'release'], $this->cache->log);
	}

	/**
	 * A stored form's file holds the form only: responses, counts, outbox and submissions in it (put back from a backup
	 * without the database) are the database's, so neither the mutator nor the file gets them again.
	 */
	public function testAStoredFormsFileIsWrittenWithTheFormOnly(): void {
		$content = (string)json_encode([
			'id' => 'uuid-in-the-file', 'title' => 'T', 'settings' => ['anonymous' => true],
			'responses' => [['id' => 'r1', 'answers' => ['q' => 'a private answer']]],
			'_index' => ['response_count' => 1, 'fingerprints' => ['fp-1']],
			'_outbox' => [['id' => 'r1:response.created']],
			'_submissions' => ['fill-1' => 'r1'],
		]);
		$written = [];
		$seen = null;

		$this->manager(null, 0.3, $this->overlayOf(new StoredForm(5, 'uuid-of-the-row', 1, 3)))
			->mutateFormFileWithLock($this->fileWithStorage($content, null, null, $written), function (array &$form) use (&$seen): void {
				$seen = $form;
				$form['title'] = 'Changed';
			});

		$this->assertSame([], $seen['responses'], 'the mutator sees the form only');
		$this->assertSame('uuid-of-the-row', $seen['id']);
		$decoded = json_decode($written[0], true);
		$this->assertSame('Changed', $decoded['title']);
		$this->assertSame([], $decoded['responses']);
		$this->assertSame(0, $decoded['_index']['response_count']);
		$this->assertSame([], $decoded['_index']['fingerprints']);
		$this->assertArrayNotHasKey('_outbox', $decoded);
		$this->assertArrayNotHasKey('_submissions', $decoded);
		$this->assertSame('uuid-of-the-row', $decoded['id']);
		$this->assertStringNotContainsString('a private answer', $written[0]);
	}

	/** Housekeeping within what the file held: a full quota does not refuse it, and the file keeps its time. */
	public function testHousekeepingWithinWhatTheFileHeldIsWrittenOnAFullQuotaAndKeepsTheFilesTime(): void {
		$content = '{"responses":[{"id":"r1","answers":{"q":"' . str_repeat('x', 200) . '"}}],"title":"T"}';
		$written = [];
		$storage = $this->createMock(IStorage::class);
		$storage->method('file_get_contents')->willReturn($content);
		$storage->method('free_space')->willReturn(0);
		$storage->method('filesize')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/form.fvform');
		$file->method('getMTime')->willReturn(1700000000);
		$file->method('putContent')->willReturnCallback(function (string $payload) use (&$written): void {
			$written[] = $payload;
		});
		$file->expects($this->once())->method('touch')->with(1700000000);

		$this->manager()->mutateFormFileWithLock($file, function (array &$form): void {
			$form['responses'] = [];
		}, false, false, true);

		$this->assertCount(1, $written);
		$this->assertArrayNotHasKey('modified_at', json_decode($written[0], true));
	}

	/** What this request already read is not decoded again under the lock, when the bytes are the same. */
	public function testTheFormThisRequestAlreadyReadIsNotDecodedAgain(): void {
		$raw = '{"responses":[]}';
		$decoded = new DecodedForms();
		$decoded->remember(1, $raw, ['responses' => [], 'seen' => 'before']);
		$seen = null;

		$this->manager($decoded)->mutateFormFileWithLock($this->fileWithStorage($raw), function (array &$form) use (&$seen): void {
			$seen = $form['seen'] ?? null;
		});

		$this->assertSame('before', $seen);
	}

	/** Another writer holds the form for a moment: this one waits, then writes, instead of failing. */
	public function testABusyFormIsWaitedFor(): void {
		$this->cache->refusals = 3;
		$written = [];

		$this->manager()->mutateFormFileWithLock($this->fileWithStorage('{"a":1}', null, null, $written), function (array &$form) {
			$form['a'] = 2;
		});

		$this->assertCount(1, $written);
		$this->assertSame(['refused', 'refused', 'refused', 'acquire', 'release'], $this->cache->log);
	}

	/** Busy until the deadline: 503 busy (FormLockedException), nothing written, nothing left held. */
	public function testAFormBusyPastTheDeadlineIsBusy(): void {
		$this->cache->refusals = PHP_INT_MAX;
		$written = [];
		$start = microtime(true);

		try {
			$this->manager(null, 0.2)->mutateFormFileWithLock($this->fileWithStorage('{"a":1}', null, null, $written), function (array &$form) {
				$form['a'] = 2;
			});
			$this->fail('A busy form was written');
		} catch (FormLockedException) {
		}

		$this->assertSame([], $written);
		$this->assertNotContains('acquire', $this->cache->log);
		$this->assertLessThan(1.0, microtime(true) - $start, 'it gives up at its deadline');
	}

	/** Nextcloud's own lock on the file, held by a reader for a moment, is waited for instead of failing with 500. */
	public function testAReaderHoldingTheFileIsWaitedFor(): void {
		$written = [];
		$attempts = 0;
		$storage = $this->createMock(IStorage::class);
		$storage->method('file_get_contents')->willReturn('{"a":1}');
		$storage->method('filesize')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/form.fvform');
		$file->method('putContent')->willReturnCallback(function (string $payload) use (&$attempts, &$written): void {
			if (++$attempts < 3) {
				throw new LockedException('files/form.fvform');
			}
			$written[] = $payload;
		});

		$this->manager()->mutateFormFileWithLock($file, function (array &$form) {
			$form['a'] = 2;
		});

		$this->assertSame(3, $attempts);
		$this->assertCount(1, $written);
		$this->assertSame(['acquire', 'release'], $this->cache->log);
	}

	public function testAReaderHoldingTheFilePastTheDeadlineIsBusy(): void {
		$file = $this->fileWithStorage('{"a":1}', null, new LockedException('files/form.fvform'));

		$this->expectException(FormLockedException::class);
		try {
			$this->manager(null, 0.2)->mutateFormFileWithLock($file, function (array &$form) {
				$form['a'] = 2;
			});
		} finally {
			$this->assertSame(['acquire', 'release'], $this->cache->log);
		}
	}
}
