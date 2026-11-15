<?php

declare(strict_types=1);

namespace OCA\FormVox\Exception;

use OCA\FormVox\L10n\Translatable;
use OCP\AppFramework\Http;

/**
 * A request that cannot be served as asked. Pass messages through Translatable::t().
 */
final class RequestException extends ClientErrorException {
	/** @param list<string> $params */
	public static function invalid(string $message, array $params = []): self {
		return new self($message, Http::STATUS_BAD_REQUEST, 'invalid_input', [], $params);
	}

	/** @param array<string, mixed> $data */
	public static function notFound(string $message, array $data = []): self {
		return new self($message, Http::STATUS_NOT_FOUND, 'not_found', $data);
	}

	/** @param list<string> $params */
	public static function forbidden(string $message, array $params = []): self {
		return new self($message, Http::STATUS_FORBIDDEN, 'forbidden', [], $params);
	}

	/** An API key without the permission this call needs. */
	public static function keyLacks(string $permission): self {
		// TRANSLATORS: Error for integrations; %s is the name of an API key permission, e.g. read_responses
		return new self(Translatable::t('API key does not have %s permission'), Http::STATUS_FORBIDDEN, 'key_permission', ['permission' => $permission], [$permission]);
	}

	/** The form's structure changed since this client loaded it; reloading shows the current one. */
	public static function changedElsewhere(): self {
		// TRANSLATORS: Error when a form was changed in another tab or by someone else since it was opened
		return new self(Translatable::t('This form was changed elsewhere. Reload to see the latest version.'), Http::STATUS_PRECONDITION_FAILED, 'changed_elsewhere');
	}

	/** Something the admin has turned off. */
	public static function turnedOff(string $message): self {
		return new self($message, Http::STATUS_FORBIDDEN, 'turned_off');
	}

	/** Kept for the type checker: Nextcloud's SecurityMiddleware already answers 401 before a controller runs. */
	public static function notLoggedIn(): self {
		// TRANSLATORS: Error shown when the session has ended and the user must log in again
		return new self(Translatable::t('Not logged in'), Http::STATUS_UNAUTHORIZED, 'not_logged_in');
	}

	public static function loginRequired(): self {
		// TRANSLATORS: Error shown when a form only takes answers from signed-in people
		return new self(Translatable::t('This form requires you to be logged in'), Http::STATUS_UNAUTHORIZED, 'login_required');
	}

	public static function passwordRequired(): self {
		// TRANSLATORS: Error shown when the form's link needs a password that was not given
		return new self(Translatable::t('Password required'), Http::STATUS_UNAUTHORIZED, 'password_required');
	}

	/** Microsoft Forms is not connected for this account; the page offers to connect it. */
	public static function notConnected(): self {
		// TRANSLATORS: Error shown when the import needs the user to connect their Microsoft account first
		return new self(Translatable::t('Connect your Microsoft account first'), Http::STATUS_CONFLICT, 'not_connected', ['needsAuth' => true]);
	}

	/** The form's storage has no room for it: writing would cut it off. */
	public static function storageFull(): self {
		// TRANSLATORS: Error when the form cannot be saved because the owner's or team folder's storage is full
		return new self(Translatable::t('There is not enough storage space to save this form'), Http::STATUS_INSUFFICIENT_STORAGE, 'storage_full');
	}

	/** A part of the server this needs is not set up. */
	public static function unavailable(string $message): self {
		return new self($message, Http::STATUS_SERVICE_UNAVAILABLE, 'unavailable');
	}

	/** The form keeps no PDFs: nothing is made for a request, so a client can tell this from a PDF still to come. */
	public static function pdfOff(): self {
		// TRANSLATORS: Error when a PDF is asked of a form whose owner turned "Keep a PDF of every response" off
		return new self(Translatable::t('PDF generation is disabled for this form'), Http::STATUS_CONFLICT, 'pdf_disabled');
	}

	/** The background job has yet to make this response's PDF. */
	public static function pdfNotReady(): self {
		// TRANSLATORS: Error for integrations asking for a response's PDF before the background job made it
		return new self(Translatable::t('PDF is not ready yet'), Http::STATUS_CONFLICT, 'pdf_not_ready');
	}

	/** @param array<string, mixed> $data */
	public static function conflict(string $message, array $data = []): self {
		return new self($message, Http::STATUS_CONFLICT, 'conflict', $data);
	}
}
