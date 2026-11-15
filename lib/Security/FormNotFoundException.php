<?php

declare(strict_types=1);

namespace OCA\FormVox\Security;

use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\L10n\Translatable;
use OCP\AppFramework\Http;

/**
 * No access to the form (or it does not exist). Answered with 404 so the two look the same.
 */
class FormNotFoundException extends ClientErrorException {
	public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null) {
		parent::__construct(Translatable::t('Form not found'), Http::STATUS_NOT_FOUND, 'not_found', [], [], $previous);
	}
}
