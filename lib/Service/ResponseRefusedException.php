<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\L10n\Translatable;
use OCP\AppFramework\Http;

/**
 * The form does not take this response now: not open yet, closed, full, or already answered by this person.
 */
class ResponseRefusedException extends ClientErrorException {
	public static function notYetOpen(?string $opensAt = null): self {
		// TRANSLATORS: Error shown when a form's opening date has not come yet
		return new self(Translatable::t('This form is not yet open'), Http::STATUS_FORBIDDEN, 'form_not_open', $opensAt !== null ? ['opensAt' => $opensAt] : []);
	}

	public static function closed(): self {
		// TRANSLATORS: Error shown when a form's closing date has passed
		return new self(Translatable::t('This form has expired'), Http::STATUS_GONE, 'form_closed');
	}

	/** $ownMessage is the creator's own text for a full form, shown as it is. */
	public static function full(string $ownMessage = ''): self {
		// TRANSLATORS: Error shown when a form has as many responses as its creator allows
		return new self($ownMessage !== '' ? $ownMessage : Translatable::t('This form has reached its response limit'), Http::STATUS_CONFLICT, 'form_full');
	}

	/** A refusal decided in another request, which wrote this one's response together with its own (3b-2). */
	public static function rebuilt(string $message, int $status, string $reason, array $data = [], array $params = []): self {
		return new self($message, $status, $reason, $data, array_values(array_map('strval', $params)));
	}

	public static function alreadyAnswered(): self {
		// TRANSLATORS: Error shown when someone answers a form that takes one response per person a second time
		return new self(Translatable::t('You have already submitted a response to this form'), Http::STATUS_CONFLICT, 'already_answered');
	}
}
