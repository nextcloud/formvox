<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCA\FormVox\Tests\Unit\Support\PdfDocuments;
use PHPUnit\Framework\TestCase;

/**
 * A response as a document: each question the respondent saw, with its answer as they read it on the form, in their
 * language; hidden questions and static text left out, and nothing of the form or the answers taken as markup.
 */
class ResponseDocumentTest extends TestCase {
	private function html(array $questions, array $answers, array $extra = [], array $extraResponse = []): string {
		$form = ['title' => 'Schulfest', 'questions' => $questions] + $extra;
		$response = ['id' => 'r-1', 'submitted_at' => '2026-10-26T14:03:00+00:00', 'answers' => $answers];
		return PdfDocuments::document($this)->html($form, $response + $extraResponse, new EnglishL10N());
	}

	public function testTheTitleWhenItWasSentAndWhichResponse(): void {
		$html = $this->html([], []);

		$this->assertStringContainsString('<h1>Schulfest</h1>', $html);
		$this->assertStringContainsString('Submitted on 2026-10-26 14:03 (UTC)', $html);
		$this->assertStringContainsString('<p class="rid">Response r-1</p>', $html);
	}

	/** The response's id and the page numbers at the foot of every page; the title is at the top. */
	public function testTheFooterNamesTheResponse(): void {
		$this->assertSame(['Response r-1', 'Page {current} of {total}'], PdfDocuments::document($this)->footer(['id' => 'r-1'], new EnglishL10N()));
		$this->assertStringNotContainsString('footer', $this->html([], []));
	}

	/** The name the PDF goes by: the day it was sent as the document shows it, in the respondent's zone. */
	public function testItsFileName(): void {
		$document = PdfDocuments::document($this);
		$form = ['title' => 'Anmeldung für das Schulfest'];
		$late = ['id' => 'r-1', 'submitted_at' => '2026-11-03T23:30:00+00:00'];

		$this->assertSame('Anmeldung-fuer-das-Schulfest_2026-11-03_r-1.pdf', $document->fileName($form, $late));
		$this->assertSame('Anmeldung-fuer-das-Schulfest_2026-11-04_r-1.pdf', $document->fileName($form, $late + ['timezone' => 'Europe/Berlin']));
		$this->assertSame('Anmeldung-fuer-das-Schulfest_r-1.pdf', $document->fileName($form, ['id' => 'r-1']), 'no day it was sent: none in the name');
		$this->assertSame('Anmeldung-fuer-das-Schulfest_r-1.pdf', $document->fileName($form, ['id' => 'r-1', 'submitted_at' => 'not a day']));
	}

	/** @return array<string, array{array, string}> */
	public static function respondents(): array {
		return [
			'signed in' => [['respondent' => ['type' => 'user', 'user_id' => 'anna', 'display_name' => 'Anna <Jansen>']], 'Anna &lt;Jansen&gt;'],
			'anonymous' => [['respondent' => ['type' => 'anonymous', 'fingerprint' => 'f']], 'Anonymous'],
			'anonymous, though a name came along' => [['respondent' => ['type' => 'anonymous', 'display_name' => 'Anna']], 'Anonymous'],
			'an API key' => [['respondent' => ['type' => 'api', 'key_id' => 'k', 'display_name' => 'Windmill']], 'Windmill (API)'],
			'an API key without a name' => [['respondent' => ['type' => 'api', 'key_id' => 'k']], 'API'],
			'an older API response' => [['source' => 'api'], 'API'],
			'a person, though marked as from the API' => [['source' => 'api', 'respondent' => ['type' => 'user', 'display_name' => 'Anna']], 'Anna'],
			'imported, named' => [['respondent' => ['type' => 'external', 'display_name' => 'Ben']], 'Ben'],
			'a person without a name' => [['respondent' => ['type' => 'user', 'user_id' => 'x', 'display_name' => '']], 'Anonymous'],
			'nobody named' => [[], 'Anonymous'],
		];
	}

	/**
	 * Who filled it in, first under the title, as the results name them.
	 *
	 * @dataProvider respondents
	 */
	public function testWhoFilledItIn(array $response, string $name): void {
		$html = $this->html([], [], [], $response);

		$this->assertStringContainsString('<p class="meta">Filled in by: ' . $name . ' · Submitted on', $html);
	}

	public function testTheFormsDescriptionUnderTheTitle(): void {
		$html = $this->html([], [], ['description' => "Fijn dat je **komt**!\n<b>x</b>"]);

		$this->assertStringContainsString('<h1>Schulfest</h1><div class="intro">Fijn dat je komt!<br>' . "\n" . '&lt;b&gt;x&lt;/b&gt;</div>', $html);
	}

	/** The instance's own name, colours and logo head the document, as Nextcloud's header does. */
	public function testTheInstancesThemingHeadsTheDocument(): void {
		$logo = 'data:image/png;base64,' . base64_encode('png');
		$html = PdfDocuments::document($this, 'UTC', ['name' => 'Schul<cloud>', 'color' => '#123456', 'textColor' => '#000000', 'logo' => $logo])
			->html(['title' => 'Schulfest', 'questions' => []], ['id' => 'r-1', 'answers' => []], new EnglishL10N());

		$this->assertStringContainsString('<table class="band" style="background-color:#123456">', $html);
		$this->assertStringContainsString('<img class="logo" src="' . $logo . '" alt="">', $html);
		$this->assertStringContainsString('<td class="band-name right" style="color:#000000">Schul&lt;cloud&gt;</td>', $html);
	}

	public function testWithoutALogoTheNameAlone(): void {
		$html = $this->html([], []);

		$this->assertStringContainsString('<tr><td class="band-name" style="color:#ffffff">Schulcloud</td></tr>', $html);
	}

	public function testEachAnswerAsTheRespondentReadIt(): void {
		$html = $this->html([
			['id' => 'c', 'type' => 'choice', 'question' => 'Class', 'options' => [['value' => 'a', 'label' => '5A'], ['value' => 'b', 'label' => '5B']]],
			['id' => 'm', 'type' => 'multiple', 'question' => 'Help', 'options' => [['value' => 'x', 'label' => 'Cake'], ['value' => 'y', 'label' => 'Music']]],
			['id' => 'k', 'type' => 'consent', 'question' => 'Photos'],
			['id' => 'd', 'type' => 'date', 'question' => 'Day'],
			['id' => 's', 'type' => 'scale', 'question' => 'Mood', 'scaleMax' => 10],
			['id' => 'x', 'type' => 'matrix', 'question' => 'Rate', 'rows' => [['id' => 'r1', 'label' => 'Food']], 'columns' => [['value' => '1', 'label' => 'Good']]],
			['id' => 't', 'type' => 'table', 'question' => 'Guests', 'columns' => [['id' => 'n', 'label' => 'Name']]],
			['id' => 'f', 'type' => 'file', 'question' => 'Upload'],
			['id' => 'a', 'type' => 'textarea', 'question' => 'Notes'],
		], [
			'c' => 'b', 'm' => ['x', 'y'], 'k' => true, 'd' => '2026-11-02', 's' => 7, 'x' => ['r1' => '1'],
			't' => [['n' => 'Anna']], 'f' => ['filename' => 'a-1.pdf', 'originalName' => 'Anmeldung.pdf'], 'a' => "one\ntwo",
		]);

		foreach (['5B', '<li>Cake</li><li>Music</li>', 'Yes', '2026-11-02', '7 / 10', '<td>Food</td><td>Good</td>', '<th>Name</th>', '<td>Anna</td>', 'Anmeldung.pdf', "one<br>\ntwo"] as $expected) {
			$this->assertStringContainsString($expected, $html);
		}
	}

	public function testAQuestionLeftEmptySaysSo(): void {
		$this->assertStringContainsString('Not answered', $this->html([['id' => 'q', 'type' => 'text', 'question' => 'Name']], []));
	}

	/** As on the form page: a question its condition hides, and static text, are not in the document. */
	public function testHiddenQuestionsAndStaticTextAreLeftOut(): void {
		$html = $this->html([
			['id' => 'go', 'type' => 'choice', 'question' => 'Coming?', 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
			['id' => 'why', 'type' => 'text', 'question' => 'Why not?', 'showIf' => ['questionId' => 'go', 'operator' => 'equals', 'value' => 'no']],
			['id' => 'info', 'type' => 'descriptor', 'question' => 'Bring a plate'],
			['id' => 'sec', 'type' => 'section', 'question' => 'Details'],
			['id' => 'diet', 'type' => 'text', 'question' => 'Diet', 'sectionId' => 'sec'],
		], ['go' => 'yes', 'why' => 'left over', 'diet' => 'none']);

		$this->assertStringNotContainsString('Why not?', $html);
		$this->assertStringNotContainsString('left over', $html);
		$this->assertStringNotContainsString('Bring a plate', $html);
		$this->assertStringContainsString('<h2>Details</h2>', $html);
	}

	/** The respondent's and the form's text is text: never markup that could reach a file or the network. */
	public function testNothingIsTakenAsMarkup(): void {
		$html = $this->html([['id' => 'q', 'type' => 'text', 'question' => '<b>Name</b>']], ['q' => '<img src="http://evil.example/x.png"><script>x</script>'], ['title' => '<style>@import url(http://evil.example)</style>']);

		$this->assertStringNotContainsString('<img', $html);
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringNotContainsString('<b>Name', $html);
		$this->assertStringNotContainsString('@import url(http', $html);
		$this->assertStringContainsString('&lt;img src=&quot;http://evil.example/x.png&quot;&gt;', $html);
	}

	/** At the respondent's clock: the page stores a moment in UTC, the respondent picked it in their own zone. */
	public function testTimesAreAtTheRespondentsClock(): void {
		$html = $this->html([['id' => 'w', 'type' => 'datetime', 'question' => 'When'], ['id' => 'd', 'type' => 'date', 'question' => 'Day']],
			['w' => '2026-11-03T23:30:00.000Z', 'd' => '2026-11-04'], [], ['timezone' => 'Europe/Berlin']);

		$this->assertStringContainsString('2026-11-04 00:30', $html);
		$this->assertStringContainsString('2026-11-04', $html);
		$this->assertStringContainsString('Submitted on 2026-10-26 15:03 (Europe/Berlin)', $html);
	}

	public function testAZoneTheBrowserMadeUpIsNotUsed(): void {
		$this->assertStringContainsString('(UTC)', $this->html([], [], [], ['timezone' => 'Mars/Olympus']));
	}

	/** In the order the respondent met them: the pages visited, each page's order, a section's questions under its heading. */
	public function testPagesAndSectionsAsTheRespondentSawThem(): void {
		$html = $this->html([
			['id' => 'phone', 'type' => 'text', 'question' => 'Phone', 'sectionId' => 'you'],
			['id' => 'name', 'type' => 'text', 'question' => 'Name', 'sectionId' => 'you'],
			['id' => 'you', 'type' => 'section', 'question' => 'Your details'],
			['id' => 'other', 'type' => 'section', 'question' => 'Nobody saw this'],
			['id' => 'note', 'type' => 'text', 'question' => 'Note'],
		], ['name' => 'Anna', 'phone' => '123', 'note' => 'n'], ['pages' => [
			['id' => 'p1', 'questions' => ['you', 'name', 'phone']],
			['id' => 'p2', 'questions' => ['other', 'note']],
		]]);

		$this->assertMatchesRegularExpression('#<h2>Your details</h2>.*Name.*Phone.*Note#s', $html);
		$this->assertStringNotContainsString('Nobody saw this', $html, 'a heading only over questions shown');
	}

	/** {{Q1}} and {{id}} are the earlier answers, as on the form page. */
	public function testReferencesToEarlierAnswersAreFilledIn(): void {
		$html = $this->html([
			['id' => 'name', 'type' => 'text', 'question' => 'Name'],
			['id' => 'role', 'type' => 'text', 'question' => 'Hello {{Q1}}, what does {{name}} do? {{Q9}}'],
		], ['name' => 'Anna <b>', 'role' => 'x']);

		$this->assertStringContainsString('Hello Anna &lt;b&gt;, what does Anna &lt;b&gt; do? {{Q9}}', $html);
	}

	/** An answer to a question the respondent could not see is not filled in elsewhere either. */
	public function testOnlyAnswersTheRespondentSawAreFilledIn(): void {
		$html = $this->html([
			['id' => 'go', 'type' => 'choice', 'question' => 'Coming?', 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
			['id' => 'why', 'type' => 'text', 'question' => 'Why not?', 'showIf' => ['questionId' => 'go', 'operator' => 'equals', 'value' => 'no']],
			['id' => 'next', 'type' => 'text', 'question' => 'You said {{why}}, {{go}}'],
		], ['go' => 'yes', 'why' => 'left over', 'next' => 'x']);

		$this->assertStringContainsString('You said {{why}}, yes', $html);
		$this->assertStringNotContainsString('left over', $html);
	}

	/** The page hands in its empty starting rows: a table nobody filled in was not answered. */
	public function testATableLeftEmptyIsNotAnswered(): void {
		$question = ['id' => 't', 'type' => 'table', 'question' => 'Guests', 'columns' => [['id' => 'n', 'label' => 'Name']]];

		$this->assertStringContainsString('Not answered', $this->html([$question], ['t' => [['n' => ''], ['n' => ' ']]]));
		$this->assertSame(1, substr_count($this->html([$question], ['t' => [['n' => ''], ['n' => 'Anna']]]), '<td>'));
	}

	public function testAQuizScoreIsShown(): void {
		$this->assertStringContainsString('Score: 7 / 10 (70%)', $this->html([], [], [], ['score' => ['total' => 7, 'max' => 10, 'percentage' => 70]]));
	}

	/** What the page showed under a question, and the statement a consent was given to. */
	public function testDescriptionsAndTheConsentStatementAreShown(): void {
		$html = $this->html([
			['id' => 'k', 'type' => 'consent', 'question' => 'Terms', 'description' => "We keep your data\nfor a year.", 'consentLabel' => 'I agree to the terms'],
		], ['k' => true]);

		$this->assertStringContainsString("We keep your data<br>\nfor a year.", $html);
		$this->assertStringContainsString('Yes: I agree to the terms', $html);
	}

	/** A section title as the page shows it: not filled in, and none where it has none. */
	public function testSectionTitlesAsThePageShowsThem(): void {
		$html = $this->html([
			['id' => 'n', 'type' => 'text', 'question' => 'Name'],
			['id' => 's', 'type' => 'section', 'question' => 'For {{Q1}}'],
			['id' => 'q', 'type' => 'text', 'question' => 'Q', 'sectionId' => 's'],
			['id' => 'q2', 'type' => 'text', 'question' => 'Q2', 'sectionId' => 'gone'],
		], ['n' => 'Anna', 'q' => 'x', 'q2' => 'y']);

		$this->assertStringContainsString('<h2>For {{Q1}}</h2>', $html);
		$this->assertSame(1, substr_count($html, '<h2>'));
	}

	/** Older zone names browsers send, and a time the API sent without an offset, at the respondent's clock. */
	public function testOlderZoneNamesAndTimesWithoutAnOffset(): void {
		$html = $this->html([['id' => 'w', 'type' => 'datetime', 'question' => 'When']], ['w' => '2026-10-26T14:30'], [], ['timezone' => 'Asia/Calcutta']);

		$this->assertStringContainsString('(Asia/Calcutta)', $html);
		$this->assertStringContainsString('2026-10-26 14:30', $html);
	}

	/** dompdf lays out one long text in quadratic time: in pieces it is linear, and the reader sees the same text. */
	public function testALongAnswerIsDrawnInPieces(): void {
		$answer = str_repeat('Worte ', 1250) . 'Ende';
		$html = $this->html([['id' => 'a', 'type' => 'textarea', 'question' => 'Notes']], ['a' => $answer]);

		preg_match('#<div class="answer">(.*?)</div>#s', $html, $match);
		$this->assertSame(4, substr_count($match[1], '<span>'));
		$this->assertSame(3, substr_count($match[1], ' </span><span>'), 'cut after a space, where the line may break anyway');
		$this->assertSame($answer, strip_tags($match[1]));
	}

	/** A word longer than half a piece is cut where the piece ends, not before it. */
	public function testALongWordIsCutAtThePiecesEnd(): void {
		$html = $this->html([['id' => 'a', 'type' => 'textarea', 'question' => 'Notes']], ['a' => 'a ' . str_repeat('b', 5000)]);

		preg_match('#<div class="answer">(.*?)</div>#s', $html, $match);
		$this->assertSame(3, substr_count($match[1], '<span>'));
	}

	/** Once the cells are spent, a list or a grid later in the document is shortened too. */
	public function testPastItsCellsListsAndGridsAreShortened(): void {
		$columns = array_map(fn (int $c) => ['id' => 'c' . $c, 'label' => 'C' . $c], range(1, 10));
		$rows = array_fill(0, 200, array_combine(array_column($columns, 'id'), array_fill(0, 10, 'v')));
		$html = $this->html([
			['id' => 't1', 'type' => 'table', 'question' => 'T', 'columns' => $columns],
			['id' => 't2', 'type' => 'table', 'question' => 'T', 'columns' => $columns],
			['id' => 'm', 'type' => 'multiple', 'question' => 'M', 'options' => [['value' => 'x', 'label' => 'Cake'], ['value' => 'y', 'label' => 'Music']]],
			['id' => 'g', 'type' => 'matrix', 'question' => 'G', 'rows' => [['id' => 'r1', 'label' => 'Food']], 'columns' => [['value' => '1', 'label' => 'Good']]],
		], ['t1' => $rows, 't2' => $rows, 'm' => ['x', 'y'], 'g' => ['r1' => '1']]);

		$this->assertStringNotContainsString('<li>', $html);
		$this->assertStringNotContainsString('Food', $html);
		$this->assertSame(3, substr_count($html, 'This answer is shortened in the PDF'));
	}

	/** An answer cut short draws no empty lines after the cut. */
	public function testNoLineBreaksAfterTheCut(): void {
		$html = $this->html([['id' => 'a', 'type' => 'textarea', 'question' => 'Notes']], ['a' => str_repeat(str_repeat('ä', 100) . "\n", 600)]);

		$this->assertSame(499, substr_count($html, '<br>'));
	}

	public function testARunOfEmptyLinesDrawsOne(): void {
		$html = $this->html([['id' => 'a', 'type' => 'textarea', 'question' => 'Notes']], ['a' => "one\n\n\n \n\r\n\ntwo"]);

		$this->assertStringContainsString("one<br>\n<br>\ntwo", $html);
	}

	/** Each line break costs dompdf a line box: past the document's share the text runs on, all of it. */
	public function testPastItsLineBreaksTheTextRunsOn(): void {
		$questions = [];
		$answers = [];
		foreach (['a', 'b', 'c'] as $id) {
			$questions[] = ['id' => $id, 'type' => 'textarea', 'question' => 'Notes'];
			$answers[$id] = str_repeat("ж\n", 1000);
		}
		$html = $this->html($questions, $answers);

		$this->assertSame(2000, substr_count($html, '<br>'));
		$this->assertSame(3000, substr_count($html, 'ж'));
		$this->assertStringNotContainsString('shortened', $html, 'nothing is left out');
	}

	public function testAnAnswerTooLongToShowWholeSaysSo(): void {
		$html = $this->html([['id' => 't', 'type' => 'text', 'question' => 'Q'], ['id' => 'u', 'type' => 'text', 'question' => 'Q']],
			['t' => str_repeat('ä', 60000), 'u' => 'next']);

		$this->assertSame(50000, substr_count($html, 'ä'));
		$this->assertSame(1, substr_count($html, 'This answer is shortened in the PDF; the full answer is in FormVox.'));
		$this->assertStringContainsString('next', $html, 'the answers after it are whole');
	}

	/** Each cell costs dompdf about 30 KB: past the document's share of them, a table is cut where it runs out. */
	public function testPastItsCellsATableIsShortened(): void {
		$columns = array_map(fn (int $c) => ['id' => 'c' . $c, 'label' => 'C' . $c], range(1, 10));
		$rows = array_fill(0, 200, array_combine(array_column($columns, 'id'), array_fill(0, 10, 'v')));
		$questions = [];
		$answers = [];
		foreach (['t1', 't2', 't3'] as $id) {
			$questions[] = ['id' => $id, 'type' => 'table', 'question' => 'Guests', 'columns' => $columns];
			$answers[$id] = $rows;
		}
		$html = $this->html($questions, $answers);

		$this->assertSame(2000 + 1980, substr_count($html, '<td>'), 'the first whole, of the second as many rows as fit');
		$this->assertSame(2, substr_count($html, 'This answer is shortened in the PDF'));
	}

	/**
	 * Columns are equal whatever a cell holds, so a long cell's lines can be reckoned: they come out of the document's
	 * line breaks, and past them the cell is cut.
	 */
	public function testALongCellTakesItsLinesFromTheDocument(): void {
		$this->assertStringContainsString('.answer table{table-layout:fixed}', $this->html([], []));

		$columns = array_map(fn (int $c) => ['id' => 'c' . $c, 'label' => 'C' . $c], range(1, 10));
		$short = array_combine(array_column($columns, 'id'), array_fill(0, 10, 'v'));
		$questions = [];
		$answers = [];
		foreach (['t1', 't2', 't3'] as $id) {
			$questions[] = ['id' => $id, 'type' => 'table', 'question' => 'Guests', 'columns' => $columns];
			// Seven characters fit a line of a tenth of the page: a thousand lines.
			$answers[$id] = [['c1' => str_repeat('ж', 7000)] + $short, $short];
		}
		$html = $this->html($questions, $answers);

		$this->assertSame(7000 + 7000 + 21, substr_count($html, 'ж'), 'two whole, the third cut to the lines left');
		$this->assertSame(1, substr_count($html, 'This answer is shortened in the PDF'));
	}

	/** A grid's two columns are reckoned the same way: forty-three characters a line, a thousand lines here. */
	public function testALongGridCellTakesItsLinesFromTheDocument(): void {
		$html = $this->html(
			[
				['id' => 'g', 'type' => 'matrix', 'question' => 'G', 'rows' => [['id' => 'r1', 'label' => str_repeat('ж', 43 * 1000)]], 'columns' => [['value' => '1', 'label' => 'Good']]],
				['id' => 'a', 'type' => 'textarea', 'question' => 'Notes'],
			],
			['g' => ['r1' => '1'], 'a' => str_repeat("x\n", 1100)],
		);

		$this->assertSame(43 * 1000, substr_count($html, 'ж'), 'whole');
		$this->assertStringNotContainsString('shortened', $html);
		$this->assertSame(2000 - 999, substr_count($html, '<br>'), 'the grid cell took its lines beyond the first');
	}

	/** Far beyond any answer allowed now: what older stored answers can add up to stops somewhere. */
	public function testTheDocumentAsAWholeIsBounded(): void {
		$questions = [];
		$answers = [];
		foreach (range(1, 11) as $n) {
			$questions[] = ['id' => 'q' . $n, 'type' => 'text', 'question' => 'Q'];
			$answers['q' . $n] = str_repeat('ä', 49000);
		}
		$html = $this->html($questions, $answers);

		$this->assertSame(1, substr_count($html, 'This answer is shortened in the PDF'), 'only the last');
		$this->assertGreaterThan(490000, substr_count($html, 'ä'));
		$this->assertLessThanOrEqual(500000, substr_count($html, 'ä'));
	}

	public function testALongWordWrapsAtThePageEdge(): void {
		$this->assertMatchesRegularExpression('/body\{[^}]*overflow-wrap:anywhere/', $this->html([], []));
	}

	/** What is no real day or moment is shown as it was given, never as another date PHP made of it. */
	public function testADateThatIsNoRealDayIsShownAsGiven(): void {
		$html = $this->html([['id' => 'd', 'type' => 'date', 'question' => 'Day'], ['id' => 'w', 'type' => 'datetime', 'question' => 'When']],
			['d' => '2026-02-31', 'w' => '2026-01-01T10:00' . str_repeat('9', 20)]);

		$this->assertStringContainsString('2026-02-31', $html);
		$this->assertStringNotContainsString('2026-03-03', $html);
		$this->assertStringContainsString('2026-01-01T10:00' . str_repeat('9', 20), $html);
		$this->assertStringNotContainsString('9999-01-01', $html);
	}

	/** The server and imports set when it was sent, also in forms an answer may not take. */
	public function testWhenItWasSentAsAnImportGaveIt(): void {
		$html = PdfDocuments::document($this)->html(['title' => 'T', 'questions' => []], ['id' => 'r', 'submitted_at' => '2024-01-01', 'answers' => []], new EnglishL10N());

		$this->assertStringContainsString('Submitted on 2024-01-01 00:00 (UTC)', $html);
	}

	/** A mark closes within 1,000 characters: a text that never closes one costs linear time, not quadratic. */
	public function testAMarkIsClosedNearby(): void {
		$far = '_' . str_repeat('a', 1001) . '_ __' . str_repeat('b', 1001) . '__';
		$html = $this->html([['id' => 'k', 'type' => 'consent', 'question' => 'Terms', 'description' => 'Read _this_ and __that__ and ' . $far]], ['k' => true]);

		$this->assertStringContainsString('Read this and that and ' . $far, strip_tags($html));
	}

	/** A description in Markdown reads as the page showed it, not as its source. */
	public function testADescriptionReadsAsThePageShowedIt(): void {
		$html = $this->html([['id' => 'k', 'type' => 'consent', 'question' => 'Terms', 'description' => "## Data\nRead **the terms** at [our site](https://x.example) ![logo](https://x/l.png).\n- a year"]], ['k' => true]);

		$this->assertStringContainsString('Read the terms at our site (https://x.example) .', $html);
		$this->assertStringContainsString('• a year', $html);
		$this->assertStringNotContainsString('**', $html);
		$this->assertStringNotContainsString('## Data', $html);
	}
}
