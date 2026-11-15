<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\SubmissionIds;
use PHPUnit\Framework\TestCase;

/**
 * A retry is recognised (7a, 3a-5): only ids the browser makes, only a response that is still there counts, and only
 * with the same answers (review 3a-5: an id reused by another fill must not swallow its answers). Read here from a form
 * still in its file.
 */
class SubmissionIdsTest extends TestCase {
	private const ID = '0f8b6c1e-aaaa-4bbb-8ccc-123456789abc';

	/** @param array<string, string> $submissions response id by submission, as a form file keeps them */
	private static function with(array $form, array $submissions): array {
		$form[SubmissionIds::KEY] = $submissions;
		return $form;
	}

	public function testOnlyIdsTheBrowserMakes(): void {
		$this->assertTrue(SubmissionIds::isValid(self::ID));
		foreach ([null, '', 'short', '../../etc/passwd!!', str_repeat('a', 65), "line\nbreak-000000000"] as $id) {
			$this->assertFalse(SubmissionIds::isValid($id), (string)$id);
			$this->assertNull(SubmissionIds::of($id, []));
		}
	}

	public function testARetryWithTheSameAnswersIsRecognised(): void {
		$form = self::with(['responses' => [['id' => 'r1']]], [SubmissionIds::of(self::ID, ['q1' => 'a']) => 'r1']);

		$this->assertSame(['id' => 'r1'], SubmissionIds::stored($form, SubmissionIds::of(self::ID, ['q1' => 'a'])));
	}

	/** The same id with other answers is another fill: stored as such, never answered with the first one's id. */
	public function testTheSameIdWithOtherAnswersIsAnotherFill(): void {
		$form = self::with(['responses' => [['id' => 'r1']]], [SubmissionIds::of(self::ID, ['q1' => 'a']) => 'r1']);

		$this->assertNull(SubmissionIds::stored($form, SubmissionIds::of(self::ID, ['q1' => 'b'])));
	}

	/**
	 * One id with answers that take turns: each set is recognised as stored, so a retry pass for one of them never stores
	 * it twice (review of the 3a fixes: only the last answers of an id were kept).
	 */
	public function testEachAnswerOfOneIdIsRecognised(): void {
		$form = self::with(['responses' => [['id' => 'r1'], ['id' => 'r2']]], [
			SubmissionIds::of(self::ID, ['q1' => 'a']) => 'r1',
			SubmissionIds::of(self::ID, ['q1' => 'b']) => 'r2',
		]);

		$this->assertSame(['id' => 'r1'], SubmissionIds::stored($form, SubmissionIds::of(self::ID, ['q1' => 'a'])));
		$this->assertSame(['id' => 'r2'], SubmissionIds::stored($form, SubmissionIds::of(self::ID, ['q1' => 'b'])));
	}

	public function testAResponseDeletedSinceIsNoStoredSubmission(): void {
		$form = self::with(['responses' => [['id' => 'r2']]], [
			SubmissionIds::of('sub-0000000000000001', []) => 'r1',
			SubmissionIds::of('sub-0000000000000002', []) => 'r2',
		]);

		$this->assertNull(SubmissionIds::stored($form, SubmissionIds::of('sub-0000000000000001', [])));
		$this->assertSame(['id' => 'r2'], SubmissionIds::stored($form, SubmissionIds::of('sub-0000000000000002', [])));
		$this->assertNull(SubmissionIds::stored($form, null));
	}
}
