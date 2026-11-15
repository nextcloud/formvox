<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\AnswerValidator;
use OCA\FormVox\Service\InvalidAnswerException;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * The validation every submission goes through. The cases below were ResponseServiceTest's
 * until the validation moved here; `call()` keeps their old method names.
 */
class AnswerValidatorTest extends TestCase {
	private function validator(): AnswerValidator {
		return new AnswerValidator($this->createMock(UploadService::class), new EnglishL10N());
	}

	/**
	 * validateAnswerType tests predate option checks and question ids: give the question
	 * an id, and options for the values the test answers with, so they keep testing what they did.
	 */
	private function call(string $method, array $args) {
		$validator = $this->validator();
		switch ($method) {
			case 'validateAnswerType':
				[$question, $answer] = $args;
				$question['id'] ??= 'q';
				if (in_array($question['type'] ?? '', ['choice', 'multiple', 'dropdown'], true) && !isset($question['options'])) {
					$values = is_array($answer) ? array_filter($answer, 'is_scalar') : (is_scalar($answer) ? [$answer] : []);
					$question['options'] = array_map(fn ($v) => ['value' => (string)$v, 'label' => (string)$v], array_values($values));
				}
				if ($answer === null || $answer === '' || $answer === []) {
					return null; // validate() never type-checks an empty answer
				}
				return (new \ReflectionMethod(AnswerValidator::class, 'normalize'))->invokeArgs($validator, [$question, $answer, null]);
			case 'validateAnswers':
				return $validator->validate(...$args);
			case 'validateCapacity':
				return $validator->checkCapacity(...$args);
			default:
				return (new \ReflectionMethod(AnswerValidator::class, $method))->invokeArgs($validator, $args);
		}
	}

	// =====================================================================
	// validateAnswerType — #113 limits + type checks
	// =====================================================================

	public function testValidateAnswerTypeNumberRejectsNonNumeric(): void {
		$this->expectException(\RuntimeException::class);
		$this->call('validateAnswerType', [['type' => 'number', 'question' => 'N'], 'abc']);
	}

	public function testValidateAnswerTypeNumberAcceptsNumeric(): void {
		$this->call('validateAnswerType', [['type' => 'number', 'question' => 'N'], '12']);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeNumberAcceptsEmpty(): void {
		$this->call('validateAnswerType', [['type' => 'number', 'question' => 'N'], '']);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeMultipleRequiresArray(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Multiple choice question requires array answer');
		$this->call('validateAnswerType', [['type' => 'multiple', 'question' => 'M'], 'x']);
	}

	public function testValidateAnswerTypeMultipleMinSelections(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Select at least 2 options');
		$this->call('validateAnswerType', [
			['type' => 'multiple', 'question' => 'M', 'minSelections' => 2], ['a'],
		]);
	}

	public function testValidateAnswerTypeMultipleMaxSelections(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Select at most 1 option for');
		$this->call('validateAnswerType', [
			['type' => 'multiple', 'question' => 'M', 'maxSelections' => 1], ['a', 'b'],
		]);
	}

	public function testValidateAnswerTypeMultipleEmptyBypassesMin(): void {
		// Empty answer is left to the required-check; min not enforced on empty.
		$this->call('validateAnswerType', [
			['type' => 'multiple', 'question' => 'M', 'minSelections' => 2], [],
		]);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeMultipleWithinBounds(): void {
		$this->call('validateAnswerType', [
			['type' => 'multiple', 'question' => 'M', 'minSelections' => 1, 'maxSelections' => 3],
			['a', 'b'],
		]);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeTextMaxLength(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('exceeds the 3-character limit');
		$this->call('validateAnswerType', [
			['type' => 'text', 'question' => 'T', 'maxLength' => 3], 'abcd',
		]);
	}

	public function testValidateAnswerTypeTextMaxLengthMultibyte(): void {
		// mb_strlen: 3 umlauts = 3 chars, within limit 3.
		$this->call('validateAnswerType', [
			['type' => 'text', 'question' => 'T', 'maxLength' => 3], 'ööö',
		]);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeTextareaMaxLength(): void {
		$this->expectException(\RuntimeException::class);
		$this->call('validateAnswerType', [
			['type' => 'textarea', 'question' => 'T', 'maxLength' => 2], 'abc',
		]);
	}

	public function testValidateAnswerTypeConsentRejectsNonBool(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Consent question requires boolean answer');
		$this->call('validateAnswerType', [['type' => 'consent', 'question' => 'C'], 'yes']);
	}

	public function testValidateAnswerTypeConsentAcceptsBool(): void {
		$this->call('validateAnswerType', [['type' => 'consent', 'question' => 'C'], true]);
		$this->call('validateAnswerType', [['type' => 'consent', 'question' => 'C'], false]);
		$this->call('validateAnswerType', [['type' => 'consent', 'question' => 'C'], '']);
		$this->call('validateAnswerType', [['type' => 'consent', 'question' => 'C'], null]);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeDateRejectsBadFormat(): void {
		$this->expectException(\RuntimeException::class);
		$this->call('validateAnswerType', [['type' => 'date', 'question' => 'D'], '2020/01/01']);
	}

	public function testValidateAnswerTypeDateAcceptsIso(): void {
		$this->call('validateAnswerType', [['type' => 'date', 'question' => 'D'], '2020-01-01']);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeDatetimeRejectsBadFormat(): void {
		$this->expectException(\RuntimeException::class);
		$this->call('validateAnswerType', [['type' => 'datetime', 'question' => 'D'], '2020-01-01']);
	}

	public function testValidateAnswerTypeDatetimeAccepts(): void {
		$this->call('validateAnswerType', [['type' => 'datetime', 'question' => 'D'], '2020-01-01T10:30']);
		$this->addToAssertionCount(1);
	}

	/** @return array<string, array{string, string, bool}> */
	public static function dates(): array {
		return [
			'a day' => ['date', '2024-02-29', true],
			'no such day' => ['date', '2026-02-31', false],
			'no such month' => ['date', '2026-13-01', false],
			'as the browser sends it' => ['datetime', '2026-01-01T10:00', true],
			'with seconds' => ['datetime', '2026-01-01T10:00:30', true],
			'from JavaScript' => ['datetime', '2026-11-03T23:30:00.000Z', true],
			'from .NET' => ['datetime', '2026-11-03T23:30:00.1234567+01:00', true],
			'more after it' => ['datetime', '2026-01-01T10:00' . str_repeat('9', 20), false],
			'a relative part' => ['datetime', '2026-01-01T10:00 +5 years', false],
			'no such hour' => ['datetime', '2026-01-01T24:00', false],
			'no such day and time' => ['datetime', '2026-02-31T10:00', false],
		];
	}

	/**
	 * Only a real day and moment: PHP's own parser makes 31 February into March, and reads what follows a time.
	 *
	 * @dataProvider dates
	 */
	public function testOnlyARealDateOrMoment(string $type, string $answer, bool $valid): void {
		if (!$valid) {
			$this->expectException(InvalidAnswerException::class);
			$this->expectExceptionMessage($type === 'date' ? 'Invalid date format' : 'Invalid datetime format');
		}
		$this->assertSame($answer, $this->call('validateAnswerType', [['type' => $type, 'question' => 'D'], $answer]));
	}

	/** @return array<string, array{string, string, bool}> */
	public static function numbers(): array {
		return [
			'64 digits' => ['number', str_repeat('1', 64), true],
			'65 digits' => ['number', str_repeat('1', 65), false],
			'a scale padded with zeros' => ['scale', str_repeat('0', 64) . '3', false],
			'a rating padded with zeros' => ['rating', str_repeat('0', 64) . '3', false],
			'a number past what a float holds' => ['number', '1e400', false],
			'a scale past what a float holds' => ['scale', '-1e999', false],
			'a rating past what a float holds' => ['rating', '1e400', false],
			'a number in exponent form' => ['number', '1e3', true],
		];
	}

	/** A JSON number like 1e999 is INF before the validator sees it: refused like '1e400'. */
	public function testANumberThatIsNoFiniteNumberIsRefused(): void {
		foreach (['number', 'scale', 'rating'] as $type) {
			try {
				$this->call('validateAnswerType', [['type' => $type, 'question' => 'N'], INF]);
				$this->fail($type . ' took INF');
			} catch (InvalidAnswerException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * A number as typed, of bounded length: a long one costs the PDF and exports, whatever its value.
	 *
	 * @dataProvider numbers
	 */
	public function testANumberOfBoundedLength(string $type, string $answer, bool $valid): void {
		if (!$valid) {
			$this->expectException(InvalidAnswerException::class);
		}
		$this->assertSame($answer, $this->call('validateAnswerType', [['type' => $type, 'question' => 'N'], $answer]));
	}

	public function testValidateAnswerTypeTimeRejectsBadFormat(): void {
		$this->expectException(\RuntimeException::class);
		$this->call('validateAnswerType', [['type' => 'time', 'question' => 'D'], '25:99:99']);
	}

	public function testValidateAnswerTypeTimeAccepts(): void {
		$this->call('validateAnswerType', [['type' => 'time', 'question' => 'D'], '10:30']);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeCustomPatternRejects(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('custom-error');
		$this->call('validateAnswerType', [[
			'type' => 'text', 'question' => 'T',
			'validation' => ['pattern' => '^\d+$', 'errorMessage' => 'custom-error'],
		], 'abc']);
	}

	public function testValidateAnswerTypeCustomPatternAccepts(): void {
		$this->call('validateAnswerType', [[
			'type' => 'text', 'question' => 'T',
			'validation' => ['pattern' => '^\d+$'],
		], '123']);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeInvalidPatternIsSkipped(): void {
		// A syntactically invalid regex is logged-and-skipped (no throw).
		$this->call('validateAnswerType', [[
			'type' => 'text', 'question' => 'T',
			'validation' => ['pattern' => '('],
		], 'anything']);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswerTypeDateMinRejects(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('before the allowed minimum');
		$this->call('validateAnswerType', [
			['type' => 'date', 'question' => 'D', 'dateMin' => '2020-06-01'], '2020-01-01',
		]);
	}

	public function testValidateAnswerTypeDateMaxRejects(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('after the allowed maximum');
		$this->call('validateAnswerType', [
			['type' => 'date', 'question' => 'D', 'dateMax' => '2020-06-01'], '2020-12-01',
		]);
	}

	public function testValidateAnswerTypeTimeMinRejects(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Time is before the allowed minimum');
		$this->call('validateAnswerType', [
			['type' => 'time', 'question' => 'D', 'timeMin' => '09:00'], '08:00',
		]);
	}

	public function testValidateAnswerTypeTimeMaxRejects(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Time is after the allowed maximum');
		$this->call('validateAnswerType', [
			['type' => 'time', 'question' => 'D', 'timeMax' => '17:00'], '18:00',
		]);
	}

	// =====================================================================
	// validateCapacity — #104
	// =====================================================================

	public function testValidateCapacityThrowsWhenReached(): void {
		$form = [
			'questions' => [[
				'id' => 'q1', 'type' => 'choice',
				'options' => [['value' => 'a', 'label' => 'Slot A', 'capacity' => 2]],
			]],
			'_index' => ['answer_counts' => ['q1' => ['a' => 2]]],
		];
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("The option 'Slot A' is no longer available");
		$this->call('validateCapacity', [$form, ['q1' => 'a']]);
	}

	public function testValidateCapacityPassesUnderLimit(): void {
		$form = [
			'questions' => [[
				'id' => 'q1', 'type' => 'choice',
				'options' => [['value' => 'a', 'capacity' => 5]],
			]],
			'_index' => ['answer_counts' => ['q1' => ['a' => 1]]],
		];
		$this->call('validateCapacity', [$form, ['q1' => 'a']]);
		$this->addToAssertionCount(1);
	}

	public function testValidateCapacityUnlimitedWhenNoCapacity(): void {
		$form = [
			'questions' => [[
				'id' => 'q1', 'type' => 'choice',
				'options' => [['value' => 'a']],
			]],
			'_index' => ['answer_counts' => ['q1' => ['a' => 999]]],
		];
		$this->call('validateCapacity', [$form, ['q1' => 'a']]);
		$this->addToAssertionCount(1);
	}

	public function testValidateCapacityIgnoresUnselectedOptions(): void {
		$form = [
			'questions' => [[
				'id' => 'q1', 'type' => 'choice',
				'options' => [
					['value' => 'a', 'capacity' => 1],
					['value' => 'b', 'capacity' => 1],
				],
			]],
			'_index' => ['answer_counts' => ['q1' => ['a' => 5]]],
		];
		// Selecting 'b' (still open) even though 'a' is full.
		$this->call('validateCapacity', [$form, ['q1' => 'b']]);
		$this->addToAssertionCount(1);
	}

	public function testValidateCapacitySkipsNonChoiceTypes(): void {
		$form = [
			'questions' => [['id' => 'q1', 'type' => 'text']],
			'_index' => ['answer_counts' => []],
		];
		$this->call('validateCapacity', [$form, ['q1' => 'whatever']]);
		$this->addToAssertionCount(1);
	}

	// =====================================================================
	// validateAnswers — required + hidden + unknown
	// =====================================================================

	public function testValidateAnswersRequiredMissingThrows(): void {
		$form = ['questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name', 'required' => true]]];
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("Question 'Name' is required");
		$this->call('validateAnswers', [$form, []]);
	}

	public function testValidateAnswersRequiredConsentNeedsTrue(): void {
		$form = ['questions' => [['id' => 'q1', 'type' => 'consent', 'question' => 'Agree', 'required' => true]]];
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("Question 'Agree' is required");
		$this->call('validateAnswers', [$form, ['q1' => false]]);
	}

	public function testValidateAnswersRequiredConsentTruePasses(): void {
		$form = ['questions' => [['id' => 'q1', 'type' => 'consent', 'question' => 'Agree', 'required' => true]]];
		$this->call('validateAnswers', [$form, ['q1' => true]]);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswersSkipsSectionAndDescriptor(): void {
		$form = ['questions' => [
			['id' => 's1', 'type' => 'section', 'question' => 'Sec', 'required' => true],
			['id' => 'd1', 'type' => 'descriptor', 'question' => 'Desc', 'required' => true],
		]];
		$this->call('validateAnswers', [$form, []]);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswersSkipsHiddenRequired(): void {
		// q2 required but hidden because its showIf references q1='yes' (not met).
		$form = ['questions' => [
			['id' => 'q1', 'type' => 'text', 'question' => 'Q1'],
			['id' => 'q2', 'type' => 'text', 'question' => 'Q2', 'required' => true,
				'showIf' => ['questionId' => 'q1', 'operator' => 'equals', 'value' => 'yes']],
		]];
		$this->call('validateAnswers', [$form, ['q1' => 'no']]);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswersSkipsUnknownQuestion(): void {
		$form = ['questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q1']]];
		// 'unknown' isn't a question → skipped, no type validation error.
		$this->call('validateAnswers', [$form, ['unknown' => 'x', 'q1' => 'ok']]);
		$this->addToAssertionCount(1);
	}

	public function testValidateAnswersBubblesTypeError(): void {
		$form = ['questions' => [['id' => 'q1', 'type' => 'number', 'question' => 'Num']]];
		$this->expectException(\RuntimeException::class);
		$this->call('validateAnswers', [$form, ['q1' => 'abc']]);
	}

	// =====================================================================
	// What the respondent could see (FormLogic; the conditions themselves are in FormLogicTest)
	// =====================================================================

	private function routedForm(): array {
		return [
			'questions' => [
				['id' => 'q1', 'type' => 'text', 'question' => 'Route'],
				['id' => 'q2', 'type' => 'text', 'question' => 'On the skipped page', 'required' => true],
				['id' => 'q3', 'type' => 'text', 'question' => 'Last'],
			],
			'pages' => [
				['id' => 'p1', 'questions' => ['q1'], 'routing' => [['questionId' => 'q1', 'operator' => 'equals', 'value' => 'skip', 'targetPageId' => 'p3']]],
				['id' => 'p2', 'questions' => ['q2']],
				['id' => 'p3', 'questions' => ['q3']],
			],
		];
	}

	/** secu#46: the respondent never saw it, so it cannot be required. */
	public function testARequiredQuestionOnASkippedPageDoesNotBlock(): void {
		$this->assertSame(['q1' => 'skip', 'q3' => 'z'], $this->validator()->validate($this->routedForm(), ['q1' => 'skip', 'q3' => 'z']));
	}

	public function testOnAPageThatIsVisitedItIsRequired(): void {
		$this->expectException(InvalidAnswerException::class);
		$this->validator()->validate($this->routedForm(), ['q1' => 'stay', 'q3' => 'z']);
	}

	/** test#50: typed on a page that was skipped after going back, so not stored. */
	public function testAnAnswerOnASkippedPageIsNotStored(): void {
		$this->assertSame(['q1' => 'skip', 'q3' => 'z'], $this->validator()->validate($this->routedForm(), ['q1' => 'skip', 'q2' => 'typed before going back', 'q3' => 'z']));
	}

	public function testAnAnswerOnAHiddenQuestionIsNotStoredNorChecked(): void {
		$form = ['questions' => [
			['id' => 'q1', 'type' => 'text', 'question' => 'Has a website'],
			['id' => 'q2', 'type' => 'number', 'question' => 'Visitors', 'showIf' => ['questionId' => 'q1', 'operator' => 'equals', 'value' => 'yes']],
		]];

		// Not a number, but the question was hidden: dropped, not refused.
		$this->assertSame(['q1' => 'no'], $this->validator()->validate($form, ['q1' => 'no', 'q2' => 'abc']));
	}

	public function testAHiddenSectionHidesItsRequiredQuestions(): void {
		$form = ['questions' => [
			['id' => 'q1', 'type' => 'text', 'question' => 'Member'],
			['id' => 's1', 'type' => 'section', 'showIf' => ['questionId' => 'q1', 'operator' => 'equals', 'value' => 'yes']],
			['id' => 'q2', 'type' => 'text', 'question' => 'Member number', 'sectionId' => 's1', 'required' => true],
		]];

		$this->assertSame(['q1' => 'no'], $this->validator()->validate($form, ['q1' => 'no']));
	}

	// =====================================================================
	// What the validator adds (H6, M26, L40, T43)
	// =====================================================================

	private function form(array $questions): array {
		return ['questions' => $questions];
	}

	private function choiceQuestion(string $type = 'choice', array $extra = []): array {
		return ['id' => 'c', 'type' => $type, 'question' => 'Colour', 'options' => [['value' => 'red'], ['value' => 'blue'], ['id' => 'green']]] + $extra;
	}

	private function rejects(array $form, array $answers, string $question = 'c', ?callable $formFile = null): void {
		try {
			$this->validator()->validate($form, $answers, $formFile);
			$this->fail('expected the answer to be refused');
		} catch (InvalidAnswerException $e) {
			$this->assertSame($question, $e->questionId);
		}
	}

	public function testAnswersToQuestionsThatDoNotExistOrTakeNoAnswerAreDropped(): void {
		$form = $this->form([['id' => 't', 'type' => 'text'], ['id' => 's', 'type' => 'section']]);

		$this->assertSame(['t' => 'hi'], $this->validator()->validate($form, ['t' => 'hi', 's' => 'x', 'invented' => 'y']));
	}

	public function testAChoiceMustBeOneOfItsOptions(): void {
		$form = $this->form([$this->choiceQuestion()]);

		$this->assertSame(['c' => 'green'], $this->validator()->validate($form, ['c' => 'green']));
		$this->rejects($form, ['c' => 'purple']);
		$this->rejects($form, ['c' => ['red']]);
	}

	public function testAMultipleChoicePicksEachOptionOnce(): void {
		$form = $this->form([$this->choiceQuestion('multiple', ['maxSelections' => 2])]);

		$this->assertSame(['c' => ['red', 'blue']], $this->validator()->validate($form, ['c' => ['red', 'red', 'red', 'blue']]));
		$this->rejects($form, ['c' => [['nested' => 'red']]]);
		$this->rejects($form, ['c' => ['red', 'blue', 'green']]);
	}

	public function testDatesAndTimesMustBeTextInTheirFormat(): void {
		$form = $this->form([['id' => 'd', 'type' => 'date', 'question' => 'D'], ['id' => 'h', 'type' => 'time', 'question' => 'H']]);

		$this->assertSame(['d' => '2026-10-06', 'h' => '09:30'], $this->validator()->validate($form, ['d' => '2026-10-06', 'h' => '09:30']));
		$this->rejects($form, ['d' => ['2026-10-06']], 'd');
		$this->rejects($form, ['h' => '9.30'], 'h');
	}

	public function testScaleAndRatingStayWithinTheirPoints(): void {
		$form = $this->form([['id' => 's', 'type' => 'scale', 'scaleMax' => 10], ['id' => 'r', 'type' => 'rating']]);

		$this->assertSame(['s' => 10, 'r' => '3'], $this->validator()->validate($form, ['s' => 10, 'r' => '3']));
		$this->rejects($form, ['s' => 11], 's');
		$this->rejects($form, ['r' => 6], 'r');
		$this->rejects($form, ['r' => 2.5], 'r');
	}

	/** As on the page: a required matrix needs every row, a required table a row with something in it. */
	public function testARequiredMatrixNeedsEveryRowAndARequiredTableARowWithContent(): void {
		$matrix = $this->form([['id' => 'm', 'type' => 'matrix', 'question' => 'Grid', 'required' => true,
			'rows' => [['id' => 'r1'], ['id' => 'r2']], 'columns' => [['value' => 'yes'], ['value' => 'no']]]]);
		$this->assertSame(['m' => ['r1' => 'yes', 'r2' => 'no']], $this->validator()->validate($matrix, ['m' => ['r1' => 'yes', 'r2' => 'no']]));
		try {
			$this->validator()->validate($matrix, ['m' => ['r1' => 'yes']]);
			$this->fail('A required matrix with a row left open was taken');
		} catch (InvalidAnswerException $e) {
			$this->assertSame("Please answer all rows of question 'Grid'", $e->getMessage());
			$this->assertSame('m', $e->questionId);
		}

		$table = $this->form([['id' => 't', 'type' => 'table', 'question' => 'People', 'required' => true, 'columns' => [['id' => 'name']]]]);
		$this->assertSame(['t' => [['name' => 'Anna']]], $this->validator()->validate($table, ['t' => [['name' => 'Anna']]]));
		$this->expectException(InvalidAnswerException::class);
		$this->expectExceptionMessage("Question 'People' is required");
		$this->validator()->validate($table, ['t' => [['name' => ''], ['name' => '']]]);
	}

	/**
	 * Every pattern the editor offers (QuestionEditor presets), read on the server as the browser reads it.
	 * The URL preset was never checked: the old '/' delimiter broke on its \/.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function editorPresets(): array {
		return [
			'email' => ['^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$', 'anna@example.com', 'anna@'],
			'phone_nl' => ['^(\+31|0)[1-9][0-9]{8}$', '0612345678', '12345'],
			'phone_intl' => ['^\+?[1-9]\d{6,14}$', '+31612345678', '+0'],
			'postal_nl' => ['^[1-9][0-9]{3}\s?[A-Za-z]{2}$', '1234 AB', 'AB 1234'],
			'postal_be' => ['^[1-9][0-9]{3}$', '1000', '100'],
			'url' => ['^https?:\/\/[\w\-]+(\.[\w\-]+)+[/#?]?.*$', 'https://example.com/path', 'not a url'],
			'digits_only' => ['^[0-9]+$', '123', '12a'],
			'letters_only' => ['^[a-zA-Z]+$', 'abc', 'ab1'],
			'alphanumeric' => ['^[a-zA-Z0-9]+$', 'ab1', 'ab 1'],
			'unicode length, as in the browser' => ['^.{0,3}$', 'ééé', 'éééé'],
		];
	}

	/**
	 * @dataProvider editorPresets
	 */
	public function testEveryEditorPatternIsCheckedOnTheServerToo(string $pattern, string $good, string $bad): void {
		$form = $this->form([['id' => 't', 'type' => 'text', 'question' => 'Field', 'validation' => ['pattern' => $pattern]]]);

		$this->assertSame(['t' => $good], $this->validator()->validate($form, ['t' => $good]));
		$this->expectException(InvalidAnswerException::class);
		$this->validator()->validate($form, ['t' => $bad]);
	}

	public function testATextWithoutItsOwnLimitIsCappedAndAnOwnLimitWins(): void {
		$form = $this->form([['id' => 't', 'type' => 'textarea'], ['id' => 'long', 'type' => 'textarea', 'maxLength' => 20000]]);

		$this->rejects($form, ['t' => str_repeat('a', AnswerValidator::MAX_TEXT_LENGTH + 1)], 't');
		$this->assertSame(15000, mb_strlen($this->validator()->validate($form, ['long' => str_repeat('a', 15000)])['long']));
	}

	public function testOneSubmissionHasAMaximumSize(): void {
		$form = $this->form(array_map(fn ($i) => ['id' => "t$i", 'type' => 'textarea'], range(1, 30)));
		$answers = array_fill_keys(array_map(fn ($i) => "t$i", range(1, 30)), str_repeat('a', 9000));

		$this->expectException(InvalidAnswerException::class);
		$this->expectExceptionMessage('The answers are too large');
		$this->validator()->validate($form, $answers);
	}

	public function testAMatrixOnlyHoldsItsOwnRowsAndColumns(): void {
		$form = $this->form([['id' => 'm', 'type' => 'matrix', 'rows' => [['id' => 'r1'], ['id' => 'r2']], 'columns' => [['value' => '1'], ['id' => '2']]]]);

		$this->assertSame(['m' => ['r1' => '1', 'r2' => '2']], $this->validator()->validate($form, ['m' => ['r1' => '1', 'r2' => '2']]));
		$this->rejects($form, ['m' => ['r3' => '1']], 'm');
		$this->rejects($form, ['m' => ['r1' => '9']], 'm');
	}

	public function testATableHasItsOwnColumnsAndAMaximumOfRows(): void {
		$form = $this->form([['id' => 'tb', 'type' => 'table', 'maxRows' => 2, 'columns' => [['id' => 'a']]]]);

		$this->assertSame(['tb' => [['a' => 'x']]], $this->validator()->validate($form, ['tb' => [['a' => 'x']]]));
		$this->rejects($form, ['tb' => [['a' => 'x'], ['a' => 'y'], ['a' => 'z']]], 'tb');
		$this->rejects($form, ['tb' => [['b' => 'x']]], 'tb');
	}

	public function testConsentIsTrueOrFalse(): void {
		$form = $this->form([['id' => 'ok', 'type' => 'consent']]);

		$this->rejects($form, ['ok' => 'yes'], 'ok');
	}

	// ---- file answers: a real upload, described by Nextcloud (L40, H5) ----

	private const UPLOAD = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

	private function fileValidator(?File $upload): AnswerValidator {
		$uploads = $this->createMock(UploadService::class);
		$uploads->method('getUpload')->willReturnCallback(function (File $form, string $responseId, string $name) use ($upload) {
			if ($upload === null || $name !== 'cv.pdf') {
				throw new NotFoundException('Upload not found');
			}
			return $upload;
		});
		return new AnswerValidator($uploads, new EnglishL10N());
	}

	private function upload(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(77);
		$file->method('getName')->willReturn('cv.pdf');
		$file->method('getSize')->willReturn(1234);
		$file->method('getMimetype')->willReturn('application/pdf');
		return $file;
	}

	public function testAFileAnswerIsDescribedByNextcloudNotByTheRequest(): void {
		$form = $this->form([['id' => 'f', 'type' => 'file', 'question' => 'CV']]);
		$claimed = ['responseId' => self::UPLOAD, 'filename' => 'cv.pdf', 'originalName' => '../My CV.pdf', 'size' => 1, 'mimeType' => 'text/html', 'fileId' => 1];

		$stored = $this->fileValidator($this->upload())->validate($form, ['f' => $claimed], fn () => $this->createMock(File::class));

		// '../My CV.pdf' is not the name this file was stored under, so the stored name is shown instead.
		$this->assertSame(['fileId' => 77, 'filename' => 'cv.pdf', 'originalName' => 'cv.pdf', 'size' => 1234, 'mimeType' => 'application/pdf', 'responseId' => self::UPLOAD], $stored['f']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function namesTheBrowserGives(): array {
		return [
			'the name it was stored under' => ['cv.pdf', 'cv.pdf'],
			'with the characters the server replaced' => ['cv .pdf', 'cv .pdf'],
			'another file\'s name' => ['invoice.pdf', 'cv.pdf'],
			'a different extension' => ['cv.exe', 'cv.pdf'],
			'a path' => ['dir/cv.pdf', 'cv.pdf'],
		];
	}

	/**
	 * The browser's name is kept only where it is the name the file was stored under.
	 *
	 * @dataProvider namesTheBrowserGives
	 */
	public function testTheFileNameShownIsTheBrowsersOnlyWhenItIsTheStoredFile(string $claimed, string $shown): void {
		$form = $this->form([['id' => 'f', 'type' => 'file', 'question' => 'CV']]);

		$stored = $this->fileValidator($this->upload())->validate($form, ['f' => ['responseId' => self::UPLOAD, 'filename' => 'cv.pdf', 'originalName' => $claimed]], fn () => $this->createMock(File::class));

		$this->assertSame($shown, $stored['f']['originalName']);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function fileAnswersThatAreNoUpload(): array {
		return [
			'empty upload id' => [['responseId' => '', 'filename' => 'cv.pdf']],
			'path as upload id' => [['responseId' => '../other', 'filename' => 'cv.pdf']],
			'path as file name' => [['responseId' => self::UPLOAD, 'filename' => '../cv.pdf']],
			'a file that was never uploaded' => [['responseId' => self::UPLOAD, 'filename' => 'other.pdf']],
			'more files than allowed' => [[['responseId' => self::UPLOAD, 'filename' => 'cv.pdf'], ['responseId' => self::UPLOAD, 'filename' => 'cv.pdf']]],
			'not a file answer at all' => ['cv.pdf'],
		];
	}

	/**
	 * @dataProvider fileAnswersThatAreNoUpload
	 */
	public function testAFileAnswerMustNameARealUpload(mixed $answer): void {
		$form = $this->form([['id' => 'f', 'type' => 'file', 'question' => 'CV']]);

		$this->expectException(InvalidAnswerException::class);
		$this->fileValidator($this->upload())->validate($form, ['f' => $answer], fn () => $this->createMock(File::class));
	}
}
