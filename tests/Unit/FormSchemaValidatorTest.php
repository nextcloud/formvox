<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormFactory;
use OCA\FormVox\Service\FormSchemaValidator;
use OCA\FormVox\Service\FormValidationException;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One check of a form's structure (review V5, PO 4b): what breaks a form is refused with the question it is
 * on; what nobody can see in the editor is tidied; every form FormVox itself makes passes.
 */
class FormSchemaValidatorTest extends TestCase {
	private function validator(): FormSchemaValidator {
		return new FormSchemaValidator(new EnglishL10N(), $this->createMock(LoggerInterface::class));
	}

	/** @return list<array{question: ?string, field: ?string, message: string}> */
	private function errorsOf(array $form): array {
		try {
			$this->validator()->check($form);
			return [];
		} catch (FormValidationException $e) {
			$this->assertSame([400, 'invalid_form'], [$e->getStatus(), $e->getReason()]);
			return $e->getErrors();
		}
	}

	private function form(array $questions, array $extra = []): array {
		return ['title' => 'T', 'questions' => $questions] + $extra;
	}

	// ---- every form FormVox itself makes passes ------------------------------------------------------------------

	/**
	 * @return array<string, array{?string}>
	 */
	public static function builtInTemplates(): array {
		return ['blank' => [null], 'survey' => ['survey'], 'poll' => ['poll'], 'registration' => ['registration'], 'demo' => ['demo']];
	}

	/**
	 * @dataProvider builtInTemplates
	 */
	public function testEveryBuiltInTemplatePasses(?string $template): void {
		$form = (new FormFactory(new EnglishL10N()))->createFormStructure('Template', 'anna', $template);

		$this->assertSame([], $this->errorsOf($form));
	}

	/** What the editor stores for each type, with its defaults and the leftovers it leaves behind. */
	public function testAFormAsTheEditorMakesItPassesAndIsTidied(): void {
		$questions = [
			['id' => 's1a2b3c4d', 'type' => 'section', 'question' => 'About you', 'description' => '', 'required' => false, 'options' => [], 'showIf' => null],
			['id' => 'qa1b2c3d4', 'type' => 'text', 'question' => 'Name', 'required' => true, 'sectionId' => 's1a2b3c4d', 'options' => [], 'timeMin' => '09:00',
				'validation' => ['pattern' => '^https?:\/\/[\w\-]+(\.[\w\-]+)+[/#?]?.*$', 'errorMessage' => 'A web address']],
			['id' => 'qb1b2c3d4', 'type' => 'choice', 'question' => 'Colour', 'options' => [
				['id' => 'opt1', 'label' => 'Red', 'value' => 'opt1', 'capacity' => 3.0], ['id' => 'opt2', 'label' => 'Blue', 'value' => 'opt2', 'score' => 0]]],
			['id' => 'qc1b2c3d4', 'type' => 'multiple', 'question' => 'Days', 'options' => [['id' => 'o1', 'label' => 'Mon', 'value' => 'o1']], 'minSelections' => 0, 'maxSelections' => 1.0],
			['id' => 'qd1b2c3d4', 'type' => 'scale', 'question' => 'Score', 'scaleMin' => 1.0, 'scaleMax' => 10.0, 'scaleMinLabel' => 'Low', 'scaleMaxLabel' => 'High'],
			['id' => 'qe1b2c3d4', 'type' => 'rating', 'question' => 'Stars', 'ratingMax' => 5],
			['id' => 'qf1b2c3d4', 'type' => 'datetime', 'question' => 'When', 'dateMin' => '2026-01-01', 'dateMax' => '2026-12-31T00:00:00.000Z'],
			['id' => 'qg1b2c3d4', 'type' => 'time', 'question' => 'At', 'timeMin' => '08:00', 'timeMax' => '18:00'],
			['id' => 'qh1b2c3d4', 'type' => 'matrix', 'question' => 'Grid', 'rows' => [['id' => 'r1', 'label' => 'A']], 'columns' => [['id' => 'c1', 'label' => 'Yes', 'value' => 1]]],
			['id' => 'qi1b2c3d4', 'type' => 'table', 'question' => 'People', 'columns' => [['id' => 'col1', 'label' => 'Name', 'inputType' => 'text', 'options' => [], 'optionsText' => '']], 'minRows' => 1, 'maxRows' => 50],
			['id' => 'qj1b2c3d4', 'type' => 'file', 'question' => 'CV', 'allowedTypePreset' => 'pdf', 'allowedTypes' => ['application/pdf'], 'maxFileSize' => 10, 'maxFiles' => 5,
				'showIf' => ['operator' => 'or', 'conditions' => [['questionId' => 'qb1b2c3d4', 'operator' => 'equals', 'value' => 'opt1'], ['questionId' => 'qa1b2c3d4', 'operator' => 'isNotEmpty', 'value' => '']]]],
			['id' => 'dk1b2c3d4', 'type' => 'descriptor', 'question' => '', 'description' => 'Thanks', 'required' => false, 'showIf' => null, 'descriptorAlign' => 'center'],
		];
		$pages = [
			['id' => 'p1', 'title' => 'One', 'questions' => ['s1a2b3c4d', 'qa1b2c3d4', 'qb1b2c3d4', 'gone'], 'routing' => [
				['id' => 'r1', 'questionId' => 'qb1b2c3d4', 'operator' => 'equals', 'value' => 'Red', 'targetPageId' => 'p2'],
				['id' => 'r2', 'questionId' => 'deleted', 'operator' => 'equals', 'value' => 'x', 'targetPageId' => 'p2'],
				['id' => 'r3', 'questionId' => 'qb1b2c3d4', 'operator' => 'equals', 'value' => 'x', 'targetPageId' => 'deleted-page']]],
			['id' => 'p2', 'title' => 'Two', 'questions' => ['qc1b2c3d4', 'qa1b2c3d4']],
		];
		$settings = ['anonymous' => true, 'max_responses' => 0, 'share_expires_at' => '2030-01-01T00:00:00.000Z', 'expires_at' => null,
			'notify_recipients' => [['type' => 'user', 'id' => 'carol', 'displayName' => 'Carol']], 'api_keys' => [['id' => 'k1']], 'keepResponsePdf' => true];

		$form = $this->validator()->check($this->form($questions, ['pages' => $pages, 'settings' => $settings, 'descriptionAlign' => 'middle']));

		$this->assertArrayNotHasKey('options', $form['questions'][0], 'a section keeps no options');
		$this->assertArrayNotHasKey('timeMin', $form['questions'][1], 'a text question keeps no time bounds');
		$this->assertSame('left', $form['descriptionAlign']);
		$this->assertSame(['s1a2b3c4d', 'qa1b2c3d4', 'qb1b2c3d4'], $form['pages'][0]['questions'], 'no deleted question, each question on one page');
		$this->assertSame(['qc1b2c3d4'], $form['pages'][1]['questions']);
		$this->assertSame(['r1'], array_column($form['pages'][0]['routing'], 'id'), 'no rule to a deleted question or page');
		$this->assertArrayNotHasKey('expires_at', $form['settings']);
		$this->assertTrue($form['settings']['keepResponsePdf'], 'a known setting stays');
		$this->assertSame([['id' => 'k1']], $form['settings']['api_keys'], 'the server\'s own settings are left as they are');
	}

	/** Forms the Microsoft import and the AI generator make. */
	public function testImportedAndGeneratedShapesPass(): void {
		$this->assertSame([], $this->errorsOf($this->form([
			['id' => 'q1', 'type' => 'scale', 'question' => 'Recommend?', 'scaleMin' => 0, 'scaleMax' => 10, 'order' => 1],
			['id' => 'q2', 'type' => 'choice', 'question' => 'Pick', 'options' => [['value' => 'A', 'label' => 'A']], 'allowOther' => false],
			['id' => 'q3', 'type' => 'matrix', 'question' => 'Grid', 'rows' => [['id' => 'r1', 'label' => 'A']], 'columns' => [['id' => 'c1', 'label' => 'Agree', 'value' => '1']]],
			['id' => 'has_account', 'type' => 'rating', 'question' => 'Stars', 'max' => 5],
			['id' => 'email', 'type' => 'text', 'question' => 'Email', 'maxLength' => '200'],
			['id' => 'q6', 'type' => 'scale', 'question' => 'Rate', 'min' => 1, 'max' => 10],
		])));
	}

	// ---- what breaks a form is refused, with the question it is on -----------------------------------------------

	/**
	 * @return array<string, array{array, ?string, string}>
	 */
	public static function brokenQuestions(): array {
		$q = fn (array $extra) => ['id' => 'q1', 'type' => 'text', 'question' => 'Name'] + $extra;
		return [
			'unknown type' => [['type' => 'slider'] + $q([]), 'q1', 'type'],
			'a condition that is text' => [$q(['showIf' => 'x']), 'q1', 'showIf'],
			'a group without its conditions' => [$q(['showIf' => ['operator' => 'and', 'conditions' => 'x']]), 'q1', 'showIf'],
			'an unknown operator' => [$q(['showIf' => ['questionId' => 'q0', 'operator' => 'like', 'value' => 'x']]), 'q1', 'showIf'],
			'a condition on itself' => [$q(['showIf' => ['questionId' => 'q1', 'operator' => 'isEmpty', 'value' => '']]), 'q1', 'showIf'],
			'a condition on a deleted question' => [$q(['showIf' => ['questionId' => 'gone', 'operator' => 'isEmpty', 'value' => '']]), 'q1', 'showIf'],
			'a pattern that is no regular expression' => [$q(['validation' => ['pattern' => '(']]), 'q1', 'validation.pattern'],
			'a colour that is not one' => [$q(['color' => 'red;background:url(x)']), 'q1', 'color'],
			'required that is not yes or no' => [$q(['required' => 'yes']), 'q1', 'required'],
			'a scale up to a hundred million' => [['type' => 'scale', 'scaleMax' => 100000000] + $q([]), 'q1', 'scaleMin'],
			'a scale from 5' => [['type' => 'scale', 'scaleMin' => 5, 'scaleMax' => 10] + $q([]), 'q1', 'scaleMin'],
			// Converted to the current names first, then checked like them.
			'an old scale field over the limit' => [['type' => 'scale', 'min' => 1, 'max' => 100] + $q([]), 'q1', 'scaleMin'],
			'eleven stars' => [['type' => 'rating', 'ratingMax' => 11] + $q([]), 'q1', 'ratingMax'],
			'101 options' => [['type' => 'choice', 'options' => array_map(fn ($i) => ['value' => "o$i", 'label' => "O$i"], range(1, 101))] + $q([]), 'q1', 'options'],
			'an option without a value' => [['type' => 'choice', 'options' => [['label' => 'A']]] + $q([]), 'q1', 'options'],
			'51 matrix rows' => [['type' => 'matrix', 'rows' => array_map(fn ($i) => ['id' => "r$i"], range(1, 51)), 'columns' => []] + $q([]), 'q1', 'rows'],
			'21 files' => [['type' => 'file', 'maxFiles' => 21] + $q([]), 'q1', 'maxFileSize'],
			'a 500 MB file' => [['type' => 'file', 'maxFileSize' => 500] + $q([]), 'q1', 'maxFileSize'],
			'a table cell type that does not exist' => [['type' => 'table', 'columns' => [['id' => 'c', 'inputType' => 'script']]] + $q([]), 'q1', 'columns'],
			'a date bound that is no date' => [['type' => 'date', 'dateMin' => 'soon'] + $q([]), 'q1', 'dateMin'],
			'a descriptor alignment that is a class' => [['type' => 'descriptor', 'descriptorAlign' => 'x onclick'] + $q([]), 'q1', 'descriptorAlign'],
			'more selections required than allowed' => [['type' => 'multiple', 'options' => [], 'minSelections' => 3, 'maxSelections' => 1] + $q([]), 'q1', 'minSelections'],
		];
	}

	/**
	 * @dataProvider brokenQuestions
	 */
	public function testWhatBreaksAQuestionIsRefusedWithTheQuestion(array $question, ?string $id, string $field): void {
		$errors = $this->errorsOf($this->form([['id' => 'q0', 'type' => 'text', 'question' => 'First'], $question]));

		$this->assertNotSame([], $errors, 'refused');
		$this->assertSame([$id, $field], [$errors[0]['question'], $errors[0]['field']], $errors[0]['message']);
	}

	public function testTheMessageNamesTheQuestion(): void {
		$errors = $this->errorsOf($this->form([['id' => 'q1', 'type' => 'text', 'question' => 'Your name', 'showIf' => ['questionId' => 'gone', 'operator' => 'isEmpty']]]));

		$this->assertSame("The condition of question 'Your name' refers to a question that no longer exists", $errors[0]['message']);
	}

	/**
	 * @return array<string, array{array, string}>
	 */
	public static function brokenForms(): array {
		return [
			'a question that is text' => [['questions' => ['tekst']], 'id'],
			'questions that are not a list' => [['questions' => ['a' => []]], 'questions'],
			'501 questions' => [['questions' => array_map(fn ($i) => ['id' => "q$i", 'type' => 'text'], range(1, 501))], 'questions'],
			'a 300-character title' => [['title' => str_repeat('x', 300), 'questions' => []], 'title'],
			'a page without an id' => [['questions' => [], 'pages' => [['title' => 'One', 'questions' => []]]], 'pages'],
			'a rule with an unknown operator' => [['questions' => [['id' => 'q1', 'type' => 'text']], 'pages' => [['id' => 'p1', 'questions' => ['q1'], 'routing' => [['questionId' => 'q1', 'operator' => 'like', 'targetPageId' => 'p1']]]]], 'pages'],
			'a closing date that is no date' => [['questions' => [], 'settings' => ['share_expires_at' => 'morgen']], 'settings.share_expires_at'],
			'a limit that is not a number' => [['questions' => [], 'settings' => ['max_responses' => 'many']], 'settings.max_responses'],
			'collecting anonymously as text' => [['questions' => [], 'settings' => ['anonymous' => 'yes']], 'settings.anonymous'],
			'keeping PDFs as text' => [['questions' => [], 'settings' => ['keepResponsePdf' => 'yes']], 'settings.keepResponsePdf'],
			'a recipient without a type' => [['questions' => [], 'settings' => ['notify_recipients' => [['id' => 'carol']]]], 'settings.notify_recipients'],
		];
	}

	/**
	 * @dataProvider brokenForms
	 */
	public function testWhatBreaksTheFormIsRefused(array $form, string $field): void {
		$errors = $this->errorsOf($form);

		$this->assertContains($field, array_column($errors, 'field'));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function infinities(): array {
		return [
			'as a number question\'s maximum' => ['{"questions":[{"id":"q1","type":"number","min":0,"max":1e999}]}'],
			'as a text\'s length' => ['{"questions":[{"id":"q1","type":"text","maxLength":1e999}]}'],
			'in the settings' => ['{"questions":[],"settings":{"max_responses":1e999}}'],
			'deep in the branding' => ['{"questions":[],"branding":{"blocks":[{"size":-1e999}]}}'],
		];
	}

	/**
	 * A number like 1e999 reads as infinity, which cannot be written back: the form would never save again.
	 *
	 * @dataProvider infinities
	 */
	public function testANumberThatReadsAsInfinityIsRefused(string $json): void {
		$errors = $this->errorsOf(json_decode($json, true));

		$this->assertContains('A number in the form is too large.', array_column($errors, 'message'));
	}

	public function testOrdinaryNumbersPass(): void {
		$messages = array_column($this->errorsOf(json_decode('{"questions":[{"id":"q1","type":"scale","scaleMax":10.0}],"branding":{"blocks":[{"size":1.5e3}]}}', true)), 'message');

		$this->assertNotContains('A number in the form is too large.', $messages);
	}

	public function testEveryProblemIsListedNotOnlyTheFirst(): void {
		$errors = $this->errorsOf($this->form([
			['id' => 'q1', 'type' => 'rating', 'ratingMax' => 50],
			['id' => 'q2', 'type' => 'slider'],
		]));

		$this->assertSame(['q1', 'q2'], array_column($errors, 'question'));
	}

	// ---- a question id used twice ---------------------------------------------------------------------------------

	/** AI forms could repeat an id, so two questions shared one answer; the later one gets its own (PO). */
	public function testARepeatedIdIsRenamedAndWhatReferredToItKeepsTheFirst(): void {
		$form = $this->validator()->check($this->form([
			['id' => 'q2', 'type' => 'text', 'question' => 'First'],
			['id' => 'q2', 'type' => 'text', 'question' => 'Second'],
			['id' => 'q2_2', 'type' => 'text', 'question' => 'Already taken'],
			['id' => 'q2', 'type' => 'text', 'question' => 'Third'],
			['id' => 'q9', 'type' => 'text', 'question' => 'Follow-up', 'showIf' => ['questionId' => 'q2', 'operator' => 'isNotEmpty']],
		], ['pages' => [
			['id' => 'p1', 'questions' => ['q2', 'q2_2']],
			['id' => 'p2', 'questions' => ['q9']],
		]]));

		$this->assertSame(['q2', 'q2_3', 'q2_2', 'q2_4', 'q9'], array_column($form['questions'], 'id'));
		$this->assertSame('q2', $form['questions'][4]['showIf']['questionId']);
		// One mention of q2 showed all three questions on that page; it still does.
		$this->assertSame(['q2', 'q2_3', 'q2_4', 'q2_2'], $form['pages'][0]['questions']);
	}

	public function testEachMentionOfARepeatedIdOnAPageIsItsOwnQuestion(): void {
		$form = $this->validator()->check($this->form([
			['id' => 'email', 'type' => 'text', 'question' => 'Your email'],
			['id' => 'email', 'type' => 'text', 'question' => 'Colleague email'],
		], ['pages' => [['id' => 'p1', 'questions' => ['email']], ['id' => 'p2', 'questions' => ['email']]]]));

		$this->assertSame(['email'], $form['pages'][0]['questions']);
		$this->assertSame(['email_2'], $form['pages'][1]['questions']);
	}

	/** The log line says everything that was tidied, also inside questions. */
	public function testTheLogNamesEveryTidiedField(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(
			fn (array $context) => $context['cleaned'] === ['q1.options', 'q1.timeMin', 'page p1 question gone'],
		));

		(new FormSchemaValidator(new EnglishL10N(), $logger))->check([
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name', 'options' => [], 'timeMin' => '09:00']],
			'pages' => [['id' => 'p1', 'title' => 'One', 'questions' => ['q1', 'gone']]],
		]);
	}

	/** Older forms (the demo template, AI forms) said min/max; a save gives them the names the page reads (PO 4c). */
	public function testOldScaleAndStarNamesAreConvertedOnSave(): void {
		$form = $this->validator()->check(['questions' => [
			['id' => 'q1', 'type' => 'scale', 'question' => 'Recommend?', 'min' => 1, 'max' => 10, 'minLabel' => 'Not likely', 'maxLabel' => 'Very likely'],
			['id' => 'q2', 'type' => 'rating', 'question' => 'Stars', 'max' => 7],
			['id' => 'q3', 'type' => 'scale', 'question' => 'Both', 'scaleMax' => 4, 'max' => 9],
			['id' => 'q4', 'type' => 'number', 'question' => 'Count', 'min' => 0, 'max' => 1000],
		]]);

		$this->assertSame(['id' => 'q1', 'type' => 'scale', 'question' => 'Recommend?', 'scaleMin' => 1, 'scaleMax' => 10, 'scaleMinLabel' => 'Not likely', 'scaleMaxLabel' => 'Very likely'], $form['questions'][0]);
		$this->assertSame(['id' => 'q2', 'type' => 'rating', 'question' => 'Stars', 'ratingMax' => 7], $form['questions'][1]);
		// The current name wins.
		$this->assertSame(4, $form['questions'][2]['scaleMax']);
		$this->assertArrayNotHasKey('max', $form['questions'][2]);
		// A number keeps min/max: those are its names.
		$this->assertSame([0, 1000], [$form['questions'][3]['min'], $form['questions'][3]['max']]);
	}

	/** secu#8: a heading block's level is drawn as a tag, so only a heading level is kept. */
	public function testABrandingHeadingKeepsOnlyAHeadingLevel(): void {
		$form = $this->validator()->check(['questions' => [], 'branding' => ['layout' => [
			'header' => [['type' => 'heading', 'settings' => ['level' => 'img', 'text' => 'Hi']], ['type' => 'heading', 'settings' => ['level' => 'h3']]],
			'thankYou' => [['type' => 'text', 'settings' => ['level' => 'whatever']]],
		]]]);

		$this->assertSame('h1', $form['branding']['layout']['header'][0]['settings']['level']);
		$this->assertSame('h3', $form['branding']['layout']['header'][1]['settings']['level']);
		$this->assertSame('whatever', $form['branding']['layout']['thankYou'][0]['settings']['level']);
	}
}
