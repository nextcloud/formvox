<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * Marks the form-file writes FormVox makes itself, so FormIntake leaves them alone when Nextcloud reports them,
 * and whether such a write may leave a version behind: a change to the form may, a response may not.
 * One instance per request (the app container shares it).
 */
class OwnWrites {
	private int $depth = 0;
	private int $versionless = 0;

	/**
	 * @template T
	 * @param callable(): T $write
	 * @param bool $version true for a change to the form itself, which Nextcloud keeps as a version
	 * @return T
	 */
	public function during(callable $write, bool $version = false): mixed {
		$this->depth++;
		if (!$version) {
			$this->versionless++;
		}
		try {
			return $write();
		} finally {
			$this->depth--;
			if (!$version) {
				$this->versionless--;
			}
		}
	}

	public function active(): bool {
		return $this->depth > 0;
	}

	/** A FormVox write that leaves no version: responses and counts, not changes to the form (PO 5). */
	public function withoutVersion(): bool {
		return $this->versionless > 0;
	}
}
