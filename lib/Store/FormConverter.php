<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\Security\FormSecrets;
use OCA\FormVox\Service\FormBrokenException;
use OCA\FormVox\Service\FormFactory;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Service\UploadService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Moves a form file's responses, secrets and files into the database and app data. First a copy ahead without the form's
 * lock, as a draft nobody else reads; then a short switch under the lock that adds what changed meanwhile and makes the
 * database the form's from the same commit. Readers are never held: before the commit they read the file, after it the
 * database. Run again after a crash, it picks up where it stopped without doubling anything.
 *
 * The files beside the form (uploads, branding, template, kept PDFs) move later, in the background (moveFiles), the
 * same way: the bulk mirrored without the lock, then a short step under it that mirrors what changed since and stamps
 * them moved, from which moment readers and writers go to app data. Until then they stay where they are.
 */
class FormConverter {
	/** The folders a form file keeps beside it, by the kind they become in app data. */
	private const KINDS = ['uploads' => FormFolders::UPLOADS, 'branding' => FormFolders::BRANDING, 'templates' => FormFolders::TEMPLATES, 'pdfs' => FormFolders::PDFS];
	/** How long the switch waits for a writer that holds the form, well within a browser's retries. */
	private const WAIT_SECONDS = 20.0;
	/** How long the last step of a files move waits for a writer that holds the form: the next run is never far. */
	private const CLOSE_WAIT_SECONDS = 5.0;

	/** @var array<int, string> the bytes each file was copied ahead from, by file id, for this process */
	private array $copied = [];

	public function __construct(
		private FormStore $forms,
		private ResponseStore $responses,
		private SecretStore $secrets,
		private FormSecrets $sealer,
		private FormFolders $folders,
		private FormLock $lock,
		private FormLockManager $lockManager,
		private FormFileLocator $locator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The copy ahead: responses, keys, counts and what waits to be sent, as a draft. Nothing changes for anyone yet; a
	 * later switch only adds what changed since.
	 *
	 * @param bool $files also copy the files beside the form ahead, for the background job
	 * @return StoredForm the draft, or the form when it is in the database already
	 * @throws FormBrokenException a file that holds no form
	 */
	public function prepare(File $file, bool $files = false): StoredForm {
		$fileId = (int)$file->getId();
		[$document, $raw] = $this->read($file);
		$uuid = is_string($document['id'] ?? null) ? $document['id'] : '';
		// Also a form that lost its file's id and is found where it was: its rows count, not what this file says.
		$stored = $this->forms->resolve($fileId, $uuid);
		if ($stored !== null) {
			return $stored;
		}
		$draft = $this->draftFor($fileId, $uuid);
		if (!$this->responses->mirror($draft->id, $document)) {
			// Switched by another run meanwhile: its rows count, not this older read of the file.
			return $this->forms->byFileId($fileId) ?? $this->forms->byId($draft->id) ?? $draft;
		}
		$this->copied[$fileId] = md5($raw);
		if ($files) {
			$this->copyFilesAhead($file, $draft);
		}
		return $draft;
	}

	/**
	 * The form in the database: copied ahead when it was not yet, then switched under its lock and that of what it sets
	 * off, so no writer and no run of the side effects job meets it half moved. Its file is tidied after, where an
	 * account may write it; where none may, the database counts all the same.
	 *
	 * @param bool $files also move the files beside the form, for the background job
	 * @throws FormBrokenException a file that holds no form
	 * @throws FormLockedException a writer held the form all the while
	 */
	public function convert(File $file, bool $files = false): StoredForm {
		$fileId = (int)$file->getId();
		$stored = $this->forms->byFileId($fileId);
		if ($stored !== null) {
			return $stored;
		}
		$draft = $this->prepare($file, $files);
		if ($draft->draft) {
			$this->switch($file, $draft);
		}
		$stored = $this->forms->byFileId($fileId) ?? throw new \RuntimeException('Form ' . $fileId . ' was not switched');
		$this->tidy($file, $stored);
		if ($files) {
			$this->moveFiles($file, $stored);
		}
		return $stored;
	}

	/**
	 * What a move left for later, or a crash between its steps: tidying the file, moving the files, and clearing what
	 * is still beside the file after the stamp. For the background job; a form with nothing left is not touched.
	 */
	public function settle(File $file, StoredForm $stored): void {
		if (!$stored->tidied) {
			$this->tidy($file, $stored);
		}
		if (!$stored->filesMoved) {
			$this->moveFiles($file, $stored);
			return;
		}
		$this->clearBeside($file, $stored);
	}

	/**
	 * The files beside the form into app data, and away from beside it once they are there whole. The bulk is mirrored
	 * without the form's lock; the last step holds it briefly: what changed since is mirrored and the files stamped
	 * moved, so nothing lands beside the form after the stamp. Then the folders beside it go. One run at a time; a busy
	 * form is left for the next run, which also finishes a run that stopped at any point.
	 */
	public function moveFiles(File $file, StoredForm $stored): void {
		$fileId = (int)$file->getId();
		try {
			$token = $this->lock->acquire($fileId, microtime(true), 'files');
		} catch (FormLockedException) {
			return;
		}
		try {
			// Stamped by a run this one did not see: app data is live then, and only what is still beside goes.
			$stored = $this->forms->byId($stored->id);
			if ($stored === null) {
				return;
			}
			if (!$stored->filesMoved) {
				$this->mirrorFiles($file, $stored->id);
				if (!$this->close($file, $stored)) {
					return;
				}
				$stored = $this->forms->byId($stored->id);
				if ($stored === null) {
					return;
				}
			}
			$this->clearBeside($file, $stored);
		} finally {
			$this->lock->release($fileId, $token, 'files');
		}
	}

	/**
	 * The last step: under the form's lock, the folders beside the file are looked up again (the form may have moved),
	 * what changed since the bulk is mirrored, and the stamp set. A file being written beside the form, or a writer that
	 * holds the form, leaves it to the next run.
	 *
	 * @return bool whether the files are stamped moved
	 */
	private function close(File $file, StoredForm $stored): bool {
		$fileId = (int)$file->getId();
		try {
			$token = $this->lock->acquire($fileId, microtime(true) + self::CLOSE_WAIT_SECONDS);
		} catch (FormLockedException) {
			return false;
		}
		try {
			$now = $this->forms->byId($stored->id);
			if ($now === null || $now->fileId !== $fileId) {
				// Moved to another storage meanwhile: its folders carry the new id, and the next run looks there.
				return false;
			}
			if ($now->filesMoved) {
				return true;
			}
			$fresh = $this->locator->getFileByIdPublic($fileId, false, true);
			if (!$this->mirrorFiles($fresh, $stored->id)) {
				return false;
			}
			if (!$this->lock->stillHeld($fileId, $token)) {
				throw new FormLockedException('Form ' . $fileId . ' was taken over while its files were moved');
			}
			$this->forms->markFilesMoved($stored->id);
			return true;
		} finally {
			$this->lock->release($fileId, $token);
		}
	}

	/**
	 * After the stamp: each file still beside the form goes, once app data has it. One that landed beside after the
	 * stamp, from a writer that chose its folder before it, is brought over first. Run again after a crash or for a
	 * late arrival, it does only what is left.
	 */
	private function clearBeside(File $file, StoredForm $stored): void {
		$writable = $stored->filesAt === null ? null : $this->writable($file);
		if ($writable === null || $stored->filesAt === null) {
			return;
		}
		$parent = $writable->getParent();
		foreach (self::KINDS as $beside => $kind) {
			try {
				$from = $parent->get('.formvox-' . $beside . '-' . $file->getId());
			} catch (NotFoundException) {
				continue;
			}
			if ($from instanceof Folder) {
				$this->takeOver($from, $this->folders->kind($stored->id, $kind, true), $stored->filesAt);
			}
		}
	}

	/** Under the form's lock: the rows made whole, the secrets stored, the database the form's, in one commit. */
	private function switch(File $file, StoredForm $draft): void {
		$fileId = (int)$file->getId();
		$deadline = microtime(true) + self::WAIT_SECONDS;
		$token = $this->lock->acquire($fileId, $deadline);
		try {
			$effects = $this->lock->acquire($fileId, $deadline, 'effects');
			try {
				if ($this->forms->byFileId($fileId) !== null) {
					return;
				}
				[$document, $raw] = $this->read($file);
				// Unchanged since the copy ahead, as is usual: then the lock is held only for the switch itself.
				$changed = ($this->copied[$fileId] ?? null) !== md5($raw);
				$this->responses->mirror($draft->id, $document, function () use ($draft, $document, $fileId, $token): void {
					$settings = is_array($document['settings'] ?? null) ? $document['settings'] : [];
					$this->secrets->save($draft->id, SecretOverlay::secretsOf(['settings' => $this->sealer->sealSettings($settings)]));
					if (!$this->lock->stillHeld($fileId, $token)) {
						throw new FormLockedException('Form ' . $fileId . ' was taken over while it was switched');
					}
					$this->forms->activate($draft->id);
				}, $changed);
			} finally {
				$this->lock->release($fileId, $effects, 'effects');
			}
		} finally {
			$this->lock->release($fileId, $token);
		}
	}

	/**
	 * A draft of its own: the one this file has, one whose file got a new id since, or a new one. A uuid another living
	 * form holds is that form's: a copy gets a uuid of its own.
	 */
	private function draftFor(int $fileId, string $uuid): StoredForm {
		$draft = $this->forms->draftOf($fileId);
		if ($draft !== null) {
			return $draft;
		}
		$known = $uuid === '' ? null : $this->forms->byUuid($uuid);
		if ($known !== null && $known->draft && $known->fileId === $fileId) {
			// Made a moment ago by another run touching the same file.
			return $known;
		}
		if ($known !== null && $known->draft && $this->forms->place($known->fileId) === null) {
			$this->forms->moveDraft($known->id, $fileId);
			return $this->forms->draftOf($fileId) ?? throw new \RuntimeException('Draft ' . $known->id . ' did not follow file ' . $fileId);
		}
		try {
			return $this->forms->draft($known === null && $uuid !== '' ? $uuid : FormFactory::uuid(), $fileId);
		} catch (\OCP\DB\Exception $e) {
			// Another run made this file's draft a moment ago, or took the uuid for its own file: then this file is a copy.
			$mine = $this->forms->draftOf($fileId);
			if ($mine !== null) {
				return $mine;
			}
			$taken = $this->forms->byUuid($uuid);
			if ($taken !== null && $taken->fileId === $fileId) {
				return $taken;
			}
			return $this->forms->draft(FormFactory::uuid(), $fileId);
		}
	}

	/**
	 * Straight from storage, below Nextcloud's file lock: a writer of the file waits for the form's lock first.
	 *
	 * @return array{0: array, 1: string} the form, and the bytes it was read from
	 * @throws FormBrokenException
	 */
	private function read(File $file): array {
		$raw = $file->getStorage()->file_get_contents($file->getInternalPath());
		$document = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($document) || array_is_list($document)) {
			throw new FormBrokenException();
		}
		return [$document, (string)$raw];
	}

	/**
	 * The folders beside the form into the draft's app data, ahead of the move, while the draft is still one: a run
	 * that is moving the files holds the same lock, and a draft switched to meanwhile is a form whose app data may be
	 * live.
	 */
	private function copyFilesAhead(File $file, StoredForm $draft): void {
		$fileId = (int)$file->getId();
		try {
			$token = $this->lock->acquire($fileId, microtime(true), 'files');
		} catch (FormLockedException) {
			return;
		}
		try {
			if ($this->forms->byId($draft->id)?->draft === true) {
				$this->mirrorFiles($file, $draft->id);
			}
		} finally {
			$this->lock->release($fileId, $token, 'files');
		}
	}

	/**
	 * App data as the folders beside the form are now: a kind that is not beside it is not in app data either. Only
	 * before the stamp, while nobody but the move writes app data.
	 *
	 * @return bool whether app data mirrors the folders whole: false while a file beside the form is being written
	 */
	private function mirrorFiles(File $file, int $formId): bool {
		$parent = $file->getParent();
		$whole = true;
		foreach (self::KINDS as $beside => $kind) {
			try {
				$from = $parent->get('.formvox-' . $beside . '-' . $file->getId());
			} catch (NotFoundException) {
				$from = null;
			}
			if ($from instanceof Folder) {
				$whole = $this->mirror($from, $this->folders->kind($formId, $kind, true)) && $whole;
				continue;
			}
			try {
				$this->folders->kind($formId, $kind)->delete();
			} catch (NotFoundException) {
				// Nothing of this kind on either side.
			}
		}
		return $whole;
	}

	/**
	 * By name, size and time: what is new or changed beside the form is copied, what is gone from beside it is removed
	 * from app data, what is there whole already is left alone.
	 */
	private function mirror(Folder $from, Folder $to): bool {
		$whole = true;
		$seen = [];
		foreach ($from->getDirectoryListing() as $node) {
			$name = $node->getName();
			$seen[$name] = true;
			try {
				if ($node instanceof Folder) {
					$target = $to->nodeExists($name) ? $to->get($name) : null;
					if ($target !== null && !$target instanceof Folder) {
						$target->delete();
						$target = null;
					}
					$whole = $this->mirror($node, $target ?? $to->newFolder($name)) && $whole;
					continue;
				}
				if (!$node instanceof File) {
					continue;
				}
				$there = $to->nodeExists($name) ? $to->get($name) : null;
				if ($there instanceof File && !$this->changed($node, $there)) {
					continue;
				}
				$there?->delete();
				$this->copy($node, $to);
			} catch (NotFoundException) {
				// Removed beside the form while it was copied: not in app data either, then.
				unset($seen[$name]);
			} catch (LockedException) {
				// Being written beside the form now: the bulk leaves it to the switch, the switch to the next run.
				$whole = false;
			}
		}
		foreach ($to->getDirectoryListing() as $node) {
			if (!isset($seen[$node->getName()])) {
				$node->delete();
			}
		}
		return $whole;
	}

	/**
	 * After the stamp: a file beside the form that app data has already, or wrote itself after the stamp, only goes;
	 * one that landed or changed beside the form after the mirror is brought over first. A file in use stays for the
	 * next run.
	 */
	private function takeOver(Folder $from, Folder $to, int $filesAt): void {
		foreach ($from->getDirectoryListing() as $node) {
			$name = $node->getName();
			try {
				if ($node instanceof Folder) {
					$target = $to->nodeExists($name) ? $to->get($name) : $to->newFolder($name);
					if ($target instanceof Folder) {
						$this->takeOver($node, $target, $filesAt);
					}
					continue;
				}
				if (!$node instanceof File) {
					continue;
				}
				$there = $to->nodeExists($name) ? $to->get($name) : null;
				if ($there !== null && !$there instanceof File) {
					continue;
				}
				if ($there === null || ($there->getMTime() <= $filesAt && $this->changed($node, $there))) {
					$there?->delete();
					$this->copy($node, $to);
				}
				UploadService::withoutTrash(fn () => $node->delete());
			} catch (NotFoundException|LockedException) {
				// Gone meanwhile, or being written or read now: what is left waits for the next run.
				continue;
			}
		}
		if ($from->getDirectoryListing() === []) {
			UploadService::withoutTrash(fn () => $from->delete());
		}
	}

	/** Whether the copy no longer matches: another size, or the original written since the copy was made. */
	private function changed(File $source, File $copy): bool {
		return $source->getSize() !== $copy->getSize() || $source->getMTime() > $copy->getMTime();
	}

	/**
	 * @throws NotFoundException also for a file that cannot be opened: gone, as far as the move is concerned
	 * @throws LockedException a file being written now
	 */
	private function copy(File $node, Folder $to): void {
		$stream = $node->fopen('r');
		if ($stream === false) {
			throw new NotFoundException('Could not read ' . $node->getName() . ' beside a form');
		}
		try {
			$to->newFile($node->getName(), $stream);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}

	/** Never fails what set it off: the form is in the database already, and a later run tidies the file. */
	private function tidy(File $file, StoredForm $stored): void {
		try {
			$writable = $this->writable($file);
			if ($writable === null) {
				return;
			}
			// Within what the file held, so a full quota does not keep responses and secrets in the file.
			$this->lockManager->mutateFormFileWithLock($writable, function (array &$form) use ($stored): void {
				$form = $stored->formOnly($form);
			}, stamp: false, counted: true);
			$this->forms->markTidied($stored->id);
		} catch (ClientErrorException|FormLockedException $e) {
			$this->logger->info('FormVox: form ' . $file->getId() . ' is in the database; its file is tidied later: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		} catch (\Throwable $e) {
			// Never with the exception: its trace carries the file, responses and secrets included.
			$this->logger->warning('FormVox: form ' . $file->getId() . ' is in the database; its file could not be tidied now: ' . get_class($e) . ': ' . $e->getMessage() . ($e->getPrevious() === null ? '' : ' (' . $e->getPrevious()->getMessage() . ')'), ['app' => Application::APP_ID]);
		}
	}

	/** The form through an account that may write it; null where none may, as in a team folder its members only read. */
	private function writable(File $file): ?File {
		try {
			return $this->locator->getFileByIdPublic((int)$file->getId(), true);
		} catch (\Throwable) {
			$this->logger->info('FormVox: form ' . $file->getId() . ' is in the database; nobody may write its file, so it keeps what it held', ['app' => Application::APP_ID]);
			return null;
		}
	}
}
