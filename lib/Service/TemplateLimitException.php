<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\L10n\Translatable;
use OCP\AppFramework\Http;

/**
 * The admin template list is full.
 */
class TemplateLimitException extends ClientErrorException {
	public static function reached(): self {
		// TRANSLATORS: Error shown to an admin when no more form templates can be saved
		return new self(Translatable::t('The maximum number of templates has been reached'), Http::STATUS_CONFLICT, 'template_limit');
	}
}
