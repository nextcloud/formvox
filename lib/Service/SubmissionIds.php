<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * The browser's id for a fill with what it answered, so a retry of one that was stored, whose answer got lost or came
 * while the server was busy, is recognised and not stored twice (7a, 3a-5). A retry sends the same answers; the same id
 * with other answers is another fill and is stored (review of the 3a fixes). The database keeps them with the responses;
 * a form still in its file keeps the last of them there.
 */
final class SubmissionIds {
	public const KEY = '_submissions';

	public static function isValid(?string $id): bool {
		return $id !== null && preg_match('/^[A-Za-z0-9-]{16,64}$/', $id) === 1;
	}

	/**
	 * A submission as it is recognised: the browser's id and what it answered, before any check changed the answers.
	 *
	 * @return string|null null without a valid id: nothing to recognise
	 */
	public static function of(?string $submissionId, array $answers): ?string {
		if (!self::isValid($submissionId)) {
			return null;
		}
		return $submissionId . '|' . substr(hash('sha256', (string)json_encode($answers)), 0, 16);
	}

	/** The response stored for this submission, or null when it is new (or that response was deleted since). */
	public static function stored(array $form, ?string $submission): ?array {
		if ($submission === null) {
			return null;
		}
		$responseId = is_array($form[self::KEY] ?? null) ? ($form[self::KEY][$submission] ?? null) : null;
		if (!is_string($responseId)) {
			return null;
		}
		foreach (is_array($form['responses'] ?? null) ? $form['responses'] : [] as $response) {
			if (is_array($response) && ($response['id'] ?? null) === $responseId) {
				return $response;
			}
		}
		return null;
	}
}
