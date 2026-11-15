<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\HintException;

/**
 * A write from outside FormVox that met a FormVox save holding the form (3b-3): the one kind of exception Nextcloud's
 * file hooks let through, so the write stops instead of overwriting a submission.
 */
class FormBusyHint extends HintException {
	public function __construct(string $hint, ?\Throwable $previous = null) {
		parent::__construct('The form is busy, please try again', $hint, 503, $previous instanceof \Exception ? $previous : null);
	}
}
