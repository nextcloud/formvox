<?php

declare(strict_types=1);

namespace OCA\FormVox\L10n;

/**
 * Marks an English message for the translation extractors without translating it.
 *
 * A ClientErrorException carries its message in English and ExceptionMiddleware translates it in the
 * user's language when it answers, by passing it to IL10N::t(). That call has no literal, so neither
 * Nextcloud's Transifex bot (xgettext --keyword=t) nor scripts/extract-en-json.js would see the msgid.
 * Wrap the English literal in Translatable::t() where the exception is made, and both pick it up.
 * Anything shown directly uses IL10N::t().
 */
final class Translatable {
	/**
	 * @param string $text an English literal; never a variable or a concatenation
	 * @return string the same text, untranslated
	 */
	public static function t(string $text): string {
		return $text;
	}
}
