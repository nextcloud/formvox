<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

use OCA\FormVox\Vendor\Dompdf\Canvas;
use OCA\FormVox\Vendor\Dompdf\Dompdf;
use OCA\FormVox\Vendor\Dompdf\FontMetrics;
use OCA\FormVox\Vendor\Dompdf\Options;

/** HTML to PDF with the dompdf in lib/Vendor, reaching no file or address outside it. */
class PdfRenderer {
	private const FONTS = __DIR__ . '/../Vendor/dompdf/dompdf/lib/fonts';
	/** The page's side margin (18 mm, as ResponseDocument's @page) and the footer's baseline, in points. */
	private const MARGIN = 51;
	private const FOOT = 33;

	public function __construct(
		private PdfCache $cache,
	) {
	}

	/**
	 * @param string $footer at the foot of every page, on the left
	 * @param string $pages on the right of it, with {current} and {total} filled in
	 * @return string the PDF
	 */
	public function render(string $html, string $footer = '', string $pages = '{current} / {total}'): string {
		// Loaded only when a PDF is made.
		require_once __DIR__ . '/../Vendor/autoload.php';
		$fonts = (string)realpath(self::FONTS);
		$cache = $this->cache->dir();

		$options = new Options();
		$options->setIsRemoteEnabled(false);
		$options->setIsPhpEnabled(false);
		$options->setIsJavascriptEnabled(false);
		$options->setChroot([$fonts]);
		$options->setFontDir($fonts);
		$options->setFontCache($cache);
		$options->setTempDir($cache);
		$options->setDefaultFont('DejaVu Sans');

		$pdf = new Dompdf($options);
		$pdf->loadHtml($html, 'UTF-8');
		$pdf->setPaper('A4');
		$pdf->render();
		// Page numbers: the total is only known once laid out, which CSS counters cannot wait for.
		$canvas = $pdf->getCanvas();
		$font = $pdf->getFontMetrics()->getFont('DejaVu Sans') ?? throw new \RuntimeException('DejaVu Sans is missing from lib/Vendor');
		$right = $canvas->get_width() - self::MARGIN;
		$y = $canvas->get_height() - self::FOOT;
		$canvas->page_script(static function (int $page, int $count, Canvas $canvas, FontMetrics $metrics) use ($footer, $pages, $font, $right, $y): void {
			$numbers = str_replace(['{current}', '{total}'], [(string)$page, (string)$count], $pages);
			// Right-aligned in every language: the words around the numbers differ in length.
			$canvas->text($right - $metrics->getTextWidth($numbers, $font, 8), $y, $numbers, $font, 8, [0.53, 0.53, 0.53]);
			if ($footer !== '') {
				$canvas->text(self::MARGIN, $y, $footer, $font, 8, [0.53, 0.53, 0.53]);
			}
		});
		return (string)$pdf->output();
	}
}
