<?php

declare(strict_types=1);

namespace OCA\FormVox\Exception;

use OCA\FormVox\L10n\Translatable;

/**
 * The AI's answer could not be turned into a form, for a reason the person who asked may read.
 * Raised in the background, so the message reaches them through a notification, translated there.
 */
final class AiGenerationException extends \RuntimeException {
	public static function unreadable(): self {
		// TRANSLATORS: Shown in a notification when the AI's answer could not be read as a form
		return new self(Translatable::t('AI returned an invalid response. Try a shorter or simpler description.'));
	}

	public static function noQuestions(): self {
		// TRANSLATORS: Shown in a notification when the AI made a form without questions
		return new self(Translatable::t('AI did not generate any questions.'));
	}

	public static function noUsableQuestions(): self {
		// TRANSLATORS: Shown in a notification when none of the AI's questions could be used
		return new self(Translatable::t('AI did not generate any usable questions.'));
	}
}
