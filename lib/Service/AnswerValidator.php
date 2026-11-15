<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Files\File;
use OCP\IL10N;

/**
 * The one check every submission goes through — public link, external API, import (H6, M16, M26, L40).
 * It returns the answers to store: only for questions that exist, each in the shape its question allows.
 */
class AnswerValidator {
	/** One submission, as JSON (H6). */
	public const MAX_SUBMISSION_BYTES = 262144;
	/** A text answer when its question sets no limit of its own. */
	public const MAX_TEXT_LENGTH = 10000;
	public const MAX_TABLE_ROWS = 200;
	/** A number, scale or rating as typed; a longer one only costs the PDF and exports. */
	public const MAX_NUMBER_LENGTH = 64;

	/** UI items that never hold an answer (#64). */
	private const NO_ANSWER_TYPES = ['section', 'descriptor'];
	private const CHOICE_TYPES = ['choice', 'multiple', 'dropdown'];

	public function __construct(
		private UploadService $uploads,
		private IL10N $l,
		private FormLogic $logic = new FormLogic(),
	) {
	}

	/**
	 * Known questions only, within the size limits; the strict per-type checks are in validate().
	 *
	 * @throws InvalidAnswerException
	 */
	public function sanitize(array $form, array $answers): array {
		$encoded = json_encode($answers);
		if ($encoded === false || strlen($encoded) > self::MAX_SUBMISSION_BYTES) {
			throw new InvalidAnswerException($this->l->t('The answers are too large'));
		}
		return array_intersect_key($answers, $this->answerableQuestions($form));
	}

	/**
	 * @param callable(): File|null $formFile resolves the form's file when a file answer must be checked
	 * @return array the answers to store
	 * @throws InvalidAnswerException
	 */
	public function validate(array $form, array $answers, ?callable $formFile = null): array {
		$answers = $this->sanitize($form, $answers);
		$questions = $this->answerableQuestions($form);
		// What the respondent could not see, hidden or on a skipped page, is neither kept nor required (secu#46, test#50).
		$hidden = $this->logic->analyse($form, $answers)['hidden'];
		$answers = array_diff_key($answers, $hidden);

		foreach ($answers as $questionId => $answer) {
			if (!$this->isEmpty($answer)) {
				$answers[$questionId] = $this->normalize($questions[$questionId], $answer, $formFile);
			}
		}

		foreach ($questions as $questionId => $question) {
			if (!($question['required'] ?? false) || isset($hidden[(string)$questionId])) {
				continue;
			}
			$answer = $answers[$questionId] ?? null;
			if ($this->isMissing($question, $answer)) {
				// TRANSLATORS: Error shown to a respondent; %s is the text of the question
				throw new InvalidAnswerException($this->l->t("Question '%s' is required", [$this->label($question)]), (string)$questionId);
			}
			// The page asks for every row of a required matrix; so does the server.
			if (($question['type'] ?? '') === 'matrix' && array_diff($this->keys($question['rows'] ?? [], 'id', 'id'), array_map('strval', array_keys($answer))) !== []) {
				throw new InvalidAnswerException($this->l->t("Please answer all rows of question '%s'", [$this->label($question)]), (string)$questionId);
			}
		}

		return $answers;
	}

	/**
	 * Rejects a choice whose option is full (#104). ResponseRules calls it on the fresh form under the lock;
	 * $replacing is the earlier answer of a response being changed, which no longer takes its place.
	 *
	 * @throws InvalidAnswerException
	 */
	public function checkCapacity(array $form, array $answers, array $replacing = []): void {
		$counts = $form['_index']['answer_counts'] ?? [];
		foreach ($replacing as $qid => $earlier) {
			foreach (is_array($earlier) ? $earlier : [$earlier] as $value) {
				if (is_scalar($value) && isset($counts[$qid][$value])) {
					$counts[$qid][$value]--;
				}
			}
		}
		foreach ($form['questions'] ?? [] as $question) {
			if (!in_array($question['type'] ?? '', self::CHOICE_TYPES, true)) {
				continue;
			}
			$qid = $question['id'];
			$selected = $answers[$qid] ?? null;
			if ($this->isEmpty($selected)) {
				continue;
			}
			$selectedValues = is_array($selected) ? $selected : [$selected];
			foreach ($question['options'] ?? [] as $option) {
				$capacity = $option['capacity'] ?? null;
				if ($capacity === null || $capacity === '' || (int)$capacity <= 0) {
					continue;
				}
				$optValue = $option['value'] ?? $option['id'] ?? null;
				if ($optValue === null || !in_array($optValue, $selectedValues, true)) {
					continue;
				}
				if ((int)($counts[$qid][$optValue] ?? 0) >= (int)$capacity) {
					$label = $option['label'] ?? $optValue;
					// TRANSLATORS: Error shown to a respondent; %s is the label of an answer option that has no places left
					throw new InvalidAnswerException($this->l->t("The option '%s' is no longer available — capacity reached", [$label]), (string)$qid);
				}
			}
		}
	}

	/**
	 * @return array<string, array> questions that take an answer, by id
	 */
	private function answerableQuestions(array $form): array {
		$out = [];
		foreach ($form['questions'] ?? [] as $question) {
			if (is_array($question) && isset($question['id']) && !in_array($question['type'] ?? '', self::NO_ANSWER_TYPES, true)) {
				$out[(string)$question['id']] = $question;
			}
		}
		return $out;
	}

	private function normalize(array $question, mixed $answer, ?callable $formFile): mixed {
		$id = (string)$question['id'];
		$type = $question['type'] ?? '';
		$answer = match ($type) {
			'text', 'textarea' => $this->text($question, $answer),
			'number' => $this->number($question, $answer, $question['min'] ?? null, $question['max'] ?? null),
			// The page offers scaleMin..scaleMax; older forms said min/max, so the widest range is accepted.
			'scale' => $this->wholeNumber($question, $answer, min(1, (int)($question['scaleMin'] ?? 1), (int)($question['min'] ?? 1)), max(5, (int)($question['scaleMax'] ?? 5), (int)($question['max'] ?? 5))),
			'rating' => $this->wholeNumber($question, $answer, 1, max(5, (int)($question['ratingMax'] ?? 5), (int)($question['max'] ?? 5))),
			'choice', 'dropdown' => $this->choice($question, $answer),
			'multiple' => $this->multiple($question, $answer),
			'consent' => $this->consent($answer, $id),
			'date' => $this->dateLike($question, $answer, AnswerDate::isDate(...), 'date', $question['dateMin'] ?? null, $question['dateMax'] ?? null),
			'datetime' => $this->dateLike($question, $answer, AnswerDate::isDateTime(...), 'datetime', $question['dateMin'] ?? null, $question['dateMax'] ?? null),
			'time' => $this->dateLike($question, $answer, static fn (string $value): bool => preg_match('/^\d{2}:\d{2}$/', $value) === 1, 'time', $question['timeMin'] ?? null, $question['timeMax'] ?? null),
			'matrix' => $this->matrix($question, $answer),
			'table' => $this->table($question, $answer),
			'file' => $this->files($question, $answer, $formFile),
			default => $this->scalar($question, $answer),
		};
		$this->checkPattern($question, $answer);
		return $answer;
	}

	private function text(array $question, mixed $answer): string {
		if (!is_scalar($answer) || is_bool($answer)) {
			throw $this->invalid($question);
		}
		$answer = (string)$answer;
		// mb_strlen counts characters, matching the browser counter (#113).
		$max = (int)($question['maxLength'] ?? 0) ?: self::MAX_TEXT_LENGTH;
		if (mb_strlen($answer) > $max) {
			// TRANSLATORS: Error shown to a respondent; %1$s is the question text, %2$d the maximum number of characters
			throw new InvalidAnswerException($this->l->t("Answer for question '%1\$s' exceeds the %2\$d-character limit", [$this->label($question), $max]), (string)$question['id']);
		}
		return $answer;
	}

	private function number(array $question, mixed $answer, mixed $min, mixed $max): int|float|string {
		// '1e400' is numeric and short, but infinite once read: no summary or JSON can hold it.
		if (!is_scalar($answer) || is_bool($answer) || !is_numeric($answer) || strlen((string)$answer) > self::MAX_NUMBER_LENGTH || !is_finite((float)$answer)) {
			throw $this->invalid($question);
		}
		if ((is_numeric($min) && $answer < $min) || (is_numeric($max) && $answer > $max)) {
			throw new InvalidAnswerException($this->l->t("Answer for question '%s' is out of range", [$this->label($question)]), (string)$question['id']);
		}
		return $answer;
	}

	private function wholeNumber(array $question, mixed $answer, int $min, int $max): int|float|string {
		$answer = $this->number($question, $answer, $min, $max);
		if ((float)$answer !== floor((float)$answer)) {
			throw $this->invalid($question);
		}
		return $answer;
	}

	private function choice(array $question, mixed $answer): string|int {
		if (!is_string($answer) && !is_int($answer)) {
			throw $this->invalid($question);
		}
		if (!in_array((string)$answer, $this->optionValues($question), true)) {
			throw new InvalidAnswerException($this->l->t("'%1\$s' is not an option of question '%2\$s'", [(string)$answer, $this->label($question)]), (string)$question['id']);
		}
		return $answer;
	}

	private function multiple(array $question, mixed $answer): array {
		if (!is_array($answer) || !array_is_list($answer)) {
			throw new InvalidAnswerException($this->l->t('Multiple choice question requires array answer'), (string)$question['id']);
		}
		$values = [];
		foreach ($answer as $value) {
			$values[] = $this->choice($question, $value);
		}
		// One person picks an option once, however often the request repeats it (H6).
		$values = array_values(array_unique($values, SORT_REGULAR));
		$count = count($values);
		$min = (int)($question['minSelections'] ?? 0);
		$max = (int)($question['maxSelections'] ?? 0);
		if ($min > 0 && $count < $min) {
			// TRANSLATORS: Error shown to a respondent; %n is the minimum number of options to tick, %s the question text
			throw new InvalidAnswerException($this->l->n("Select at least %n option for question '%s'", "Select at least %n options for question '%s'", $min, [$this->label($question)]), (string)$question['id']);
		}
		if ($max > 0 && $count > $max) {
			// TRANSLATORS: Error shown to a respondent; %n is the maximum number of options to tick, %s the question text
			throw new InvalidAnswerException($this->l->n("Select at most %n option for question '%s'", "Select at most %n options for question '%s'", $max, [$this->label($question)]), (string)$question['id']);
		}
		return $values;
	}

	private function consent(mixed $answer, string $id): bool {
		if (!is_bool($answer)) {
			throw new InvalidAnswerException($this->l->t('Consent question requires boolean answer'), $id);
		}
		return $answer;
	}

	/** @param \Closure(string): bool $valid */
	private function dateLike(array $question, mixed $answer, \Closure $valid, string $kind, mixed $min, mixed $max): string {
		$label = $this->label($question);
		$id = (string)$question['id'];
		if (!is_string($answer) || !$valid($answer)) {
			throw new InvalidAnswerException(match ($kind) {
				// TRANSLATORS: Error; %s is the question text
				'time' => $this->l->t("Invalid time format for question '%s'", [$label]),
				// TRANSLATORS: Error; %s is the question text; a datetime is a date with a time
				'datetime' => $this->l->t("Invalid datetime format for question '%s'", [$label]),
				// TRANSLATORS: Error; %s is the question text
				default => $this->l->t("Invalid date format for question '%s'", [$label]),
			}, $id);
		}
		$isTime = $kind === 'time';
		if (!empty($min) && $answer < $min) {
			throw new InvalidAnswerException($isTime
				// TRANSLATORS: Error shown to a respondent; %s is the question text
				? $this->l->t("Time is before the allowed minimum for question '%s'", [$label])
				// TRANSLATORS: Error shown to a respondent; %s is the question text
				: $this->l->t("Date is before the allowed minimum for question '%s'", [$label]), $id);
		}
		if (!empty($max) && $answer > $max) {
			throw new InvalidAnswerException($isTime
				// TRANSLATORS: Error shown to a respondent; %s is the question text
				? $this->l->t("Time is after the allowed maximum for question '%s'", [$label])
				// TRANSLATORS: Error shown to a respondent; %s is the question text
				: $this->l->t("Date is after the allowed maximum for question '%s'", [$label]), $id);
		}
		return $answer;
	}

	private function matrix(array $question, mixed $answer): array {
		$rows = $this->keys($question['rows'] ?? [], 'id', 'id');
		$columns = $this->keys($question['columns'] ?? [], 'value', 'id');
		if (!is_array($answer) || array_is_list($answer)) {
			throw $this->invalid($question);
		}
		foreach ($answer as $rowId => $value) {
			if (!in_array((string)$rowId, $rows, true) || !is_scalar($value) || !in_array((string)$value, $columns, true)) {
				throw $this->invalid($question);
			}
		}
		return $answer;
	}

	private function table(array $question, mixed $answer): array {
		$columns = $this->keys($question['columns'] ?? [], 'id', 'id');
		$maxRows = min((int)($question['maxRows'] ?? 0) ?: self::MAX_TABLE_ROWS, self::MAX_TABLE_ROWS);
		if (!is_array($answer) || !array_is_list($answer) || count($answer) > $maxRows) {
			throw $this->invalid($question);
		}
		foreach ($answer as $row) {
			if (!is_array($row)) {
				throw $this->invalid($question);
			}
			foreach ($row as $columnId => $cell) {
				if (!in_array((string)$columnId, $columns, true) || !(is_string($cell) || is_int($cell) || is_float($cell)) || mb_strlen((string)$cell) > self::MAX_TEXT_LENGTH) {
					throw $this->invalid($question);
				}
			}
		}
		return $answer;
	}

	/**
	 * A file answer names a file that was really uploaded for this form; what it says about
	 * that file comes from Nextcloud, not from the request (L40, H5).
	 */
	private function files(array $question, mixed $answer, ?callable $formFile): array {
		if (!is_array($answer)) {
			throw $this->invalid($question);
		}
		$items = array_is_list($answer) ? $answer : [$answer];
		$maxFiles = max(1, (int)($question['maxFiles'] ?? 1));
		if ($formFile === null || $items === [] || count($items) > $maxFiles) {
			throw $this->invalid($question);
		}
		$file = null;
		$stored = [];
		foreach ($items as $item) {
			$responseId = is_array($item) ? (string)($item['responseId'] ?? '') : '';
			$filename = is_array($item) ? (string)($item['filename'] ?? '') : '';
			if (!UploadService::isValidResponseId($responseId) || $filename === '' || basename($filename) !== $filename) {
				throw $this->invalid($question);
			}
			$file ??= $formFile();
			try {
				$upload = $this->uploads->getUpload($file, $responseId, $filename);
			} catch (\Exception $e) {
				throw new InvalidAnswerException($this->l->t("The uploaded file for question '%s' was not found", [$this->label($question)]), (string)$question['id']);
			}
			// The browser's name is kept only where it is the name this file was stored under.
			$claimed = (string)($item['originalName'] ?? '');
			$stored[] = [
				'fileId' => $upload->getId(),
				'filename' => $upload->getName(),
				'originalName' => UploadService::isStoredNameOf($upload->getName(), $claimed) ? mb_substr($claimed, 0, 255) : $upload->getName(),
				'size' => $upload->getSize(),
				'mimeType' => $upload->getMimetype(),
				'responseId' => $responseId,
			];
		}
		return count($stored) === 1 && !array_is_list($answer) ? $stored[0] : $stored;
	}

	/** A type this validator does not know yet: a plain value of bounded size. */
	private function scalar(array $question, mixed $answer): string|int|float|bool {
		if (!is_scalar($answer) || mb_strlen((string)$answer) > self::MAX_TEXT_LENGTH) {
			throw $this->invalid($question);
		}
		return $answer;
	}

	private function checkPattern(array $question, mixed $answer): void {
		$pattern = $question['validation']['pattern'] ?? '';
		if (!is_string($pattern) || $pattern === '' || !is_scalar($answer) || $answer === '') {
			return;
		}
		// Read as the browser reads it; the old '/' delimiter broke the URL preset, so it was never checked.
		$regex = AnswerPattern::compile($pattern);
		if ($regex === null) {
			return; // An invalid pattern in the form is the form's problem, not the respondent's; a save refuses it now.
		}
		if (preg_match($regex, (string)$answer) !== 1) {
			throw new InvalidAnswerException(
				// TRANSLATORS: Error shown to a respondent; %s is the question text
				$question['validation']['errorMessage'] ?? $this->l->t("Answer does not match the required format for question '%s'", [$this->label($question)]),
				(string)$question['id'],
			);
		}
	}

	/**
	 * @return list<string>
	 */
	private function optionValues(array $question): array {
		$values = [];
		foreach ($question['options'] ?? [] as $option) {
			$value = $option['value'] ?? $option['id'] ?? null;
			if ($value !== null) {
				$values[] = (string)$value;
			}
		}
		return $values;
	}

	/**
	 * @return list<string>
	 */
	private function keys(mixed $items, string $field, string $fallback): array {
		$keys = [];
		foreach (is_array($items) ? $items : [] as $item) {
			$key = is_array($item) ? ($item[$field] ?? $item[$fallback] ?? null) : null;
			if (is_scalar($key)) {
				$keys[] = (string)$key;
			}
		}
		return $keys;
	}

	private function isEmpty(mixed $answer): bool {
		return $answer === null || $answer === '' || $answer === [];
	}

	private function isMissing(array $question, mixed $answer): bool {
		return match ($question['type'] ?? '') {
			// Consent: only an explicit `true` counts as answered (#94).
			'consent' => $answer !== true,
			// A table counts once a row has something in it, as on the page.
			'table' => !is_array($answer) || array_filter($answer, fn ($row) => is_array($row) && array_filter($row, fn ($cell) => $cell !== '' && $cell !== null) !== []) === [],
			default => $this->isEmpty($answer),
		};
	}

	private function label(array $question): string {
		return (string)($question['question'] ?? $question['id'] ?? '');
	}

	private function invalid(array $question): InvalidAnswerException {
		// TRANSLATORS: Error shown to a respondent; %s is the question text
		return new InvalidAnswerException($this->l->t("Invalid answer for question '%s'", [$this->label($question)]), (string)$question['id']);
	}
}
