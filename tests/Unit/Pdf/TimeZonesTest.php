<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use OCA\FormVox\Pdf\TimeZones;
use PHPUnit\Framework\TestCase;

/** A time zone as browsers name it, older names too; never an offset or a made-up name. */
class TimeZonesTest extends TestCase {
	public function testNamesBrowsersSend(): void {
		foreach (['Europe/Berlin', 'Asia/Calcutta', 'Europe/Kiev', 'Asia/Saigon', 'UTC'] as $zone) {
			$this->assertSame($zone, TimeZones::name($zone), $zone);
		}
	}

	public function testNothingElse(): void {
		foreach (['+05:30', 'Mars/Olympus', '', '<script>', null, 5] as $zone) {
			$this->assertNull(TimeZones::name($zone));
		}
	}
}
