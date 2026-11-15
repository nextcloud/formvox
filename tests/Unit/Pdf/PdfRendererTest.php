<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use OCA\FormVox\Pdf\PdfRenderer;
use OCA\FormVox\Tests\Unit\Support\PdfText;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * dompdf as FormVox ships it (lib/Vendor): a PDF comes out, and nothing outside it is reached.
 */
class PdfRendererTest extends TestCase {
	private string $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/formvox-pdf-test-' . bin2hex(random_bytes(4));
		mkdir($this->base);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
		parent::tearDown();
	}

	private function renderer(): PdfRenderer {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTempBaseDir')->willReturn($this->base);
		$temp->method('getTemporaryFolder')->willReturnCallback(function () {
			$folder = $this->base . '/fresh-' . bin2hex(random_bytes(4));
			mkdir($folder, 0700);
			return $folder;
		});
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getSystemValueString')->willReturn('oc1234abcd');
		return new PdfRenderer(new \OCA\FormVox\Pdf\PdfCache($temp, $config));
	}

	public function testAPdfComesOut(): void {
		$pdf = $this->renderer()->render('<html><body><h1>Anmeldung – Größe „gut“</h1><p>Ελληνικά, Кириллица</p></body></html>');

		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertGreaterThan(1000, strlen($pdf));
	}

	/** One response full of line breaks once took dompdf past 512 MB (17 KB a line); now one document stays small. */
	public function testAResponseFullOfLineBreaksStaysSmall(): void {
		$questions = [];
		$answers = [];
		foreach (['a', 'b', 'c'] as $id) {
			$questions[] = ['id' => $id, 'type' => 'textarea', 'question' => 'Notes'];
			$answers[$id] = str_repeat("x\n", 5000);
		}
		$html = \OCA\FormVox\Tests\Unit\Support\PdfDocuments::document($this)->html(['title' => 'T', 'questions' => $questions], ['id' => 'r', 'answers' => $answers], new \OCA\FormVox\Tests\Unit\Support\EnglishL10N());
		$before = memory_get_usage();
		memory_reset_peak_usage();

		$pdf = $this->renderer()->render($html);

		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertLessThan(96 * 1024 * 1024, memory_get_peak_usage() - $before);
	}

	/**
	 * One long cell once squeezed the other columns to a letter a line, thousands of lines outside any budget: with
	 * equal columns the render stays small and quick.
	 */
	public function testATableWithOneLongCellStaysSmall(): void {
		$columns = array_map(fn (int $c) => ['id' => 'c' . $c, 'label' => 'Column ' . $c], range(1, 6));
		$rows = array_fill(0, 200, ['c1' => 'Anna Jansen', 'c2' => '42', 'c3' => 'Amsterdam', 'c4' => 'yes', 'c5' => '2026-05-31', 'c6' => 'none']);
		$rows[7]['c3'] = str_repeat('A remark of a few hundred words, as someone puts in a cell of a table now and then. ', 25);
		$html = \OCA\FormVox\Tests\Unit\Support\PdfDocuments::document($this)->html(['title' => 'T', 'questions' => [['id' => 't', 'type' => 'table', 'question' => 'Guests', 'columns' => $columns]]], ['id' => 'r', 'answers' => ['t' => $rows]], new \OCA\FormVox\Tests\Unit\Support\EnglishL10N());
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$started = microtime(true);

		$pdf = $this->renderer()->render($html);

		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $before);
		$this->assertLessThan(15.0, microtime(true) - $started);
	}

	/** Each page names the response and its place among the pages, the numbers flush right in any language. */
	public function testEveryPageCarriesTheFooter(): void {
		$html = '<html><body>' . str_repeat('<p>Zeile</p>', 40) . '</body></html>';

		$runs = PdfText::runs($this->renderer()->render($html, 'Antwort 0123-abcd', 'Seite {current} von {total}'));
		$short = PdfText::runs($this->renderer()->render($html, 'Antwort 0123-abcd', '{current}/{total}'));

		$texts = array_column($runs, 'text');
		$this->assertContains('Seite 1 von 2', $texts);
		$this->assertContains('Seite 2 von 2', $texts);
		$this->assertSame(2, count(array_keys($texts, 'Antwort 0123-abcd', true)));
		$x = fn (array $runs, string $text): float => array_values(array_filter($runs, fn ($run) => $run['text'] === $text))[0]['x'];
		$this->assertGreaterThan($x($runs, 'Seite 1 von 2') + 20, $x($short, '1/2'), 'a shorter text starts further right: both end at the margin');
		$this->assertSame(51.0, $x($runs, 'Antwort 0123-abcd'));
	}

	/** A remote or local file named in the document is not fetched or embedded. */
	public function testNoFileOutsideIsReached(): void {
		$started = microtime(true);
		$pdf = $this->renderer()->render('<html><body><img src="http://10.255.255.1/x.png"><img src="file:///etc/passwd"><img src="/etc/hosts"></body></html>');

		$this->assertLessThan(5.0, microtime(true) - $started, 'no remote fetch was waited for');
		$this->assertStringNotContainsString('root:', $pdf);
		$this->assertStringNotContainsString('localhost', $pdf);
	}

	/** Its own folder, kept between PDFs: this instance's, only this process may write it. */
	public function testFontMetricsAreKeptInThisInstancesOwnFolder(): void {
		$this->renderer()->render('<html><body>x</body></html>');

		$this->assertDirectoryExists($this->base . '/formvox-pdf-oc1234abcd');
		$this->assertSame(0, fileperms($this->base . '/formvox-pdf-oc1234abcd') & 0077);
	}

	/** A folder of that name that others may write is not trusted: dompdf would write through what they put there. */
	public function testAFolderOthersMayWriteIsNotUsed(): void {
		mkdir($this->base . '/formvox-pdf-oc1234abcd');
		chmod($this->base . '/formvox-pdf-oc1234abcd', 0777);
		symlink($this->base . '/victim', $this->base . '/formvox-pdf-oc1234abcd/DejaVuSans.ufm.json');

		$pdf = $this->renderer()->render('<html><body>x</body></html>');

		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertFileDoesNotExist($this->base . '/victim', 'nothing written through the planted link');
	}
}
