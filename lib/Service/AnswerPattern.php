<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * A question's validation pattern read the way the browser reads it, new RegExp(pattern): no delimiter that
 * the pattern would have to escape (the URL preset's \/ broke the old '/'), and Unicode like JavaScript strings,
 * so a length counts characters rather than bytes.
 */
final class AnswerPattern {
	public static function compile(string $pattern): ?string {
		if ($pattern === '' || str_contains($pattern, "\x01")) {
			return null;
		}
		$regex = "\x01" . $pattern . "\x01u";
		return @preg_match($regex, '') === false ? null : $regex;
	}
}
