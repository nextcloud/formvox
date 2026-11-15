<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\IndexService;
use PHPUnit\Framework\TestCase;

/**
 * Only answers from a fixed set are counted; typed text never lands in the index (H6).
 */
class IndexServiceTest extends TestCase {
	private function form(array $responses = []): array {
		return [
			'questions' => [
				['id' => 'name', 'type' => 'text'],
				['id' => 'colour', 'type' => 'choice'],
				['id' => 'tags', 'type' => 'multiple'],
				['id' => 'ok', 'type' => 'consent'],
				['id' => 'grid', 'type' => 'matrix'],
				['id' => 'rows', 'type' => 'table'],
				['id' => 'cv', 'type' => 'file'],
				['id' => 'age', 'type' => 'number'],
			],
			'responses' => $responses,
		];
	}

	private function response(array $answers): array {
		return ['id' => 'r', 'submitted_at' => '2026-10-06T10:00:00+00:00', 'respondent' => ['type' => 'user', 'user_id' => 'u'], 'answers' => $answers];
	}

	private function answers(): array {
		return [
			'name' => str_repeat('spam ', 1000),
			'colour' => 'red',
			'tags' => ['a', 'a', 'a', 'b'],
			'ok' => true,
			'grid' => ['r1' => '2'],
			'rows' => [['c' => 'x'], ['c' => 'y']],
			'cv' => [['responseId' => 'x', 'filename' => 'a.pdf'], ['responseId' => 'x', 'filename' => 'b.pdf']],
			'age' => '42',
			'unknown' => 'junk',
		];
	}

	public function testOnlyFixedAnswersFilesAndTableRowsAreCounted(): void {
		$form = $this->form([$this->response($this->answers())]);

		(new IndexService())->rebuildIndex($form);

		$this->assertSame([
			'colour' => ['red' => 1],
			'tags' => ['a' => 1, 'b' => 1],
			'ok' => ['Yes' => 1],
			'grid' => ['r1:2' => 1],
			'rows' => ['[table]' => 2],
			'cv' => ['[file]' => 2],
		], $form['_index']['answer_counts']);
	}

	/** Nothing read the checksum, and hashing every response was half the cost of a submission (spike M). */
	public function testTheIndexKeepsNoChecksumOfAllResponses(): void {
		$form = $this->form([$this->response($this->answers())]);
		$form['_index'] = ['_checksum' => 'from an older version', 'response_count' => 1, 'answer_counts' => []];

		(new IndexService())->rebuildIndex($form);
		$this->assertArrayNotHasKey('_checksum', $form['_index']);
	}
}
