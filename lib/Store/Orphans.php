<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\BackgroundJob\SideEffectsJob;
use OCA\FormVox\Exception\OrphanException;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Forms in the database whose file is gone. One that disappeared with nothing to tell (found gone by a scan, its
 * storage away, files put back without it) is kept GRACE_SECONDS, in case the file turns up again, then removed with
 * every row it owns and its files in app data. One that Nextcloud deleted for good is removed at once (removeDeleted), as is one that went with its account (removeGone).
 * A form in the trash still has its file.
 */
class Orphans {
	public const GRACE_SECONDS = 30 * 86400;

	public function __construct(
		private FormStore $forms,
		private FormFolders $folders,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private FormFileLocator $locator,
		private FormReader $reader,
		private OutboxStore $outbox,
		private IJobList $jobs,
	) {
	}

	/**
	 * Marks the forms whose file is gone, brings where the others are up to date (a folder renamed, which tells the
	 * forms in it nothing), takes back one whose file is there again or holds it where it was under another id, and
	 * removes those gone long enough. What waits to be sent gets its job again, under the id its form has now.
	 *
	 * @return array{orphaned: int, found: int, removed: int, drafts: int}
	 */
	public function sweep(): array {
		$now = $this->time->getTime();
		$done = ['orphaned' => 0, 'found' => 0, 'removed' => 0, 'drafts' => 0];
		$expired = [];
		$abandoned = [];
		foreach ($this->forms->withPlaces() as [$form, $place]) {
			if ($form->draft) {
				// Copied ahead from a file that is gone since, or a second draft of a file another run switched: never
				// switched to, so nothing of it is anyone's. Switched meanwhile, it is that file's form itself.
				$active = $place === null ? null : $this->forms->byFileId($form->fileId);
				if ($place === null || ($active !== null && $active->id !== $form->id)) {
					$abandoned[] = $form->id;
				}
				continue;
			}
			if ($place !== null) {
				if ($form->orphanedAt !== null) {
					$done['found']++;
				}
				if ($form->orphanedAt !== null || [$form->storageId, $form->pathHash] !== $place) {
					$this->forms->rebind($form->id, $form->fileId, $place[0], $place[1]);
				}
			} elseif ($this->foundWhereItWas($form)) {
				$done['found']++;
			} elseif ($form->orphanedAt === null) {
				$this->forms->markOrphan($form->id);
				$done['orphaned']++;
			} elseif ($form->orphanedAt <= $now - self::GRACE_SECONDS) {
				$expired[] = $form->id;
			}
		}
		$this->scheduleWaiting();
		foreach ($abandoned as $formId) {
			try {
				if ($this->forms->deleteDraft($formId)) {
					$this->removeFiles($formId);
					$done['drafts']++;
				}
			} catch (\Throwable $e) {
				$this->logger->error('FormVox: could not remove the draft of form ' . $formId . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		foreach ($expired as $formId) {
			try {
				$this->remove($formId);
				$done['removed']++;
			} catch (\Throwable $e) {
				$this->logger->error('FormVox: could not remove orphaned form ' . $formId . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		return $done;
	}

	/**
	 * A file under another id where the form's was, as after files were put back or scanned again: reading it binds the
	 * form to it when it holds the form's uuid (FormStore::resolve), by the same rule as when someone opens it.
	 */
	private function foundWhereItWas(StoredForm $form): bool {
		$fileId = $form->pathHash === '' ? null : $this->forms->fileAt($form->storageId, $form->pathHash);
		if ($fileId === null || $this->forms->byFileId($fileId) !== null) {
			return false;
		}
		try {
			$this->reader->read($this->locator->getFileByIdPublic($fileId));
		} catch (\Throwable) {
			return false;
		}
		return $this->forms->byId($form->id)?->fileId === $fileId;
	}

	/** A job is keyed by the form's file: one asked for under an id the form no longer has never runs. */
	private function scheduleWaiting(): void {
		foreach ($this->outbox->waitingForms() as $formId) {
			$form = $this->forms->byId($formId);
			if ($form !== null && $form->orphanedAt === null && !$this->jobs->has(SideEffectsJob::class, ['fileId' => $form->fileId])) {
				$this->jobs->add(SideEffectsJob::class, ['fileId' => $form->fileId]);
			}
		}
	}

	/**
	 * An orphan's rows and files, now. Its rows first, and only while it is still an orphan: found again meanwhile it
	 * is the form, and files removed before a refused row would be that form's.
	 *
	 * @throws OrphanException when it is no orphan
	 */
	public function remove(int $formId): void {
		if ($this->forms->byId($formId)?->orphanedAt === null || !$this->forms->deleteOrphan($formId)) {
			throw new OrphanException('Form ' . $formId . ' is no orphan');
		}
		$this->removeFiles($formId);
	}

	/**
	 * The forms whose file Nextcloud deleted for good just now: their rows and files go at once, as whoever deleted
	 * the file meant. A form is known by the id its file had; where no form had the id an entry came with (Nextcloud 34
	 * and 35.0.0 number a removed folder's children instead of naming them), by where it last saw its file. So a form
	 * in a trash is told where it lies, to be known when that trash is emptied.
	 *
	 * @param list<array{0: int, 1: int, 2: string}> $deleted the file id, storage and path of each entry deleted
	 * @return list<StoredForm> the forms removed
	 */
	public function removeDeleted(array $deleted): array {
		$gone = [];
		foreach ($this->forms->goneOrTrashed() as [$form, $place]) {
			if ($place === null) {
				$gone[] = $form;
			} elseif ([$form->storageId, $form->pathHash] !== $place) {
				$this->forms->seenAt($form->id, $form->fileId, $place[0], $place[1]);
			}
		}
		$ids = array_fill_keys(array_column($deleted, 0), true);
		$places = [];
		foreach ($deleted as [$fileId, $storageId, $path]) {
			$places[$storageId . ':' . md5($path)] = $fileId;
		}
		$byId = array_filter($gone, fn (StoredForm $form): bool => isset($ids[$form->fileId]));
		$named = array_fill_keys(array_map(fn (StoredForm $form): int => $form->fileId, $byId), true);
		// An entry that named a form says nothing of another form that was there before it.
		$byPlace = array_filter($gone, function (StoredForm $form) use ($ids, $places, $named): bool {
			$fileId = $places[$form->storageId . ':' . $form->pathHash] ?? null;
			return !isset($ids[$form->fileId]) && $fileId !== null && !isset($named[$fileId]);
		});
		return $this->removeBound([...$byId, ...$byPlace]);
	}

	/**
	 * The forms whose file lay in a storage Nextcloud emptied without a word per file, as it does a deleted account's
	 * home: those whose file is gone now go at once, as removeDeleted's do.
	 *
	 * @param list<StoredForm> $forms
	 * @return list<StoredForm> the forms removed
	 */
	public function removeGone(array $forms): array {
		$gone = array_fill_keys($this->forms->gone(array_map(fn (StoredForm $form): int => $form->fileId, $forms)), true);
		return $this->removeBound(array_values(array_filter($forms, fn (StoredForm $form): bool => isset($gone[$form->fileId]))));
	}

	/**
	 * @param array<StoredForm> $forms
	 * @return list<StoredForm> the forms removed
	 */
	private function removeBound(array $forms): array {
		$removed = [];
		foreach ($forms as $form) {
			try {
				// Its rows first, and only while it is still that file's: rebound meanwhile, it lives on elsewhere.
				if ($this->forms->deleteBoundTo($form->id, $form->fileId)) {
					$this->removeFiles($form->id);
					$removed[] = $form;
				}
			} catch (\Throwable $e) {
				$this->logger->error('FormVox: could not remove form ' . $form->id . ', whose file was deleted: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		return $removed;
	}

	private function removeFiles(int $formId): void {
		try {
			$this->folders->all($formId)->delete();
		} catch (NotFoundException) {
			// It kept no files.
		}
	}

	/**
	 * Binds a form to a file by hand, as only an administrator may when its file got a new id somewhere else.
	 *
	 * @throws OrphanException a form or file that does not exist, or a file another form is bound to
	 */
	public function rebind(int $formId, int $fileId): void {
		$form = $this->forms->byId($formId);
		if ($form === null || $form->draft) {
			throw new OrphanException('There is no form ' . $formId);
		}
		$place = $this->forms->place($fileId) ?? throw new OrphanException('There is no file ' . $fileId);
		try {
			$isForm = strtolower(pathinfo($this->locator->getFileByIdPublic($fileId)->getName(), PATHINFO_EXTENSION)) === Application::FILE_EXTENSION;
		} catch (\Throwable) {
			throw new OrphanException('There is no file ' . $fileId);
		}
		if (!$isForm) {
			throw new OrphanException('File ' . $fileId . ' is no form');
		}
		$bound = $this->forms->byFileId($fileId);
		if ($bound !== null && $bound->id !== $form->id) {
			throw new OrphanException('File ' . $fileId . ' holds form ' . $bound->id . ' already');
		}
		$this->forms->rebind($form->id, $fileId, $place[0], $place[1]);
	}
}
