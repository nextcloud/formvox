<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\WebhookService;
use OCP\Files\File;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exhaustive characterization tests for ResponseService.
 *
 * Everything is mocked — no server, no filesystem (except exportXlsx, which
 * uses \ZipArchive + a real tempfile locally; that's exercised for real).
 * Pure/logic methods (calculateScore #118, validateAnswerType #113,
 * sanitizeCsvCell injection, buildExportData, xlsx helpers, evaluateCondition)
 * are pinned via reflection so we assert the exact current behaviour.
 */
class ResponseServiceTest extends TestCase {
	private FormRepository $formRepository;
	private FormFileLocator $fileLocator;
	private ResponsePersistenceService $responsePersistence;
	private IndexService $indexService;
	private WebhookService $webhookService;
	private INotificationManager $notificationManager;
	private IGroupManager $groupManager;
	private IMailer $mailer;
	private IURLGenerator $urlGenerator;
	private IL10N $l;
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->fileLocator = $this->createMock(FormFileLocator::class);
		$this->responsePersistence = $this->createMock(ResponsePersistenceService::class);
		$this->indexService = $this->createMock(IndexService::class);
		$this->webhookService = $this->createMock(WebhookService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// IL10N::t echoes back a formatted string; mirror sprintf semantics.
		$this->l->method('t')->willReturnCallback(function (string $text, array $params = []): string {
			return $params === [] ? $text : vsprintf(str_replace('%s', '%s', $text), $params);
		});
	}

	private function service(): ResponseService {
		return new ResponseService(
			$this->formRepository,
			$this->fileLocator,
			$this->responsePersistence,
			$this->indexService,
			$this->webhookService,
			$this->notificationManager,
			$this->groupManager,
			$this->mailer,
			$this->urlGenerator,
			$this->l,
			$this->logger
		);
	}

	/** Invoke a private/protected method via reflection. */
	private function call(string $method, array $args) {
		// No setAccessible(): private methods are invocable via reflection
		// without it since PHP 8.1 (and it is deprecated in 8.5).
		$ref = new \ReflectionMethod(ResponseService::class, $method);
		return $ref->invokeArgs($this->service(), $args);
	}

	// =====================================================================
	// sanitizeCsvCell — formula-injection prefixing
	// =====================================================================

	public function testSanitizeCsvCellPrefixesEquals(): void {
		$this->assertSame("'=1+1", $this->call('sanitizeCsvCell', ['=1+1']));
	}

	public function testSanitizeCsvCellPrefixesPlus(): void {
		$this->assertSame("'+x", $this->call('sanitizeCsvCell', ['+x']));
	}

	public function testSanitizeCsvCellPrefixesMinus(): void {
		$this->assertSame("'-x", $this->call('sanitizeCsvCell', ['-x']));
	}

	public function testSanitizeCsvCellPrefixesAt(): void {
		$this->assertSame("'@SUM", $this->call('sanitizeCsvCell', ['@SUM']));
	}

	public function testSanitizeCsvCellPrefixesTab(): void {
		$this->assertSame("'\tx", $this->call('sanitizeCsvCell', ["\tx"]));
	}

	public function testSanitizeCsvCellPrefixesCarriageReturn(): void {
		$this->assertSame("'\rx", $this->call('sanitizeCsvCell', ["\rx"]));
	}

	public function testSanitizeCsvCellLeavesPlainText(): void {
		$this->assertSame('hello', $this->call('sanitizeCsvCell', ['hello']));
	}

	public function testSanitizeCsvCellLeavesEmptyString(): void {
		$this->assertSame('', $this->call('sanitizeCsvCell', ['']));
	}

	public function testSanitizeCsvCellPassesThroughNonString(): void {
		$this->assertSame(42, $this->call('sanitizeCsvCell', [42]));
		$this->assertSame(null, $this->call('sanitizeCsvCell', [null]));
		$this->assertSame(true, $this->call('sanitizeCsvCell', [true]));
	}

	// =====================================================================
	// xlsxColumnLetter
	// =====================================================================

	public function testXlsxColumnLetterFirst(): void {
		$this->assertSame('A', $this->call('xlsxColumnLetter', [0]));
	}

	public function testXlsxColumnLetterZ(): void {
		$this->assertSame('Z', $this->call('xlsxColumnLetter', [25]));
	}

	public function testXlsxColumnLetterAA(): void {
		$this->assertSame('AA', $this->call('xlsxColumnLetter', [26]));
	}

	public function testXlsxColumnLetterAB(): void {
		$this->assertSame('AB', $this->call('xlsxColumnLetter', [27]));
	}

	public function testXlsxColumnLetterAZ(): void {
		$this->assertSame('AZ', $this->call('xlsxColumnLetter', [51]));
	}

	public function testXlsxColumnLetterBA(): void {
		$this->assertSame('BA', $this->call('xlsxColumnLetter', [52]));
	}

	public function testXlsxColumnLetterAAA(): void {
		$this->assertSame('AAA', $this->call('xlsxColumnLetter', [702]));
	}

	// =====================================================================
	// xmlEscape
	// =====================================================================

	public function testXmlEscapeEscapesEntities(): void {
		$this->assertSame('&lt;a&gt; &amp; &quot;q&quot;', $this->call('xmlEscape', ['<a> & "q"']));
	}

	public function testXmlEscapeStripsControlChars(): void {
		// \x00-\x08, \x0B, \x0C, \x0E-\x1F removed; tab/newline (\x09/\x0A) kept.
		$in = "a\x00b\x08c\x1Fd";
		$this->assertSame('abcd', $this->call('xmlEscape', [$in]));
	}

	public function testXmlEscapeKeepsTabAndNewline(): void {
		$this->assertSame("a\tb\nc", $this->call('xmlEscape', ["a\tb\nc"]));
	}

	public function testXmlEscapeUnicodePreserved(): void {
		$this->assertSame('ö', $this->call('xmlEscape', ['ö']));
	}

	// =====================================================================
	// calculateFingerprint
	// =====================================================================

	public function testCalculateFingerprintDeterministic(): void {
		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('1.2.3.4');
		$req->method('getHeader')->with('User-Agent')->willReturn('UA');
		$expected = 'sha256:' . hash('sha256', implode('|', ['1.2.3.4', 'UA', 'tok']));
		$this->assertSame($expected, $this->call('calculateFingerprint', [$req, 'tok']));
	}

	// =====================================================================
	// isQuizMode
	// =====================================================================

	public function testIsQuizModeTrueWhenOptionHasScore(): void {
		$form = ['questions' => [['id' => 'q', 'options' => [['value' => 'a', 'score' => 5]]]]];
		$this->assertTrue($this->call('isQuizMode', [$form]));
	}

	public function testIsQuizModeFalseWhenNoScores(): void {
		$form = ['questions' => [['id' => 'q', 'options' => [['value' => 'a']]]]];
		$this->assertFalse($this->call('isQuizMode', [$form]));
	}

	public function testIsQuizModeFalseWhenNoOptions(): void {
		$form = ['questions' => [['id' => 'q', 'type' => 'text']]];
		$this->assertFalse($this->call('isQuizMode', [$form]));
	}

	public function testIsQuizModeFalseWhenNoQuestions(): void {
		$this->assertFalse($this->call('isQuizMode', [[]]));
	}

	// =====================================================================
	// calculateScore — #118 multiple-choice max = sum of positive scores
	// =====================================================================

	public function testCalculateScoreSingleChoice(): void {
		$form = ['questions' => [[
			'id' => 'q1', 'type' => 'choice',
			'options' => [
				['value' => 'a', 'score' => 2],
				['value' => 'b', 'score' => 5],
				['value' => 'c', 'score' => 0],
			],
		]]];
		$score = $this->call('calculateScore', [$form, ['q1' => 'a']]);
		// max = highest option (5), total = picked 'a' (2)
		$this->assertSame(2, $score['total']);
		$this->assertSame(5, $score['max']);
		$this->assertSame(40.0, $score['percentage']);
		$this->assertSame(['q1' => 2], $score['byQuestion']);
	}

	public function testCalculateScoreMultipleSumsPositiveForMax(): void {
		// #118: max is the SUM of positively-scored options, not the single max.
		$form = ['questions' => [[
			'id' => 'q1', 'type' => 'multiple',
			'options' => [
				['value' => 'a', 'score' => 3],
				['value' => 'b', 'score' => 2],
				['value' => 'c', 'score' => -1],
				['value' => 'd', 'score' => 0],
			],
		]]];
		$score = $this->call('calculateScore', [$form, ['q1' => ['a', 'c']]]);
		// max = 3 + 2 = 5 (negatives/zero excluded)
		$this->assertSame(5, $score['max']);
		// total = a(3) + c(-1) = 2
		$this->assertSame(2, $score['total']);
		$this->assertSame(40.0, $score['percentage']);
	}

	public function testCalculateScoreMultipleAllCorrect(): void {
		$form = ['questions' => [[
			'id' => 'q1', 'type' => 'multiple',
			'options' => [
				['value' => 'a', 'score' => 3],
				['value' => 'b', 'score' => 2],
			],
		]]];
		$score = $this->call('calculateScore', [$form, ['q1' => ['a', 'b']]]);
		$this->assertSame(5, $score['total']);
		$this->assertSame(5, $score['max']);
		$this->assertSame(100.0, $score['percentage']);
	}

	public function testCalculateScoreSkipsQuestionsWithoutOptions(): void {
		$form = ['questions' => [
			['id' => 'q1', 'type' => 'text'],
			['id' => 'q2', 'type' => 'choice', 'options' => [['value' => 'a', 'score' => 4]]],
		]];
		$score = $this->call('calculateScore', [$form, ['q1' => 'ignored', 'q2' => 'a']]);
		$this->assertSame(4, $score['total']);
		$this->assertSame(4, $score['max']);
		$this->assertArrayNotHasKey('q1', $score['byQuestion']);
	}

	public function testCalculateScoreZeroMaxGivesZeroPercentage(): void {
		// All options score 0 → max 0 → percentage 0 (avoid div by zero).
		$form = ['questions' => [[
			'id' => 'q1', 'type' => 'choice',
			'options' => [['value' => 'a', 'score' => 0]],
		]]];
		$score = $this->call('calculateScore', [$form, ['q1' => 'a']]);
		$this->assertSame(0, $score['max']);
		$this->assertSame(0, $score['percentage']);
	}

	public function testCalculateScoreMissingAnswerScoresZero(): void {
		$form = ['questions' => [[
			'id' => 'q1', 'type' => 'choice',
			'options' => [['value' => 'a', 'score' => 5]],
		]]];
		$score = $this->call('calculateScore', [$form, []]);
		$this->assertSame(0, $score['total']);
		$this->assertSame(5, $score['max']);
	}

	public function testCalculateScorePercentageRounding(): void {
		$form = ['questions' => [[
			'id' => 'q1', 'type' => 'choice',
			'options' => [['value' => 'a', 'score' => 1], ['value' => 'b', 'score' => 3]],
		]]];
		$score = $this->call('calculateScore', [$form, ['q1' => 'a']]);
		// 1/3 = 33.333 → round to 33.3
		$this->assertSame(33.3, $score['percentage']);
	}

	// =====================================================================
	// calculateNumericStats
	// =====================================================================

	public function testCalculateNumericStatsAggregates(): void {
		$form = ['responses' => [
			['answers' => ['q1' => '2']],
			['answers' => ['q1' => '4']],
			['answers' => ['q1' => 'notnum']],
			['answers' => []],
		]];
		$stats = $this->call('calculateNumericStats', [$form, 'q1']);
		$this->assertSame(3.0, $stats['average']);
		$this->assertSame(2.0, $stats['min']);
		$this->assertSame(4.0, $stats['max']);
	}

	public function testCalculateNumericStatsEmpty(): void {
		$stats = $this->call('calculateNumericStats', [['responses' => []], 'q1']);
		$this->assertSame(['average' => null, 'min' => null, 'max' => null], $stats);
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
		$this->expectExceptionMessage('Select at least 2 option(s)');
		$this->call('validateAnswerType', [
			['type' => 'multiple', 'question' => 'M', 'minSelections' => 2], ['a'],
		]);
	}

	public function testValidateAnswerTypeMultipleMaxSelections(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Select at most 1 option(s)');
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
	// validateFormAcceptsResponses
	// =====================================================================

	public function testValidateFormAcceptsResponsesExpired(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('This form has expired');
		$this->call('validateFormAcceptsResponses', [[
			'settings' => ['expires_at' => '2000-01-01T00:00:00'],
		]]);
	}

	public function testValidateFormAcceptsResponsesNotExpired(): void {
		$this->indexService->method('getResponseCount')->willReturn(0);
		$this->call('validateFormAcceptsResponses', [[
			'settings' => ['expires_at' => '2999-01-01T00:00:00'],
		]]);
		$this->addToAssertionCount(1);
	}

	public function testValidateFormAcceptsResponsesLimitReachedDefaultMessage(): void {
		$this->indexService->method('getResponseCount')->willReturn(10);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('This form has reached its response limit');
		$this->call('validateFormAcceptsResponses', [[
			'settings' => ['max_responses' => 10],
		]]);
	}

	public function testValidateFormAcceptsResponsesLimitCustomMessage(): void {
		$this->indexService->method('getResponseCount')->willReturn(10);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Sold out!');
		$this->call('validateFormAcceptsResponses', [[
			'settings' => ['max_responses' => 10, 'limit_message' => 'Sold out!'],
		]]);
	}

	public function testValidateFormAcceptsResponsesUnderLimit(): void {
		$this->indexService->method('getResponseCount')->willReturn(3);
		$this->call('validateFormAcceptsResponses', [[
			'settings' => ['max_responses' => 10],
		]]);
		$this->addToAssertionCount(1);
	}

	public function testValidateFormAcceptsResponsesNoSettings(): void {
		$this->call('validateFormAcceptsResponses', [[]]);
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
	// isQuestionHidden / evaluateCondition
	// =====================================================================

	public function testEvaluateConditionEquals(): void {
		$cond = ['questionId' => 'q1', 'operator' => 'equals', 'value' => 'a'];
		$this->assertTrue($this->call('evaluateCondition', [$cond, ['q1' => 'a'], []]));
		$this->assertFalse($this->call('evaluateCondition', [$cond, ['q1' => 'b'], []]));
	}

	public function testEvaluateConditionNotEquals(): void {
		$cond = ['questionId' => 'q1', 'operator' => 'notEquals', 'value' => 'a'];
		$this->assertFalse($this->call('evaluateCondition', [$cond, ['q1' => 'a'], []]));
		$this->assertTrue($this->call('evaluateCondition', [$cond, ['q1' => 'b'], []]));
	}

	public function testEvaluateConditionContains(): void {
		$cond = ['questionId' => 'q1', 'operator' => 'contains', 'value' => 'ell'];
		$this->assertTrue($this->call('evaluateCondition', [$cond, ['q1' => 'hello'], []]));
		$this->assertFalse($this->call('evaluateCondition', [$cond, ['q1' => 'world'], []]));
	}

	public function testEvaluateConditionIsEmptyAndNotEmpty(): void {
		$empty = ['questionId' => 'q1', 'operator' => 'isEmpty'];
		$notEmpty = ['questionId' => 'q1', 'operator' => 'isNotEmpty'];
		$this->assertTrue($this->call('evaluateCondition', [$empty, ['q1' => ''], []]));
		$this->assertTrue($this->call('evaluateCondition', [$empty, [], []]));
		$this->assertTrue($this->call('evaluateCondition', [$notEmpty, ['q1' => 'x'], []]));
		$this->assertFalse($this->call('evaluateCondition', [$notEmpty, ['q1' => ''], []]));
	}

	public function testEvaluateConditionGreaterAndLessThan(): void {
		$gt = ['questionId' => 'q1', 'operator' => 'greaterThan', 'value' => 5];
		$lt = ['questionId' => 'q1', 'operator' => 'lessThan', 'value' => 5];
		$this->assertTrue($this->call('evaluateCondition', [$gt, ['q1' => '7'], []]));
		$this->assertFalse($this->call('evaluateCondition', [$gt, ['q1' => '3'], []]));
		$this->assertTrue($this->call('evaluateCondition', [$lt, ['q1' => '3'], []]));
		$this->assertFalse($this->call('evaluateCondition', [$lt, ['q1' => 'notnum'], []]));
	}

	public function testEvaluateConditionInAndNotIn(): void {
		$in = ['questionId' => 'q1', 'operator' => 'in', 'value' => ['a', 'b']];
		$notIn = ['questionId' => 'q1', 'operator' => 'notIn', 'value' => ['a', 'b']];
		$this->assertTrue($this->call('evaluateCondition', [$in, ['q1' => 'a'], []]));
		$this->assertFalse($this->call('evaluateCondition', [$in, ['q1' => 'z'], []]));
		$this->assertTrue($this->call('evaluateCondition', [$notIn, ['q1' => 'z'], []]));
		$this->assertFalse($this->call('evaluateCondition', [$notIn, ['q1' => 'a'], []]));
	}

	public function testEvaluateConditionInNonArrayValueFalse(): void {
		$in = ['questionId' => 'q1', 'operator' => 'in', 'value' => 'notarray'];
		$this->assertFalse($this->call('evaluateCondition', [$in, ['q1' => 'a'], []]));
	}

	public function testEvaluateConditionAndOr(): void {
		$and = [
			'operator' => 'and',
			'conditions' => [
				['questionId' => 'q1', 'operator' => 'equals', 'value' => 'a'],
				['questionId' => 'q2', 'operator' => 'equals', 'value' => 'b'],
			],
		];
		$or = ['operator' => 'or', 'conditions' => $and['conditions']];
		$this->assertTrue($this->call('evaluateCondition', [$and, ['q1' => 'a', 'q2' => 'b'], []]));
		$this->assertFalse($this->call('evaluateCondition', [$and, ['q1' => 'a', 'q2' => 'x'], []]));
		$this->assertTrue($this->call('evaluateCondition', [$or, ['q1' => 'a', 'q2' => 'x'], []]));
		$this->assertFalse($this->call('evaluateCondition', [$or, ['q1' => 'x', 'q2' => 'x'], []]));
	}

	public function testEvaluateConditionUnknownOperatorFalse(): void {
		// #99: half-configured / unknown operator must NOT match.
		$cond = ['questionId' => 'q1', 'operator' => 'bogus', 'value' => 'a'];
		$this->assertFalse($this->call('evaluateCondition', [$cond, ['q1' => 'a'], []]));
	}

	public function testEvaluateConditionEmptyConditionFalse(): void {
		$this->assertFalse($this->call('evaluateCondition', [[], [], []]));
	}

	public function testEvaluateConditionArrayAnswerEquals(): void {
		$cond = ['questionId' => 'q1', 'operator' => 'equals', 'value' => 'a'];
		$this->assertTrue($this->call('evaluateCondition', [$cond, ['q1' => ['a', 'c']], []]));
		$this->assertFalse($this->call('evaluateCondition', [$cond, ['q1' => ['x', 'c']], []]));
	}

	public function testChoiceCompareCandidatesMapsLabelToValue(): void {
		$questionsById = ['q1' => [
			'id' => 'q1', 'type' => 'choice',
			'options' => [['value' => 'opt1', 'label' => 'Ja']],
		]];
		// #99: a label-based rule 'Ja' resolves to both value and label.
		$out = $this->call('choiceCompareCandidates', ['q1', 'Ja', $questionsById]);
		$this->assertSame(['opt1', 'Ja'], $out);
	}

	public function testChoiceCompareCandidatesNonChoicePassthrough(): void {
		$questionsById = ['q1' => ['id' => 'q1', 'type' => 'text']];
		$this->assertSame(['x'], $this->call('choiceCompareCandidates', ['q1', 'x', $questionsById]));
	}

	public function testIsQuestionHiddenBySection(): void {
		$questionsById = [
			's1' => ['id' => 's1', 'type' => 'section',
				'showIf' => ['questionId' => 'q1', 'operator' => 'equals', 'value' => 'yes']],
		];
		$q = ['id' => 'q2', 'type' => 'text', 'sectionId' => 's1'];
		// section showIf not met → hidden
		$this->assertTrue($this->call('isQuestionHidden', [$q, ['q1' => 'no'], $questionsById]));
		// section showIf met → not hidden
		$this->assertFalse($this->call('isQuestionHidden', [$q, ['q1' => 'yes'], $questionsById]));
	}

	public function testIsQuestionHiddenNoShowIfIsVisible(): void {
		$q = ['id' => 'q1', 'type' => 'text'];
		$this->assertFalse($this->call('isQuestionHidden', [$q, [], []]));
	}

	// =====================================================================
	// buildExportData
	// =====================================================================

	public function testBuildExportDataNullWhenNoResponses(): void {
		$this->formRepository->method('load')->willReturn(['questions' => [], 'responses' => []]);
		$this->assertNull($this->call('buildExportData', [1]));
	}

	public function testBuildExportDataHeadersAndRows(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [
				['id' => 'q1', 'type' => 'text', 'question' => 'Name'],
				['id' => 'sec', 'type' => 'section', 'question' => 'Section'],
			],
			'responses' => [[
				'id' => 'r1', 'submitted_at' => '2020-01-01T00:00:00',
				'respondent' => ['type' => 'user', 'user_id' => 'bob'],
				'answers' => ['q1' => 'Alice'],
			]],
		]);
		$data = $this->call('buildExportData', [1]);
		// Section excluded from headers.
		$this->assertSame(['Response ID', 'Submitted At', 'Respondent Type', 'Respondent ID', 'Name'], $data['headers']);
		$this->assertSame(['r1', '2020-01-01T00:00:00', 'user', 'bob', 'Alice'], $data['rows'][0]);
	}

	public function testBuildExportDataAnonymousUsesFingerprint(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']],
			'responses' => [[
				'id' => 'r1', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'fp123'],
				'answers' => ['q1' => 'x'],
			]],
		]);
		$data = $this->call('buildExportData', [1]);
		$this->assertSame('fp123', $data['rows'][0][3]);
	}

	public function testBuildExportDataConsentYesNo(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'consent', 'question' => 'Agree']],
			'responses' => [
				['id' => 'r1', 'submitted_at' => 't', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'], 'answers' => ['q1' => true]],
				['id' => 'r2', 'submitted_at' => 't', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'], 'answers' => ['q1' => false]],
				['id' => 'r3', 'submitted_at' => 't', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'], 'answers' => []],
			],
		]);
		$data = $this->call('buildExportData', [1]);
		$this->assertSame('Yes', $data['rows'][0][4]);
		$this->assertSame('No', $data['rows'][1][4]);
		$this->assertSame('', $data['rows'][2][4]);
	}

	public function testBuildExportDataMatrix(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [[
				'id' => 'q1', 'type' => 'matrix', 'question' => 'M',
				'rows' => [['id' => 'r1', 'label' => 'Row1']],
				'columns' => [['value' => 'c1', 'label' => 'Col1']],
			]],
			'responses' => [[
				'id' => 'x', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'],
				'answers' => ['q1' => ['r1' => 'c1']],
			]],
		]);
		$data = $this->call('buildExportData', [1]);
		$this->assertSame('Row1: Col1', $data['rows'][0][4]);
	}

	public function testBuildExportDataTableJson(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [[
				'id' => 'q1', 'type' => 'table', 'question' => 'T',
				'columns' => [['id' => 'c1', 'label' => 'Price']],
			]],
			'responses' => [[
				'id' => 'x', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'],
				'answers' => ['q1' => [['c1' => '10']]],
			]],
		]);
		$data = $this->call('buildExportData', [1]);
		// Column id relabelled to 'Price' and JSON-encoded.
		$this->assertSame(json_encode([['Price' => '10']], JSON_UNESCAPED_UNICODE), $data['rows'][0][4]);
	}

	public function testBuildExportDataChoiceOptionMapping(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [[
				'id' => 'q1', 'type' => 'choice', 'question' => 'C',
				'options' => [['value' => 'opt1', 'label' => 'Yes']],
			]],
			'responses' => [[
				'id' => 'x', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'],
				'answers' => ['q1' => 'opt1'],
			]],
		]);
		$data = $this->call('buildExportData', [1]);
		$this->assertSame('Yes', $data['rows'][0][4]);
	}

	public function testBuildExportDataMultipleOptionMappingJoined(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [[
				'id' => 'q1', 'type' => 'multiple', 'question' => 'M',
				'options' => [
					['value' => 'o1', 'label' => 'A'],
					['value' => 'o2', 'label' => 'B'],
				],
			]],
			'responses' => [[
				'id' => 'x', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'],
				'answers' => ['q1' => ['o1', 'o2']],
			]],
		]);
		$data = $this->call('buildExportData', [1]);
		$this->assertSame('A, B', $data['rows'][0][4]);
	}

	public function testBuildExportDataSanitizesInjectionInAnswerAndHeader(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => '=DANGER']],
			'responses' => [[
				'id' => 'x', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'],
				'answers' => ['q1' => '=HYPERLINK("evil")'],
			]],
		]);
		$data = $this->call('buildExportData', [1]);
		$this->assertSame("'=DANGER", $data['headers'][4]);
		$this->assertSame('\'=HYPERLINK("evil")', $data['rows'][0][4]);
	}

	// =====================================================================
	// exportCsv / exportXlsx / exportJson (public)
	// =====================================================================

	public function testExportCsvEmptyWhenNoResponses(): void {
		$this->formRepository->method('load')->willReturn(['questions' => [], 'responses' => []]);
		$this->assertSame('', $this->service()->exportCsv(1));
	}

	public function testExportCsvHasBomAndSemicolons(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
			'responses' => [[
				'id' => 'r1', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'],
				'answers' => ['q1' => 'Alice'],
			]],
		]);
		$csv = $this->service()->exportCsv(1);
		$this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
		// Semicolon delimiter present between quoted header cells.
		$this->assertStringContainsString('"Response ID";"Submitted At"', $csv);
		// Data row terminated with CRLF; Alice value present.
		$this->assertStringContainsString('Alice', $csv);
		$this->assertStringContainsString("\r\n", $csv);
	}

	public function testExportXlsxEmptyWhenNoResponses(): void {
		$this->formRepository->method('load')->willReturn(['questions' => [], 'responses' => []]);
		$this->assertSame('', $this->service()->exportXlsx(1));
	}

	public function testExportXlsxProducesValidZipWithSheet(): void {
		if (!class_exists(\ZipArchive::class)) {
			$this->markTestSkipped('ZipArchive extension not available');
		}
		$this->formRepository->method('load')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
			'responses' => [[
				'id' => 'r1', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'],
				'answers' => ['q1' => 'Alice'],
			]],
		]);
		$bytes = $this->service()->exportXlsx(1);
		$this->assertNotSame('', $bytes);
		// PK zip magic
		$this->assertStringStartsWith('PK', $bytes);

		// Open the produced zip and check the worksheet contains the value.
		$tmp = tempnam(sys_get_temp_dir(), 'fvtest_');
		file_put_contents($tmp, $bytes);
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($tmp) === true);
		$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
		$zip->close();
		unlink($tmp);
		$this->assertStringContainsString('Alice', $sheet);
		$this->assertStringContainsString('inlineStr', $sheet);
	}

	public function testExportXlsxNumericCellUsesValueTag(): void {
		if (!class_exists(\ZipArchive::class)) {
			$this->markTestSkipped('ZipArchive extension not available');
		}
		// number question keeps numeric answer as number in xlsx? Answer is stored
		// as string typically, but buildExportData leaves plain strings. Force an
		// int by using consent? No — use a response answer that is int.
		$this->formRepository->method('load')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'number', 'question' => 'N']],
			'responses' => [[
				'id' => 'r1', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'],
				'answers' => ['q1' => 5],
			]],
		]);
		$bytes = $this->service()->exportXlsx(1);
		$tmp = tempnam(sys_get_temp_dir(), 'fvtest_');
		file_put_contents($tmp, $bytes);
		$zip = new \ZipArchive();
		$zip->open($tmp);
		$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
		$zip->close();
		unlink($tmp);
		// int value 5 rendered as <v>5</v>
		$this->assertStringContainsString('<v>5</v>', $sheet);
	}

	public function testExportJson(): void {
		$this->formRepository->method('load')->willReturn([
			'title' => 'My Form',
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']],
			'responses' => [['id' => 'r1']],
		]);
		$json = $this->service()->exportJson(1);
		$decoded = json_decode($json, true);
		$this->assertSame('My Form', $decoded['title']);
		$this->assertArrayHasKey('exportedAt', $decoded);
		$this->assertSame([['id' => 'r1']], $decoded['responses']);
	}

	public function testExportJsonNoResponsesKey(): void {
		$this->formRepository->method('load')->willReturn([
			'title' => 'T', 'questions' => [],
		]);
		$decoded = json_decode($this->service()->exportJson(1), true);
		$this->assertSame([], $decoded['responses']);
	}

	// =====================================================================
	// getResponses / getSummary
	// =====================================================================

	public function testGetResponsesReturnsAll(): void {
		$this->formRepository->method('load')->willReturn(['responses' => [['id' => 'r1']]]);
		$this->assertSame([['id' => 'r1']], $this->service()->getResponses(1));
	}

	public function testGetResponsesEmptyWhenNoKey(): void {
		$this->formRepository->method('load')->willReturn([]);
		$this->assertSame([], $this->service()->getResponses(1));
	}

	public function testGetResponsesWithDateFilterDelegates(): void {
		$this->formRepository->method('load')->willReturn(['responses' => []]);
		$this->indexService->expects($this->once())
			->method('getResponsesByDate')
			->with($this->anything(), '2020-01-01')
			->willReturn([['id' => 'd1']]);
		$this->assertSame([['id' => 'd1']], $this->service()->getResponses(1, '2020-01-01'));
	}

	public function testGetSummaryBuildsQuestions(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [
				['id' => 'q1', 'type' => 'choice', 'question' => 'C', 'options' => [['value' => 'a']]],
				['id' => 'sec', 'type' => 'section', 'question' => 'S'],
				['id' => 'q2', 'type' => 'number', 'question' => 'N'],
			],
			'responses' => [],
		]);
		$this->indexService->method('getResponseCount')->willReturn(2);
		$this->indexService->method('getAnswerStats')->willReturn(['a' => 1]);
		$summary = $this->service()->getSummary(1);
		$this->assertSame(2, $summary['responseCount']);
		// section excluded
		$this->assertCount(2, $summary['questions']);
		$this->assertSame('q1', $summary['questions'][0]['id']);
		// choice keeps options
		$this->assertArrayHasKey('options', $summary['questions'][0]);
		// number gets average/min/max keys
		$this->assertArrayHasKey('average', $summary['questions'][1]);
	}

	public function testGetSummaryMatrixKeepsRowsColumns(): void {
		$this->formRepository->method('load')->willReturn([
			'questions' => [[
				'id' => 'q1', 'type' => 'matrix', 'question' => 'M',
				'rows' => [['id' => 'r']], 'columns' => [['value' => 'c']],
			]],
			'responses' => [],
		]);
		$this->indexService->method('getResponseCount')->willReturn(0);
		$this->indexService->method('getAnswerStats')->willReturn([]);
		$summary = $this->service()->getSummary(1);
		$this->assertArrayHasKey('rows', $summary['questions'][0]);
		$this->assertArrayHasKey('columns', $summary['questions'][0]);
	}

	public function testGetSummaryPublicUsesLoadPublic(): void {
		$this->formRepository->expects($this->once())->method('loadPublic')->with(7)
			->willReturn(['questions' => [], 'responses' => []]);
		$this->indexService->method('getResponseCount')->willReturn(0);
		$summary = $this->service()->getSummaryPublic(7);
		$this->assertSame(0, $summary['responseCount']);
		$this->assertSame([], $summary['questions']);
	}

	// =====================================================================
	// sendConfirmationEmail (private, best-effort #103)
	// =====================================================================

	public function testSendConfirmationEmailDisabledDoesNothing(): void {
		$this->mailer->expects($this->never())->method('createMessage');
		$this->call('sendConfirmationEmail', [['settings' => []], []]);
		$this->addToAssertionCount(1);
	}

	public function testSendConfirmationEmailNoEmailFieldDoesNothing(): void {
		$this->mailer->expects($this->never())->method('createMessage');
		$form = [
			'settings' => ['sendConfirmationEmail' => true],
			'questions' => [['id' => 'q1', 'type' => 'text']], // no useAsRespondentEmail
		];
		$this->call('sendConfirmationEmail', [$form, ['q1' => 'a@b.com']]);
		$this->addToAssertionCount(1);
	}

	public function testSendConfirmationEmailInvalidAddressDoesNothing(): void {
		$this->mailer->method('validateMailAddress')->willReturn(false);
		$this->mailer->expects($this->never())->method('createMessage');
		$form = [
			'settings' => ['sendConfirmationEmail' => true],
			'questions' => [['id' => 'q1', 'type' => 'text', 'useAsRespondentEmail' => true]],
		];
		$this->call('sendConfirmationEmail', [$form, ['q1' => 'not-an-email']]);
		$this->addToAssertionCount(1);
	}

	public function testSendConfirmationEmailSends(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setTo')->with(['a@b.com'])->willReturnSelf();
		$message->expects($this->once())->method('setSubject')->willReturnSelf();
		$message->expects($this->once())->method('setPlainBody')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->once())->method('send')->with($message)->willReturn([]);

		$form = [
			'title' => 'My Form',
			'settings' => ['sendConfirmationEmail' => true],
			'questions' => [['id' => 'q1', 'type' => 'text', 'useAsRespondentEmail' => true]],
		];
		$this->call('sendConfirmationEmail', [$form, ['q1' => 'a@b.com']]);
		$this->addToAssertionCount(1);
	}

	public function testSendConfirmationEmailSwallowsExceptions(): void {
		// createMessage throws → caught, logged, no rethrow.
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('createMessage')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->atLeastOnce())->method('warning');
		$form = [
			'settings' => ['sendConfirmationEmail' => true],
			'questions' => [['id' => 'q1', 'type' => 'text', 'useAsRespondentEmail' => true]],
		];
		$this->call('sendConfirmationEmail', [$form, ['q1' => 'a@b.com']]);
		$this->addToAssertionCount(1);
	}

	// =====================================================================
	// notifyFormOwner (private)
	// =====================================================================

	public function testNotifyFormOwnerNotifiesOwner(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner1');
		$file = $this->createMock(File::class);
		$file->method('getOwner')->willReturn($owner);
		$this->fileLocator->method('getFileByIdPublic')->willReturn($file);

		$notification = $this->makeNotificationMock();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify')->with($notification);

		$form = ['title' => 'F', 'settings' => []];
		$response = ['id' => 'r1', 'respondent' => ['type' => 'anonymous']];
		$this->call('notifyFormOwner', [1, $form, $response]);
		$this->addToAssertionCount(1);
	}

	public function testNotifyFormOwnerExcludesRespondentSelf(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('bob');
		$file = $this->createMock(File::class);
		$file->method('getOwner')->willReturn($owner);
		$this->fileLocator->method('getFileByIdPublic')->willReturn($file);

		// Owner == respondent → no notification sent.
		$this->notificationManager->expects($this->never())->method('notify');

		$form = ['title' => 'F', 'settings' => []];
		$response = ['id' => 'r1', 'respondent' => ['type' => 'user', 'user_id' => 'bob', 'display_name' => 'Bob']];
		$this->call('notifyFormOwner', [1, $form, $response]);
		$this->addToAssertionCount(1);
	}

	public function testNotifyFormOwnerNotifyOwnerDisabledSkipsOwner(): void {
		// notify_owner=false and no recipients → nothing sent, file never fetched.
		$this->fileLocator->expects($this->never())->method('getFileByIdPublic');
		$this->notificationManager->expects($this->never())->method('notify');
		$form = ['title' => 'F', 'settings' => ['notify_owner' => false]];
		$response = ['id' => 'r1', 'respondent' => ['type' => 'anonymous']];
		$this->call('notifyFormOwner', [1, $form, $response]);
		$this->addToAssertionCount(1);
	}

	public function testNotifyFormOwnerGroupRecipients(): void {
		$u1 = $this->createMock(IUser::class);
		$u1->method('getUID')->willReturn('g-user');
		$group = $this->createMock(\OCP\IGroup::class);
		$group->method('getUsers')->willReturn([$u1]);
		$this->groupManager->method('get')->with('grp')->willReturn($group);

		$notification = $this->makeNotificationMock();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');

		$form = [
			'title' => 'F',
			'settings' => [
				'notify_owner' => false,
				'notify_recipients' => [['type' => 'group', 'id' => 'grp']],
			],
		];
		$response = ['id' => 'r1', 'respondent' => ['type' => 'anonymous']];
		$this->call('notifyFormOwner', [1, $form, $response]);
		$this->addToAssertionCount(1);
	}

	public function testNotifyFormOwnerSwallowsExceptions(): void {
		$this->fileLocator->method('getFileByIdPublic')->willThrowException(new \RuntimeException('x'));
		// Must not propagate.
		$this->call('notifyFormOwner', [1, ['title' => 'F', 'settings' => []], ['id' => 'r', 'respondent' => ['type' => 'anonymous']]]);
		$this->addToAssertionCount(1);
	}

	/** Build an INotification whose fluent setters return self. */
	private function makeNotificationMock(): INotification {
		$n = $this->createMock(INotification::class);
		$n->method('setApp')->willReturnSelf();
		$n->method('setUser')->willReturnSelf();
		$n->method('setDateTime')->willReturnSelf();
		$n->method('setObject')->willReturnSelf();
		$n->method('setSubject')->willReturnSelf();
		return $n;
	}

	// =====================================================================
	// submitAnonymous / submitAuthenticated (public flows)
	// =====================================================================

	public function testSubmitAnonymousHappyPath(): void {
		$form = [
			'title' => 'F',
			'settings' => ['allow_multiple' => true],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']],
		];
		$this->formRepository->method('load')->willReturn($form);
		$this->responsePersistence->method('appendResponsePublic')->willReturn(['ok' => true]);
		// notifyFormOwner: getFileByIdPublic
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner');
		$file = $this->createMock(File::class);
		$file->method('getOwner')->willReturn($owner);
		$this->fileLocator->method('getFileByIdPublic')->willReturn($file);
		$this->notificationManager->method('createNotification')->willReturn($this->makeNotificationMock());

		$this->webhookService->expects($this->once())->method('trigger')
			->with($form, 'response.created', $this->anything());

		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('ip');
		$req->method('getHeader')->willReturn('ua');

		$result = $this->service()->submitAnonymous(1, ['q1' => 'answer'], $req, 'tok');
		$this->assertSame(['ok' => true], $result);
	}

	public function testSubmitAnonymousDuplicateThrows(): void {
		$form = ['settings' => [], 'questions' => []];
		$this->formRepository->method('load')->willReturn($form);
		$this->indexService->method('hasFingerprint')->willReturn(true);
		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('ip');
		$req->method('getHeader')->willReturn('ua');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('You have already submitted');
		$this->service()->submitAnonymous(1, [], $req, 'tok');
	}

	public function testSubmitAnonymousExpiredThrows(): void {
		$form = ['settings' => ['expires_at' => '2000-01-01T00:00:00'], 'questions' => []];
		$this->formRepository->method('load')->willReturn($form);
		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('ip');
		$req->method('getHeader')->willReturn('ua');
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('This form has expired');
		$this->service()->submitAnonymous(1, [], $req, 'tok');
	}

	public function testSubmitAnonymousComputesScoreInQuizMode(): void {
		$form = [
			'title' => 'F',
			'settings' => ['allow_multiple' => true],
			'questions' => [[
				'id' => 'q1', 'type' => 'choice', 'question' => 'Q',
				'options' => [['value' => 'a', 'score' => 5]],
			]],
		];
		$this->formRepository->method('load')->willReturn($form);
		$captured = null;
		$this->responsePersistence->method('appendResponsePublic')
			->willReturnCallback(function ($id, $response) use (&$captured) {
				$captured = $response;
				return ['ok' => true];
			});
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner');
		$file = $this->createMock(File::class);
		$file->method('getOwner')->willReturn($owner);
		$this->fileLocator->method('getFileByIdPublic')->willReturn($file);
		$this->notificationManager->method('createNotification')->willReturn($this->makeNotificationMock());

		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('ip');
		$req->method('getHeader')->willReturn('ua');

		$this->service()->submitAnonymous(1, ['q1' => 'a'], $req, 'tok');
		$this->assertArrayHasKey('score', $captured);
		$this->assertSame(5, $captured['score']['total']);
		$this->assertSame('anonymous', $captured['respondent']['type']);
	}

	public function testSubmitAuthenticatedHappyPath(): void {
		$form = [
			'title' => 'F',
			'settings' => ['allow_multiple' => true],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']],
		];
		$this->formRepository->method('loadPublic')->willReturn($form);
		$captured = null;
		$this->responsePersistence->method('appendResponsePublic')
			->willReturnCallback(function ($id, $response) use (&$captured) {
				$captured = $response;
				return ['ok' => true];
			});
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner');
		$file = $this->createMock(File::class);
		$file->method('getOwner')->willReturn($owner);
		$this->fileLocator->method('getFileByIdPublic')->willReturn($file);
		$this->notificationManager->method('createNotification')->willReturn($this->makeNotificationMock());

		$result = $this->service()->submitAuthenticated(1, ['q1' => 'x'], 'bob', 'Bob');
		$this->assertSame(['ok' => true], $result);
		$this->assertSame('user', $captured['respondent']['type']);
		$this->assertSame('bob', $captured['respondent']['user_id']);
		$this->assertSame('Bob', $captured['respondent']['display_name']);
	}

	public function testSubmitAuthenticatedDuplicateThrows(): void {
		$form = ['settings' => [], 'questions' => []];
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->indexService->method('hasUserResponse')->willReturn(true);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('You have already submitted');
		$this->service()->submitAuthenticated(1, [], 'bob', 'Bob');
	}

	public function testSubmitAuthenticatedValidationErrorBubbles(): void {
		$form = [
			'settings' => ['allow_multiple' => true],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q', 'required' => true]],
		];
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("Question 'Q' is required");
		$this->service()->submitAuthenticated(1, [], 'bob', 'Bob');
	}
}
