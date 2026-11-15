<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use OCA\FormVox\Pdf\PdfStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

/** A kept PDF is found from the file cache alone, without reading it; put() keeps the first whole one, under a lock. */
class PdfStoreTest extends TestCase {
	private int $opened = 0;

	private const RESPONSE = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';
	private const REVISION = '0123456789abcdef';
	private const WHOLE = "%PDF-1.7\nkept\n%%EOF\n";

	private function formWithKept(?string $pdf): File {
		$kept = null;
		if ($pdf !== null) {
			$kept = $this->createMock(File::class);
			$kept->method('getSize')->willReturn(strlen($pdf));
			$kept->method('fopen')->willReturnCallback(function () use ($pdf) {
				$this->opened++;
				$stream = fopen('php://memory', 'w+b');
				fwrite($stream, $pdf);
				rewind($stream);
				return $stream;
			});
		}
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(fn (string $path) => $path === self::RESPONSE . '/' . self::REVISION . '.pdf' && $kept !== null ? $kept : throw new NotFoundException());
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with(PdfStore::FOLDER . '7')->willReturn($folder);
		$form = $this->createMock(File::class);
		$form->method('getId')->willReturn(7);
		$form->method('getParent')->willReturn($parent);
		return $form;
	}

	/** @return array<string, array{?string}> */
	public static function notThere(): array {
		return ['none kept' => [null], 'empty' => ['']];
	}

	/**
	 * @dataProvider notThere
	 */
	public function testNoneIsFound(?string $pdf): void {
		$this->assertNull((new PdfStore())->find($this->formWithKept($pdf), self::RESPONSE, self::REVISION));
	}

	/** From the file cache alone: put() renames a whole PDF into place, so find() never opens it. */
	public function testItIsFoundWithoutOpeningIt(): void {
		$form = $this->formWithKept("%PDF-1.7\n" . str_repeat('x', 2000) . "\n%%EOF\n");

		$this->assertSame(0, $this->opened, 'not before');
		$this->assertInstanceOf(File::class, (new PdfStore())->find($form, self::RESPONSE, self::REVISION));
		$this->assertSame(0, $this->opened);
	}

	public function testAResponseIdThatIsAPathFindsNothing(): void {
		$this->assertNull((new PdfStore())->find($this->formWithKept('%PDF-'), '../x', self::REVISION));
	}

	/** @var list<string> what put() wrote, moved, deleted and locked, in order */
	private array $did = [];

	/**
	 * A response's folder for put(): $listing by name and age in seconds; $standing is what is under the PDF's name
	 * before and after this writer wrote its part (null: nothing).
	 *
	 * @param array<string, int> $listing
	 * @param array{0: ?string, 1: ?string} $standing
	 */
	private function formToPut(array $listing = [], array $standing = [null, null], ?\Throwable $moveFails = null): File {
		$own = $this->createMock(Folder::class);
		$own->method('getPath')->willReturn('/owner/files/.formvox-pdfs-7/' . self::RESPONSE);
		$own->method('getId')->willReturn(70);
		$own->method('getDirectoryListing')->willReturn(array_map(function (string $name, int $age) {
			$node = $this->createMock(File::class);
			$node->method('getName')->willReturn($name);
			$node->method('getMTime')->willReturn(time() - $age);
			$node->method('delete')->willReturnCallback(function () use ($name): void {
				$this->did[] = 'delete ' . $name;
			});
			return $node;
		}, array_keys($listing), array_values($listing)));
		$asked = 0;
		$own->method('get')->willReturnCallback(function (string $name) use (&$asked, $standing) {
			$content = $name === self::REVISION . '.pdf' ? $standing[min($asked++, 1)] : null;
			if ($content === null) {
				throw new NotFoundException();
			}
			$file = $this->createMock(File::class);
			$file->method('getContent')->willReturn($content);
			$file->method('delete')->willReturnCallback(function () use ($name): void {
				$this->did[] = 'delete ' . $name;
			});
			return $file;
		});
		$own->method('newFile')->willReturnCallback(function (string $name, string $content) use ($moveFails) {
			$this->did[] = 'write ' . $name;
			$part = $this->createMock(File::class);
			$part->method('move')->willReturnCallback(function (string $to) use ($name, $moveFails) {
				$this->did[] = 'move ' . $name . ' to ' . $to;
				return $moveFails === null ? $this->createMock(File::class) : throw $moveFails;
			});
			$part->method('delete')->willReturnCallback(function () use ($name): void {
				$this->did[] = 'delete ' . $name;
			});
			return $part;
		});
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->with(self::RESPONSE)->willReturn($own);
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with(PdfStore::FOLDER . '7')->willReturn($folder);
		$form = $this->createMock(File::class);
		$form->method('getId')->willReturn(7);
		$form->method('getParent')->willReturn($parent);
		return $form;
	}

	/** Locks as Nextcloud's, each noted; $held refuses every take, as when another writer holds it. */
	private function locks(bool $held = false): ILockingProvider {
		$note = function (string $what): void {
			$this->did[] = $what;
		};
		return new class($note, $held) implements ILockingProvider {
			public function __construct(
				private \Closure $note,
				private bool $held,
			) {
			}

			public function isLocked(string $path, int $type): bool {
				return $this->held;
			}

			public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
				if ($this->held) {
					throw new LockedException($path);
				}
				($this->note)('lock ' . $path);
			}

			public function releaseLock(string $path, int $type): void {
				($this->note)('unlock ' . $path);
			}

			public function changeLock(string $path, int $targetType): void {
			}

			public function releaseAll(): void {
			}
		};
	}

	/** Written whole under a name of its own, then renamed under the lock: a write that stops never stands under the PDF's name. */
	public function testAPdfIsWrittenWholeBeforeItTakesItsName(): void {
		(new PdfStore(null, $this->locks()))->put($this->formToPut(), self::RESPONSE, self::REVISION, self::WHOLE);

		$this->assertCount(4, $this->did);
		$this->assertMatchesRegularExpression('/^write ' . self::REVISION . '\\.[0-9a-f]{8}\\.tmp$/', $this->did[0]);
		$this->assertSame('lock formvox/pdf/70/' . self::REVISION . '.pdf', $this->did[1]);
		$this->assertSame('move ' . substr($this->did[0], 6) . ' to /owner/files/.formvox-pdfs-7/' . self::RESPONSE . '/' . self::REVISION . '.pdf', $this->did[2]);
		$this->assertSame('unlock formvox/pdf/70/' . self::REVISION . '.pdf', $this->did[3]);
	}

	public function testAMoveThatFailsLeavesNoPart(): void {
		try {
			(new PdfStore())->put($this->formToPut([], [null, null], new \RuntimeException('quota')), self::RESPONSE, self::REVISION, self::WHOLE);
			$this->fail('the failure is not hidden');
		} catch (\RuntimeException $e) {
			$this->assertSame('quota', $e->getMessage());
		}

		$this->assertSame('delete ' . substr($this->did[0], 6), $this->did[2]);
	}

	/** The first one kept stays: a download, the API and a webhook's checksum all have the same bytes. */
	public function testOneKeptFirstStays(): void {
		(new PdfStore())->put($this->formToPut([self::REVISION . '.pdf' => 10], [self::WHOLE, self::WHOLE]), self::RESPONSE, self::REVISION, self::WHOLE);

		$this->assertSame([], $this->did);
	}

	/** Between this writer's look and its part, another kept the PDF: seen under the lock, so this one's part goes. */
	public function testAnotherWriterThatWasFirstWins(): void {
		(new PdfStore(null, $this->locks()))->put($this->formToPut([], [null, self::WHOLE]), self::RESPONSE, self::REVISION, self::WHOLE);

		$this->assertCount(4, $this->did);
		$this->assertSame('delete ' . substr($this->did[0], 6), $this->did[2], 'its own part goes, nothing moves');
	}

	/** Another writer holds the name while this one would take it: it is theirs, and this one's part goes. */
	public function testWhileAnotherWriterTakesTheNameThisOnesPartGoes(): void {
		(new PdfStore(null, $this->locks(true)))->put($this->formToPut(), self::RESPONSE, self::REVISION, self::WHOLE);

		$this->assertCount(2, $this->did);
		$this->assertSame('delete ' . substr($this->did[0], 6), $this->did[1]);
	}

	/** What stands under the name without being a whole PDF would never be served: it goes, and the PDF is written. */
	public function testWhatIsNoWholePdfUnderTheNameIsReplaced(): void {
		(new PdfStore())->put($this->formToPut([self::REVISION . '.pdf' => 10], ['%PDF-1.7 cut off', null]), self::RESPONSE, self::REVISION, self::WHOLE);

		$this->assertCount(3, $this->did);
		$this->assertSame('delete ' . self::REVISION . '.pdf', $this->did[0]);
		$this->assertStringStartsWith('write ', $this->did[1]);
		$this->assertStringStartsWith('move ', $this->did[2]);
	}

	/**
	 * Other revisions are forget()'s to clear: whoever writes may hold an older response than the PDF standing here is
	 * of. A part whose writer has surely stopped goes, of any revision; a younger one may still be written.
	 */
	public function testOtherRevisionsStayAndOldPartsGo(): void {
		(new PdfStore())->put($this->formToPut([
			'fedcba9876543210.pdf' => 10,
			'fedcba9876543210.aaaaaaaa.tmp' => 7200,
			self::REVISION . '.bbbbbbbb.tmp' => 7200,
			self::REVISION . '.cccccccc.tmp' => 10,
		]), self::RESPONSE, self::REVISION, self::WHOLE);

		$this->assertSame(['delete fedcba9876543210.aaaaaaaa.tmp', 'delete ' . self::REVISION . '.bbbbbbbb.tmp'], array_slice($this->did, 0, 2));
		$this->assertNotContains('delete fedcba9876543210.pdf', $this->did);
		$this->assertNotContains('delete ' . self::REVISION . '.cccccccc.tmp', $this->did);
	}
}
