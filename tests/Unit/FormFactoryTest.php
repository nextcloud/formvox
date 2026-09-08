<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormFactory;
use OCP\Files\Folder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for FormFactory (pure form construction).
 *
 * These pin the EXACT shape of a freshly-created form and each built-in
 * template. The code was moved verbatim out of FormService; these snapshots
 * are the regression net that proves the move — and any future change to the
 * template logic — is intentional.
 *
 * The mocked IL10N::t() is the identity function, so translatable strings
 * appear as their English source in the snapshots (deterministic).
 */
class FormFactoryTest extends TestCase {
	private FormFactory $factory;

	protected function setUp(): void {
		parent::setUp();
		$l = $this->createMock(IL10N::class);
		// Identity translation: t('x') === 'x'. Ignore the sprintf params arg.
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$this->factory = new FormFactory($l);
	}

	/**
	 * Strip the three non-deterministic fields so the rest can be snapshotted.
	 */
	private function normalize(array $form): array {
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$form['id'],
			'id must be a v4 UUID'
		);
		$this->assertIsString($form['created_at']);
		$this->assertIsString($form['modified_at']);
		$form['id'] = '<uuid>';
		$form['created_at'] = '<ts>';
		$form['modified_at'] = '<ts>';
		return $form;
	}

	public function testEmptyFormSkeleton(): void {
		$form = $this->factory->createFormStructure('My Title', 'alice');

		$this->assertSame([
			'version' => '1.0',
			'id' => '<uuid>',
			'title' => 'My Title',
			'description' => '',
			'created_at' => '<ts>',
			'modified_at' => '<ts>',
			'settings' => [
				'anonymous' => true,
				'allow_multiple' => false,
				'expires_at' => null,
				'require_login' => false,
				'allowed_users' => [],
				'allowed_groups' => [],
			],
			'permissions' => [
				'owner' => 'alice',
				'roles' => [],
			],
			'questions' => [],
			'pages' => null,
			'branding' => null,
			'_index' => [
				'_checksum' => '',
				'response_count' => 0,
				'last_response_at' => null,
				'fingerprints' => [],
				'user_ids' => [],
				'by_date' => [],
				'answer_counts' => [],
			],
			'responses' => [],
		], $this->normalize($form));
	}

	public function testSurveyTemplateSnapshot(): void {
		$form = $this->factory->createFormStructure('S', 'bob', 'survey');
		$form = $this->normalize($form);

		// Description untouched (survey has no description override).
		$this->assertSame('', $form['description']);
		// Settings untouched (survey has no settings override).
		$this->assertSame([
			'anonymous' => true,
			'allow_multiple' => false,
			'expires_at' => null,
			'require_login' => false,
			'allowed_users' => [],
			'allowed_groups' => [],
		], $form['settings']);

		$this->assertSame([
			[
				'id' => 'q1',
				'type' => 'choice',
				'question' => 'How would you rate your overall experience?',
				'required' => true,
				'options' => [
					['id' => 'opt1', 'label' => 'Excellent', 'value' => '5'],
					['id' => 'opt2', 'label' => 'Good', 'value' => '4'],
					['id' => 'opt3', 'label' => 'Average', 'value' => '3'],
					['id' => 'opt4', 'label' => 'Poor', 'value' => '2'],
					['id' => 'opt5', 'label' => 'Very poor', 'value' => '1'],
				],
			],
			[
				'id' => 'q2',
				'type' => 'textarea',
				'question' => 'Do you have any additional comments?',
				'required' => false,
			],
		], $form['questions']);
	}

	public function testPollTemplateSnapshot(): void {
		$form = $this->factory->createFormStructure('P', 'bob', 'poll');

		$this->assertSame([
			[
				'id' => 'q1',
				'type' => 'choice',
				'question' => 'What is your preferred option?',
				'required' => true,
				'options' => [
					['id' => 'opt1', 'label' => 'Option A', 'value' => 'a'],
					['id' => 'opt2', 'label' => 'Option B', 'value' => 'b'],
					['id' => 'opt3', 'label' => 'Option C', 'value' => 'c'],
				],
			],
		], $form['questions']);
	}

	public function testRegistrationTemplatePartiallyMergesSettings(): void {
		$form = $this->factory->createFormStructure('R', 'bob', 'registration');

		// The template overrides ONLY anonymous + require_login; array_merge
		// keeps the other four keys from the skeleton (partial merge).
		$this->assertSame([
			'anonymous' => false,
			'allow_multiple' => false,
			'expires_at' => null,
			'require_login' => false,
			'allowed_users' => [],
			'allowed_groups' => [],
		], $form['settings']);

		$this->assertSame([
			['id' => 'q1', 'type' => 'text', 'question' => 'Full name', 'required' => true],
			['id' => 'q2', 'type' => 'text', 'question' => 'Email address', 'required' => true, 'validation' => ['type' => 'email']],
			['id' => 'q3', 'type' => 'text', 'question' => 'Phone number', 'required' => false],
		], $form['questions']);
	}

	public function testDemoTemplateOverridesDescriptionAndSettingsAndHasAllQuestionTypes(): void {
		$form = $this->factory->createFormStructure('D', 'bob', 'demo');

		// Description override.
		$this->assertStringStartsWith('This demo form showcases all FormVox features', $form['description']);

		// Settings partial merge: demo sets anonymous(true) + allow_multiple(true).
		$this->assertSame([
			'anonymous' => true,
			'allow_multiple' => true,
			'expires_at' => null,
			'require_login' => false,
			'allowed_users' => [],
			'allowed_groups' => [],
		], $form['settings']);

		// Pin the full set of question ids + types (the demo is the coverage
		// form; if a question is added/removed/retyped this flags it).
		$idsAndTypes = array_map(
			static fn (array $q): string => $q['id'] . ':' . $q['type'],
			$form['questions']
		);
		$this->assertSame([
			'demo_name:text',
			'demo_email:text',
			'demo_bio:textarea',
			'demo_experience:choice',
			'demo_expert_tools:multiple',
			'demo_features:multiple',
			'demo_priority:dropdown',
			'demo_date:date',
			'demo_datetime:datetime',
			'demo_time:time',
			'demo_number:number',
			'demo_scale:scale',
			'demo_rating:rating',
			'demo_quiz1:choice',
			'demo_quiz2:choice',
			'demo_matrix:matrix',
			'demo_table:table',
			'demo_want_contact:choice',
			'demo_contact_method:choice',
			'demo_feedback:textarea',
		], $idsAndTypes);

		// Conditional-logic (showIf) preserved on the two branching questions.
		$byId = [];
		foreach ($form['questions'] as $q) {
			$byId[$q['id']] = $q;
		}
		$this->assertSame(
			['questionId' => 'demo_experience', 'operator' => 'equals', 'value' => 'expert'],
			$byId['demo_expert_tools']['showIf']
		);
		$this->assertSame(
			['questionId' => 'demo_want_contact', 'operator' => 'equals', 'value' => 'yes'],
			$byId['demo_contact_method']['showIf']
		);

		// Quiz scoring preserved.
		$this->assertSame(10, $byId['demo_quiz1']['options'][1]['score']); // .fvform = 10
		$this->assertSame(0, $byId['demo_quiz1']['options'][0]['score']);  // .docx = 0
	}

	public function testUnknownTemplateLeavesSkeletonUntouched(): void {
		$form = $this->factory->createFormStructure('X', 'bob', 'does-not-exist');
		$this->assertSame([], $form['questions']);
		$this->assertSame('', $form['description']);
	}

	public function testGenerateUuidFormatIsV4(): void {
		for ($i = 0; $i < 20; $i++) {
			$this->assertMatchesRegularExpression(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
				$this->factory->generateUuid()
			);
		}
	}

	/**
	 * @dataProvider sanitizeCases
	 */
	public function testSanitizeFilename(string $in, string $expected): void {
		$this->assertSame($expected, $this->factory->sanitizeFilename($in));
	}

	public static function sanitizeCases(): array {
		return [
			'spaces to dashes + lowercase' => ['My Form Title', 'my-form-title'],
			'strips invalid chars' => ['a/b\\c:d*e?f"g<h>i|j', 'abcdefghij'],
			'collapses whitespace' => ["a  \t b", 'a-b'],
			'empty becomes form' => ['', 'form'],
			'only-invalid becomes form' => ['///', 'form'],
			'truncates to 50' => [str_repeat('a', 80), str_repeat('a', 50)],
		];
	}

	public function testGetUniqueFilenameAppendsCounterOnCollision(): void {
		$folder = $this->createMock(Folder::class);
		// "form.fvform" and "form-1.fvform" exist; "form-2.fvform" is free.
		$folder->method('nodeExists')->willReturnCallback(
			static fn (string $name): bool => in_array($name, ['form.fvform', 'form-1.fvform'], true)
		);

		$this->assertSame('form-2.fvform', $this->factory->getUniqueFilename($folder, 'form.fvform'));
	}

	public function testGetUniqueFilenameReturnsInputWhenFree(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$this->assertSame('free.fvform', $this->factory->getUniqueFilename($folder, 'free.fvform'));
	}
}
