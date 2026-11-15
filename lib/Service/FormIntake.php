<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Versions\IFormVersionCleaner;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The one place that decides what a form file may hold when it arrives outside FormVox.
 *
 * A copy is a new form: questions and behaviour only, made by whoever copied it, without the original's responses,
 * link, keys, webhooks or upload folders; a copy that cannot be cleaned is removed rather than left with them.
 *
 * A file written over a form (Files, WebDAV, the sync client, another app) brings its content only: the responses,
 * link, keys, webhooks and creator stay as the server has them, and content that would break the form is refused.
 * A new form file written that way starts like a copy.
 */
class FormIntake {
	/** The hidden folders next to a form, named by its file id; in a copy they belong to the original. */
	private const SIBLING_FOLDER = '/^\.formvox-(uploads|branding|templates|pdfs)-\d+$/';

	/** What a file written over a form may change: what a download of it carries (FormSanitizer::structureOnly()). */
	private const CONTENT_FIELDS = ['title', 'description', 'descriptionAlign', 'questions', 'pages'];

	/** @var array<string, ?string> each form file's bytes before an outside write, by path; null: a new file */
	private array $before = [];
	/** The path a rename is under way to: Nextcloud sends no event after a rename that fails. */
	private ?string $renaming = null;
	/** @var array<string, int> the id of a form another file is renamed over, by its path */
	private array $over = [];

	public function __construct(
		private FormSanitizer $sanitizer,
		private FormSchemaValidator $schema,
		private FormLockManager $lockManager,
		private OwnWrites $ownWrites,
		private IFormVersionCleaner $versionCleaner,
		private IUserSession $userSession,
		private FormWriteGuard $writeGuard,
		private FormLock $lock,
		private OutsideWriteHold $hold,
		private IL10N $l,
		private LoggerInterface $logger,
		private ?FormStore $forms = null,
		private ?ResponseOutbox $outbox = null,
		private ?FormConverter $converter = null,
	) {
	}

	/**
	 * A file or a folder Nextcloud copied; every form in it is cleaned, also in nested folders.
	 */
	public function copied(Node $target): void {
		if ($target instanceof File) {
			if ($this->isForm($target)) {
				$this->cleanCopy($target);
			}
			return;
		}
		if ($target instanceof Folder) {
			try {
				$this->cleanFolder($target);
			} catch (\Throwable $e) {
				$this->logger->error('FormVox: could not look through a copied folder for forms (' . $target->getPath() . '): ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
	}

	/**
	 * The form to store when $upload is written over $current, or as a new file when $current is null.
	 *
	 * @return array|null null for a new file that holds no form: there is nothing of one to keep out
	 * @throws FormValidationException content that would break the form
	 * @throws RequestException a file written over a form that is not a form
	 */
	public function takeIn(?array $current, string $upload): ?array {
		$incoming = json_decode($upload, true);
		if (!is_array($incoming) || array_is_list($incoming)) {
			if ($current === null) {
				return null;
			}
			// TRANSLATORS: Error when a file put over a form through Files or WebDAV is not a FormVox form
			throw RequestException::invalid(Translatable::t('This file is not a FormVox form'));
		}

		if ($current === null) {
			$form = $this->sanitizer->structureOnly($incoming);
			// Whoever puts it there becomes the creator; without a session nobody rather than whoever the file names.
			$form = FormCreatorService::withCreator($form, $this->userSession->getUser()?->getUID());
			unset($form['downloaded_at']);
			$form['created_at'] = date('c');
		} else {
			$form = $current;
			foreach (self::CONTENT_FIELDS as $field) {
				if (array_key_exists($field, $incoming)) {
					$form[$field] = $incoming[$field];
				}
			}
			if (!is_array($form['settings'] ?? null)) {
				$form['settings'] = [];
			}
			$settings = is_array($incoming['settings'] ?? null) ? $incoming['settings'] : [];
			foreach (array_keys(FormSanitizer::STRUCTURE_SETTINGS) as $key) {
				if (array_key_exists($key, $settings)) {
					$form['settings'][$key] = $settings[$key];
				}
			}
		}
		$form['modified_at'] = date('c');
		return $this->schema->check($form);
	}

	/**
	 * A file put over a stored form, merged under the form's lock so a response submitted meanwhile is not lost.
	 *
	 * @return bool false when the stored file is no form FormVox can read: the upload then arrives as a new form
	 */
	public function overwrite(File $file, string $upload): bool {
		$stored = $file->getStorage()->file_get_contents($file->getInternalPath());
		$current = is_string($stored) ? json_decode($stored, true) : null;
		if (!is_array($current) || array_is_list($current)) {
			return false;
		}
		// Checked first, so a refused upload leaves the form as it was; then moved in, so the merge writes the form only.
		$this->takeIn($current, $upload);
		$this->converter?->convert($file);
		// Someone changing the form, so Nextcloud keeps a version as it would for any file.
		$this->lockManager->mutateFormFileWithLock($file, function (array &$form) use ($upload): void {
			$form = $this->takeIn($form, $upload) ?? $form;
		}, true);
		return true;
	}

	/**
	 * Nextcloud is about to write a form file for someone other than FormVox: remember what the server has.
	 */
	public function beforeWrite(Node $node): void {
		if (!$this->isOutsideWrite($node)) {
			return;
		}
		try {
			$fileId = (int)$node->getId();
		} catch (NotFoundException) {
			$this->before[$this->key($node)] = null;
			return;
		}
		// Held until it is taken in, so a submission waits instead of being written over (3b-3).
		try {
			$this->hold->take($fileId);
		} catch (FormLockedException $e) {
			// TRANSLATORS: Error when a form file is written through Files or another app while FormVox saves it
			throw new FormBusyHint($this->l->t('The form is busy, please try again'), $e);
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: took in a form written from outside without its lock: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
		try {
			$raw = $node->getStorage()->file_get_contents($node->getInternalPath());
		} catch (\Throwable) {
			$raw = false;
		}
		if (!is_string($raw)) {
			// What it held is unknown, so nothing can be put back after the write.
			return;
		}
		// Only the bytes: decoded when the write is done, so a large form is not held twice meanwhile.
		$this->before[$this->key($node)] = $raw;
	}

	/**
	 * That write is done: the content stays and the server's data comes back. Content that would break the form is
	 * undone; a new form that would not open is removed, like a copy that cannot be cleaned.
	 */
	public function written(Node $node): void {
		try {
			$this->takeInWritten($node);
		} finally {
			try {
				$this->hold->release((int)$node->getId());
			} catch (\Throwable) {
				// Gone meanwhile: released when the request ends.
			}
		}
	}

	private function takeInWritten(Node $node): void {
		$key = $this->key($node);
		if (!$this->isOutsideWrite($node) || !array_key_exists($key, $this->before)) {
			return;
		}
		/** @var File $node */
		$before = $this->before[$key];
		unset($this->before[$key]);

		$upload = '';
		$payload = null;
		try {
			$read = $node->getStorage()->file_get_contents($node->getInternalPath());
			$upload = is_string($read) ? $read : '';
			if ($before !== null && $upload === $before) {
				// The same bytes again, as encrypt-all and decrypt-all put them back: nothing to take in or rewrite.
				return;
			}
			$form = $this->takeIn($this->formIn($before), $upload);
			if ($form === null) {
				// No form in it, so nothing of one to keep out.
				return;
			}
			$payload = json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($payload === false) {
				throw new \RuntimeException('the form could not be encoded');
			}
			if ($payload !== $upload) {
				$this->store($node, $payload);
			}
		} catch (ClientErrorException $e) {
			// No room for the server's data next to the new content: that data was counted before the write, so it goes
			// back past the quota. A new form only when cleaning it takes no more room than the upload did.
			// Cleaning lays the form out and adds a few fields: room for that, never for what the upload did not bring.
			$counted = $this->formIn($before) !== null || strlen((string)$payload) <= 2 * strlen($upload) + 65536;
			if ($e->getReason() === 'storage_full' && $payload !== null && $counted
				&& $this->holding($node, fn () => $this->writeGuard->rewrite($node->getStorage(), $node->getInternalPath(), $payload))) {
				$this->logger->warning('FormVox: a form written outside FormVox was taken in past the quota (' . $node->getPath() . ')', ['app' => Application::APP_ID]);
				return;
			}
			$this->refuse($node, $before, $e);
		} catch (\Throwable $e) {
			$this->logger->error('FormVox: could not take in a form file written outside FormVox (' . $node->getPath() . '): ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			// Left in place, the upload would stand as written: its own responses, keys and webhooks, the server's gone.
			try {
				$this->refuse($node, $before, $e);
			} catch (\Throwable $undo) {
				$this->logger->error('FormVox: could not undo a form file written outside FormVox (' . $node->getPath() . '): ' . $undo->getMessage(), ['app' => Application::APP_ID, 'exception' => $undo]);
			}
		}
	}

	/**
	 * Another app is about to rename a file onto a form's name. Over an existing form (encrypt-all and decrypt-all copy
	 * each file aside and rename the copy back over it) that form is remembered, so its responses are kept.
	 */
	public function beforeRename(Node $source, Node $target): void {
		if ($this->renaming !== null) {
			// An earlier rename that never happened: what it remembered is not needed any more.
			unset($this->before[$this->renaming], $this->over[$this->renaming]);
			$this->renaming = null;
		}
		if (!$this->wasFormAllAlong($source)) {
			$this->beforeWrite($target);
			$this->renaming = $this->key($target);
			try {
				// The renamed file takes the form's place under its own id: the form's row goes with it.
				$this->over[$this->renaming] = (int)$target->getId();
			} catch (NotFoundException) {
			}
		}
	}

	/**
	 * A file renamed into a form by another app (WebDAV refuses it). Over an existing form it is a write over that form,
	 * so the server's data stays; onto a free name it is taken in as a new form, like a copy.
	 */
	public function renamed(Node $source, Node $target): void {
		$this->renaming = null;
		$over = $this->over[$this->key($target)] ?? null;
		unset($this->over[$this->key($target)]);
		if (!$this->isOutsideWrite($target) || $this->wasFormAllAlong($source)) {
			return;
		}
		if ($over !== null) {
			try {
				$this->forms?->follow($over, (int)$target->getId());
				// What still waited was asked for under the old id.
				$this->outbox?->schedule((int)$target->getId());
			} catch (\Throwable $e) {
				$this->logger->error('FormVox: could not bind form ' . $over . ' to the file renamed over it (' . $target->getPath() . '): ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		/** @var File $target */
		if (!array_key_exists($this->key($target), $this->before)) {
			$this->markNew($target);
		}
		$this->written($target);
	}

	/** A form renamed, or back from the trash where it is named "….fvform.d<time>". */
	private function wasFormAllAlong(Node $source): bool {
		return preg_match('/\.' . Application::FILE_EXTENSION . '(\.d\d+)?$/i', $source->getName()) === 1;
	}

	/** As if the file had just been written new: what it held is not kept. */
	private function markNew(File $file): File {
		$this->before[$this->key($file)] = null;
		return $file;
	}

	/** The form in bytes remembered before a write, or null when they hold none (or the file was new). */
	private function formIn(?string $raw): ?array {
		$form = $raw === null ? null : json_decode($raw, true);
		return is_array($form) && !array_is_list($form) ? $form : null;
	}

	private function refuse(File $file, ?string $before, \Throwable $reason): void {
		if ($this->formIn($before) === null) {
			$this->logger->warning('FormVox: a form file written outside FormVox would not open and is removed (' . $file->getPath() . '): ' . $reason->getMessage(), ['app' => Application::APP_ID]);
			$this->remove($file, 'a form that would not open');
			return;
		}
		$this->logger->warning('FormVox: content written over a form outside FormVox could not be taken in and is undone (' . $file->getPath() . '): ' . $reason->getMessage(), ['app' => Application::APP_ID]);
		// What it held was counted before, so it goes back also when the account is out of room.
		$this->holding($file, fn () => $this->writeGuard->rewrite($file->getStorage(), $file->getInternalPath(), (string)$before));
	}

	private function cleanFolder(Folder $folder): void {
		$children = $folder->getDirectoryListing();
		$holdsForm = false;
		foreach ($children as $child) {
			if ($child instanceof File && $this->isForm($child)) {
				$holdsForm = true;
				$this->cleanCopy($child);
			}
		}
		foreach ($children as $child) {
			if (!($child instanceof Folder)) {
				continue;
			}
			if ($holdsForm && preg_match(self::SIBLING_FOLDER, $child->getName()) === 1) {
				// The original's responses' files and images; the copy starts empty.
				$this->remove($child, 'a copied form folder');
				continue;
			}
			$this->cleanFolder($child);
		}
	}

	private function cleanCopy(File $copy): void {
		try {
			$content = $copy->getStorage()->file_get_contents($copy->getInternalPath());
			if (!is_string($content)) {
				throw new \RuntimeException('the copy could not be read');
			}
			$form = json_decode($content, true);
			if (!is_array($form)) {
				// Not a form FormVox could open, so there is nothing of a form in it to leave behind.
				return;
			}

			$form = $this->sanitizer->structureOnly($form);
			// Whoever copies becomes the creator; without a session nobody rather than the wrong person.
			$form = FormCreatorService::withCreator($form, $this->userSession->getUser()?->getUID());
			// A form of its own, in the database from the start: the original's uuid stays the original's.
			$form['id'] = FormFactory::uuid();
			$form['copied_from'] = $form['created_at'] ?? null;
			$form['created_at'] = date('c');
			$form['updated_at'] = date('c');

			$payload = json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($payload === false) {
				throw new \RuntimeException('the cleaned form could not be encoded');
			}
			$this->store($copy, $payload, true);
			$this->forms?->bindNew($form['id'], (int)$copy->getId());
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: a copied form could not be cleaned and is removed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			$this->remove($copy, 'an uncleaned copy');
		}
	}

	/**
	 * Straight to storage: whoever copied or wrote the file may still hold Nextcloud's lock on it.
	 *
	 * @param bool $dropHistory for a copy: the versions it took along are the original's
	 */
	private function store(File $file, string $payload, bool $dropHistory = false): void {
		$storage = $file->getStorage();
		$path = $file->getInternalPath();
		$this->holding($file, fn () => $this->writeGuard->write($storage, $path, $payload, function () use ($storage, $path, $payload): void {
			$written = $storage->file_put_contents($path, $payload);
			if ($written === false || $written < strlen($payload)) {
				throw new \RuntimeException('the form could not be written');
			}
			$storage->getUpdater()->update($path);
		}));
		if ($dropHistory) {
			$this->versionCleaner->deleteVersionsForFile($file);
		}
	}

	/**
	 * A write of ours under the form's lock when it is free, so readers see a save under way (review of the 3a fixes).
	 * Never waited for: this request holds Nextcloud's lock, which a FormVox writer holding the form's lock may wait on;
	 * holding it from before the write to after it is 3b's (spike M2/A1).
	 *
	 * @template T
	 * @param callable(): T $write
	 * @return T
	 */
	private function holding(File $file, callable $write): mixed {
		$fileId = 0;
		$token = null;
		try {
			$fileId = (int)$file->getId();
			// Held from before this write already (3b-3); otherwise taken when free.
			$token = $this->hold->holds($fileId) ? null : $this->lock->acquire($fileId, microtime(true));
		} catch (FormLockedException) {
		} catch (\Throwable $e) {
			// The lock only helps readers; whatever keeps it from us, the write still happens (review of the 3a fixes).
			$this->logger->warning('FormVox: wrote a form taken in from outside without its lock: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
		try {
			return $write();
		} finally {
			if ($token !== null) {
				$this->lock->release($fileId, $token);
			}
		}
	}

	/** What a copy or a refused file leaves behind is no one's to restore: not into the trash. */
	private function remove(Node $node, string $what): void {
		try {
			UploadService::withoutTrash(fn () => $node->delete());
		} catch (\Throwable $e) {
			$this->logger->error('FormVox: could not remove ' . $what . ' (' . $node->getPath() . '): ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	/** Nextcloud 32 reports a file that does not exist yet as ".../files//name". */
	private function key(Node $node): string {
		return (string)preg_replace('#/{2,}#', '/', $node->getPath());
	}

	private function isOutsideWrite(Node $node): bool {
		return !$this->ownWrites->active() && $node instanceof File && $this->isForm($node);
	}

	private function isForm(File $file): bool {
		return strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)) === Application::FILE_EXTENSION;
	}
}
