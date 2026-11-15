<?php

declare(strict_types=1);

namespace OCA\FormVox\Security;

use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\L10n\Translatable;
use OCP\AppFramework\Http;

/**
 * The user can open the form but lacks the permission for this action. Answered with 403.
 */
class FormAccessDeniedException extends ClientErrorException {
	public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null) {
		// TRANSLATORS: Error when the account may not do this with the form
		parent::__construct(Translatable::t('Permission denied'), Http::STATUS_FORBIDDEN, 'forbidden', [], [], $previous);
	}
}
