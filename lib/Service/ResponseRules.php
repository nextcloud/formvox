<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * The one place that decides whether a response may be written now: the share dialog's dates, the response
 * limit, once per person and the capacity of options. The persistence layer applies it under the lock on the
 * freshly read form, so no way in can skip it (H8, Q7); callers may ask earlier for a quick answer.
 * Whether the answers themselves are right is AnswerValidator's job.
 */
class ResponseRules {
	public function __construct(
		private AnswerValidator $answers,
		private IndexService $index,
	) {
	}

	/**
	 * @throws ResponseRefusedException|InvalidAnswerException
	 */
	public function assertNew(array $form, array $response): void {
		$settings = $form['settings'] ?? [];
		$this->assertOpen($settings);

		$max = (int)($settings['max_responses'] ?? 0);
		if ($max > 0 && $this->index->getResponseCount($form) >= $max) {
			throw ResponseRefusedException::full((string)($settings['limit_message'] ?? ''));
		}

		if (!($settings['allow_multiple'] ?? false) && $this->answeredBefore($form, $response['respondent'] ?? [])) {
			throw ResponseRefusedException::alreadyAnswered();
		}

		$this->answers->checkCapacity($form, $response['answers'] ?? []);
	}

	/**
	 * A changed response: the form must still be open, and a newly chosen option must have room;
	 * the response's own earlier choice does not count against it.
	 *
	 * @throws ResponseRefusedException|InvalidAnswerException
	 */
	public function assertChange(array $form, array $old, array $new): void {
		$this->assertOpen($form['settings'] ?? []);
		$this->answers->checkCapacity($form, $new['answers'] ?? [], $old['answers'] ?? []);
	}

	/**
	 * The share dialog's opening and closing dates, for submissions, uploads and the form's page alike.
	 *
	 * @throws ResponseRefusedException
	 */
	public function assertOpen(array $settings): void {
		$now = new \DateTimeImmutable();
		if (!empty($settings['share_starts_at']) && ($opens = $this->date($settings['share_starts_at'])) > $now) {
			throw ResponseRefusedException::notYetOpen($opens->format(\DateTimeInterface::ATOM));
		}
		if (!empty($settings['share_expires_at']) && $this->date($settings['share_expires_at']) < $now) {
			throw ResponseRefusedException::closed();
		}
	}

	/**
	 * A date that cannot be read closes the form rather than leaving it open.
	 */
	private function date(mixed $value): \DateTimeImmutable {
		try {
			return new \DateTimeImmutable((string)$value);
		} catch (\Exception) {
			throw ResponseRefusedException::closed();
		}
	}

	private function answeredBefore(array $form, array $respondent): bool {
		return match ($respondent['type'] ?? null) {
			'anonymous' => is_string($respondent['fingerprint'] ?? null) && $this->index->hasFingerprint($form, $respondent['fingerprint']),
			'user' => is_string($respondent['user_id'] ?? null) && $this->index->hasUserResponse($form, $respondent['user_id']),
			default => false,
		};
	}
}
