<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\ResponseStore;
use OCA\FormVox\Store\StoredForm;
use OCP\Files\File;
use OCP\Files\NotFoundException;

/**
 * All changes to a form's responses, in the database (ResponseStore); a form still in its file moves in first
 * (FormConverter). A new or changed response is held to the form's rules there, under the form's row, so no way in can
 * skip them; only an import of earlier responses is exempt.
 */
class ResponsePersistenceService {
	public function __construct(
		private FormFileLocator $fileLocator,
		private UploadService $uploadService,
		private ResponseOutbox $outbox,
		private ResponsePdf $pdfs,
		private FormStore $forms,
		private ResponseStore $responses,
		private FormReader $reader,
		private FormConverter $converter,
	) {
	}

	/**
	 * A response from a respondent without access to the form file. It goes to the database, so an account that may read
	 * the form will do (#90, H9).
	 *
	 * @param list<string> $effects what it sets off (ResponseOutbox)
	 * @param string|null $submissionId the fill as recognised (SubmissionIds::of): a retry of a stored one returns that one
	 */
	/** @param string|null $account the signed-in respondent's account, whose address the confirmation goes to */
	public function appendResponsePublic(int $fileId, array $response, array $effects = [], string $language = '', ?string $submissionId = null, bool $signedIn = false, ?string $account = null): array {
		$stored = $this->forms->byFileId($fileId) ?? $this->converter->convert($this->fileLocator->getFileByIdPublic($fileId));
		try {
			$file = $this->fileLocator->getFileByIdPublic($fileId);
		} catch (NotFoundException) {
			// Moved during this request: Nextcloud still remembers the old place.
			$file = $this->fileLocator->findAgain($fileId, false);
		}
		return $this->appendStored($stored, $file, $response, $effects, $language, $submissionId, $signedIn, true, $account);
	}

	/** The form's row; a form file moves into the database first, so every write goes there (FormConverter). */
	private function storedOrConverted(File $file): StoredForm {
		return $this->forms->byFileId((int)$file->getId()) ?? $this->converter->convert($file);
	}

	/**
	 * @param list<string> $effects
	 */
	private function appendStored(StoredForm $stored, File $file, array $response, array $effects, string $language, ?string $submissionId, bool $signedIn, bool $applyRules = true, ?string $account = null): array {
		$form = $this->reader->read($file);
		$entry = self::outboxEntry($form, $response, $effects, $language, ResponseOutbox::CREATED, $signedIn, $account);
		[$response, $new] = $this->responses->add($stored, $form, $response, $entry, $submissionId, $applyRules);
		if ($entry !== null || !$new) {
			// A retry of a stored fill: what its first try set off may still wait.
			$this->outbox->schedule((int)$file->getId());
		}
		return $response;
	}

	/**
	 * What an event sets off, as ResponseOutbox would put it in the form's outbox; null for nothing.
	 *
	 * @param list<string> $effects
	 */
	private static function outboxEntry(array $form, array $response, array $effects, string $language, string $event, bool $signedIn = false, ?string $account = null): ?array {
		unset($form[ResponseOutbox::KEY]);
		if (!ResponseOutbox::add($form, $response, $effects, $language, $event, $signedIn, $account)) {
			return null;
		}
		return $form[ResponseOutbox::KEY][0];
	}

	/**
	 * Adds one response, if the form's rules allow it now (#4). What it sets off is stored with it and sent by a
	 * background job.
	 *
	 * @param list<string> $effects what it sets off (ResponseOutbox)
	 * @param string|null $submissionId the fill as recognised (SubmissionIds::of)
	 * @return array the response stored now, or the one stored before under the same submission id
	 * @throws ResponseRefusedException|InvalidAnswerException
	 */
	public function append(File $file, array $response, array $effects = [], string $language = '', ?string $submissionId = null, bool $signedIn = false, ?string $account = null): array {
		return $this->appendStored($this->storedOrConverted($file), $file, $response, $effects, $language, $submissionId, $signedIn, true, $account);
	}

	/**
	 * The response stored for this submission: from the database for a form there, from the file for one still in it.
	 */
	public function submitted(int $fileId, array $form, ?string $submission): ?array {
		if ($submission === null) {
			return null;
		}
		$stored = $this->forms->byFileId($fileId);
		return $stored === null ? SubmissionIds::stored($form, $submission) : $this->responses->bySubmission($stored, $submission);
	}

	/**
	 * A retry found stored before: what its first try set off may still wait, if that try stored and then failed, so its
	 * job is asked for again (review of the 3a fixes).
	 */
	public function retried(int $fileId, array $form, array $stored): void {
		// A form in the database keeps its outbox in rows: the job finds out itself whether anything still waits.
		if ($this->forms->byFileId($fileId) !== null || ResponseOutbox::waitsFor($form, (string)($stored['id'] ?? ''))) {
			$this->outbox->schedule($fileId);
		}
	}

	/**
	 * An earlier response brought in by an import: history, so the form's dates, limit and capacity do not apply.
	 */
	public function appendImported(int $fileId, array $response): array {
		$file = $this->fileLocator->getFileById($fileId);
		return $this->appendStored($this->storedOrConverted($file), $file, $response, [], '', null, false, false);
	}

	/**
	 * Changes one response by id (H8): a response that arrived meanwhile is kept, one deleted meanwhile does not come
	 * back. The change is held to the form's rules, and files the response no longer names are removed (L32).
	 *
	 * @param callable(array): array $change gets the stored response, returns the new one
	 * @return array the changed response
	 * @throws NotFoundException|ResponseRefusedException|InvalidAnswerException
	 */
	public function update(File $file, string $responseId, callable $change): array {
		$stored = $this->storedOrConverted($file);
		$form = $this->reader->read($file);
		$queued = false;
		[$old, $new] = $this->responses->change($stored, $form, $responseId, $change, function (array $changed) use ($form, &$queued): ?array {
			$entry = self::outboxEntry($form, $changed, [ResponseOutbox::WEBHOOKS], '', ResponseOutbox::UPDATED);
			$queued = $entry !== null;
			return $entry;
		});
		if ($queued) {
			$this->outbox->schedule((int)$file->getId());
		}
		// No longer its document: made anew from the changed answers.
		$this->pdfs->forget($file, $responseId);
		$this->removeStoredUploads($this->home($file, $stored), $stored, $old, $new);
		return $new;
	}

	/**
	 * Removes one response by id, and then its uploads (L32).
	 *
	 * @return array{0: array, 1: array} the removed response and the form
	 * @throws NotFoundException
	 */
	public function delete(File $file, string $responseId): array {
		$stored = $this->storedOrConverted($file);
		$form = $this->reader->read($file);
		$removed = $this->responses->remove($stored, $form, $responseId, fn (array $gone) => self::outboxEntry($form, $gone, [ResponseOutbox::WEBHOOKS], '', ResponseOutbox::DELETED));
		$this->outbox->schedule((int)$file->getId());
		$this->pdfs->forget($file, $responseId);
		$this->removeStoredUploads($this->home($file, $stored), $stored, $removed, null);
		return [$removed, $form];
	}

	/**
	 * Counts the responses again (L21).
	 */
	public function rebuildIndex(int $fileId): void {
		$file = $this->fileLocator->getFileById($fileId);
		$this->responses->recount($this->storedOrConverted($file), $this->reader->read($file));
	}

	/**
	 * Removes every response, and then all uploads.
	 *
	 * @return array{0: list<array>, 1: array} the removed responses (their ids) and the form
	 */
	public function deleteAll(File $file): array {
		$stored = $this->storedOrConverted($file);
		$form = $this->reader->read($file);
		$ids = $this->responses->removeAll($stored, fn (array $gone) => self::outboxEntry($form, $gone, [ResponseOutbox::WEBHOOKS], '', ResponseOutbox::DELETED));
		$this->outbox->schedule((int)$file->getId());
		$home = $this->home($file, $stored);
		$this->pdfs->forgetAll($home);
		$this->uploadService->deleteAllUploads($home);
		return [array_map(fn (string $id) => ['id' => $id], $ids), $form];
	}

	/**
	 * Where the form's own files are. In app data they are found by the form; beside the file, until they are moved,
	 * they are beside the owner's form, which someone it is shared with does not see in their own folder.
	 */
	private function home(File $file, StoredForm $stored): File {
		if ($stored->filesMoved) {
			return $file;
		}
		try {
			return $this->fileLocator->getFileByIdPublicForWrite((int)$file->getId(), true);
		} catch (\Throwable) {
			return $file;
		}
	}

	/**
	 * What a changed or deleted response named and no stored response names any more goes: a folder as a whole when
	 * nothing names it, else the files in it nothing names. A response never takes someone else's files (H5): a folder
	 * another response names is kept whole where this one named it whole.
	 *
	 * @param array|null $new the response after a change, null when it was removed
	 */
	private function removeStoredUploads(File $file, StoredForm $stored, array $old, ?array $new): void {
		$named = $this->uploadsNamedBy($old);
		$groups = array_values(array_unique(array_column($named, 0)));
		$still = [];
		foreach ([...($new === null ? [] : [$new]), ...$this->responses->namingUploads($stored->id, $groups, (string)$old['id'])] as $response) {
			foreach ($this->uploadsNamedBy($response) as [$group, $filename]) {
				$still[$group][$filename ?? ''] = true;
			}
		}
		$folders = [];
		foreach ($named as [$group, $filename]) {
			if (!isset($still[$group])) {
				$folders[$group] = true;
			} elseif ($filename !== null && !isset($still[$group][$filename]) && !isset($still[$group][''])) {
				$this->uploadService->deleteUpload($file, $group, $filename);
			}
		}
		foreach (array_keys($folders) as $group) {
			$this->uploadService->deleteResponseUploads($file, (string)$group);
		}
	}

	/**
	 * The uploads one response names, through any answer: only well-formed folder ids (H5).
	 *
	 * @return list<array{0: string, 1: string|null}> folder and file name, null when it names no file
	 */
	private function uploadsNamedBy(array $response): array {
		$named = [];
		foreach (is_array($response['answers'] ?? null) ? $response['answers'] : [] as $answer) {
			if (!is_array($answer)) {
				continue;
			}
			foreach (array_is_list($answer) ? $answer : [$answer] as $item) {
				$id = is_array($item) ? ($item['responseId'] ?? null) : null;
				if (is_string($id) && UploadService::isValidResponseId($id)) {
					$name = $item['filename'] ?? null;
					$named[] = [$id, is_string($name) && $name !== '' ? $name : null];
				}
			}
		}
		return $named;
	}
}
