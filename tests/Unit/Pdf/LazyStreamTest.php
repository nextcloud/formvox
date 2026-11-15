<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use OCA\FormVox\Pdf\LazyStream;
use PHPUnit\Framework\TestCase;

/**
 * A stream that opens what it reads only when read and closes it at its end, so a ZIP of thousands holds one at a time,
 * and that always gives the size the archive was told, as a tar needs.
 */
class LazyStreamTest extends TestCase {
	/** @return resource */
	private static function source(string $content) {
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}

	/** @param resource $stream */
	private static function readAll($stream): string {
		$read = '';
		while (!feof($stream)) {
			$read .= fread($stream, 8192);
		}
		return $read;
	}

	public function testNothingIsOpenedUntilTheFirstRead(): void {
		$opened = 0;
		$streams = [];
		for ($i = 0; $i < 2000; $i++) {
			$streams[] = LazyStream::open(function () use (&$opened) {
				$opened++;
				return self::source('%PDF-');
			}, 5);
		}

		$this->assertSame(0, $opened);
		$this->assertSame('stream', get_resource_type($streams[0]), 'what the ZIP streamers accept');
	}

	public function testItReadsWhatItOpensAndClosesItAtTheEnd(): void {
		$inner = self::source(str_repeat('%PDF-', 5000));
		$stream = LazyStream::open(fn () => $inner, 25000);

		$this->assertSame(str_repeat('%PDF-', 5000), self::readAll($stream));
		$this->assertFalse(is_resource($inner), 'closed at its end, before the stream itself is');
	}

	/** @return array<string, array{\Closure}> */
	public static function sourcesThatFail(): array {
		return [
			'gone before it is read' => [fn () => false],
			'throws when opened' => [fn () => throw new \RuntimeException('locked')],
			'shorter than it was' => [fn () => self::source('%PDF-1.7')],
		];
	}

	/**
	 * A kept PDF that went or changed during a long download still gives the size the archive was told, and says so.
	 *
	 * @dataProvider sourcesThatFail
	 */
	public function testItAlwaysGivesTheSizeItWasTold(\Closure $open): void {
		$missing = null;
		$stream = LazyStream::open($open, 100, function (int $bytes) use (&$missing) {
			$missing = $bytes;
		});

		$read = self::readAll($stream);

		$this->assertSame(100, strlen($read));
		$this->assertSame(100 - strlen(rtrim($read, "\0")), $missing);
	}

	/**
	 * A real PDF is padded over many of PHP's 8 KB reads: the shortfall is told once, and whole.
	 *
	 * @dataProvider sourcesThatFail
	 */
	public function testALongShortfallIsToldOnceAndWhole(\Closure $open): void {
		$told = [];
		$stream = LazyStream::open($open, 50000, function (int $bytes) use (&$told) {
			$told[] = $bytes;
		});

		$read = self::readAll($stream);

		$this->assertSame(50000, strlen($read));
		$this->assertSame([50000 - strlen(rtrim($read, "\0"))], $told);
	}

	public function testALongerSourceIsCutAtTheSizeItWasTold(): void {
		$inner = self::source(str_repeat('x', 300));
		$stream = LazyStream::open(fn () => $inner, 100);

		$this->assertSame(str_repeat('x', 100), self::readAll($stream));
		$this->assertFalse(is_resource($inner));
	}

	public function testClosedBeforeItsEndItClosesItsSource(): void {
		$inner = self::source(str_repeat('x', 30000));
		$stream = LazyStream::open(fn () => $inner, 30000);
		fread($stream, 10);

		fclose($stream);

		$this->assertFalse(is_resource($inner));
	}

	/** Its checksum is of what it gave, zeros for a source that ended early included. */
	public function testItsChecksumIsOfWhatItGave(): void {
		$whole = hash_init('sha256');
		$inner = fopen('php://memory', 'w+b');
		fwrite($inner, str_repeat('a', 20000));
		rewind($inner);
		$read = self::readAll(LazyStream::open(fn () => $inner, 20000, null, $whole));

		$short = hash_init('sha256');
		$cut = fopen('php://memory', 'w+b');
		fwrite($cut, 'abc');
		rewind($cut);
		$padded = self::readAll(LazyStream::open(fn () => $cut, 10000, null, $short));

		$this->assertSame(hash('sha256', $read), hash_final($whole));
		$this->assertSame(hash('sha256', 'abc' . str_repeat("\0", 9997)), hash_final($short));
		$this->assertSame(10000, strlen($padded));
	}
}
