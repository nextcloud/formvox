<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FileNames;
use PHPUnit\Framework\TestCase;

/** One way to name what a form hands out: ASCII any system takes, German spelled out, the response id always whole. */
class FileNamesTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function titles(): array {
		return [
			'German' => ['Anmeldung für das Schulfest ½ Tag!', 'Anmeldung-fuer-das-Schulfest-Tag'],
			'German capitals' => ['Größe & Maße: ÄÖÜ', 'Groesse-Masse-AeOeUe'],
			'accents' => ['Café crème, señor, Øresund, Łódź', 'Cafe-creme-senor-Oresund-Lodz'],
			'signs only' => [' ½ ⅓ ÷ / \\ : * ? " < > | ', ''],
			'another script' => ['Опитування', ''],
			'control characters' => ["a\nb\tc\x00d", 'a-b-c-d'],
		];
	}

	/** @dataProvider titles */
	public function testATitleInPlainAscii(string $title, string $name): void {
		$this->assertSame($name, FileNames::title($title));
	}

	public function testATitleIsCutAtSixtyWithoutADashAtTheEnd(): void {
		$name = FileNames::title(str_repeat('Wort ', 30));

		$this->assertLessThanOrEqual(60, strlen($name));
		$this->assertStringEndsWith('Wort', $name);
	}

	public function testAResponsesPdf(): void {
		$this->assertSame('Anmeldung-fuer-das-Schulfest-Tag_2026-11-10_3f2a9c1e-7b4d-4c1a-9e2f-0a1b2c3d4e5f.pdf',
			FileNames::responsePdf('Anmeldung für das Schulfest ½ Tag!', '2026-11-10', '3f2a9c1e-7b4d-4c1a-9e2f-0a1b2c3d4e5f'));
	}

	/** The id is the key to match the PDF elsewhere: a long title never pushes it out. */
	public function testTheIdIsNeverCut(): void {
		$id = '3f2a9c1e-7b4d-4c1a-9e2f-0a1b2c3d4e5f';

		$this->assertStringEndsWith('_2026-11-10_' . $id . '.pdf', FileNames::responsePdf(str_repeat('Anmeldung ', 40), '2026-11-10', $id));
	}

	public function testATitleWithNoLetterLeftAndNoDay(): void {
		$this->assertSame('response_2026-11-10_abc.pdf', FileNames::responsePdf('Опитування', '2026-11-10', 'abc'));
		$this->assertSame('response_abc.pdf', FileNames::responsePdf('', '', 'abc'));
	}

	/** An id read from a file made elsewhere is no path in a ZIP. */
	public function testAnIdIsNoPath(): void {
		$this->assertSame('T_2026-11-10_etcpasswd.pdf', FileNames::responsePdf('T', '2026-11-10', '../../etc/passwd'));
	}

	public function testAnExport(): void {
		$this->assertSame('anmeldung-fuer-das-schulfest-tag-responses.csv', FileNames::export('Anmeldung für das Schulfest ½ Tag!', 'csv'));
		$this->assertSame('responses.json', FileNames::export('Опитування', 'json'));
	}
}
