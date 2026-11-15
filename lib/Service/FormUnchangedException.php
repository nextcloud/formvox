<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * Thrown inside a locked mutation to leave the form file untouched; never leaves FormRepository.
 */
class FormUnchangedException extends \RuntimeException {
}
