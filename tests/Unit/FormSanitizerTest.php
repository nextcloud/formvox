<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\FormRevision;
use OCA\FormVox\Service\ResponseOutbox;
use OCA\FormVox\Service\SubmissionIds;
use PHPUnit\Framework\TestCase;

class FormSanitizerTest extends TestCase {
	private const VIEWER = ['viewResponses' => true, 'editSettings' => false, 'canShare' => false];
	private const FULL = ['viewResponses' => true, 'editSettings' => true, 'canShare' => true];

	private function storedForm(): array {
		return [
			'title' => 'Survey',
			'description' => 'About you',
			'questions' => [['id' => 'q1', 'type' => 'choice', 'options' => [['id' => 'a', 'label' => 'A', 'score' => 10], ['id' => 'b', 'label' => 'B', 'score' => 0]]]],
			'pages' => null,
			'responses' => [['id' => 'r1']],
			'_index' => ['response_count' => 1],
			'permissions' => ['owner' => 'anna'],
			'branding' => ['blocks' => []],
			'settings' => [
				'anonymous' => false,
				'allow_multiple' => true,
				'max_responses' => 10,
				'expires_at' => '2030-01-01T00:00:00Z',
				'public_token' => 'TOK',
				'share_password' => 'plain',
				'share_password_hash' => 'enc:v1:pw',
				'share_expires_at' => '2030-01-01T00:00:00Z',
				'api_keys' => [['id' => 'k1', 'name' => 'CRM', 'hash' => 'enc:v1:key', 'created_by' => 'anna']],
				'webhooks' => [['id' => 'w1', 'url' => 'https://example.com/hook', 'secret' => 'enc:v1:wh']],
				'allowed_users' => ['bob'],
				'allowed_groups' => ['staff'],
				'notify_owner' => true,
				'notify_recipients' => [['type' => 'user', 'id' => 'carol']],
				'sendConfirmationEmail' => true,
				'confirmationEmailSubject' => 'Thanks',
				'keepResponsePdf' => true,
				'unknown_future_setting' => 'stays on the server',
			],
		];
	}

	public function testNoAudienceEverGetsASecret(): void {
		$sanitizer = new FormSanitizer();
		$outputs = [
			'read-only' => $sanitizer->forUser($this->storedForm(), self::VIEWER),
			'full rights' => $sanitizer->forUser($this->storedForm(), self::FULL),
			'public' => $sanitizer->forPublic($this->storedForm()),
			'template' => $sanitizer->forTemplate($this->storedForm()),
			'structure' => $sanitizer->structureOnly($this->storedForm()),
		];
		foreach ($outputs as $audience => $form) {
			$json = json_encode($form);
			foreach (['plain', 'enc:v1:', 'share_password_hash', '"share_password"'] as $secret) {
				$this->assertStringNotContainsString($secret, $json, "$audience got a secret");
			}
		}
	}

	public function testReadOnlyUsersSeeTheSameSettingsAsFullRights(): void {
		$sanitizer = new FormSanitizer();

		$this->assertSame(
			$sanitizer->forUser($this->storedForm(), self::FULL)['settings'],
			$sanitizer->forUser($this->storedForm(), self::VIEWER)['settings'],
		);
	}

	public function testUsersSeeEverySettingWithIntegrationsWithoutTheirSecrets(): void {
		$settings = (new FormSanitizer())->forUser($this->storedForm(), self::VIEWER)['settings'];

		$this->assertSame('TOK', $settings['public_token']);
		$this->assertTrue($settings['has_share_password']);
		$this->assertSame(['bob'], $settings['allowed_users']);
		$this->assertSame([['type' => 'user', 'id' => 'carol']], $settings['notify_recipients']);
		$this->assertSame('Thanks', $settings['confirmationEmailSubject']);
		$this->assertTrue($settings['keepResponsePdf']);
		$this->assertSame([['id' => 'k1', 'name' => 'CRM', 'created_by' => 'anna']], $settings['api_keys']);
		$this->assertSame([['id' => 'w1', 'url' => 'https://example.com/hook']], $settings['webhooks']);
	}

	public function testASettingThatIsNotListedStaysOnTheServer(): void {
		$settings = (new FormSanitizer())->forUser($this->storedForm(), self::FULL)['settings'];

		$this->assertArrayNotHasKey('unknown_future_setting', $settings);
	}

	public function testResponsesOnlyForThoseWhoMayViewThem(): void {
		$form = (new FormSanitizer())->forUser($this->storedForm(), ['viewResponses' => false] + self::VIEWER);

		$this->assertArrayNotHasKey('responses', $form);
		$this->assertArrayNotHasKey('_index', $form);
	}

	public function testHasSharePasswordIsFalseWithoutAPassword(): void {
		$form = $this->storedForm();
		unset($form['settings']['share_password_hash']);

		$this->assertFalse((new FormSanitizer())->forUser($form, self::FULL)['settings']['has_share_password']);
	}

	public function testAFormWithoutSettingsPassesThrough(): void {
		$this->assertSame(['title' => 'T', 'revision' => FormRevision::of(['title' => 'T'])], (new FormSanitizer())->forUser(['title' => 'T'], self::FULL));
	}

	public function testAnonymousVisitorsGetWhatThePageNeedsAndNoAnswerKey(): void {
		$form = (new FormSanitizer())->forPublic($this->storedForm());

		$this->assertSame(['title', 'description', 'questions', 'pages', 'settings', '_index'], array_keys($form));
		$this->assertSame(['anonymous' => false, 'allow_multiple' => true, 'max_responses' => 10], $form['settings']);
		$this->assertSame([['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']], $form['questions'][0]['options']);
	}

	/**
	 * The page gets counts only for options with a capacity, and the number of responses only to say the form is
	 * full: nothing else respondents chose, and no count at all without a limit (H6).
	 */
	public function testThePublicPageGetsOnlyTheCountsItShows(): void {
		$form = [
			'questions' => [
				['id' => 'slot', 'type' => 'choice', 'options' => [['value' => 'a', 'capacity' => 5], ['value' => 'b']]],
				['id' => 'colour', 'type' => 'multiple', 'options' => [['value' => 'red'], ['value' => 'blue']]],
				['id' => 'name', 'type' => 'text'],
			],
			'_index' => ['response_count' => 7, 'answer_counts' => ['slot' => ['a' => 3, 'b' => 4], 'colour' => ['red' => 6], 'name' => ['Anna' => 1]], 'fingerprints' => ['v2:x' => 0]],
		];
		$sanitizer = new FormSanitizer();

		$this->assertSame(['answer_counts' => ['slot' => ['a' => 3]]], $sanitizer->forPublic($form)['_index']);
		$this->assertSame(['answer_counts' => ['slot' => ['a' => 3]], 'response_count' => 7], $sanitizer->forPublic($form + ['settings' => ['max_responses' => 10]])['_index']);
	}

	public function testATemplateCarriesTheFormButNothingBoundToIt(): void {
		$form = (new FormSanitizer())->forTemplate($this->storedForm());

		$this->assertSame(['title', 'description', 'questions', 'pages', 'settings'], array_keys($form));
		$this->assertSame([
			'anonymous' => false,
			'allow_multiple' => true,
			'max_responses' => 10,
			'sendConfirmationEmail' => true,
			'confirmationEmailSubject' => 'Thanks',
			'keepResponsePdf' => true,
		], $form['settings']);
		$this->assertSame(10, $form['questions'][0]['options'][0]['score'], 'a quiz template keeps its scores');
	}

	public function testACopyOrDownloadKeepsOnlyQuestionsAndBehaviour(): void {
		$form = (new FormSanitizer())->structureOnly($this->storedForm());

		$this->assertSame(['anonymous' => false, 'allow_multiple' => true, 'require_login' => false], $form['settings']);
		$this->assertSame([], $form['responses']);
		$this->assertSame(0, $form['_index']['response_count']);
		$this->assertArrayNotHasKey('branding', $form);
		$this->assertArrayNotHasKey('permissions', $form);
	}

	public function testTheFingerprintStaysOnTheServer(): void {
		$form = $this->storedForm();
		$form['responses'] = [['id' => 'r1', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'v2:abc']]];
		$form['_index'] = ['response_count' => 1, 'fingerprints' => ['v2:abc' => 0], 'user_ids' => ['anna' => 0]];

		$out = (new FormSanitizer())->forUser($form, self::FULL);

		$this->assertSame([['id' => 'r1', 'respondent' => ['type' => 'anonymous']]], $out['responses']);
		$this->assertSame(['response_count' => 1], $out['_index']);
	}

	/** What new responses still set off and the last submissions' ids are the server's: no editor, download or copy carries them (7a, 3a-4/5). */
	public function testTheOutboxStaysOnTheServer(): void {
		$form = $this->storedForm() + [ResponseOutbox::KEY => [['id' => 'r1:response.created', 'language' => 'nl', 'todo' => ['mail']]], SubmissionIds::KEY => ['0f8b6c1e-aaaa-4bbb-8ccc-123456789abc' => 'r1']];
		$sanitizer = new FormSanitizer();

		foreach ([$sanitizer->forUser($form, self::FULL), $sanitizer->structureOnly($form), $sanitizer->forPublic($form)] as $out) {
			$this->assertArrayNotHasKey(ResponseOutbox::KEY, $out);
			$this->assertArrayNotHasKey(SubmissionIds::KEY, $out);
		}
	}

	/** Kept for the response's PDF only: an anonymous respondent's zone or language could tell who they are. */
	public function testAResponsesLanguageAndTimeZoneStayOnTheServer(): void {
		$shown = (new \OCA\FormVox\Security\FormSanitizer())->response(['id' => 'r1', 'language' => 'hi', 'timezone' => 'Asia/Kolkata', 'answers' => []]);

		$this->assertArrayNotHasKey('language', $shown);
		$this->assertArrayNotHasKey('timezone', $shown);
	}

	/**
	 * With the form, an integration is told which answers the respondent was shown and which questions did not apply
	 * (a condition, a hidden section, a skipped page); `answers` stays as stored. Without the form, as for the app's own
	 * pages, nothing is added.
	 */
	public function testAnIntegrationIsToldWhichAnswersApplied(): void {
		$form = [
			'questions' => [
				['id' => 1, 'type' => 'choice'],
				['id' => 2, 'type' => 'text', 'showIf' => ['questionId' => 1, 'operator' => 'equals', 'value' => 'yes']],
				['id' => 's1', 'type' => 'section', 'showIf' => ['questionId' => 1, 'operator' => 'equals', 'value' => 'yes']],
				['id' => 3, 'type' => 'text', 'sectionId' => 's1'],
				['id' => 4, 'type' => 'text'],
			],
		];
		$response = ['id' => 'r1', 'answers' => [1 => 'no', 2 => 'stale', 3 => 'stale', 4 => 'kept']];
		$sanitizer = new FormSanitizer();

		$told = $sanitizer->response($response, $form);

		$this->assertSame($response['answers'], $told['answers']);
		$this->assertSame([1 => 'no', 4 => 'kept'], $told['applicable_answers']);
		$this->assertSame(['2', '3'], $told['not_applicable'], 'question ids as strings, in the form\'s order; the hidden section itself is no question');
		$this->assertSame(['id', 'answers'], array_keys($sanitizer->response($response)), 'nothing added without the form');
		$this->assertSame(['id' => 'r1'], $sanitizer->response(['id' => 'r1'], $form), 'a deletion has no answers to tell about');
		$this->assertSame([$told, 7], $sanitizer->responses([$response, 7], $form));
	}
}
