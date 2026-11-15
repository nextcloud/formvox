<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

/**
 * Keys that fit their column: a longer one is stored as its hash, so lookups stay exact and indexes small.
 */
final class StoreKey {
	private const HASHED = 'h:';

	/** @param int $max the column's length, at least 34 */
	public static function fit(string $key, int $max): string {
		if (strlen($key) <= $max && !str_starts_with($key, self::HASHED)) {
			return $key;
		}
		return self::HASHED . substr(hash('sha256', $key), 0, min(64, $max - strlen(self::HASHED)));
	}
}
