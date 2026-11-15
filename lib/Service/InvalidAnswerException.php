<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * An answer the form does not accept; answered with 400 and the question it is about.
 */
class InvalidAnswerException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly ?string $questionId = null,
	) {
		parent::__construct($message);
	}
}
