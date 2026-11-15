<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * Which questions a respondent sees, the same as the form page works it out (src/utils/formLogic.js): conditions,
 * hidden sections, and the pages page routing leads through. Both are checked against one table of cases
 * (tests/fixtures/form-logic-cases.json), so they cannot drift apart again.
 *
 * - An answer to a hidden question does not count in other conditions.
 * - Values compare by their text, so 5 and "5" are the same; a choice rule may name the option's label or value.
 * - Empty: nothing, "", an empty list or object, false. A 0 is an answer.
 * - Greater/less than: dates (YYYY-MM-DD…) as dates, numbers as numbers, anything else never matches.
 * - Pages: from the first, each page's first matching rule leads on, otherwise the next page; a page is not
 *   visited twice. A question on no page is shown on the last one.
 */
class FormLogic {
	private const NUMBER = '/^\s*-?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?\s*$/';
	private const DATE = '/^\d{4}-\d{2}-\d{2}/';
	private const CHOICE_TYPES = ['choice', 'multiple', 'dropdown'];

	/**
	 * @return array{hidden: array<string, true>, pages: list<string>|null} hidden question ids; the page ids visited, or null without pages
	 */
	public function analyse(array $form, array $answers): array {
		$questions = [];
		$byId = [];
		foreach (is_array($form['questions'] ?? null) ? $form['questions'] : [] as $q) {
			if (is_array($q) && is_scalar($q['id'] ?? null)) {
				$questions[] = $q;
				$byId[(string)$q['id']] ??= $q;
			}
		}
		$pages = $this->pages($form, $questions);

		// Hiding one question can hide the next that depended on it; repeat until nothing changes.
		$effective = $answers;
		$hidden = [];
		$visited = null;
		for ($round = 0; $round <= count($questions); $round++) {
			$visited = $pages === null ? null : $this->path($pages, $effective, $byId);
			$hidden = $this->hidden($questions, $byId, $pages, $visited, $effective);
			$next = array_diff_key($answers, $hidden);
			if ($next === $effective) {
				break;
			}
			$effective = $next;
		}
		return ['hidden' => $hidden, 'pages' => $visited];
	}

	/**
	 * The question ids page by page as the respondent met them: the pages visited, in order, each with its questions in
	 * the page's order (one on no page on the last); without pages, all questions in the form's order. Hidden ones
	 * included: analyse() says which.
	 *
	 * @return list<list<string>>
	 */
	public function pagesShown(array $form, array $answers): array {
		$questions = [];
		foreach (is_array($form['questions'] ?? null) ? $form['questions'] : [] as $q) {
			if (is_array($q) && is_scalar($q['id'] ?? null)) {
				$questions[] = $q;
			}
		}
		$pages = $this->pages($form, $questions);
		if ($pages === null) {
			return [array_map(static fn (array $q): string => (string)$q['id'], $questions)];
		}
		$byPage = array_column($pages, 'questions', 'id');
		$shown = [];
		foreach ($this->analyse($form, $answers)['pages'] ?? [] as $pageId) {
			$shown[] = $byPage[$pageId] ?? [];
		}
		return $shown;
	}

	/**
	 * The answers a respondent could give: those on hidden questions and skipped pages left out.
	 */
	public function visibleAnswers(array $form, array $answers): array {
		return array_diff_key($answers, $this->analyse($form, $answers)['hidden']);
	}

	public function evaluate(mixed $condition, array $answers, array $questionsById): bool {
		if (!is_array($condition)) {
			return false;
		}
		$operator = is_string($condition['operator'] ?? null) ? $condition['operator'] : '';
		if (is_array($condition['conditions'] ?? null) && array_is_list($condition['conditions']) && ($operator === 'and' || $operator === 'or')) {
			$results = array_map(fn ($c) => $this->evaluate($c, $answers, $questionsById), $condition['conditions']);
			return $operator === 'and' ? !in_array(false, $results, true) : in_array(true, $results, true);
		}
		if (!is_scalar($condition['questionId'] ?? null) || $operator === '') {
			return false;
		}

		$questionId = (string)$condition['questionId'];
		$answer = $answers[$questionId] ?? null;
		$value = $condition['value'] ?? null;
		$question = $questionsById[$questionId] ?? null;

		switch ($operator) {
			case 'isEmpty':
				return $this->isEmpty($answer);
			case 'isNotEmpty':
				return !$this->isEmpty($answer);
			case 'equals':
			case 'contains':
				return $this->matches($answer, $this->candidates($question, $value), $operator === 'contains');
			case 'notEquals':
			case 'notContains':
				return !$this->matches($answer, $this->candidates($question, $value), $operator === 'notContains');
			case 'in':
			case 'notIn':
				if (!is_array($value) || !array_is_list($value)) {
					return $operator === 'notIn';
				}
				$allowed = [];
				foreach ($value as $v) {
					array_push($allowed, ...$this->candidates($question, $v));
				}
				return $this->matches($answer, $allowed, false) === ($operator === 'in');
			case 'greaterThan':
			case 'lessThan':
				$a = is_array($answer) && array_is_list($answer) ? ($answer[0] ?? null) : $answer;
				$order = $this->compare($a, $value);
				return $order !== null && ($operator === 'greaterThan' ? $order > 0 : $order < 0);
		}
		return false;
	}

	/**
	 * The pages in order, each with its question ids; questions on no page join the last one, as the form page shows them.
	 *
	 * @return list<array{id: string, questions: list<string>, routing: array}>|null
	 */
	private function pages(array $form, array $questions): ?array {
		if (!is_array($form['pages'] ?? null) || !array_is_list($form['pages']) || $form['pages'] === []) {
			return null;
		}
		$pages = [];
		$placed = [];
		foreach ($form['pages'] as $p => $page) {
			$ids = [];
			foreach (is_array($page['questions'] ?? null) ? $page['questions'] : [] as $id) {
				if (is_scalar($id)) {
					$ids[] = (string)$id;
					$placed[(string)$id] = true;
				}
			}
			$pages[] = [
				'id' => is_scalar($page['id'] ?? null) ? (string)$page['id'] : (string)$p,
				'questions' => $ids,
				'routing' => is_array($page['routing'] ?? null) ? $page['routing'] : [],
			];
		}
		foreach ($questions as $q) {
			if (!isset($placed[(string)$q['id']])) {
				$pages[count($pages) - 1]['questions'][] = (string)$q['id'];
			}
		}
		return $pages;
	}

	/**
	 * @param list<array{id: string, questions: list<string>, routing: array}> $pages
	 * @return list<string>
	 */
	private function path(array $pages, array $answers, array $byId): array {
		$index = array_flip(array_column($pages, 'id'));
		$visited = [];
		$seen = [];
		$i = 0;
		while ($i < count($pages) && !isset($seen[$i])) {
			$seen[$i] = true;
			$visited[] = $pages[$i]['id'];
			$next = $i + 1;
			foreach ($pages[$i]['routing'] as $rule) {
				if (!$this->isRule($rule)) {
					continue;
				}
				if ($this->evaluate(['questionId' => $rule['questionId'], 'operator' => $rule['operator'], 'value' => $rule['value'] ?? null], $answers, $byId)) {
					$target = $index[(string)$rule['targetPageId']] ?? null;
					if ($target !== null && !isset($seen[$target])) {
						$next = $target;
					}
					break;
				}
			}
			$i = $next;
		}
		return $visited;
	}

	/**
	 * @return array<string, true>
	 */
	private function hidden(array $questions, array $byId, ?array $pages, ?array $visited, array $answers): array {
		$onVisitedPage = null;
		if ($pages !== null) {
			$onVisitedPage = [];
			$visitedIds = array_flip($visited ?? []);
			foreach ($pages as $page) {
				if (isset($visitedIds[$page['id']])) {
					foreach ($page['questions'] as $id) {
						$onVisitedPage[$id] = true;
					}
				}
			}
		}

		$hidden = [];
		foreach ($questions as $q) {
			$id = (string)$q['id'];
			$section = is_scalar($q['sectionId'] ?? null) ? ($byId[(string)$q['sectionId']] ?? null) : null;
			if (($onVisitedPage !== null && !isset($onVisitedPage[$id]))
				|| (isset($q['showIf']) && !$this->evaluate($q['showIf'], $answers, $byId))
				|| ($section !== null && isset($section['showIf']) && !$this->evaluate($section['showIf'], $answers, $byId))) {
				$hidden[$id] = true;
			}
		}
		return $hidden;
	}

	/**
	 * What a rule's value may match: for a choice, both the option's value and its label (#99).
	 *
	 * @return list<string>
	 */
	private function candidates(?array $question, mixed $value): array {
		$text = $this->text($value);
		if ($text === null) {
			return [];
		}
		if ($question === null || !in_array($question['type'] ?? '', self::CHOICE_TYPES, true)) {
			return [$text];
		}
		foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
			if (!is_array($option)) {
				continue;
			}
			$optionValue = $this->text($option['value'] ?? $option['id'] ?? null);
			$label = $this->text($option['label'] ?? null);
			if ($text === $optionValue || $text === $label) {
				return array_values(array_unique(array_filter([$optionValue, $label], fn ($c) => $c !== null)));
			}
		}
		return [$text];
	}

	/**
	 * @param list<string> $candidates
	 */
	private function matches(mixed $answer, array $candidates, bool $partly): bool {
		$values = is_array($answer) && array_is_list($answer) ? $answer : [$answer];
		foreach ($values as $v) {
			$text = $this->text($v);
			if ($text === null) {
				continue;
			}
			foreach ($candidates as $c) {
				if ($partly && !is_array($answer) ? str_contains($text, $c) : $text === $c) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * -1, 0 or 1; null when the two cannot be compared.
	 */
	private function compare(mixed $a, mixed $b): ?int {
		if (is_string($a) && is_string($b) && preg_match(self::DATE, $a) === 1 && preg_match(self::DATE, $b) === 1) {
			return $a <=> $b;
		}
		$x = $this->text($a);
		$y = $this->text($b);
		if ($x === null || $y === null || preg_match(self::NUMBER, $x) !== 1 || preg_match(self::NUMBER, $y) !== 1) {
			return null;
		}
		return (float)$x <=> (float)$y;
	}

	/** A routing rule that is filled in; a half-made one in the editor never leads anywhere (#99). */
	private function isRule(mixed $rule): bool {
		return is_array($rule)
			&& is_scalar($rule['questionId'] ?? null) && (string)$rule['questionId'] !== ''
			&& is_string($rule['operator'] ?? null) && $rule['operator'] !== ''
			&& is_scalar($rule['targetPageId'] ?? null);
	}

	private function isEmpty(mixed $answer): bool {
		return $answer === null || $answer === '' || $answer === [] || $answer === false;
	}

	/** A value as text, the way the browser writes it; null for what is no single value. */
	private function text(mixed $value): ?string {
		return match (true) {
			is_bool($value) => $value ? 'true' : 'false',
			is_string($value) => $value,
			is_int($value) => (string)$value,
			is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 1e15 ? (string)(int)$value : (string)$value,
			default => null,
		};
	}
}
