<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Exception\ClientErrorException;
use OCP\AppFramework\Http;

/**
 * A form that would break if stored: every problem with the question it is on, already in the user's language.
 */
class FormValidationException extends ClientErrorException {
	/**
	 * @param list<array{question: ?string, field: ?string, message: string}> $errors
	 */
	public static function withErrors(array $errors): self {
		return new self($errors[0]['message'] ?? 'The form is not valid', Http::STATUS_BAD_REQUEST, 'invalid_form', ['errors' => $errors]);
	}

	/**
	 * @return list<array{question: ?string, field: ?string, message: string}>
	 */
	public function getErrors(): array {
		return $this->getData()['errors'] ?? [];
	}
}
