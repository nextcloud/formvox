<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\AnswerValidator;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\InvalidAnswerException;
use OCA\FormVox\Service\ResponseOutbox;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseRefusedException;
use OCA\FormVox\Service\ResponseRules;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\SubmissionIds;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCA\FormVox\Tests\Unit\Support\ReversibleCrypto;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
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
	private IURLGenerator $urlGenerator;
	private IL10N $l;
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->fileLocator = $this->createMock(FormFileLocator::class);
		$this->responsePersistence = $this->createMock(ResponsePersistenceService::class);
		$this->indexService = $this->createMock(IndexService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// As for a form file: its responses, and what was submitted, are in the form as read.
		$this->formRepository->method('eachResponse')->willReturnCallback(fn (int $fileId, array $form) => array_values(array_filter($form['responses'] ?? [], 'is_array')));
		$this->formRepository->method('page')->willReturnCallback(fn (int $fileId, array $form) => ['responses' => array_values(array_filter($form['responses'] ?? [], 'is_array')), 'next' => null]);
		$this->responsePersistence->method('submitted')->willReturnCallback(fn (int $fileId, array $form, ?string $submission) => SubmissionIds::stored($form, $submission));

		// The respondent filled the form in Dutch.
		$this->l->method('getLanguageCode')->willReturn('nl');
		// IL10N::t echoes back a formatted string; mirror sprintf semantics.
		$this->l->method('t')->willReturnCallback(function (string $text, array $params = []): string {
			return $params === [] ? $text : vsprintf(str_replace('%s', '%s', $text), $params);
		});
		// IL10N::n as Nextcloud does: the count goes into %n, the parameters into sprintf.
		$this->l->method('n')->willReturnCallback(function (string $singular, string $plural, int $count, array $params = []): string {
			return vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $params);
		});
	}

	private function service(): ResponseService {
		return new ResponseService(
			$this->formRepository,
			$this->fileLocator,
			$this->responsePersistence,
			$this->indexService,
			$this->urlGenerator,
			$this->l,
			$this->logger,
			new AnswerValidator($this->createMock(UploadService::class), new EnglishL10N()),
			new ReversibleCrypto(),
			new FormSanitizer(),
			new ResponseRules(new AnswerValidator($this->createMock(UploadService::class), new EnglishL10N()), $this->indexService)
		);
	}

	/** The export table as the CSV and XLSX exports get it; null when there are no responses. */
	private function exportData(int $fileId): ?array {
		$rows = iterator_to_array($this->call('exportRows', [$fileId]), false);
		return $rows === [] ? null : ['headers' => $rows[0], 'rows' => array_slice($rows, 1)];
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
	// fingerprint — keyed, per form, without the share token (M13, L5, M34, T40)
	// =====================================================================

	private function request(string $ip = '1.2.3.4', string $ua = 'UA'): IRequest {
		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn($ip);
		$req->method('getHeader')->willReturn($ua);
		return $req;
	}

	public function testTheFingerprintIsKeyedAndCannotBeReadBackToTheNetwork(): void {
		$fp = $this->call('fingerprint', [7, $this->request(), null]);

		$this->assertStringStartsWith('v2:', $fp);
		$this->assertSame($fp, $this->call('fingerprint', [7, $this->request(), null]), 'the same visitor is recognised');
		$this->assertStringNotContainsString('1.2.3.4', $fp);
		$this->assertNotSame(hash('sha256', '1.2.3.4|UA'), substr($fp, 3));
	}

	public function testTheFingerprintBelongsToOneForm(): void {
		$this->assertNotSame($this->call('fingerprint', [7, $this->request(), null]), $this->call('fingerprint', [8, $this->request(), null]));
	}

	public function testASignedInRespondentIsCountedPerAccountWhereverTheyAre(): void {
		$here = $this->call('fingerprint', [7, $this->request('192.0.2.1', 'Firefox'), 'anna']);
		$elsewhere = $this->call('fingerprint', [7, $this->request('198.51.100.9', 'Safari'), 'anna']);

		$this->assertSame($here, $elsewhere);
		$this->assertNotSame($here, $this->call('fingerprint', [7, $this->request('192.0.2.1', 'Firefox'), 'bob']));
		$this->assertStringNotContainsString('anna', $here, 'the account is not stored');
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

	public function testTheSummaryAveragesTheNumbersGiven(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'number', 'question' => 'N']],
			'responses' => [
				['answers' => ['q1' => '2']],
				['answers' => ['q1' => '4']],
				['answers' => ['q1' => 'notnum']],
				['answers' => []],
			],
		]);
		$question = $this->service()->getSummary(1)['questions'][0];
		$this->assertSame([3.0, 2.0, 4.0], [$question['average'], $question['min'], $question['max']]);
		$this->assertSame(3, $question['uniqueAnswers'], 'what is no number is a different answer too');
	}

	/** A '1e400' stored before numbers were checked for it must not take the whole results page down. */
	public function testTheSummaryLeavesOutANumberTooLargeToHold(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'number', 'question' => 'N']],
			'responses' => [
				['answers' => ['q1' => '2']],
				['answers' => ['q1' => '1e400']],
				['answers' => ['q1' => '4']],
			],
		]);
		$summary = $this->service()->getSummary(1);
		$question = $summary['questions'][0];
		$this->assertSame([3.0, 2.0, 4.0], [$question['average'], $question['min'], $question['max']]);
		$this->assertIsString(json_encode($summary, JSON_THROW_ON_ERROR));
	}

	public function testTheSummaryOfNoNumbersHasNoAverage(): void {
		$this->formRepository->method('loadForm')->willReturn(['questions' => [['id' => 'q1', 'type' => 'rating', 'question' => 'R']], 'responses' => []]);
		$question = $this->service()->getSummary(1)['questions'][0];
		$this->assertSame([null, null, null], [$question['average'], $question['min'], $question['max']]);
	}

	// =====================================================================
	// buildExportData
	// =====================================================================

	public function testBuildExportDataNullWhenNoResponses(): void {
		$this->formRepository->method('loadForm')->willReturn(['questions' => [], 'responses' => []]);
		$this->assertNull($this->exportData(1));
	}

	public function testBuildExportDataHeadersAndRows(): void {
		$this->formRepository->method('loadForm')->willReturn([
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
		$data = $this->exportData(1);
		// Section excluded from headers.
		$this->assertSame(['Response ID', 'Submitted at', 'Respondent type', 'Respondent ID', 'Name'], $data['headers']);
		$this->assertSame(['r1', '2020-01-01T00:00:00', 'user', 'bob', 'Alice'], $data['rows'][0]);
	}

	/** The fingerprint only tells the server about repeats; an export does not carry it (M13, T40). */
	public function testAnExportDoesNotCarryTheFingerprint(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'title' => 'T',
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']],
			'responses' => [[
				'id' => 'r1', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'fp123'],
				'answers' => ['q1' => 'x'],
			]],
		]);

		$data = $this->exportData(1);

		$this->assertSame('', $data['rows'][0][3]);
		$this->assertStringNotContainsString('fp123', json_encode($data));
		$this->assertStringNotContainsString('fp123', $this->service()->exportJson(1));
		$this->assertStringNotContainsString('fp123', json_encode($this->service()->getResponses(1)));
	}

	/** An API response from before respondents were recorded has none; the export says so instead of failing (M30). */
	public function testAnExportNamesAnOldApiResponse(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']],
			'responses' => [['id' => 'r1', 'submitted_at' => 't', 'source' => 'api', 'answers' => ['q1' => 'x']]],
		]);

		$this->assertSame('api', $this->exportData(1)['rows'][0][2]);
	}

	public function testBuildExportDataConsentYesNo(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'consent', 'question' => 'Agree']],
			'responses' => [
				['id' => 'r1', 'submitted_at' => 't', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'], 'answers' => ['q1' => true]],
				['id' => 'r2', 'submitted_at' => 't', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'], 'answers' => ['q1' => false]],
				['id' => 'r3', 'submitted_at' => 't', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'], 'answers' => []],
			],
		]);
		$data = $this->exportData(1);
		$this->assertSame('Yes', $data['rows'][0][4]);
		$this->assertSame('No', $data['rows'][1][4]);
		$this->assertSame('', $data['rows'][2][4]);
	}

	public function testBuildExportDataMatrix(): void {
		$this->formRepository->method('loadForm')->willReturn([
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
		$data = $this->exportData(1);
		$this->assertSame('Row1: Col1', $data['rows'][0][4]);
	}

	public function testBuildExportDataTableJson(): void {
		$this->formRepository->method('loadForm')->willReturn([
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
		$data = $this->exportData(1);
		// Column id relabelled to 'Price' and JSON-encoded.
		$this->assertSame(json_encode([['Price' => '10']], JSON_UNESCAPED_UNICODE), $data['rows'][0][4]);
	}

	public function testBuildExportDataChoiceOptionMapping(): void {
		$this->formRepository->method('loadForm')->willReturn([
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
		$data = $this->exportData(1);
		$this->assertSame('Yes', $data['rows'][0][4]);
	}

	public function testBuildExportDataMultipleOptionMappingJoined(): void {
		$this->formRepository->method('loadForm')->willReturn([
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
		$data = $this->exportData(1);
		$this->assertSame('A, B', $data['rows'][0][4]);
	}

	public function testBuildExportDataSanitizesInjectionInAnswerAndHeader(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => '=DANGER']],
			'responses' => [[
				'id' => 'x', 'submitted_at' => 't',
				'respondent' => ['type' => 'anonymous', 'fingerprint' => 'f'],
				'answers' => ['q1' => '=HYPERLINK("evil")'],
			]],
		]);
		$data = $this->exportData(1);
		$this->assertSame("'=DANGER", $data['headers'][4]);
		$this->assertSame('\'=HYPERLINK("evil")', $data['rows'][0][4]);
	}

	// =====================================================================
	// exportCsv / exportXlsx / exportJson (public)
	// =====================================================================

	public function testExportCsvEmptyWhenNoResponses(): void {
		$this->formRepository->method('loadForm')->willReturn(['questions' => [], 'responses' => []]);
		$this->assertSame('', $this->service()->exportCsv(1));
	}

	public function testExportCsvHasBomAndSemicolons(): void {
		$this->formRepository->method('loadForm')->willReturn([
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
		$this->assertStringContainsString('"Response ID";"Submitted at"', $csv);
		// Data row terminated with CRLF; Alice value present.
		$this->assertStringContainsString('Alice', $csv);
		$this->assertStringContainsString("\r\n", $csv);
	}

	public function testExportXlsxEmptyWhenNoResponses(): void {
		$this->formRepository->method('loadForm')->willReturn(['questions' => [], 'responses' => []]);
		$this->assertSame('', $this->service()->exportXlsx(1));
	}

	public function testExportXlsxProducesValidZipWithSheet(): void {
		if (!class_exists(\ZipArchive::class)) {
			$this->markTestSkipped('ZipArchive extension not available');
		}
		$this->formRepository->method('loadForm')->willReturn([
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
		$this->formRepository->method('loadForm')->willReturn([
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
		$this->formRepository->method('loadForm')->willReturn([
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

	/** Written a response at a time, it is laid out byte for byte as json_encode lays out the whole. */
	public function testTheJsonExportIsLaidOutAsTheWholeWouldBe(): void {
		$form = [
			'title' => 'Formulier ü',
			'questions' => [['id' => 'q1', 'type' => 'matrix', 'question' => 'Q', 'rows' => [['id' => 'r']], 'columns' => []]],
			'responses' => [
				['id' => 'r1', 'answers' => ['q1' => ['r' => 'c'], 'q2' => []], 'respondent' => ['type' => 'user', 'user_id' => 'bob']],
				['id' => 'r2', 'answers' => ['q1' => "two\nlines"], 'score' => ['total' => 1.5]],
			],
		];
		$this->formRepository->method('loadForm')->willReturn($form);

		$json = $this->service()->exportJson(1);

		$whole = json_encode([
			'title' => $form['title'],
			'exportedAt' => json_decode($json, true)['exportedAt'],
			'questions' => $form['questions'],
			'responses' => (new FormSanitizer())->responses($form['responses']),
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		$this->assertSame($whole, $json);
	}

	public function testExportJsonNoResponsesKey(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'title' => 'T', 'questions' => [],
		]);
		$decoded = json_decode($this->service()->exportJson(1), true);
		$this->assertSame([], $decoded['responses']);
	}

	// =====================================================================
	// getResponses / getSummary
	// =====================================================================

	public function testGetResponsesReturnsAll(): void {
		$this->formRepository->method('loadForm')->willReturn(['responses' => [['id' => 'r1']]]);
		$this->assertSame([['id' => 'r1']], $this->service()->getResponses(1));
	}

	public function testGetResponsesEmptyWhenNoKey(): void {
		$this->formRepository->method('loadForm')->willReturn([]);
		$this->assertSame([], $this->service()->getResponses(1));
	}

	public function testGetResponsesWithDateFilterKeepsThatDay(): void {
		$this->formRepository->method('loadForm')->willReturn(['responses' => [
			['id' => 'd1', 'submitted_at' => '2020-01-01T09:00:00+00:00'],
			['id' => 'd2', 'submitted_at' => '2020-01-02T09:00:00+00:00'],
		]]);
		$this->assertSame(['d1'], array_column($this->service()->getResponses(1, '2020-01-01'), 'id'));
	}

	public function testGetSummaryBuildsQuestions(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'questions' => [
				['id' => 'q1', 'type' => 'choice', 'question' => 'C', 'options' => [['value' => 'a']]],
				['id' => 'sec', 'type' => 'section', 'question' => 'S'],
				['id' => 'q2', 'type' => 'number', 'question' => 'N'],
			],
			'responses' => [
				['submitted_at' => '2026-01-01T09:00:00+00:00', 'answers' => ['q1' => 'a']],
				['submitted_at' => '2026-01-02T09:00:00+00:00', 'answers' => ['q1' => 'a', 'q2' => 5]],
			],
		]);
		$this->indexService = new IndexService();
		$summary = $this->service()->getSummary(1);
		$this->assertSame(2, $summary['responseCount']);
		$this->assertSame('2026-01-02T09:00:00+00:00', $summary['lastResponseAt']);
		$this->assertSame(['a' => 2], $summary['questions'][0]['answerCounts']);
		// section excluded
		$this->assertCount(2, $summary['questions']);
		$this->assertSame('q1', $summary['questions'][0]['id']);
		// choice keeps options
		$this->assertArrayHasKey('options', $summary['questions'][0]);
		// number gets average/min/max keys
		$this->assertArrayHasKey('average', $summary['questions'][1]);
	}

	public function testGetSummaryMatrixKeepsRowsColumns(): void {
		$this->formRepository->method('loadForm')->willReturn([
			'questions' => [[
				'id' => 'q1', 'type' => 'matrix', 'question' => 'M',
				'rows' => [['id' => 'r']], 'columns' => [['value' => 'c']],
			]],
			'responses' => [],
		]);
		$summary = $this->service()->getSummary(1);
		$this->assertArrayHasKey('rows', $summary['questions'][0]);
		$this->assertArrayHasKey('columns', $summary['questions'][0]);
	}

	// =====================================================================
	// submitAnonymousWithForm / submitAuthenticated: one shared way in for the form page
	// =====================================================================

	public function testSubmitAnonymousHappyPath(): void {
		$form = [
			'title' => 'F',
			'settings' => ['allow_multiple' => true],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']],
		];
		// Stored with what it sets off, in the respondent's language; sent by a background job, not while they wait.
		$this->responsePersistence->expects($this->once())->method('appendResponsePublic')
			->with(1, $this->anything(), [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY, ResponseOutbox::MAIL], 'nl')
			->willReturn(['ok' => true]);

		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('ip');
		$req->method('getHeader')->willReturn('ua');

		$result = $this->service()->submitAnonymousWithForm(1, $form, ['q1' => 'answer'], $req);
		$this->assertSame(['ok' => true], $result);
	}

	/** A retry of a stored submission is answered as stored, also where only one answer is allowed (7a, 3a-5). */
	public function testARetryOfAStoredSubmissionIsAnsweredAsStored(): void {
		$stored = ['id' => 'r1', 'submitted_at' => 'then', 'score' => ['total' => 3]];
		$form = ['settings' => [], 'questions' => [], 'responses' => [$stored]];
		$form[SubmissionIds::KEY] = [SubmissionIds::of('sub-0000000000000001', []) => 'r1'];
		// The fingerprint is there: as a new submission it would be "already answered".
		$this->indexService->method('hasFingerprint')->willReturn(true);
		$this->responsePersistence->expects($this->never())->method('appendResponsePublic');
		$req = $this->createMock(IRequest::class);

		$this->assertSame($stored, $this->service()->submitAnonymousWithForm(1, $form, [], $req, null, 'sub-0000000000000001'));
	}

	public function testSubmitAnonymousDuplicateThrows(): void {
		$form = ['settings' => [], 'questions' => []];
		$this->indexService->method('hasFingerprint')->willReturn(true);
		$this->responsePersistence->expects($this->never())->method('appendResponsePublic');
		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('ip');
		$req->method('getHeader')->willReturn('ua');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('You have already submitted');
		$this->service()->submitAnonymousWithForm(1, $form, [], $req);
	}

	public function testSubmitAnonymousExpiredThrows(): void {
		$form = ['settings' => ['share_expires_at' => '2000-01-01T00:00:00Z'], 'questions' => []];
		$this->responsePersistence->expects($this->never())->method('appendResponsePublic');
		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('ip');
		$req->method('getHeader')->willReturn('ua');
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('This form has expired');
		$this->service()->submitAnonymousWithForm(1, $form, [], $req);
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
		$captured = null;
		$this->responsePersistence->method('appendResponsePublic')
			->willReturnCallback(function ($id, $response) use (&$captured) {
				$captured = $response;
				return ['ok' => true];
			});

		$req = $this->createMock(IRequest::class);
		$req->method('getRemoteAddress')->willReturn('ip');
		$req->method('getHeader')->willReturn('ua');

		$this->service()->submitAnonymousWithForm(1, $form, ['q1' => 'a'], $req);
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
		$captured = null;
		$this->responsePersistence->method('appendResponsePublic')
			->willReturnCallback(function ($id, $response) use (&$captured) {
				$captured = $response;
				return ['ok' => true];
			});

		$result = $this->service()->submitAuthenticated(1, $form, ['q1' => 'x'], 'bob', 'Bob');
		$this->assertSame(['ok' => true], $result);
		$this->assertSame('user', $captured['respondent']['type']);
		$this->assertSame('bob', $captured['respondent']['user_id']);
		$this->assertSame('Bob', $captured['respondent']['display_name']);
	}

	/** Signed in, named or not: the confirmation may carry the PDF. */
	public function testWhetherTheRespondentWasSignedInGoesAlong(): void {
		$form = ['title' => 'F', 'settings' => ['allow_multiple' => true, 'anonymous' => true], 'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']]];
		$signedIn = [];
		$this->responsePersistence->method('appendResponsePublic')->willReturnCallback(function ($id, $response, $effects, $language, $submission, $flag) use (&$signedIn) {
			$signedIn[] = $flag;
			return $response;
		});
		$request = $this->createMock(\OCP\IRequest::class);

		$this->service()->submitAuthenticated(1, $form, ['q1' => 'x'], 'bob', 'Bob');
		$this->service()->submitAnonymousWithForm(1, $form, ['q1' => 'y'], $request, null, null, null, 'bob');
		$this->service()->submitAnonymousWithForm(1, $form, ['q1' => 'z'], $request);

		$this->assertSame([true, true, false], $signedIn);
	}

	/** The respondent's language and, where their browser named a real one, time zone: for the PDF of the response. */
	public function testAResponseKeepsItsLanguageAndARealTimeZone(): void {
		$form = ['title' => 'F', 'settings' => ['allow_multiple' => true], 'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q']]];
		$captured = [];
		$this->responsePersistence->method('appendResponsePublic')->willReturnCallback(function ($id, $response) use (&$captured) {
			$captured[] = $response;
			return $response;
		});

		$this->service()->submitAuthenticated(1, $form, ['q1' => 'x'], 'bob', 'Bob', null, 'Europe/Berlin');
		$this->service()->submitAuthenticated(1, $form, ['q1' => 'y'], 'bob', 'Bob', null, '<script>');

		$this->assertSame('Europe/Berlin', $captured[0]['timezone']);
		$this->assertArrayNotHasKey('timezone', $captured[1]);
		$this->assertSame('nl', $captured[0]['language']);
	}

	public function testSubmitAuthenticatedDuplicateThrows(): void {
		$form = ['settings' => [], 'questions' => []];
		$this->indexService->method('hasUserResponse')->willReturn(true);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('You have already submitted');
		$this->service()->submitAuthenticated(1, $form, [], 'bob', 'Bob');
	}

	public function testSubmitAuthenticatedValidationErrorBubbles(): void {
		$form = [
			'settings' => ['allow_multiple' => true],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Q', 'required' => true]],
		];
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("Question 'Q' is required");
		$this->service()->submitAuthenticated(1, $form, [], 'bob', 'Bob');
	}

	// =====================================================================
	// The external API's writes: the public form's checks, one response under the lock (H8, M16, Q7, M30)
	// =====================================================================

	private function apiForm(array $settings = []): array {
		return ['settings' => $settings, 'questions' => [['id' => 'c', 'type' => 'choice', 'question' => 'Colour', 'options' => [['value' => 'red']]]]];
	}

	/** The persistence layer holds it to the form's rules under the lock (ResponsePersistenceServiceTest). */
	public function testAnApiResponseIsCheckedAndAddedThroughTheSharedRules(): void {
		$file = $this->createMock(File::class);
		$this->responsePersistence->expects($this->once())->method('append')
			->with($file, $this->callback(function (array $response) {
				return $response['answers'] === ['c' => 'red']
					&& $response['respondent'] === ['type' => 'api', 'key_id' => 'k1', 'display_name' => 'CRM']
					&& $response['source'] === 'api'
					&& preg_match('/^[0-9a-f]{32}$/', $response['id']) === 1;
			}), [ResponseOutbox::WEBHOOKS, ResponseOutbox::NOTIFY], 'nl')
			->willReturnArgument(1);

		$this->service()->submitViaApi(7, $file, $this->apiForm(), ['c' => 'red', 'invented' => 'x'], ['id' => 'k1', 'name' => 'CRM']);
	}

	public function testAnApiResponseToAFullFormIsRefusedBeforeAnythingIsWritten(): void {
		$this->indexService->method('getResponseCount')->willReturn(5);
		$this->responsePersistence->expects($this->never())->method('append');

		$this->expectException(ResponseRefusedException::class);
		$this->service()->submitViaApi(7, $this->createMock(File::class), $this->apiForm(['max_responses' => 5]), ['c' => 'red'], ['id' => 'k1']);
	}

	public function testAnApiResponseToAClosedFormIsRefusedBeforeAnythingIsWritten(): void {
		$this->responsePersistence->expects($this->never())->method('append');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('This form has expired');
		$this->service()->submitViaApi(7, $this->createMock(File::class), $this->apiForm(['share_expires_at' => '2001-01-01T00:00:00Z']), ['c' => 'red'], ['id' => 'k1']);
	}

	public function testAnApiResponseBeforeTheFormOpensIsRefused(): void {
		$this->responsePersistence->expects($this->never())->method('append');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('This form is not yet open');
		$this->service()->submitViaApi(7, $this->createMock(File::class), $this->apiForm(['share_starts_at' => '2999-01-01T00:00:00Z']), ['c' => 'red'], ['id' => 'k1']);
	}

	public function testAnApiResponseThatDoesNotFitIsRefused(): void {
		$this->responsePersistence->expects($this->never())->method('append');

		$this->expectException(InvalidAnswerException::class);
		$this->service()->submitViaApi(7, $this->createMock(File::class), $this->apiForm(), ['c' => 'purple'], ['id' => 'k1']);
	}

	public function testAnApiUpdateChangesOneResponseAndRecordsTheKey(): void {
		$stored = ['id' => 'r1', 'answers' => ['c' => 'old'], 'respondent' => ['type' => 'user', 'user_id' => 'anna']];
		$this->responsePersistence->expects($this->once())->method('update')
			->willReturnCallback(fn (File $f, string $id, callable $change) => $change($stored));

		$updated = $this->service()->updateViaApi(7, $this->createMock(File::class), $this->apiForm(), 'r1', ['c' => 'red'], ['id' => 'k9']);

		$this->assertSame(['c' => 'red'], $updated['answers']);
		$this->assertSame('api:k9', $updated['updated_by']);
		$this->assertSame('anna', $updated['respondent']['user_id'], 'who answered stays who answered');
	}

	/**
	 * One delete path for web and API, and for deleting everything; the webhooks of each follow from the background job,
	 * queued by the persistence in the same write (ResponsePersistenceServiceTest) (L33, PO, review 3a-4).
	 */
	public function testDeletingGoesThroughTheOneDeletePath(): void {
		$file = $this->createMock(File::class);
		$this->responsePersistence->expects($this->once())->method('delete')->with($file, 'r1')->willReturn([['id' => 'r1'], []]);
		$this->responsePersistence->expects($this->once())->method('deleteAll')->with($file)->willReturn([[], []]);

		$this->service()->deleteResponse(7, $file, 'r1');
		$this->service()->deleteAllResponses(7, $file);
	}
}
