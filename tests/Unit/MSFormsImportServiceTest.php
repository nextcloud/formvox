<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\MicrosoftFormsApiClient;
use OCA\FormVox\Service\MSFormsImportService;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Characterization tests for MSFormsImportService — pins the pure MS Forms ->
 * FormVox transformation logic: question-type mapping, branching resolution,
 * matrix grouping, page grouping, response import shape, and id generation.
 *
 * Everything is mocked. The apiClient returns canned MS payloads; the
 * FormService records the questions/pages/responses handed to it. These tests
 * assert the CURRENT behaviour, not desired behaviour.
 */
class MSFormsImportServiceTest extends TestCase {
	private FormRepository $formRepository;
	private ResponsePersistenceService $responsePersistence;
	private MicrosoftFormsApiClient $apiClient;
	private ISecureRandom $secureRandom;
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->responsePersistence = $this->createMock(ResponsePersistenceService::class);
		$this->apiClient = $this->createMock(MicrosoftFormsApiClient::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(): MSFormsImportService {
		return new MSFormsImportService(
			$this->formRepository,
			$this->responsePersistence,
			$this->apiClient,
			$this->secureRandom,
			$this->logger
		);
	}

	/**
	 * Make secureRandom->generate() deterministic: return a fixed marker per
	 * call so ids are stable and inspectable. All generated ids begin with the
	 * caller's prefix ('q', 'p', or the responder fingerprint fragment).
	 */
	private function stubSecureRandom(string $token = 'AAAAAAAA'): void {
		$this->secureRandom->method('generate')->willReturn($token);
	}

	// =====================================================================
	// mapQuestionType() — one test per MS type branch
	// =====================================================================

	public function testMapChoiceSingleSelection(): void {
		$this->stubSecureRandom();
		$result = $this->service()->mapQuestionType([
			'id' => 'ms1',
			'msType' => 'Question.Choice',
			'title' => 'Pick one',
			'subtitle' => 'sub',
			'isRequired' => true,
			'order' => 3,
			'allowMultipleSelection' => false,
			'choices' => [
				['text' => 'Red', 'value' => 'r'],
				['text' => 'Blue'],
			],
		]);

		$q = $result['question'];
		$this->assertSame('choice', $q['type']);
		$this->assertStringStartsWith('q', $q['id']);
		$this->assertSame('Pick one', $q['question']);
		$this->assertSame('sub', $q['description']);
		$this->assertTrue($q['required']);
		$this->assertSame(3, $q['order']);
		$this->assertSame([
			['id' => 'opt1', 'label' => 'Red', 'value' => 'r'],
			['id' => 'opt2', 'label' => 'Blue', 'value' => 'Blue'],
		], $q['options']);
		$this->assertArrayNotHasKey('allowOther', $q);
		$this->assertSame('ms1', $q['_msQuestionId']);
		$this->assertSame([], $result['warnings']);
	}

	public function testMapChoiceMultipleSelectionWithOtherAndBranchTarget(): void {
		$this->stubSecureRandom();
		$result = $this->service()->mapQuestionType([
			'id' => 'ms2',
			'msType' => 'Question.Choice',
			'title' => 'Pick many',
			'allowMultipleSelection' => true,
			'allowOther' => true,
			'choices' => [
				['text' => 'A', 'branchTarget' => 'msTarget'],
			],
		]);

		$q = $result['question'];
		$this->assertSame('multiple', $q['type']);
		$this->assertTrue($q['allowOther']);
		$this->assertSame('msTarget', $q['options'][0]['_msBranchTarget']);
		$this->assertSame('A', $q['options'][0]['value']);
	}

	public function testMapTextSingleLine(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms3',
			'msType' => 'Question.Text',
			'title' => 'Name',
		])['question'];

		$this->assertSame('text', $q['type']);
		$this->assertArrayNotHasKey('maxLength', $q);
		$this->assertSame('', $q['description']);
		$this->assertFalse($q['required']);
		$this->assertSame(0, $q['order']);
	}

	public function testMapTextMultilineWithMaxLength(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms4',
			'msType' => 'Question.TextField',
			'title' => 'Bio',
			'isMultiline' => true,
			'maxLength' => 500,
		])['question'];

		$this->assertSame('textarea', $q['type']);
		$this->assertSame(500, $q['maxLength']);
	}

	public function testMapRatingStar(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms5',
			'msType' => 'Question.Rating',
			'ratingType' => 'Star',
			'ratingScale' => 7,
		])['question'];

		$this->assertSame('rating', $q['type']);
		$this->assertSame(7, $q['ratingMax']);
	}

	public function testMapRatingStarDefaultsScaleToFive(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms5b',
			'msType' => 'Question.Rating',
		])['question'];

		// ratingType defaults to 'Star'
		$this->assertSame('rating', $q['type']);
		$this->assertSame(5, $q['ratingMax']);
	}

	public function testMapRatingNonStarBecomesScale(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms6',
			'msType' => 'Question.Rating',
			'ratingType' => 'Number',
			'ratingScale' => 8,
			'ratingLowLabel' => 'Low',
			'ratingHighLabel' => 'High',
		])['question'];

		$this->assertSame('scale', $q['type']);
		$this->assertSame(1, $q['scaleMin']);
		$this->assertSame(8, $q['scaleMax']);
		$this->assertSame('Low', $q['scaleMinLabel']);
		$this->assertSame('High', $q['scaleMaxLabel']);
	}

	public function testMapDateWithoutTime(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms7',
			'msType' => 'Question.Date',
		])['question'];
		$this->assertSame('date', $q['type']);
	}

	public function testMapDateWithTime(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms8',
			'msType' => 'Question.Date',
			'includeTime' => true,
		])['question'];
		$this->assertSame('datetime', $q['type']);
	}

	public function testMapLikertBecomesMatrix(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms9',
			'msType' => 'Question.Likert',
			'statements' => [['text' => 'Stmt A'], ['text' => 'Stmt B']],
			'options' => [['text' => 'Agree'], ['text' => 'Disagree']],
		])['question'];

		$this->assertSame('matrix', $q['type']);
		$this->assertSame([
			['id' => 'r1', 'label' => 'Stmt A'],
			['id' => 'r2', 'label' => 'Stmt B'],
		], $q['rows']);
		$this->assertSame([
			['id' => 'c1', 'label' => 'Agree', 'value' => '1'],
			['id' => 'c2', 'label' => 'Disagree', 'value' => '2'],
		], $q['columns']);
	}

	public function testMapRankingBecomesMatrixWithNumberedColumnsAndWarning(): void {
		$this->stubSecureRandom();
		$result = $this->service()->mapQuestionType([
			'id' => 'ms10',
			'msType' => 'Question.Ranking',
			'title' => 'Rank these',
			'items' => [['text' => 'One'], ['text' => 'Two'], ['text' => 'Three']],
		]);
		$q = $result['question'];

		$this->assertSame('matrix', $q['type']);
		$this->assertSame([
			['id' => 'r1', 'label' => 'One'],
			['id' => 'r2', 'label' => 'Two'],
			['id' => 'r3', 'label' => 'Three'],
		], $q['rows']);
		$this->assertSame([
			['id' => 'c1', 'label' => '1', 'value' => '1'],
			['id' => 'c2', 'label' => '2', 'value' => '2'],
			['id' => 'c3', 'label' => '3', 'value' => '3'],
		], $q['columns']);
		$this->assertCount(1, $result['warnings']);
		$this->assertStringContainsString('Ranking converted to matrix', $result['warnings'][0]);
	}

	public function testMapNetPromoterScore(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms11',
			'msType' => 'Question.Net Promoter Score',
		])['question'];

		$this->assertSame('scale', $q['type']);
		$this->assertSame(0, $q['scaleMin']);
		$this->assertSame(10, $q['scaleMax']);
		$this->assertSame('Not at all likely', $q['scaleMinLabel']);
		$this->assertSame('Extremely likely', $q['scaleMaxLabel']);
	}

	public function testMapNetPromoterScoreCustomLabels(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms11b',
			'msType' => 'Question.Net Promoter Score',
			'lowLabel' => 'Nope',
			'highLabel' => 'Yep',
		])['question'];

		$this->assertSame('Nope', $q['scaleMinLabel']);
		$this->assertSame('Yep', $q['scaleMaxLabel']);
	}

	public function testMapFileWithWarning(): void {
		$this->stubSecureRandom();
		$result = $this->service()->mapQuestionType([
			'id' => 'ms12',
			'msType' => 'Question.File',
			'title' => 'Upload',
			'allowedFileTypes' => ['pdf', 'docx'],
			'maxFileSize' => 1048576,
		]);
		$q = $result['question'];

		$this->assertSame('file', $q['type']);
		$this->assertSame(['pdf', 'docx'], $q['allowedTypes']);
		$this->assertSame(1048576, $q['maxFileSize']);
		$this->assertCount(1, $result['warnings']);
		$this->assertStringContainsString('cannot be migrated', $result['warnings'][0]);
	}

	public function testMapFileDefaultsAllowedTypesToEmpty(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms12b',
			'msType' => 'Question.File',
		])['question'];

		$this->assertSame([], $q['allowedTypes']);
		$this->assertArrayNotHasKey('maxFileSize', $q);
	}

	public function testMapUnknownTypeDefaultsToTextWithWarning(): void {
		$this->stubSecureRandom();
		$result = $this->service()->mapQuestionType([
			'id' => 'ms13',
			'msType' => 'Question.Bogus',
			'title' => 'Weird',
		]);
		$q = $result['question'];

		$this->assertSame('text', $q['type']);
		$this->assertCount(1, $result['warnings']);
		$this->assertStringContainsString("Unknown type 'Question.Bogus'", $result['warnings'][0]);
	}

	public function testMapDefaultsMsTypeToTextWhenMissing(): void {
		$this->stubSecureRandom();
		// No msType at all -> defaults to 'Question.Text' (not the unknown branch)
		$result = $this->service()->mapQuestionType([
			'id' => 'ms14',
			'title' => 'Untyped',
		]);
		$q = $result['question'];

		$this->assertSame('text', $q['type']);
		$this->assertSame([], $result['warnings']);
	}

	public function testMapStoresTemporaryBranchingMetadata(): void {
		$this->stubSecureRandom();
		$q = $this->service()->mapQuestionType([
			'id' => 'ms15',
			'msType' => 'Question.Text',
			'branchingRules' => ['Rules' => [['x' => 1]]],
			'formulaInfo' => ['Formula' => 'a == b'],
			'showIf' => ['questionId' => 'z'],
			'branchInfo' => ['TargetQuestionId' => 't'],
		])['question'];

		$this->assertSame(['Rules' => [['x' => 1]]], $q['_msBranchingRules']);
		$this->assertSame(['Formula' => 'a == b'], $q['_msFormulaInfo']);
		$this->assertSame(['questionId' => 'z'], $q['_msShowIf']);
		$this->assertSame(['TargetQuestionId' => 't'], $q['_msBranchInfo']);
	}

	// =====================================================================
	// applyBranchingRules() — showIf resolution & temp-field cleanup
	// =====================================================================

	public function testApplyBranchingStripsTemporaryFields(): void {
		$questions = [[
			'id' => 'q1',
			'type' => 'text',
			'_msQuestionId' => 'ms1',
			'_msBranchingRules' => ['Rules' => []],
			'_msFormulaInfo' => ['Formula' => 'x'],
			'_msShowIf' => ['questionId' => 'y'],
			'_msBranchInfo' => ['TargetQuestionId' => 't'],
			'options' => [['id' => 'opt1', 'value' => 'A', '_msBranchTarget' => 'foo']],
		]];

		$out = $this->service()->applyBranchingRules($questions, [], []);
		$q = $out[0];

		$this->assertArrayNotHasKey('_msQuestionId', $q);
		$this->assertArrayNotHasKey('_msBranchingRules', $q);
		$this->assertArrayNotHasKey('_msFormulaInfo', $q);
		$this->assertArrayNotHasKey('_msShowIf', $q);
		$this->assertArrayNotHasKey('_msBranchInfo', $q);
		$this->assertArrayNotHasKey('_msBranchTarget', $q['options'][0]);
		$this->assertArrayNotHasKey('showIf', $q);
	}

	public function testApplyBranchingPerChoiceTargetSingleCondition(): void {
		// Source question q1 (ms-src) has a choice branching to ms-tgt.
		// Target question q2 (ms-tgt) should get a showIf equals condition.
		$questions = [
			[
				'id' => 'q1',
				'type' => 'choice',
				'_msQuestionId' => 'ms-src',
				'options' => [
					['id' => 'opt1', 'value' => 'Yes', '_msBranchTarget' => 'ms-tgt'],
				],
			],
			[
				'id' => 'q2',
				'type' => 'text',
				'_msQuestionId' => 'ms-tgt',
			],
		];
		$idMap = ['ms-src' => 'q1', 'ms-tgt' => 'q2'];
		$choiceMap = ['ms-src' => ['c1' => 'Yes']];

		$out = $this->service()->applyBranchingRules($questions, $idMap, $choiceMap);

		$this->assertArrayNotHasKey('showIf', $out[0]);
		$this->assertSame([
			'questionId' => 'q1',
			'operator' => 'equals',
			'value' => 'Yes',
		], $out[1]['showIf']);
	}

	public function testApplyBranchingPerChoiceMultipleTargetsCombineWithOr(): void {
		$questions = [
			[
				'id' => 'q1', 'type' => 'choice', '_msQuestionId' => 'ms-a',
				'options' => [['id' => 'opt1', 'value' => 'AA', '_msBranchTarget' => 'ms-tgt']],
			],
			[
				'id' => 'q2', 'type' => 'choice', '_msQuestionId' => 'ms-b',
				'options' => [['id' => 'opt1', 'value' => 'BB', '_msBranchTarget' => 'ms-tgt']],
			],
			['id' => 'q3', 'type' => 'text', '_msQuestionId' => 'ms-tgt'],
		];
		$idMap = ['ms-a' => 'q1', 'ms-b' => 'q2', 'ms-tgt' => 'q3'];

		$out = $this->service()->applyBranchingRules($questions, $idMap, []);
		$showIf = $out[2]['showIf'];

		$this->assertSame('or', $showIf['operator']);
		$this->assertCount(2, $showIf['conditions']);
		$this->assertSame('q1', $showIf['conditions'][0]['questionId']);
		$this->assertSame('AA', $showIf['conditions'][0]['value']);
		$this->assertSame('q2', $showIf['conditions'][1]['questionId']);
		$this->assertSame('BB', $showIf['conditions'][1]['value']);
	}

	public function testApplyBranchingRulesFormat(): void {
		// Target question carries _msBranchingRules directly.
		$questions = [
			['id' => 'q1', 'type' => 'choice', '_msQuestionId' => 'ms-src'],
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msBranchingRules' => [
					'Rules' => [
						[
							'Condition' => ['QuestionId' => 'ms-src', 'ChoiceId' => 'c1'],
							'Action' => 'Show',
						],
					],
				],
			],
		];
		$idMap = ['ms-src' => 'q1', 'ms-tgt' => 'q2'];
		$choiceMap = ['ms-src' => ['c1' => 'Yes']];

		$out = $this->service()->applyBranchingRules($questions, $idMap, $choiceMap);
		$this->assertSame([
			'questionId' => 'q1',
			'operator' => 'equals',
			'value' => 'Yes',
		], $out[1]['showIf']);
	}

	public function testApplyBranchingRulesNonShowActionIgnored(): void {
		$questions = [
			['id' => 'q1', 'type' => 'choice', '_msQuestionId' => 'ms-src'],
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msBranchingRules' => [
					'Rules' => [
						[
							'Condition' => ['QuestionId' => 'ms-src', 'ChoiceId' => 'c1'],
							'Action' => 'Hide',
						],
					],
				],
			],
		];
		$idMap = ['ms-src' => 'q1', 'ms-tgt' => 'q2'];
		$out = $this->service()->applyBranchingRules($questions, $idMap, ['ms-src' => ['c1' => 'Yes']]);
		$this->assertArrayNotHasKey('showIf', $out[1]);
	}

	public function testApplyBranchingRulesUsesValueThenChoiceIdFallback(): void {
		// No choiceMap entry, but Value present -> uses Value.
		$questions = [
			['id' => 'q1', 'type' => 'choice', '_msQuestionId' => 'ms-src'],
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msBranchingRules' => [
					'Rules' => [[
						'Condition' => ['QuestionId' => 'ms-src', 'Value' => 'raw-value'],
						'Action' => 'Show',
					]],
				],
			],
		];
		$out = $this->service()->applyBranchingRules(
			$questions,
			['ms-src' => 'q1', 'ms-tgt' => 'q2'],
			[]
		);
		$this->assertSame('raw-value', $out[1]['showIf']['value']);
	}

	public function testApplyBranchingRulesChoiceIdFallbackWhenNoValueNoMap(): void {
		$questions = [
			['id' => 'q1', 'type' => 'choice', '_msQuestionId' => 'ms-src'],
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msBranchingRules' => [
					'Rules' => [[
						'Condition' => ['QuestionId' => 'ms-src', 'ChoiceId' => 'cX'],
						'Action' => 'Show',
					]],
				],
			],
		];
		$out = $this->service()->applyBranchingRules(
			$questions,
			['ms-src' => 'q1', 'ms-tgt' => 'q2'],
			[]
		);
		// Falls back to using the raw choice id as the compare value.
		$this->assertSame('cX', $out[1]['showIf']['value']);
	}

	public function testApplyBranchingFormulaInfoSimpleEquality(): void {
		$questions = [
			['id' => 'q1', 'type' => 'choice', '_msQuestionId' => 'ms-src'],
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msFormulaInfo' => ['Formula' => "ms-src == 'hello'"],
			],
		];
		$out = $this->service()->applyBranchingRules(
			$questions,
			['ms-src' => 'q1', 'ms-tgt' => 'q2'],
			[]
		);
		$this->assertSame([
			'questionId' => 'q1',
			'operator' => 'equals',
			'value' => 'hello',
		], $out[1]['showIf']);
	}

	public function testApplyBranchingFormulaInfoUnparseableYieldsNoShowIf(): void {
		$questions = [
			[
				'id' => 'q1', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msFormulaInfo' => ['Formula' => 'not a simple equality expression!!'],
			],
		];
		$out = $this->service()->applyBranchingRules($questions, ['ms-tgt' => 'q1'], []);
		$this->assertArrayNotHasKey('showIf', $out[0]);
	}

	public function testApplyBranchingDirectMsShowIfWithOperatorMapping(): void {
		$questions = [
			['id' => 'q1', 'type' => 'choice', '_msQuestionId' => 'ms-src'],
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msShowIf' => [
					'questionId' => 'ms-src',
					'operator' => 'NotEquals',
					'value' => 'v',
				],
			],
		];
		$out = $this->service()->applyBranchingRules(
			$questions,
			['ms-src' => 'q1', 'ms-tgt' => 'q2'],
			[]
		);
		$this->assertSame([
			'questionId' => 'q1',
			'operator' => 'notEquals',
			'value' => 'v',
		], $out[1]['showIf']);
	}

	public function testApplyBranchingDirectMsShowIfIsEmptyDropsValue(): void {
		$questions = [
			['id' => 'q1', 'type' => 'text', '_msQuestionId' => 'ms-src'],
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msShowIf' => [
					'questionId' => 'ms-src',
					'operator' => 'isEmpty',
					'value' => 'ignored',
				],
			],
		];
		$out = $this->service()->applyBranchingRules(
			$questions,
			['ms-src' => 'q1', 'ms-tgt' => 'q2'],
			[]
		);
		$this->assertSame([
			'questionId' => 'q1',
			'operator' => 'isEmpty',
		], $out[1]['showIf']);
		$this->assertArrayNotHasKey('value', $out[1]['showIf']);
	}

	public function testApplyBranchingDirectMsShowIfResolvesChoiceValue(): void {
		$questions = [
			['id' => 'q1', 'type' => 'choice', '_msQuestionId' => 'ms-src'],
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msShowIf' => [
					'questionId' => 'ms-src',
					'operator' => 'equals',
					'choiceId' => 'cid',
				],
			],
		];
		$out = $this->service()->applyBranchingRules(
			$questions,
			['ms-src' => 'q1', 'ms-tgt' => 'q2'],
			['ms-src' => ['cid' => 'Resolved']]
		);
		$this->assertSame('Resolved', $out[1]['showIf']['value']);
	}

	public function testApplyBranchingUnknownOperatorDefaultsToEquals(): void {
		$questions = [
			['id' => 'q1', 'type' => 'text', '_msQuestionId' => 'ms-src'],
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msShowIf' => ['questionId' => 'ms-src', 'operator' => 'weird', 'value' => 'v'],
			],
		];
		$out = $this->service()->applyBranchingRules(
			$questions,
			['ms-src' => 'q1', 'ms-tgt' => 'q2'],
			[]
		);
		$this->assertSame('equals', $out[1]['showIf']['operator']);
	}

	public function testApplyBranchingUnmappedSourceYieldsNoShowIf(): void {
		// Source ms-src not in idMap -> condition skipped -> no showIf.
		$questions = [
			[
				'id' => 'q2', 'type' => 'text', '_msQuestionId' => 'ms-tgt',
				'_msShowIf' => ['questionId' => 'ms-src', 'operator' => 'equals', 'value' => 'v'],
			],
		];
		$out = $this->service()->applyBranchingRules($questions, ['ms-tgt' => 'q2'], []);
		$this->assertArrayNotHasKey('showIf', $out[0]);
	}

	// =====================================================================
	// importForm() — end-to-end shape with mocked API + FormService
	// =====================================================================

	private function primeCreate(int $fileId = 42): void {
		$this->formRepository->method('create')->willReturn(['fileId' => $fileId]);
	}

	public function testImportFormNoPagesFlatQuestionsSortedByOrder(): void {
		$this->stubSecureRandom();
		$this->primeCreate(42);

		$this->apiClient->method('getForm')->willReturn([
			'title' => 'My Form',
			'description' => 'desc',
			'settings' => ['isAnonymous' => false, 'allowMultipleResponses' => true],
		]);
		$this->apiClient->method('getQuestions')->willReturn([
			['id' => 'a', 'msType' => 'Question.Text', 'title' => 'Second', 'order' => 2],
			['id' => 'b', 'msType' => 'Question.Text', 'title' => 'First', 'order' => 1],
		]);

		$captured = null;
		$this->formRepository->method('update')->willReturnCallback(
			function ($fileId, $data) use (&$captured) {
				$captured = $data;
				return $data;
			}
		);

		$result = $this->service()->importForm('alice', 'token', 'msform', '/', false);

		$this->assertSame(42, $result['fileId']);
		$this->assertSame('My Form', $result['title']);
		$this->assertSame(2, $result['questionsImported']);
		$this->assertSame(0, $result['pagesImported']);
		$this->assertSame(0, $result['responsesImported']);
		$this->assertSame([], $result['warnings']);

		// Update payload: settings mapped, questions sorted by order (First, Second)
		$this->assertSame('desc', $captured['description']);
		$this->assertFalse($captured['settings']['anonymous']);
		$this->assertTrue($captured['settings']['allow_multiple']);
		$this->assertSame('First', $captured['questions'][0]['question']);
		$this->assertSame('Second', $captured['questions'][1]['question']);
		$this->assertArrayNotHasKey('pages', $captured);
		// Temp branching fields cleaned off the final questions
		$this->assertArrayNotHasKey('_msQuestionId', $captured['questions'][0]);
	}

	public function testImportFormDefaultsWhenFormMetadataMissing(): void {
		$this->stubSecureRandom();
		$this->primeCreate(7);
		$this->apiClient->method('getForm')->willReturn([]); // no title/description/settings
		$this->apiClient->method('getQuestions')->willReturn([]);

		$captured = null;
		$this->formRepository->method('update')->willReturnCallback(
			function ($fileId, $data) use (&$captured) {
				$captured = $data;
				return $data;
			}
		);
		// create() must be called with the fallback title
		$this->formRepository->expects($this->once())->method('create')
			->with('Imported Form', '/', null)
			->willReturn(['fileId' => 7]);

		$result = $this->service()->importForm('alice', 'token', 'msform', '/', false);

		$this->assertSame('Imported Form', $result['title']);
		$this->assertSame('', $captured['description']);
		$this->assertTrue($captured['settings']['anonymous']); // default true
		$this->assertFalse($captured['settings']['allow_multiple']); // default false
	}

	public function testImportFormWithPagesGroupsQuestionsAndPrependsIntro(): void {
		// secureRandom returns distinct tokens across calls so page ids differ
		// enough to be structurally checked; use a callback counter.
		$i = 0;
		$this->secureRandom->method('generate')->willReturnCallback(
			function () use (&$i) {
				return 'T' . str_pad((string)(++$i), 7, '0', STR_PAD_LEFT);
			}
		);
		$this->primeCreate(99);

		$this->apiClient->method('getForm')->willReturn(['title' => 'Paged']);
		$this->apiClient->method('getQuestions')->willReturn([
			// An intro question before any page header
			['id' => 'intro', 'msType' => 'Question.Text', 'title' => 'Intro Q', 'order' => 0],
			// Page header
			['id' => 'pg1', 'msType' => 'Question.ColumnGroup', 'title' => '<b>Page One</b>', 'order' => 1],
			// Question on page one
			['id' => 'q-on-1', 'msType' => 'Question.Text', 'title' => 'On Page', 'order' => 2],
		]);

		$captured = null;
		$this->formRepository->method('update')->willReturnCallback(
			function ($fileId, $data) use (&$captured) {
				$captured = $data;
				return $data;
			}
		);

		$result = $this->service()->importForm('alice', 'token', 'msform', '/', false);

		// 1 real page + prepended Introduction page
		$this->assertSame(2, $result['pagesImported']);
		$this->assertArrayHasKey('pages', $captured);
		$this->assertCount(2, $captured['pages']);
		// First page is the prepended Introduction
		$this->assertSame('Introduction', $captured['pages'][0]['title']);
		// Second page title had HTML stripped
		$this->assertSame('Page One', $captured['pages'][1]['title']);
		// Pages reference question IDs; those IDs exist in the flat questions array
		$flatIds = array_map(fn ($q) => $q['id'], $captured['questions']);
		foreach ($captured['pages'] as $page) {
			foreach ($page['questions'] as $qid) {
				$this->assertContains($qid, $flatIds);
			}
		}
		$this->assertSame(2, $result['questionsImported']);
	}

	public function testImportFormMatrixGroupFromMatrixChoice(): void {
		$this->stubSecureRandom();
		$this->primeCreate(11);

		$this->apiClient->method('getForm')->willReturn(['title' => 'Matrix Form']);
		$this->apiClient->method('getQuestions')->willReturn([
			[
				'id' => 'grp', 'msType' => 'Question.MatrixChoiceGroup',
				'title' => 'How much?', 'order' => 1, 'isRequired' => true,
			],
			[
				'id' => 'row1', 'msType' => 'Question.MatrixChoice', 'groupId' => 'grp',
				'title' => 'Speed', 'order' => 2,
			],
			[
				'id' => 'row2', 'msType' => 'Question.MatrixChoice', 'groupId' => 'grp',
				'title' => 'Quality', 'order' => 3,
			],
		]);

		$captured = null;
		$this->formRepository->method('update')->willReturnCallback(
			function ($fileId, $data) use (&$captured) {
				$captured = $data;
				return $data;
			}
		);

		$result = $this->service()->importForm('alice', 'token', 'msform', '/', false);

		// One matrix question produced from the group
		$this->assertSame(1, $result['questionsImported']);
		$matrix = $captured['questions'][0];
		$this->assertSame('matrix', $matrix['type']);
		$this->assertSame('How much?', $matrix['question']);
		$this->assertTrue($matrix['required']);
		$this->assertSame([
			['id' => 'r1', 'label' => 'Speed'],
			['id' => 'r2', 'label' => 'Quality'],
		], $matrix['rows']);
		// Placeholder columns
		$this->assertCount(3, $matrix['columns']);
		$this->assertSame('c1', $matrix['columns'][0]['id']);
		// Warning about columns not importable
		$this->assertNotEmpty($result['warnings']);
		$this->assertStringContainsString('columns could not be imported', $result['warnings'][0]);
	}

	public function testImportFormMatrixChoiceWithoutHeaderCreatesGroup(): void {
		// A MatrixChoice row whose group header never appeared -> group with
		// header=null still produces a matrix question (default title).
		$this->stubSecureRandom();
		$this->primeCreate(12);
		$this->apiClient->method('getForm')->willReturn(['title' => 'F']);
		$this->apiClient->method('getQuestions')->willReturn([
			['id' => 'r', 'msType' => 'Question.MatrixChoice', 'groupId' => 'orphan', 'title' => 'RowA', 'order' => 1],
		]);
		$captured = null;
		$this->formRepository->method('update')->willReturnCallback(
			function ($f, $d) use (&$captured) {
				$captured = $d;
				return $d;
			}
		);

		$result = $this->service()->importForm('alice', 'token', 'msform', '/', false);
		$this->assertSame(1, $result['questionsImported']);
		$this->assertSame('Matrix Question', $captured['questions'][0]['question']);
	}

	public function testImportFormResponsesImportedAndMapped(): void {
		$this->stubSecureRandom();
		$this->primeCreate(50);

		$this->apiClient->method('getForm')->willReturn(['title' => 'RForm']);
		$this->apiClient->method('getQuestions')->willReturn([
			['id' => 'msq', 'msType' => 'Question.Text', 'title' => 'Q', 'order' => 1],
		]);
		$this->formRepository->method('update')->willReturn([]);

		// Determine the FormVox question id generated for 'msq'
		// (stubSecureRandom returns 'AAAAAAAA' => id 'qAAAAAAAA')
		$this->apiClient->method('getResponses')->willReturn([
			[
				'id' => 'resp1',
				'submitDate' => '2024-01-02T03:04:05Z',
				'answers' => ['msq' => 'hello', 'unknownQ' => 'dropped'],
			],
			[
				'id' => 'resp2',
				'responder' => 'Bob',
				'answers' => ['msq' => 'world'],
			],
		]);

		$appended = [];
		$this->responsePersistence->method('appendResponse')->willReturnCallback(
			function ($fileId, $response) use (&$appended) {
				$appended[] = $response;
				return $response;
			}
		);

		$result = $this->service()->importForm('alice', 'token', 'msform', '/', true);

		$this->assertSame(2, $result['responsesImported']);
		$this->assertCount(2, $appended);

		// First response: anonymous, answer mapped to FormVox question id,
		// unknown MS question dropped.
		$r1 = $appended[0];
		$this->assertSame('anonymous', $r1['respondent']['type']);
		$this->assertStringStartsWith('ms-import:resp1', $r1['respondent']['fingerprint']);
		$this->assertSame('2024-01-02T03:04:05Z', $r1['submitted_at']);
		$this->assertCount(1, $r1['answers']);
		$this->assertContains('hello', array_values($r1['answers']));
		$this->assertNotContains('dropped', array_values($r1['answers']));
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
			$r1['id']
		);

		// Second response: external responder info.
		$r2 = $appended[1];
		$this->assertSame('external', $r2['respondent']['type']);
		$this->assertSame('Bob', $r2['respondent']['display_name']);
		$this->assertSame('ms-forms', $r2['respondent']['source']);
	}

	public function testImportFormResponseFetchFailureAddsWarning(): void {
		$this->stubSecureRandom();
		$this->primeCreate(60);
		$this->apiClient->method('getForm')->willReturn(['title' => 'F']);
		$this->apiClient->method('getQuestions')->willReturn([]);
		$this->formRepository->method('update')->willReturn([]);
		$this->apiClient->method('getResponses')
			->willThrowException(new \RuntimeException('boom'));

		$result = $this->service()->importForm('alice', 'token', 'msform', '/', true);

		$this->assertSame(0, $result['responsesImported']);
		$this->assertNotEmpty($result['warnings']);
		$this->assertStringContainsString('Could not import responses: boom', $result['warnings'][0]);
	}

	public function testImportFormResponseAppendFailureIsSkippedNotCounted(): void {
		$this->stubSecureRandom();
		$this->primeCreate(61);
		$this->apiClient->method('getForm')->willReturn(['title' => 'F']);
		$this->apiClient->method('getQuestions')->willReturn([
			['id' => 'msq', 'msType' => 'Question.Text', 'title' => 'Q', 'order' => 1],
		]);
		$this->formRepository->method('update')->willReturn([]);
		$this->apiClient->method('getResponses')->willReturn([
			['id' => 'r1', 'answers' => ['msq' => 'ok']],
			['id' => 'r2', 'answers' => ['msq' => 'fail']],
		]);

		$call = 0;
		$this->responsePersistence->method('appendResponse')->willReturnCallback(
			function ($f, $r) use (&$call) {
				$call++;
				if ($call === 2) {
					throw new \RuntimeException('append failed');
				}
				return $r;
			}
		);

		$result = $this->service()->importForm('alice', 'token', 'msform', '/', true);
		// Only the successful append is counted.
		$this->assertSame(1, $result['responsesImported']);
	}

	public function testImportFormPassesPathAndTemplateToCreate(): void {
		$this->stubSecureRandom();
		$this->apiClient->method('getForm')->willReturn(['title' => 'F']);
		$this->apiClient->method('getQuestions')->willReturn([]);
		$this->formRepository->method('update')->willReturn([]);
		$this->formRepository->expects($this->once())->method('create')
			->with('F', '/Projects', null)
			->willReturn(['fileId' => 5]);

		$this->service()->importForm('alice', 'token', 'msform', '/Projects', false);
	}

	public function testImportFormBranchingEndToEndSetsShowIf(): void {
		$this->stubSecureRandom();
		$this->primeCreate(70);
		$this->apiClient->method('getForm')->willReturn(['title' => 'Branchy']);
		// q1 choice branches to q2 via _msBranchTarget on a choice.
		$this->apiClient->method('getQuestions')->willReturn([
			[
				'id' => 'ms-src', 'msType' => 'Question.Choice', 'title' => 'Src', 'order' => 1,
				'allowMultipleSelection' => false,
				'choices' => [
					['id' => 'c1', 'text' => 'Yes', 'branchTarget' => 'ms-tgt'],
					['id' => 'c2', 'text' => 'No'],
				],
			],
			['id' => 'ms-tgt', 'msType' => 'Question.Text', 'title' => 'Tgt', 'order' => 2],
		]);

		$captured = null;
		$this->formRepository->method('update')->willReturnCallback(
			function ($f, $d) use (&$captured) {
				$captured = $d;
				return $d;
			}
		);

		$this->service()->importForm('alice', 'token', 'msform', '/', false);

		// Find the target question (the text one) and confirm it has a showIf.
		$target = null;
		$source = null;
		foreach ($captured['questions'] as $q) {
			if ($q['type'] === 'text') {
				$target = $q;
			}
			if ($q['type'] === 'choice') {
				$source = $q;
			}
		}
		$this->assertNotNull($target);
		$this->assertArrayHasKey('showIf', $target);
		$this->assertSame($source['id'], $target['showIf']['questionId']);
		$this->assertSame('equals', $target['showIf']['operator']);
		$this->assertSame('Yes', $target['showIf']['value']);
		// Branch-target temp field cleaned from source options.
		$this->assertArrayNotHasKey('_msBranchTarget', $source['options'][0]);
	}
}
