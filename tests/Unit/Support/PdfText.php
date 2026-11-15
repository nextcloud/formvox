<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Support;

/** The text runs a dompdf PDF draws, read back from its content streams: dompdf writes them as UTF-16BE literals. */
final class PdfText {
	/** @return list<array{x: float, y: float, text: string}> in the order drawn */
	public static function runs(string $pdf): array {
		preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
		$runs = [];
		foreach ($streams[1] as $stream) {
			$content = @gzuncompress($stream);
			if ($content === false) {
				continue;
			}
			preg_match_all('/BT ([\d.]+) ([\d.]+) Td \/F\d+ [\d.]+ Tf\s+\[\((.*?)\)\] TJ ET/s', $content, $found, PREG_SET_ORDER);
			foreach ($found as [, $x, $y, $literal]) {
				$bytes = (string)preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', static fn (array $m) => ctype_digit($m[1]) ? chr(octdec($m[1])) : $m[1], $literal);
				$runs[] = ['x' => (float)$x, 'y' => (float)$y, 'text' => (string)mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE')];
			}
		}
		return $runs;
	}

	/** @return list<string> */
	public static function texts(string $pdf): array {
		return array_column(self::runs($pdf), 'text');
	}
}
