<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

use OCA\FormVox\Service\ResponseOutbox;
use OCA\FormVox\Service\SubmissionIds;

/**
 * A form's row in the database: its own id, the uuid in its file, and the file it is bound to now, with where that file
 * was last seen.
 */
final class StoredForm {
	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly int $fileId,
		public readonly int $storageId,
		public readonly int $count = 0,
		public readonly string $pathHash = '',
		/** When its file was found gone; null while it has one. */
		public readonly ?int $orphanedAt = null,
		/** Still being copied from a form file: no one but the converter reads or writes it yet. */
		public readonly bool $draft = false,
		/** Its files are in app data; until then they stay beside its file (FormConverter::moveFiles). */
		public readonly bool $filesMoved = true,
		/** Its file holds the form only; a moved-in form's file is tidied after the switch. */
		public readonly bool $tidied = true,
		/** When its files reached app data; null until then. */
		public readonly ?int $filesAt = null,
	) {
	}

	/** @param array<string, mixed> $row */
	public static function fromRow(array $row): self {
		return new self((int)$row['id'], (string)$row['uuid'], (int)$row['file_id'], (int)$row['storage_id'], (int)($row['n'] ?? 0),
			(string)($row['path_hash'] ?? ''), isset($row['orphaned_at']) ? (int)$row['orphaned_at'] : null, (int)($row['state'] ?? 0) === FormStore::DRAFT,
			($row['files_at'] ?? null) !== null, ($row['tidied_at'] ?? null) !== null, isset($row['files_at']) ? (int)$row['files_at'] : null);
	}

	/**
	 * What its file holds: the form under its own uuid, with no responses, counts, outbox or submissions. The database
	 * holds those, so what a file says of them counts for nothing and is never written again.
	 */
	public function formOnly(array $form): array {
		$form['id'] = $this->uuid;
		$form['responses'] = [];
		$form['_index'] = ['response_count' => 0, 'last_response_at' => null, 'fingerprints' => [], 'user_ids' => [], 'by_date' => [], 'answer_counts' => []];
		unset($form[ResponseOutbox::KEY], $form[SubmissionIds::KEY]);
		return $form;
	}
}
