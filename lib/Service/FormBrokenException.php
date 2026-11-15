<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * The form file holds no form, and no save is under way that could change that: waiting does not help.
 */
class FormBrokenException extends \RuntimeException {
	public function __construct(?\Throwable $previous = null) {
		parent::__construct('Invalid form file format', 0, $previous);
	}
}
