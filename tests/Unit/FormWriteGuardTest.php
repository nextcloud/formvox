<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Service\DecodedForms;
use OCA\FormVox\Service\FormWriteGuard;
use OCP\Encryption\IManager;
use OCP\Files\Cache\IUpdater;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** A storage wrapper as Nextcloud's are: it can hand out the storage it wraps. */
interface WrappingStorage extends IStorage {
	public function getWrapperStorage(): IStorage;
}

/** Nextcloud's quota wrapper, as far as the guard uses it. */
interface QuotaWrapper extends WrappingStorage {
	public function enableQuota(bool $enabled): void;
}

/**
 * A form is written whole: a write that does not fit must never leave a cut-off form behind (feasibility spike E:
 * with the encryption app on, Nextcloud's quota stream truncates silently, also with server-side encryption off).
 */
class FormWriteGuardTest extends TestCase {
	private string $disk = 'OLD FORM';
	/** @var int|float|false */
	private int|float|false $free = 1_000_000;
	private bool $wrapped = false;
	private bool $sseOn = false;
	private bool $legacyBlocks = false;
	/** @var int|null bytes the storage keeps of a write while the quota applies: the quota stream's silent cut */
	private ?int $keeps = null;
	private bool $quotaOn = true;
	private int $reads = 0;
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function guard(): FormWriteGuard {
		$encryption = $this->createMock(IManager::class);
		$encryption->method('isEnabled')->willReturnCallback(fn () => $this->sseOn);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(fn (string $key, bool $default) => $key === 'encryption.use_legacy_base64_encoding' ? $this->legacyBlocks : $default);
		return new FormWriteGuard($encryption, $config, $this->logger);
	}

	/** What a write leaves on disk: all of it, or the cut while the quota applies. */
	private function land(string $data): string {
		return $this->quotaOn && $this->keeps !== null ? substr($data, 0, $this->keeps) : $data;
	}

	/** The encryption wrapper (when wrapped) around a quota wrapper around the disk. */
	private function storage(): IStorage {
		$quota = $this->createMock(QuotaWrapper::class);
		$quota->method('enableQuota')->willReturnCallback(function (bool $enabled): void {
			$this->quotaOn = $enabled;
		});
		$quota->method('getWrapperStorage')->willReturn($this->createMock(IStorage::class));

		$storage = $this->createMock(WrappingStorage::class);
		$storage->method('getWrapperStorage')->willReturn($quota);
		$storage->method('free_space')->willReturnCallback(fn () => $this->free);
		$storage->method('instanceOfStorage')->willReturnCallback(fn (string $class) => $this->wrapped && $class === 'OC\Files\Storage\Wrapper\Encryption');
		$storage->method('file_get_contents')->willReturnCallback(function () {
			$this->reads++;
			return $this->disk;
		});
		$storage->method('file_put_contents')->willReturnCallback(function (string $path, string $data): int {
			$this->disk = $this->land($data);
			return strlen($this->disk);
		});
		$storage->method('getUpdater')->willReturn($this->createMock(IUpdater::class));
		return $storage;
	}

	private function writeThrough(string $payload): callable {
		return function () use ($payload): void {
			$this->disk = $this->land($payload);
		};
	}

	public function testAFormThatFitsIsWritten(): void {
		$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW FORM', $this->writeThrough('NEW FORM'));
		$this->assertSame('NEW FORM', $this->disk);
	}

	public function testAFormThatDoesNotFitIsNotWritten(): void {
		$this->free = 5;
		try {
			$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW FORM', $this->writeThrough('NEW FORM'));
			$this->fail('Written without room');
		} catch (RequestException $e) {
			$this->assertSame([507, 'storage_full'], [$e->getStatus(), $e->getReason()]);
		}
		$this->assertSame('OLD FORM', $this->disk);
	}

	/** Unknown, not computed or unlimited space: Nextcloud puts no quota stream around the write, so nothing is cut or read back. */
	public function testUnknownOrUnlimitedSpaceDoesNotStopAWriteNorReadsItBack(): void {
		$this->wrapped = true;
		foreach ([-1, -2, -3, false] as $free) {
			$this->free = $free;
			$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW FORM', $this->writeThrough('NEW FORM'));
			$this->assertSame('NEW FORM', $this->disk);
			$this->disk = 'OLD FORM';
		}
		$this->assertSame(0, $this->reads);
	}

	/**
	 * With server-side encryption on, a form takes an 8 KiB header and 8 KiB per block on disk: 8,096 bytes of plain
	 * text per block, 6,072 with legacy base64 blocks. 16,000 bytes are two blocks, or three in legacy.
	 */
	public function testEncryptedFormsNeedTheRoomTheyTakeOnDisk(): void {
		$payload = str_repeat('x', 16_000);
		$this->wrapped = true;
		$this->sseOn = true;
		$this->free = 8192 + 2 * 8192;

		$this->guard()->write($this->storage(), 'files/f.fvform', $payload, $this->writeThrough($payload));
		$this->assertSame($payload, $this->disk, 'header and two blocks fit');

		$this->free--;
		$this->disk = 'OLD FORM';
		try {
			$this->guard()->write($this->storage(), 'files/f.fvform', $payload, $this->writeThrough($payload));
			$this->fail('Written without room for the second block');
		} catch (RequestException) {
		}

		$this->legacyBlocks = true;
		$this->free = 8192 + 3 * 8192 - 1;
		$this->expectException(RequestException::class);
		$this->guard()->write($this->storage(), 'files/f.fvform', $payload, $this->writeThrough($payload));
	}

	/**
	 * Under the encryption wrapper a cut goes unnoticed by Nextcloud: read back, put the old form back, refuse. The
	 * put-back goes past the quota, or it would be cut off as well (spike t6c); the quota applies again afterwards.
	 */
	public function testACutOffFormIsPutBackWholePastTheQuotaAndRefused(): void {
		$this->wrapped = true;
		$this->keeps = 3;
		try {
			$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW FORM', $this->writeThrough('NEW FORM'));
			$this->fail('A cut-off write was accepted');
		} catch (RequestException $e) {
			$this->assertSame('storage_full', $e->getReason());
		}
		$this->assertSame('OLD FORM', $this->disk);
		$this->assertTrue($this->quotaOn);
	}

	/** A write that cuts off and then throws (a short count) is put back all the same: what is on disk decides. */
	public function testACutThatThrowsIsPutBackToo(): void {
		$this->wrapped = true;
		$this->keeps = 3;
		$this->expectException(RequestException::class);
		try {
			$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW FORM', function (): void {
				$this->disk = $this->land('NEW FORM');
				throw new \RuntimeException('the form could not be written');
			});
		} finally {
			$this->assertSame('OLD FORM', $this->disk);
		}
	}

	/** The log of a cut names what went wrong, never the exception: its trace holds the form that was being written. */
	public function testTheLogOfACutCarriesNoException(): void {
		$this->wrapped = true;
		$this->keeps = 3;
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('RuntimeException: the form could not be written'), $this->callback(fn (array $context) => !array_key_exists('exception', $context)));
		try {
			$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW FORM', function (): void {
				$this->disk = $this->land('NEW FORM');
				throw new \RuntimeException('the form could not be written');
			});
			$this->fail('A cut-off write was accepted');
		} catch (RequestException) {
		}
	}

	/** Tidying shrinks the form: within what the file held, a full quota does not refuse it, and applies again after. */
	public function testAWriteWithinWhatTheFileHeldGoesPastAFullQuota(): void {
		$this->free = 0;
		$quotaDuringWrite = null;
		$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW', function () use (&$quotaDuringWrite): void {
			$quotaDuringWrite = $this->quotaOn;
			$this->disk = 'NEW';
		}, 'OLD FORM', true);
		$this->assertSame('NEW', $this->disk);
		$this->assertFalse($quotaDuringWrite, 'the quota is lifted for the write');
		$this->assertTrue($this->quotaOn, 'and applies again after it');
	}

	/** Only within what it held, and a small margin: a form that grows past that needs the room, counted or not. */
	public function testAWriteThatGrowsPastWhatTheFileHeldStillNeedsRoom(): void {
		$this->free = 0;
		$this->expectException(RequestException::class);
		$this->guard()->write($this->storage(), 'files/f.fvform', str_repeat('x', strlen('OLD FORM') + 8192 + 1), $this->writeThrough('x'), 'OLD FORM', true);
	}

	/** Without asking for it, a shrinking write needs the room like any: only tidying may go past the quota. */
	public function testAShrinkingWriteThatDoesNotAskForItStillNeedsRoom(): void {
		$this->free = 0;
		$this->expectException(RequestException::class);
		$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW', $this->writeThrough('NEW'), 'OLD FORM');
	}

	/** A write that failed before it changed anything keeps its own error. */
	public function testAWriteThatChangedNothingKeepsItsError(): void {
		$this->wrapped = true;
		$this->expectExceptionMessage('locked');
		$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW FORM', function (): void {
			throw new \RuntimeException('locked');
		});
	}

	/**
	 * A lock refusal comes before anything is written: what is on disk is someone else's write, never a cut of ours, so
	 * nothing is put back over it (review 3a-1 fixes).
	 */
	public function testALockRefusalIsNeverTakenForACut(): void {
		$this->wrapped = true;
		$this->expectException(\OCP\Lock\LockedException::class);
		try {
			$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW FORM', function (): void {
				// Another writer is halfway: its bytes start like ours.
				$this->disk = 'NEW';
				throw new \OCP\Lock\LockedException('files/f.fvform');
			});
		} finally {
			$this->assertSame('NEW', $this->disk, 'the other writer\'s write stays');
		}
	}

	/** Neither what was written nor a cut of it: another write came in between. It stays, and it is no storage_full. */
	public function testAnotherWriteInBetweenIsLeftAlone(): void {
		$this->wrapped = true;
		$this->logger->expects($this->once())->method('warning');
		$this->guard()->write($this->storage(), 'files/f.fvform', 'NEW FORM', function (): void {
			$this->disk = 'SOMEONE ELSE';
		});
		$this->assertSame('SOMEONE ELSE', $this->disk);
	}

	/** Without the wrapper Nextcloud refuses a write that does not fit, so nothing is read back. */
	public function testWithoutTheEncryptionWrapperNothingIsReadBack(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('free_space')->willReturn(1_000_000);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->expects($this->never())->method('file_get_contents');
		$this->guard()->write($storage, 'files/f.fvform', 'NEW FORM', fn () => null);
	}

	public function testOnlyTheSameBytesAreRecalledAndOnlyOnce(): void {
		$decoded = new DecodedForms();
		$decoded->remember(7, 'RAW', ['title' => 'A']);
		$this->assertNull($decoded->recall(7, 'OTHER'), 'different bytes');
		$this->assertNull($decoded->recall(7, 'RAW'), 'cleared after a recall');

		$decoded->remember(7, 'RAW', ['title' => 'A']);
		$this->assertNull($decoded->recall(8, 'RAW'), 'another form');

		$decoded->remember(7, 'RAW', ['title' => 'A']);
		$this->assertSame(['title' => 'A'], $decoded->recall(7, 'RAW'));
	}
}
