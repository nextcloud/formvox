<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * The form this request already read and decoded, so the write under the lock need not decode the same bytes again
 * (a second decode was about a fifth of a submission). Only identical bytes are reused; one form is kept at a time.
 */
class DecodedForms {
	/** @var array{fileId: int, raw: string, form: array}|null */
	private ?array $last = null;

	public function remember(int $fileId, string $raw, array $form): void {
		$this->last = ['fileId' => $fileId, 'raw' => $raw, 'form' => $form];
	}

	/** The decoded form if $raw is exactly what was remembered for $fileId; the memory is cleared either way. */
	public function recall(int $fileId, string $raw): ?array {
		$last = $this->last;
		$this->last = null;
		if ($last === null || $last['fileId'] !== $fileId || $last['raw'] !== $raw) {
			return null;
		}
		return $last['form'];
	}
}
