<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * Names of the files made from a form, from its title, in plain ASCII: every system, mail client and browser takes
 * them, and Nextcloud 35 refuses a download name whose ASCII form holds a slash ("½" becomes "1/2").
 */
final class FileNames {
	private const TITLE_LENGTH = 60;
	/** German as Germans write it without umlauts, and letters that have no accent to drop. */
	private const LETTERS = [
		'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'ẞ' => 'SS',
		'Æ' => 'AE', 'æ' => 'ae', 'Œ' => 'OE', 'œ' => 'oe', 'Ø' => 'O', 'ø' => 'o', 'Ł' => 'L', 'ł' => 'l',
		'Đ' => 'D', 'đ' => 'd', 'Ð' => 'D', 'ð' => 'd', 'Þ' => 'Th', 'þ' => 'th', 'ı' => 'i',
	];

	/** The title in ASCII letters and digits, words joined by "-", at most 60 characters; "" when none are left. */
	public static function title(string $title): string {
		$text = strtr($title, self::LETTERS);
		if (function_exists('normalizer_normalize')) {
			// Without it an accented letter becomes "-" below, which is still a valid name.
			$text = (string)preg_replace('/\p{Mn}+/u', '', (string)normalizer_normalize($text, \Normalizer::FORM_D));
		}
		$text = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-');
		return rtrim(substr($text, 0, self::TITLE_LENGTH), '-');
	}

	/** {title}_{day}_{id}.pdf: the id is never cut; a title with no letter left is "response". */
	public static function responsePdf(string $title, string $day, string $responseId): string {
		$name = self::title($title);
		$parts = [$name === '' ? 'response' : $name, $day, (string)preg_replace('/[^A-Za-z0-9-]+/', '', $responseId)];
		return implode('_', array_filter($parts, static fn (string $part) => $part !== '')) . '.pdf';
	}

	/** {title}-responses.{extension}, in lower case. */
	public static function export(string $title, string $extension): string {
		$name = strtolower(self::title($title));
		return ($name === '' ? '' : $name . '-') . 'responses.' . $extension;
	}
}
