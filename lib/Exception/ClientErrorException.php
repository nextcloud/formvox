<?php

declare(strict_types=1);

namespace OCA\FormVox\Exception;

/**
 * A request FormVox refuses or cannot serve: the HTTP status, an English message ExceptionMiddleware
 * translates (with its %s parameters), a fixed reason code for integrations, and optional extra fields.
 * A RuntimeException, so callers that already catch those keep working.
 */
abstract class ClientErrorException extends \RuntimeException {
	/**
	 * @param array<string, mixed> $data
	 * @param list<string> $params
	 */
	protected function __construct(
		string $message,
		int $status,
		private string $reason,
		private array $data = [],
		private array $params = [],
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $status, $previous);
	}

	public function getStatus(): int {
		return $this->getCode();
	}

	public function getReason(): string {
		return $this->reason;
	}

	/** @return array<string, mixed> extra fields for the answer, next to error and reason */
	public function getData(): array {
		return $this->data;
	}

	/** @return list<string> */
	public function getParams(): array {
		return $this->params;
	}
}
