<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * A date or date-time as the form's inputs and the API give it, and only a real one: PHP's own parser also reads
 * "2026-02-31" as 3 March and "2026-01-01T10:00 +5 years" as a later year.
 */
final class AnswerDate {
	public static function isDate(string $value): bool {
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}

	/** With minutes, optional seconds and fraction (.NET sends seven digits), and optional offset: at most 35 characters. */
	public static function isDateTime(string $value): bool {
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,9})?)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?$/', $value, $m) === 1
			&& checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}
}
