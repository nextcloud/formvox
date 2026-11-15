<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * Which version of a form's structure a client holds, so a stale tab cannot overwrite a newer one (qual#26).
 * Responses and settings are left out: a submission or a share setting must not count as a change.
 */
final class FormRevision {
	private const FIELDS = ['title', 'description', 'descriptionAlign', 'questions', 'pages', 'branding'];
	/** The last editor save, {id, revision}, outside the revision: an editor whose answer was lost asks whether it landed. */
	public const LAST_SAVE = '_lastSave';

	public static function of(array $form): string {
		$structure = [];
		foreach (self::FIELDS as $field) {
			$structure[$field] = $form[$field] ?? null;
		}
		return hash('xxh128', (string)json_encode($structure));
	}

	/** The save id an editor sends (X-FormVox-Save-Id); null when it sends none or something else. */
	public static function saveId(string $header): ?string {
		return preg_match('/^[A-Za-z0-9-]{8,64}$/', $header) === 1 ? $header : null;
	}

	/**
	 * The revision an If-Match header names; null when it names none, or any ("*").
	 */
	public static function fromIfMatch(string $header): ?string {
		$value = trim((string)preg_replace('/^W\//', '', trim($header)), "\" \t");
		return $value === '' || $value === '*' ? null : $value;
	}
}
