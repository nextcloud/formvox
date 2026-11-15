<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

class IndexService {
	/** Answers that come from a fixed set (options, scale points, matrix cells), plus files and table rows. */
	private const COUNTED_TYPES = ['choice', 'multiple', 'dropdown', 'scale', 'rating', 'consent', 'matrix', 'table', 'file'];

	/**
	 * Rebuild the entire index from scratch
	 */
	public function rebuildIndex(array &$form): void {
		$form['_index'] = [
			'response_count' => 0,
			'last_response_at' => null,
			'fingerprints' => [],
			'user_ids' => [],
			'by_date' => [],
			'answer_counts' => [],
		];

		$responses = $form['responses'] ?? [];

		foreach ($responses as $index => $response) {
			$form['_index']['response_count']++;

			if (isset($response['submitted_at'])) {
				$form['_index']['last_response_at'] = $response['submitted_at'];
			}

			// Index fingerprint or user_id
			if (isset($response['respondent'])) {
				if ($response['respondent']['type'] === 'anonymous' && isset($response['respondent']['fingerprint'])) {
					$form['_index']['fingerprints'][$response['respondent']['fingerprint']] = $index;
				} elseif ($response['respondent']['type'] === 'user' && isset($response['respondent']['user_id'])) {
					$form['_index']['user_ids'][$response['respondent']['user_id']] = $index;
				}
			}

			// Index by date
			if (isset($response['submitted_at'])) {
				$date = substr($response['submitted_at'], 0, 10);
				if (!isset($form['_index']['by_date'][$date])) {
					$form['_index']['by_date'][$date] = [];
				}
				$form['_index']['by_date'][$date][] = $index;
			}

			$this->countAnswers($form['_index']['answer_counts'], $form, $response);
		}
	}

	/**
	 * What one response adds to the counts a summary shows, as rebuildIndex counts them: for responses read a page at
	 * a time.
	 *
	 * @param array{response_count?: int, last_response_at?: ?string, answer_counts?: array} $index
	 */
	public function tally(array &$index, array $form, array $response): void {
		$index['response_count'] = ($index['response_count'] ?? 0) + 1;
		if (isset($response['submitted_at'])) {
			$index['last_response_at'] = $response['submitted_at'];
		}
		$index['answer_counts'] ??= [];
		$this->countAnswers($index['answer_counts'], $form, $response);
	}

	/** @return array<string, array<string, int>> what one response adds to the answer counts */
	public function answerCounts(array $form, array $response): array {
		$counts = [];
		$this->countAnswers($counts, $form, $response);
		return $counts;
	}

	/**
	 * Check if a fingerprint already exists
	 */
	public function hasFingerprint(array $form, string $fingerprint): bool {
		return isset($form['_index']['fingerprints'][$fingerprint]);
	}

	/**
	 * Check if a user has already responded
	 */
	public function hasUserResponse(array $form, string $userId): bool {
		return isset($form['_index']['user_ids'][$userId]);
	}

	/**
	 * Get response count
	 */
	public function getResponseCount(array $form): int {
		return $form['_index']['response_count'] ?? count($form['responses'] ?? []);
	}

	/**
	 * Get answer statistics for a question
	 */
	public function getAnswerStats(array $form, string $questionId): array {
		return $form['_index']['answer_counts'][$questionId] ?? [];
	}

	/**
	 * Get responses by date
	 */
	public function getResponsesByDate(array $form, string $date): array {
		$indices = $form['_index']['by_date'][$date] ?? [];
		$responses = [];

		foreach ($indices as $index) {
			if (isset($form['responses'][$index])) {
				$responses[] = $form['responses'][$index];
			}
		}

		return $responses;
	}

	/**
	 * Questions whose answers are counted: those with a fixed set of possible answers, plus files and table rows.
	 *
	 * @return array<string, string> type by question id
	 */
	private function countedQuestions(array $form): array {
		$out = [];
		foreach ($form['questions'] ?? [] as $question) {
			if (isset($question['id']) && in_array($question['type'] ?? '', self::COUNTED_TYPES, true)) {
				$out[(string)$question['id']] = $question['type'];
			}
		}
		return $out;
	}

	private function countAnswers(array &$counts, array $form, array $response): void {
		$counted = $this->countedQuestions($form);
		foreach ($response['answers'] ?? [] as $questionId => $answer) {
			$type = $counted[(string)$questionId] ?? null;
			if ($type === null || $answer === null || $answer === '' || $answer === []) {
				continue;
			}
			$add = function (string $key, int $by = 1) use (&$counts, $questionId): void {
				$counts[$questionId][$key] = ($counts[$questionId][$key] ?? 0) + $by;
			};
			if ($type === 'file') {
				// Multi-file uploads are a list of file objects.
				$add('[file]', is_array($answer) && array_is_list($answer) ? count($answer) : 1);
			} elseif ($type === 'table') {
				$add('[table]', is_array($answer) ? count($answer) : 0);
			} elseif ($type === 'matrix' && is_array($answer)) {
				foreach ($answer as $rowId => $colValue) {
					if (is_scalar($colValue)) {
						$add($rowId . ':' . $colValue);
					}
				}
			} elseif ($type === 'consent') {
				// Yes/No reads better in the summary than "" vs "1" (#94).
				$add($answer === true ? 'Yes' : 'No');
			} elseif (is_array($answer)) {
				// A multiple choice counts each option once (H6).
				foreach (array_unique(array_map('strval', array_filter($answer, 'is_scalar'))) as $value) {
					$add($value);
				}
			} elseif (is_scalar($answer)) {
				$add((string)$answer);
			}
		}
	}
}
