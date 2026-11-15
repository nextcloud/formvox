<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\DecodedForms;
use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormReader;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ILockingStorage;
use OCP\Files\Storage\IStorage;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * FormReader reads below Nextcloud's file locks, so a save in progress never fails a reader: half a file or a
 * decryption error mid-save is read again; steady bytes or a steady error while nobody saves are a broken file; still
 * unreadable at the deadline is a busy form (7a, 3a-3; review of the 3a fixes).
 */
class FormReaderTest extends TestCase {
	private const FORM = '{"title":"Survey","settings":{"public_token":"t"}}';

	private DecodedForms $decoded;
	private ?IEventDispatcher $dispatcher = null;
	/** @var list<string|false|\Throwable> what each read meets, in turn; the last one repeats */
	private array $reads = [];
	private int $readCount = 0;
	/** Until when a writer saves the form, and whose lock it holds: FormVox's or Nextcloud's. */
	private float $savingUntil = 0.0;
	private string $saver = 'formvox';
	/** What the read meets once the save is done. */
	private ?string $afterSave = null;
	/** The read taken just before the save ends lasts past its end, as the decode of a large half file does. */
	private bool $saveEndsDuringARead = false;
	/** A save begins during the first read after a probe found nobody saving, for half a second. */
	private bool $saveStartsDuringTheJudgedRead = false;
	/** How long each read takes, as on slow storage. */
	private float $readSeconds = 0.0;
	/** @var list<array{string, string, int}> Nextcloud lock calls: [acquire|release, path, type] */
	private array $lockCalls = [];
	private ILockingProvider $provider;
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->decoded = new DecodedForms();
		$this->provider = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function file(): File {
		$storage = $this->createMockForIntersectionOfInterfaces([IStorage::class, ILockingStorage::class]);
		$storage->method('acquireLock')->willReturnCallback(function (string $path, int $type, ILockingProvider $provider) {
			$this->lockCalls[] = ['acquire', $path, $type];
			if ($this->saver === 'nextcloud' && microtime(true) < $this->savingUntil) {
				throw new LockedException($path);
			}
		});
		$storage->method('releaseLock')->willReturnCallback(function (string $path, int $type, ILockingProvider $provider) {
			$this->lockCalls[] = ['release', $path, $type];
		});
		$storage->method('file_get_contents')->with('files/Survey.fvform')->willReturnCallback(function () {
			$read = $this->reads[min($this->readCount, count($this->reads) - 1)];
			$this->readCount++;
			if ($this->readSeconds > 0) {
				usleep((int)($this->readSeconds * 1_000_000));
			}
			if ($this->saveStartsDuringTheJudgedRead && $this->lockCalls !== []) {
				$this->saveStartsDuringTheJudgedRead = false;
				$this->savingUntil = microtime(true) + 0.5;
				$this->afterSave = self::FORM;
				return $read;
			}
			if ($this->saveEndsDuringARead && microtime(true) < $this->savingUntil && microtime(true) > $this->savingUntil - 0.15) {
				usleep((int)(($this->savingUntil - microtime(true) + 0.02) * 1_000_000));
				return $read;
			}
			if ($this->afterSave !== null && microtime(true) >= $this->savingUntil) {
				return $this->afterSave;
			}
			if ($read instanceof \Throwable) {
				throw $read;
			}
			return $read;
		});
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/Survey.fvform');
		// Never through the View: that takes the lock a writer holds.
		$file->expects($this->never())->method('getContent');
		return $file;
	}

	private function reader(): FormReader {
		$lock = $this->createMock(FormLock::class);
		$lock->method('heldByAnother')->willReturnCallback(fn () => $this->saver === 'formvox' && microtime(true) < $this->savingUntil);
		return new FormReader($this->decoded, $this->dispatcher ?? $this->createMock(IEventDispatcher::class), $lock, $this->provider, $this->logger);
	}

	/**
	 * A save that ends while the read taken during it is still being decoded: the read after it finds the whole form,
	 * never a broken one (review of the 3a fixes: nobody saving was asked after the read it judged).
	 */
	public function testASaveThatEndsDuringAReadIsNoBrokenForm(): void {
		$this->reads = ['{"title": "Surv'];
		$this->savingUntil = microtime(true) + 0.6;
		$this->saveEndsDuringARead = true;
		$this->afterSave = self::FORM;

		$this->assertSame('Survey', $this->reader()->read($this->file(), null, 3.0)['title']);
	}

	/** A save that begins during the read that would judge: asked again after it, so it is waited for, not called broken. */
	public function testASaveThatStartsDuringTheJudgedReadIsWaitedFor(): void {
		$this->reads = ['{"title": "Surv'];
		$this->saveStartsDuringTheJudgedRead = true;

		$this->assertSame('Survey', $this->reader()->read($this->file(), null, 3.0)['title']);
	}

	/** On slow storage a broken file is still told within the forms list's short wait: the steady time counts from a read's start. */
	public function testASlowBrokenReadIsStillCalledBroken(): void {
		$this->reads = [''];
		$this->readSeconds = 0.2;

		$this->expectException(\OCA\FormVox\Service\FormBrokenException::class);
		$this->reader()->read($this->file(), null, 0.5);
	}

	/** The same half file while a writer saves: waited for, then read whole. */
	public function testSteadyBytesDuringASaveAreWaitedFor(): void {
		$this->reads = ['{"title": "Surv'];
		$this->savingUntil = microtime(true) + 0.8;
		$this->afterSave = self::FORM;

		$this->assertSame('Survey', $this->reader()->read($this->file(), null, 3.0)['title']);
	}

	/**
	 * Nextcloud's lock is only asked once the read has been the same for the steady time, shared, and given back at once.
	 */
	public function testNextcloudsLockIsAskedSharedAndGivenBack(): void {
		$this->reads = ['{"title": "Surv'];

		try {
			$this->reader()->read($this->file(), null, 3.0);
			$this->fail('A broken file was read as a form');
		} catch (\OCA\FormVox\Service\FormBrokenException) {
		}

		$this->assertNotSame([], $this->lockCalls);
		foreach (array_chunk($this->lockCalls, 2) as $pair) {
			$this->assertSame([['acquire', 'files/Survey.fvform', ILockingProvider::LOCK_SHARED], ['release', 'files/Survey.fvform', ILockingProvider::LOCK_SHARED]], $pair);
		}
		$this->assertGreaterThan(1, $this->readCount, 'not asked before the first reads');
	}

	/** A form that cannot be decrypted or reached is worth a log line when it is called broken; an empty file is not. */
	public function testOnlyASteadyErrorIsLoggedAsBroken(): void {
		$this->reads = [new \Exception('Bad Signature: the key cannot open this file')];
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('cannot be read'));

		try {
			$this->reader()->read($this->file(), null, 0.5);
		} catch (\OCA\FormVox\Service\FormBrokenException) {
		}
	}

	public function testAnEmptyFileIsNotLogged(): void {
		$this->reads = [''];
		$this->logger->expects($this->never())->method('warning');

		$this->expectException(\OCA\FormVox\Service\FormBrokenException::class);
		$this->reader()->read($this->file(), null, 0.5);
	}

	/** What Nextcloud's own read hooks rethrow is rethrown here too: a server that is not available stops the read. */
	public function testAServerThatIsNotAvailableStopsTheRead(): void {
		require_once __DIR__ . '/Support/Stub/ServerNotAvailableException.php';
		$this->reads = [self::FORM];
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willThrowException(new \OC\ServerNotAvailableException('LDAP is down'));

		$this->expectException(\OC\ServerNotAvailableException::class);
		$this->reader()->read($this->file());
	}

	public function testAWholeFormIsReadOnceAndKeptForTheWrite(): void {
		$this->reads = [self::FORM];

		$this->assertSame('Survey', $this->reader()->read($this->file())['title']);
		$this->assertSame(1, $this->readCount);
		$this->assertSame('Survey', $this->decoded->recall(7, self::FORM)['title'] ?? null);
	}

	/**
	 * @return array<string, array{list<string|false|\Throwable>}>
	 */
	public static function midSave(): array {
		return [
			'half a file' => [[substr(self::FORM, 0, 20), self::FORM]],
			'an empty file just truncated' => [['', self::FORM]],
			'a decryption error' => [[new \Exception('Bad Signature'), self::FORM]],
			'a type error from a short block' => [[new \TypeError('fread(): Argument #1 must be of type resource'), self::FORM]],
			'no file between unlink and rename' => [[false, self::FORM]],
		];
	}

	/**
	 * @dataProvider midSave
	 * @param list<string|false|\Throwable> $reads
	 */
	public function testWhatASaveInProgressLeavesIsReadAgain(array $reads): void {
		$this->reads = $reads;

		$this->assertSame('Survey', $this->reader()->read($this->file())['title']);
		$this->assertSame(2, $this->readCount);
	}

	/** The same bytes that are no form, for longer than any save takes: a broken file, said at once, not after the wait. */
	public function testSteadyBytesThatAreNoFormAreABrokenFile(): void {
		$this->reads = ['{"title": "Survey"'];
		$started = microtime(true);

		try {
			$this->reader()->read($this->file(), null, 10.0);
			$this->fail('A broken file was read as a form');
		} catch (FormLockedException) {
			$this->fail('A broken file was answered as busy');
		} catch (\RuntimeException $e) {
			$this->assertSame('Invalid form file format', $e->getMessage());
		}
		$this->assertLessThan(2.0, microtime(true) - $started);
	}

	public function testStillUnreadableAtTheDeadlineIsABusyForm(): void {
		$this->reads = [new \Exception('Bad Signature')];
		$this->savingUntil = microtime(true) + 10;
		$started = microtime(true);

		$this->expectException(FormLockedException::class);
		try {
			$this->reader()->read($this->file(), null, 0.2);
		} finally {
			$this->assertGreaterThanOrEqual(0.2, microtime(true) - $started);
			$this->assertGreaterThan(1, $this->readCount);
		}
	}

	/** What an anonymous request may make the server read stays bounded, also when the file grew since the check. */
	public function testLargerThanTheBoundIsNotFound(): void {
		$this->reads = [self::FORM];

		$this->expectException(NotFoundException::class);
		$this->reader()->read($this->file(), 10);
	}

	/** The same error, not a save that comes and goes: after a second it is a broken file (500), not a busy one (503). */
	public function testTheSameErrorForASecondIsABrokenFile(): void {
		$this->reads = [new \Exception('Bad Signature: the key cannot open this file')];

		try {
			$this->reader()->read($this->file(), null, 10.0);
			$this->fail('An unreadable file was read');
		} catch (FormLockedException) {
			$this->fail('A broken file was answered as busy, again and again');
		} catch (\RuntimeException $e) {
			$this->assertSame('Invalid form file format', $e->getMessage());
		}
	}

	/** Read below the View, but announced as the View would: the audit log records that the form was read. */
	public function testTheReadIsAnnouncedForTheAuditLog(): void {
		$this->reads = [self::FORM];
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->with($this->isInstanceOf(\OCP\Files\Events\Node\BeforeNodeReadEvent::class));

		$this->reader()->read($this->file());
	}

	/**
	 * A slow encrypted save fails every read in the same place for as long as it lasts: waited for while a writer holds
	 * the form, by FormVox's lock or by Nextcloud's on the file, and read once it is done (review of the 3a fixes: it
	 * was a 500 after a second).
	 *
	 * @return array<string, array{string}>
	 */
	public static function savers(): array {
		return ['FormVox saving' => ['formvox'], 'a save through Nextcloud' => ['nextcloud']];
	}

	/**
	 * @dataProvider savers
	 */
	public function testTheSameErrorDuringALongSaveIsWaitedFor(string $saver): void {
		$this->reads = [new \Exception('Bad Signature')];
		$this->saver = $saver;
		$this->savingUntil = microtime(true) + 1.4;
		$this->afterSave = self::FORM;

		$this->assertSame('Survey', $this->reader()->read($this->file(), null, 3.0)['title']);
	}

	/** An empty .fvform in the forms list, with its short wait: told broken at once, not after the wait as a busy one. */
	public function testAnEmptyFileIsBrokenWithinAShortWait(): void {
		$this->reads = [''];
		$started = microtime(true);

		try {
			$this->reader()->read($this->file(), null, 0.5);
			$this->fail('An empty file was read as a form');
		} catch (FormLockedException) {
			$this->fail('An empty file was answered as busy');
		} catch (\RuntimeException $e) {
			$this->assertSame('Invalid form file format', $e->getMessage());
		}
		$this->assertLessThan(0.45, microtime(true) - $started);
	}

	/** A listener on the read that fails is logged and the read goes on, as with Nextcloud's own hooks. */
	public function testAListenerThatFailsDoesNotFailTheRead(): void {
		$this->reads = [self::FORM];
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willThrowException(new \RuntimeException('no session'));

		$this->assertSame('Survey', $this->reader()->read($this->file())['title']);
	}
}
