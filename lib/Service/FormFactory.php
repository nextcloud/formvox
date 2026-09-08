<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Files\Folder;
use OCP\IL10N;

/**
 * Pure form construction — no I/O, no storage, no locking.
 *
 * Builds the canonical .fvform document skeleton and applies the built-in
 * starter templates. Extracted from FormService so the (large, purely
 * data-shaping) template logic can be unit-tested in isolation against a
 * mocked IL10N, with no filesystem or database.
 */
class FormFactory {
	private IL10N $l;

	public function __construct(IL10N $l) {
		$this->l = $l;
	}

	/**
	 * Build the canonical form document for a new form.
	 *
	 * The caller supplies created_at/modified_at semantics via date('c') here;
	 * id is a fresh v4 UUID. Applying a template overlays questions/description/
	 * settings/responses on top of the empty skeleton.
	 */
	public function createFormStructure(string $title, string $userId, ?string $template = null): array {
		$form = [
			'version' => '1.0',
			'id' => $this->generateUuid(),
			'title' => $title,
			'description' => '',
			'created_at' => date('c'),
			'modified_at' => date('c'),
			'settings' => [
				'anonymous' => true,
				'allow_multiple' => false,
				'expires_at' => null,
				'require_login' => false,
				'allowed_users' => [],
				'allowed_groups' => [],
			],
			'permissions' => [
				'owner' => $userId,
				'roles' => [],
			],
			'questions' => [],
			'pages' => null,
			'branding' => null, // null = use admin defaults, object = custom branding
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
		];

		// Apply template if specified
		if ($template !== null) {
			$form = $this->applyTemplate($form, $template);
		}

		return $form;
	}

	/**
	 * Apply a template to a form
	 */
	public function applyTemplate(array $form, string $template): array {
		$templates = [
			'survey' => [
				'questions' => [
					[
						'id' => 'q1',
						'type' => 'choice',
						'question' => $this->l->t('How would you rate your overall experience?'),
						'required' => true,
						'options' => [
							['id' => 'opt1', 'label' => $this->l->t('Excellent'), 'value' => '5'],
							['id' => 'opt2', 'label' => $this->l->t('Good'), 'value' => '4'],
							['id' => 'opt3', 'label' => $this->l->t('Average'), 'value' => '3'],
							['id' => 'opt4', 'label' => $this->l->t('Poor'), 'value' => '2'],
							['id' => 'opt5', 'label' => $this->l->t('Very poor'), 'value' => '1'],
						],
					],
					[
						'id' => 'q2',
						'type' => 'textarea',
						'question' => $this->l->t('Do you have any additional comments?'),
						'required' => false,
					],
				],
			],
			'poll' => [
				'questions' => [
					[
						'id' => 'q1',
						'type' => 'choice',
						'question' => $this->l->t('What is your preferred option?'),
						'required' => true,
						'options' => [
							['id' => 'opt1', 'label' => $this->l->t('Option A'), 'value' => 'a'],
							['id' => 'opt2', 'label' => $this->l->t('Option B'), 'value' => 'b'],
							['id' => 'opt3', 'label' => $this->l->t('Option C'), 'value' => 'c'],
						],
					],
				],
			],
			'registration' => [
				'questions' => [
					[
						'id' => 'q1',
						'type' => 'text',
						'question' => $this->l->t('Full name'),
						'required' => true,
					],
					[
						'id' => 'q2',
						'type' => 'text',
						'question' => $this->l->t('Email address'),
						'required' => true,
						'validation' => ['type' => 'email'],
					],
					[
						'id' => 'q3',
						'type' => 'text',
						'question' => $this->l->t('Phone number'),
						'required' => false,
					],
				],
				'settings' => [
					'anonymous' => false,
					'require_login' => false,
				],
			],
			'demo' => [
				'description' => $this->l->t('This demo form showcases all FormVox features including different question types, conditional logic (branching), quiz mode with scoring, and various input validations.'),
				'questions' => [
					// Section 1: Basic Info
					[
						'id' => 'demo_name',
						'type' => 'text',
						'question' => $this->l->t('What is your name?'),
						'description' => $this->l->t('This is a simple text field'),
						'required' => true,
						'placeholder' => $this->l->t('Enter your full name'),
					],
					[
						'id' => 'demo_email',
						'type' => 'text',
						'question' => $this->l->t('What is your email address?'),
						'description' => $this->l->t('Text field with email validation'),
						'required' => true,
						'validation' => ['type' => 'email'],
					],
					[
						'id' => 'demo_bio',
						'type' => 'textarea',
						'question' => $this->l->t('Tell us about yourself'),
						'description' => $this->l->t('Multi-line text area for longer responses'),
						'required' => false,
						'placeholder' => $this->l->t('Write a short bio …'),
					],
					// Section 2: Choice Questions
					[
						'id' => 'demo_experience',
						'type' => 'choice',
						'question' => $this->l->t('How much experience do you have with forms?'),
						'description' => $this->l->t('Single choice (radio buttons)'),
						'required' => true,
						'options' => [
							['id' => 'exp1', 'label' => $this->l->t('Beginner - Just getting started'), 'value' => 'beginner'],
							['id' => 'exp2', 'label' => $this->l->t('Intermediate - Some experience'), 'value' => 'intermediate'],
							['id' => 'exp3', 'label' => $this->l->t('Expert - I create forms daily'), 'value' => 'expert'],
						],
					],
					// Conditional question - only shown for experts
					[
						'id' => 'demo_expert_tools',
						'type' => 'multiple',
						'question' => $this->l->t('Which form tools have you used before?'),
						'description' => $this->l->t('This question only appears if you selected "Expert" above (conditional logic)'),
						'required' => false,
						'options' => [
							['id' => 'tool1', 'label' => 'Google Forms', 'value' => 'google'],
							['id' => 'tool2', 'label' => 'Microsoft Forms', 'value' => 'microsoft'],
							['id' => 'tool3', 'label' => 'Typeform', 'value' => 'typeform'],
							['id' => 'tool4', 'label' => 'SurveyMonkey', 'value' => 'surveymonkey'],
							['id' => 'tool5', 'label' => 'Nextcloud Forms', 'value' => 'nextcloud'],
						],
						'showIf' => [
							'questionId' => 'demo_experience',
							'operator' => 'equals',
							'value' => 'expert',
						],
					],
					[
						'id' => 'demo_features',
						'type' => 'multiple',
						'question' => $this->l->t('Which features are most important to you?'),
						'description' => $this->l->t('Multiple choice (checkboxes) - select all that apply'),
						'required' => true,
						'options' => [
							['id' => 'feat1', 'label' => $this->l->t('Easy to use interface'), 'value' => 'easy'],
							['id' => 'feat2', 'label' => $this->l->t('Conditional logic / Branching'), 'value' => 'branching'],
							['id' => 'feat3', 'label' => $this->l->t('File-based storage'), 'value' => 'files'],
							['id' => 'feat4', 'label' => $this->l->t('Privacy / Self-hosted'), 'value' => 'privacy'],
							['id' => 'feat5', 'label' => $this->l->t('Export options'), 'value' => 'export'],
						],
					],
					[
						'id' => 'demo_priority',
						'type' => 'dropdown',
						'question' => $this->l->t('What is your top priority?'),
						'description' => $this->l->t('Dropdown select for longer option lists'),
						'required' => true,
						'options' => [
							['id' => 'pri1', 'label' => $this->l->t('Speed'), 'value' => 'speed'],
							['id' => 'pri2', 'label' => $this->l->t('Security'), 'value' => 'security'],
							['id' => 'pri3', 'label' => $this->l->t('Flexibility'), 'value' => 'flexibility'],
							['id' => 'pri4', 'label' => $this->l->t('Integration'), 'value' => 'integration'],
							['id' => 'pri5', 'label' => $this->l->t('Cost'), 'value' => 'cost'],
						],
					],
					// Section 3: Date & Time
					[
						'id' => 'demo_date',
						'type' => 'date',
						'question' => $this->l->t('When did you start using Nextcloud?'),
						'description' => $this->l->t('Date picker'),
						'required' => false,
					],
					[
						'id' => 'demo_datetime',
						'type' => 'datetime',
						'question' => $this->l->t('When would you like a demo call?'),
						'description' => $this->l->t('Date and time picker'),
						'required' => false,
					],
					[
						'id' => 'demo_time',
						'type' => 'time',
						'question' => $this->l->t('What time works best for you?'),
						'description' => $this->l->t('Time picker only'),
						'required' => false,
					],
					// Section 4: Numbers & Ratings
					[
						'id' => 'demo_number',
						'type' => 'number',
						'question' => $this->l->t('How many forms do you create per month?'),
						'description' => $this->l->t('Numeric input'),
						'required' => false,
						'min' => 0,
						'max' => 1000,
					],
					[
						'id' => 'demo_scale',
						'type' => 'scale',
						'question' => $this->l->t('How likely are you to recommend FormVox?'),
						'description' => $this->l->t('Linear scale (1-10)'),
						'required' => true,
						'min' => 1,
						'max' => 10,
						'minLabel' => $this->l->t('Not likely'),
						'maxLabel' => $this->l->t('Very likely'),
					],
					[
						'id' => 'demo_rating',
						'type' => 'rating',
						'question' => $this->l->t('Rate this demo form'),
						'description' => $this->l->t('Star rating (1-5 stars)'),
						'required' => true,
						'max' => 5,
					],
					// Section 5: Quiz Mode
					[
						'id' => 'demo_quiz1',
						'type' => 'choice',
						'question' => $this->l->t('Quiz: What file extension does FormVox use?'),
						'description' => $this->l->t('This is a quiz question with scoring - correct answer: .fvform'),
						'required' => true,
						'options' => [
							['id' => 'quiz1a', 'label' => '.docx', 'value' => 'docx', 'score' => 0],
							['id' => 'quiz1b', 'label' => '.fvform', 'value' => 'fvform', 'score' => 10],
							['id' => 'quiz1c', 'label' => '.json', 'value' => 'json', 'score' => 5],
							['id' => 'quiz1d', 'label' => '.xml', 'value' => 'xml', 'score' => 0],
						],
					],
					[
						'id' => 'demo_quiz2',
						'type' => 'choice',
						'question' => $this->l->t('Quiz: Where does FormVox store form data?'),
						'description' => $this->l->t('Another quiz question - correct answer: In Nextcloud files'),
						'required' => true,
						'options' => [
							['id' => 'quiz2a', 'label' => $this->l->t('In a separate database'), 'value' => 'database', 'score' => 0],
							['id' => 'quiz2b', 'label' => $this->l->t('In the cloud'), 'value' => 'cloud', 'score' => 0],
							['id' => 'quiz2c', 'label' => $this->l->t('In Nextcloud files'), 'value' => 'files', 'score' => 10],
							['id' => 'quiz2d', 'label' => $this->l->t('On external servers'), 'value' => 'external', 'score' => 0],
						],
					],
					// Section 6: Matrix Question
					[
						'id' => 'demo_matrix',
						'type' => 'matrix',
						'question' => $this->l->t('Rate these aspects of FormVox'),
						'description' => $this->l->t('Matrix/grid question with multiple rows and columns'),
						'required' => false,
						'rows' => [
							['id' => 'row1', 'label' => $this->l->t('Ease of use')],
							['id' => 'row2', 'label' => $this->l->t('Feature set')],
							['id' => 'row3', 'label' => $this->l->t('Design')],
							['id' => 'row4', 'label' => $this->l->t('Performance')],
						],
						'columns' => [
							['id' => 'col1', 'label' => $this->l->t('Poor'), 'value' => '1'],
							['id' => 'col2', 'label' => $this->l->t('Fair'), 'value' => '2'],
							['id' => 'col3', 'label' => $this->l->t('Good'), 'value' => '3'],
							['id' => 'col4', 'label' => $this->l->t('Excellent'), 'value' => '4'],
						],
					],
					// Section 7: Table (Dynamic Rows)
					[
						'id' => 'demo_table',
						'type' => 'table',
						'question' => $this->l->t('Expense declaration'),
						'description' => $this->l->t('Add your expenses below. Click "+ Add row" to add more items.'),
						'required' => false,
						'columns' => [
							['id' => 'col_desc', 'label' => $this->l->t('Description'), 'inputType' => 'text', 'options' => []],
							['id' => 'col_amount', 'label' => $this->l->t('Amount'), 'inputType' => 'number', 'options' => []],
							['id' => 'col_date', 'label' => $this->l->t('Date'), 'inputType' => 'date', 'options' => []],
							['id' => 'col_cat', 'label' => $this->l->t('Category'), 'inputType' => 'dropdown', 'options' => ['Travel', 'Food', 'Office', 'Other']],
						],
						'minRows' => 1,
						'maxRows' => 20,
					],

					// Section 8: Conditional Branching Demo
					[
						'id' => 'demo_want_contact',
						'type' => 'choice',
						'question' => $this->l->t('Would you like us to contact you?'),
						'description' => $this->l->t('This controls whether the next question is shown'),
						'required' => true,
						'options' => [
							['id' => 'contact_yes', 'label' => $this->l->t('Yes, please contact me'), 'value' => 'yes'],
							['id' => 'contact_no', 'label' => $this->l->t('No, thanks'), 'value' => 'no'],
						],
					],
					[
						'id' => 'demo_contact_method',
						'type' => 'choice',
						'question' => $this->l->t('How would you prefer to be contacted?'),
						'description' => $this->l->t('This question only appears if you selected "Yes" above'),
						'required' => false,
						'options' => [
							['id' => 'method1', 'label' => $this->l->t('Email'), 'value' => 'email'],
							['id' => 'method2', 'label' => $this->l->t('Phone'), 'value' => 'phone'],
							['id' => 'method3', 'label' => $this->l->t('Video call'), 'value' => 'video'],
						],
						'showIf' => [
							'questionId' => 'demo_want_contact',
							'operator' => 'equals',
							'value' => 'yes',
						],
					],
					// Final feedback
					[
						'id' => 'demo_feedback',
						'type' => 'textarea',
						'question' => $this->l->t('Any final thoughts or feedback?'),
						'description' => $this->l->t('Thank you for trying this demo form!'),
						'required' => false,
						'placeholder' => $this->l->t('Share your thoughts …'),
					],
				],
				'settings' => [
					'anonymous' => true,
					'allow_multiple' => true,
				],
			],
		];

		if (isset($templates[$template])) {
			$templateData = $templates[$template];
			if (isset($templateData['description'])) {
				$form['description'] = $templateData['description'];
			}
			if (isset($templateData['questions'])) {
				$form['questions'] = $templateData['questions'];
			}
			if (isset($templateData['settings'])) {
				$form['settings'] = array_merge($form['settings'], $templateData['settings']);
			}
			if (isset($templateData['responses'])) {
				$form['responses'] = $templateData['responses'];
			}
		}

		return $form;
	}

	/**
	 * Generate an RFC 4122 v4 UUID.
	 */
	public function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Sanitize a form title into a safe filename base.
	 */
	public function sanitizeFilename(string $name): string {
		// Remove invalid characters
		$name = preg_replace('/[\/\\\\:*?"<>|]/', '', $name);
		// Replace spaces with dashes
		$name = preg_replace('/\s+/', '-', $name);
		// Lowercase
		$name = strtolower($name);
		// Limit length
		$name = substr($name, 0, 50);
		// Default if empty
		if (empty($name)) {
			$name = 'form';
		}
		return $name;
	}

	/**
	 * Get a filename unique within $folder by appending -1, -2, … on collision.
	 */
	public function getUniqueFilename(Folder $folder, string $filename): string {
		$baseName = pathinfo($filename, PATHINFO_FILENAME);
		$extension = pathinfo($filename, PATHINFO_EXTENSION);
		$counter = 1;

		while ($folder->nodeExists($filename)) {
			$filename = $baseName . '-' . $counter . '.' . $extension;
			$counter++;
		}

		return $filename;
	}
}
