<?php

declare(strict_types=1);

namespace OCA\FormVox\Security;

/**
 * The folders FormVox keeps next to a form (.formvox-pdfs-, -uploads-, -branding-, -templates-<id>) and all inside them.
 */
final class HiddenPath {
	private const SEGMENT = '/^\.formvox-/i';

	public static function isHidden(string $path): bool {
		foreach (self::segments($path) as $segment) {
			if (preg_match(self::SEGMENT, $segment) === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The segments Nextcloud resolves the path to: it drops NUL bytes and reads a backslash as a slash only after a
	 * request parameter was read, so a raw test would miss a segment that Nextcloud then opens.
	 *
	 * @return list<string>
	 */
	public static function segments(string $path): array {
		$path = str_replace([chr(0), '\\'], ['', '/'], $path);
		/** @psalm-suppress InternalMethod intl's class, or the polyfill Nextcloud ships */
		$normalized = \Normalizer::normalize($path, \Normalizer::FORM_C);
		if (is_string($normalized)) {
			$path = $normalized;
		}
		return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
	}
}
