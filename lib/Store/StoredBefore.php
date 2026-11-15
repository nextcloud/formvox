<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

/**
 * The same fill was stored before: its response is the answer, nothing is written.
 *
 * @internal
 */
final class StoredBefore extends \RuntimeException {
	public function __construct(
		public readonly array $response,
	) {
		parent::__construct('Stored before');
	}
}
