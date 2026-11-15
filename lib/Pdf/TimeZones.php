<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

/** Browsers report older IANA names too (Chrome: Asia/Calcutta); offsets and abbreviations are refused. */
final class TimeZones {
	public static function name(mixed $zone): ?string {
		return is_string($zone) && in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $zone : null;
	}
}
