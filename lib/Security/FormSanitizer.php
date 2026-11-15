<?php

declare(strict_types=1);

namespace OCA\FormVox\Security;

use OCA\FormVox\Service\FormLogic;
use OCA\FormVox\Service\FormRevision;
use OCA\FormVox\Service\ResponseOutbox;
use OCA\FormVox\Service\SubmissionIds;

/**
 * The one place that decides what of a stored form leaves the server, per audience.
 * Settings go out by allowlist: a new setting stays on the server until it is listed here.
 */
class FormSanitizer {
	public function __construct(
		private FormLogic $logic = new FormLogic(),
	) {
	}

	/** Every setting a signed-in user may see; read-only users see them too, only without changing them. */
	private const USER_SETTINGS = [
		'anonymous', 'allow_multiple', 'require_login', 'max_responses', 'limit_message',
		'share_expires_at', 'share_starts_at', 'public_token',
		'allowed_users', 'allowed_groups', 'notify_owner', 'notify_recipients',
		'sendConfirmationEmail', 'confirmationEmailSubject', 'confirmationEmailBody', 'confirmationEmailPdf', 'keepResponsePdf',
	];

	/** Integration fields that may be shown; hashes and secrets are not among them. */
	private const INTEGRATION_FIELDS = [
		'api_keys' => ['id', 'name', 'permissions', 'created_at', 'created_by'],
		'webhooks' => ['id', 'name', 'url', 'events', 'enabled', 'created_at', 'created_by', 'updated_at', 'updated_by'],
	];

	/** What an anonymous visitor's page needs. */
	private const PUBLIC_FIELDS = ['title', 'description', 'descriptionAlign', 'questions', 'pages'];
	private const PUBLIC_SETTINGS = ['anonymous', 'allow_multiple', 'require_login', 'max_responses', 'limit_message'];

	/** A template: the form without its link, people, integrations, dates, branding or responses. */
	private const TEMPLATE_FIELDS = ['title', 'description', 'descriptionAlign', 'questions', 'pages'];
	private const TEMPLATE_SETTINGS = ['anonymous', 'allow_multiple', 'require_login', 'max_responses', 'limit_message', 'sendConfirmationEmail', 'confirmationEmailSubject', 'confirmationEmailBody', 'confirmationEmailPdf', 'keepResponsePdf'];

	/** What survives a copy or a download of the file. */
	public const STRUCTURE_SETTINGS = ['anonymous' => true, 'allow_multiple' => false, 'require_login' => false];

	/**
	 * For a signed-in user, by the flags of PermissionService::getPermissionsForFile().
	 */
	public function forUser(array $form, array $permissions): array {
		// The editor sends it back with each save (If-Match), so a stale tab cannot overwrite a newer form.
		$form['revision'] = FormRevision::of($form);
		if (empty($permissions['viewResponses'])) {
			unset($form['responses'], $form['_index']);
		}
		if (is_array($form['responses'] ?? null)) {
			$form['responses'] = $this->responses($form['responses']);
		}
		// Who answered is recorded per response where the form asks for it, not as an index anyone can read.
		unset($form['_index']['fingerprints'], $form['_index']['user_ids']);
		// What new responses still set off, and the last submissions' ids, are the server's own.
		unset($form[ResponseOutbox::KEY], $form[SubmissionIds::KEY]);
		if (!is_array($form['settings'] ?? null)) {
			return $form;
		}
		$stored = $form['settings'];
		$settings = $this->only($stored, self::USER_SETTINGS);
		$settings['has_share_password'] = !empty($stored['share_password_hash']);
		foreach (self::INTEGRATION_FIELDS as $list => $fields) {
			if (is_array($stored[$list] ?? null)) {
				$settings[$list] = array_values(array_map(fn ($item) => $this->only(is_array($item) ? $item : [], $fields), $stored[$list]));
			}
		}
		$form['settings'] = $settings;
		return $form;
	}

	/**
	 * A response as it leaves the server: the fingerprint only tells the server about repeats (M13).
	 *
	 * @param array|null $form with the form, an integration is also told which answers the respondent was shown:
	 *                         `applicable_answers`, and in `not_applicable` the questions a condition, a hidden
	 *                         section or page routing kept from them; `answers` stays as it is
	 */
	public function response(array $response, ?array $form = null): array {
		unset($response['respondent']['fingerprint']);
		// Only for the PDF: on an anonymous response they could tell who answered.
		unset($response['language'], $response['timezone']);
		if ($form !== null && is_array($response['answers'] ?? null)) {
			$hidden = $this->logic->analyse($form, $response['answers'])['hidden'];
			$response['applicable_answers'] = array_diff_key($response['answers'], $hidden);
			// A hidden section counts as hidden too, but an integration asks about questions; ids are strings,
			// also where PHP made them numbers as keys.
			$sections = [];
			foreach (is_array($form['questions'] ?? null) ? $form['questions'] : [] as $question) {
				if (($question['type'] ?? null) === 'section' && is_scalar($question['id'] ?? null)) {
					$sections[(string)$question['id']] = true;
				}
			}
			$response['not_applicable'] = array_values(array_filter(array_map('strval', array_keys($hidden)), fn (string $id): bool => !isset($sections[$id])));
		}
		return $response;
	}

	/**
	 * @param array<int, mixed> $responses
	 * @return array<int, mixed>
	 */
	public function responses(array $responses, ?array $form = null): array {
		return array_map(fn ($r) => is_array($r) ? $this->response($r, $form) : $r, $responses);
	}

	/**
	 * For an anonymous visitor of the public link. Option scores stay on the server: they are the quiz's answer key.
	 */
	public function forPublic(array $form): array {
		$public = $this->only($form, self::PUBLIC_FIELDS);
		$public['settings'] = $this->only(is_array($form['settings'] ?? null) ? $form['settings'] : [], self::PUBLIC_SETTINGS);
		if (is_array($public['questions'] ?? null)) {
			$public['questions'] = array_map(fn ($question) => $this->withoutScores($question), $public['questions']);
		}
		$public['_index'] = ['answer_counts' => $this->capacityCounts($form)];
		// The page needs the count only to say the form is full; without a limit it is nobody's business.
		if ((int)($public['settings']['max_responses'] ?? 0) > 0) {
			$public['_index']['response_count'] = (int)($form['_index']['response_count'] ?? 0);
		}
		return $public;
	}

	/**
	 * Counts only for options with a capacity, so the page can show what is left (#104); nothing else a
	 * respondent chose or typed (H6).
	 *
	 * @return array<string, array<string, int>>
	 */
	private function capacityCounts(array $form): array {
		$all = $form['_index']['answer_counts'] ?? [];
		$out = [];
		foreach (is_array($form['questions'] ?? null) ? $form['questions'] : [] as $question) {
			if (!is_array($question) || !in_array($question['type'] ?? '', ['choice', 'multiple', 'dropdown'], true) || !isset($question['id'])) {
				continue;
			}
			$qid = (string)$question['id'];
			foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
				$capacity = is_array($option) ? ($option['capacity'] ?? null) : null;
				$value = is_array($option) ? ($option['value'] ?? $option['id'] ?? null) : null;
				if (is_scalar($value) && $capacity !== null && $capacity !== '' && (int)$capacity > 0) {
					$out[$qid][(string)$value] = (int)($all[$qid][(string)$value] ?? 0);
				}
			}
		}
		return $out;
	}

	public function forTemplate(array $form): array {
		$template = $this->only($form, self::TEMPLATE_FIELDS);
		$template['settings'] = $this->only(is_array($form['settings'] ?? null) ? $form['settings'] : [], self::TEMPLATE_SETTINGS);
		return $template;
	}

	/**
	 * Questions and behaviour only, for a copy or a downloaded file.
	 */
	public function structureOnly(array $form): array {
		$settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
		$form['settings'] = [];
		foreach (self::STRUCTURE_SETTINGS as $key => $default) {
			$form['settings'][$key] = $settings[$key] ?? $default;
		}
		$form['responses'] = [];
		$form['_index'] = [
			'response_count' => 0,
			'last_response_at' => null,
			'fingerprints' => [],
			'user_ids' => [],
			'by_date' => [],
			'answer_counts' => [],
		];
		unset($form['branding'], $form['permissions'], $form[ResponseOutbox::KEY], $form[SubmissionIds::KEY]);
		return $form;
	}

	private function withoutScores(mixed $question): mixed {
		if (!is_array($question) || !is_array($question['options'] ?? null)) {
			return $question;
		}
		$question['options'] = array_map(fn ($option) => is_array($option) ? array_diff_key($option, ['score' => true]) : $option, $question['options']);
		return $question;
	}

	private function only(array $values, array $keys): array {
		return array_intersect_key($values, array_flip($keys));
	}
}
