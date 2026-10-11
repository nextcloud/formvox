<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Support;

use OCP\IL10N;

/**
 * IL10N that returns the English source text with its parameters filled in, as Nextcloud does without a translation.
 */
class EnglishL10N implements IL10N {
	public function t(string $text, $parameters = []): string {
		return vsprintf($text, (array)$parameters);
	}

	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		// As Nextcloud: the count goes into %n, the parameters into the sprintf placeholders.
		return vsprintf(str_replace('%n', (string)$count, $count === 1 ? $text_singular : $text_plural), $parameters);
	}

	public function l(string $type, $data, array $options = []) {
		return $data instanceof \DateTimeInterface ? $data->format('Y-m-d H:i') : (string)$data;
	}

	public function getLanguageCode(): string {
		return 'en';
	}

	public function getLocaleCode(): string {
		return 'en';
	}
}
