<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Support;

use OCA\FormVox\Pdf\Brand;
use OCA\FormVox\Pdf\ResponseDocument;
use OCA\FormVox\Service\FormLogic;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;

/** A ResponseDocument whose dates read plainly: Y-m-d, and Y-m-d H:i in the zone it is given. */
final class PdfDocuments {
	/** @param array{name: string, color: string, textColor: string, logo: ?string}|null $brand */
	public static function document(TestCase $test, string $serverZone = 'UTC', ?array $brand = null): ResponseDocument {
		$formatter = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IDateTimeFormatter::class);
		$formatter->method('formatDate')->willReturnCallback(fn (\DateTime $date, string $format, \DateTimeZone $tz) => (clone $date)->setTimezone($tz)->format('Y-m-d'));
		$formatter->method('formatDateTime')->willReturnCallback(fn (\DateTime $date, string $d, string $t, \DateTimeZone $tz) => (clone $date)->setTimezone($tz)->format('Y-m-d H:i'));
		$zone = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IDateTimeZone::class);
		$zone->method('getDefaultTimeZone')->willReturn(new \DateTimeZone($serverZone));
		$theming = (new \ReflectionMethod($test, 'createMock'))->invoke($test, Brand::class);
		$theming->method('header')->willReturn($brand ?? ['name' => 'Schulcloud', 'color' => '#0082c9', 'textColor' => '#ffffff', 'logo' => null]);
		return new ResponseDocument(new FormLogic(), $formatter, $zone, $theming);
	}
}
