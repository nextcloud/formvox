<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormLogic;
use OCA\FormVox\Service\FormSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * What a respondent sees, worked out on the server exactly as in the browser: both run the same table
 * (tests/fixtures/form-logic-cases.json; the browser side is tests-js/utils/formLogic.spec.js).
 * Review secu#46, test#50; PO 4c.
 */
class FormLogicTest extends TestCase {
	private static function cases(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../fixtures/form-logic-cases.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return array<string, array{array, array, array, bool}>
	 */
	public static function conditions(): array {
		$out = [];
		foreach (self::cases()['conditions'] as $case) {
			$out[$case['name']] = [$case['condition'], $case['answers'], $case['questions'] ?? [], $case['expect']];
		}
		return $out;
	}

	/**
	 * @dataProvider conditions
	 */
	public function testACondition(array $condition, array $answers, array $questions, bool $expect): void {
		$this->assertSame($expect, (new FormLogic())->evaluate($condition, $answers, array_column($questions, null, 'id')));
	}

	/**
	 * @return array<string, array{array, array, list<string>, ?list<string>}>
	 */
	public static function forms(): array {
		$out = [];
		foreach (self::cases()['forms'] as $case) {
			$out[$case['name']] = [$case['form'], $case['answers'], $case['hidden'], $case['pages']];
		}
		return $out;
	}

	/**
	 * @dataProvider forms
	 */
	public function testWhatIsHiddenAndWhichPagesAreVisited(array $form, array $answers, array $hidden, ?array $pages): void {
		$result = (new FormLogic())->analyse($form, $answers);

		$found = array_keys($result['hidden']);
		sort($found);
		sort($hidden);
		$this->assertSame($hidden, $found);
		$this->assertSame($pages, $result['pages']);
	}

	/** A new operator gets cases here before it can be saved, or the browser and the server could tell it apart. */
	public function testEveryOperatorAFormMayUseHasCases(): void {
		$used = array_unique(array_column(array_column(self::cases()['conditions'], 'condition'), 'operator'));
		$this->assertSame([], array_values(array_diff(FormSchemaValidator::OPERATORS, $used)));
	}

	public function testVisibleAnswersLeaveOutHiddenOnes(): void {
		$case = self::forms()['routing skips a page, and what is on it'];

		$this->assertSame(['q1' => 'skip', 'q3' => 'z'], (new FormLogic())->visibleAnswers($case[0], $case[1]));
	}
}
