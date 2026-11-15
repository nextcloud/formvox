<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * Another write held the form's lock, or the form stayed unreadable, for too long. Answered with 503 and Retry-After;
 * the client may retry.
 */
class FormLockedException extends \RuntimeException {
	/** @var array<string, mixed> */
	private array $data = [];

	/**
	 * The same refusal with something the client needs to retry, such as a pass to skip the challenge (7a, 3a-5).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function withData(\Throwable $busy, array $data): self {
		$e = new self('The form stayed busy', 0, $busy);
		$e->data = $data;
		return $e;
	}

	/** @return array<string, mixed> */
	public function getData(): array {
		return $this->data;
	}
}
