<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Security\FormSecrets;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\ResponseStore;
use OCA\FormVox\Store\StoredForm;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMovableMount;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class FormRepository {
	/** Far above a form with thousands of responses; what an anonymous request may make the server read. */
	private const MAX_PUBLIC_BYTES = 64 * 1024 * 1024;

	/**
	 * Chunk size for IN() clauses — stays below SQLite's 999 bound-parameter limit.
	 */
	private const STORAGE_ID_CHUNK = 500;

	/** Only the server writes these; a client save keeps what is stored, so a stale or placeholder copy cannot overwrite them. */
	private const SERVER_OWNED_SETTINGS = ['api_keys', 'webhooks', 'share_password_hash', 'has_share_password'];

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IDBConnection $db,
		private IL10N $l,
		private IMimeTypeLoader $mimeTypeLoader,
		private FormFactory $formFactory,
		private FormFileLocator $fileLocator,
		private FormLockManager $lockManager,
		private PermissionService $permissions,
		private FormSecrets $secrets,
		private FormSanitizer $sanitizer,
		private FormSchemaValidator $schema,
		private OwnWrites $ownWrites,
		private FormReader $reader,
		private OutsideWriteHold $hold,
		private IFilenameValidator $filenames,
		private LoggerInterface $logger,
		private ?FormStore $forms = null,
		private ?ResponseStore $responses = null,
		private ?IndexService $index = null,
		private ?FormConverter $converter = null,
	) {
	}

	/**
	 * Create a new form
	 */
	public function create(string $title, string $path = '', ?string $template = null, array $prefilled = []): array {
		$user = $this->userSession->getUser();
		return $this->createAsUser($user->getUID(), $title, $path, $template, $prefilled);
	}

	/**
	 * Same as create(), but with an explicit userId so it can be invoked from
	 * a background context (event listener) where there is no session.
	 */
	public function createAsUser(string $userId, string $title, string $path = '', ?string $template = null, array $prefilled = []): array {
		// Create form structure
		$form = $this->formFactory->createFormStructure($title, $userId, $template);

		// Apply prefilled content (e.g. from AI generation) on top of the template
		if (isset($prefilled['description']) && is_string($prefilled['description'])) {
			$form['description'] = $prefilled['description'];
		}
		if (isset($prefilled['questions']) && is_array($prefilled['questions']) && $prefilled['questions'] !== []) {
			$form['questions'] = $prefilled['questions'];
		}
		if (isset($prefilled['pages']) && is_array($prefilled['pages']) && $prefilled['pages'] !== []) {
			$form['pages'] = $prefilled['pages'];
		}
		// A template's settings over the defaults, by the template allowlist: never a link, people or integrations.
		if (isset($prefilled['settings']) && is_array($prefilled['settings'])) {
			$form['settings'] = array_replace($form['settings'] ?? [], $this->sanitizer->forTemplate(['settings' => $prefilled['settings']])['settings']);
		}

		// AI output, a template or a client's prefill: checked like any save, before anything is written (V5).
		$form = $this->schema->check($form);

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$targetFolder = $userFolder;
		if (!empty($path)) {
			try {
				$targetFolder = $userFolder->get($path);
			} catch (NotFoundException $e) {
				$targetFolder = $userFolder->newFolder($path);
			}
		}
		$filename = $this->formFactory->getUniqueFilename($targetFolder, $this->fileNameFor($title));

		$file = $this->ownWrites->during(fn () => $targetFolder->newFile($filename, json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));
		if ($this->forms !== null) {
			try {
				$this->forms->bindNew((string)$form['id'], (int)$file->getId());
			} catch (\Throwable $e) {
				// A form without its row would keep its secrets in the file: not made at all, nor kept in the trash.
				UploadService::withoutTrash(fn () => $file->delete());
				throw $e;
			}
		}

		return [
			'fileId' => $file->getId(),
			'path' => $file->getPath(),
			'form' => $form,
		];
	}

	/**
	 * Load a form by file ID
	 */
	public function load(int $fileId): array {
		return $this->normalised($this->withResponses($fileId, $this->reader->read($this->fileLocator->getFileById($fileId))));
	}

	/**
	 * The form for the editor and integrations: a form in the database comes with its counts, not its responses; a form
	 * file is read as it is.
	 */
	public function loadForm(int $fileId): array {
		return $this->normalised($this->withCounts($fileId, $this->reader->read($this->fileLocator->getFileById($fileId))));
	}

	/** As loadForm(), for a file opened elsewhere. */
	public function readForm(File $file): array {
		return $this->withCounts((int)$file->getId(), $this->reader->read($file));
	}

	/** One response by id, from the database for a form there, else from the form as read. */
	public function response(int $fileId, array $form, string $responseId): ?array {
		$stored = $this->stored($fileId);
		if ($stored !== null) {
			return $this->responses->get($stored->id, $responseId);
		}
		foreach (is_array($form['responses'] ?? null) ? $form['responses'] : [] as $response) {
			if (is_array($response) && ($response['id'] ?? null) === $responseId) {
				return $response;
			}
		}
		return null;
	}

	/**
	 * Responses oldest first; with $limit one page of them, starting after the cursor or response $after.
	 *
	 * @return array{responses: list<array>, next: ?string} nothing when $after names no stored response; next is null
	 *                                                      on the last page, and without a limit
	 */
	public function page(int $fileId, array $form, ?int $limit = null, ?string $after = null): array {
		$stored = $this->stored($fileId);
		if ($stored !== null) {
			return $this->responses->page($stored->id, $limit, $after);
		}
		$all = [];
		foreach (is_array($form['responses'] ?? null) ? $form['responses'] : [] as $response) {
			if (is_array($response)) {
				$all[] = $response;
			}
		}
		if ($after !== null) {
			$at = array_search($after, array_column($all, 'id'), true);
			$all = $at === false ? [] : array_slice($all, $at + 1);
		}
		$page = $limit === null ? $all : array_slice($all, 0, $limit);
		$next = $limit !== null && count($page) === $limit ? (string)end($page)['id'] : null;
		return ['responses' => $page, 'next' => $next];
	}

	/**
	 * Every response, oldest first: from the database a page at a time, or as the form file holds them.
	 *
	 * @param array $form as loadForm() or readForm() gave it
	 * @return iterable<array>
	 */
	public function eachResponse(int $fileId, array $form): iterable {
		$stored = $this->stored($fileId);
		if ($stored !== null) {
			return $this->responses->each($stored->id);
		}
		$all = [];
		foreach (is_array($form['responses'] ?? null) ? $form['responses'] : [] as $response) {
			if (is_array($response)) {
				$all[] = $response;
			}
		}
		return $all;
	}

	private function normalised(array $form): array {
		// Ensure default values for optional fields (backwards compatibility)
		if (!array_key_exists('branding', $form)) {
			$form['branding'] = null;
		}
		if (!array_key_exists('pages', $form)) {
			$form['pages'] = null;
		}
		// A null title/description crashes the editor's NcTextField on open
		// (renders via .toString(), no null guard — #134). Normalise to '' so
		// no consumer of a loaded form has to guard against it.
		if (($form['title'] ?? null) === null) {
			$form['title'] = '';
		}
		if (($form['description'] ?? null) === null) {
			$form['description'] = '';
		}

		return $form;
	}

	/**
	 * Update a form
	 */
	/**
	 * @param string|null $expectedRevision the structure the client last saw (FormRevision); null skips the check
	 */
	/**
	 * @param string|null $saveId the editor's id for this save, kept so it can ask later whether the save landed
	 */
	public function update(int $fileId, array $data, ?string $expectedRevision = null, ?string $saveId = null): array {
		$file = $this->fileLocator->getFileById($fileId);
		// Moved into the database first: a save never writes responses or secrets into the file again.
		$this->converter?->convert($file);

		// Apply the edit under the shared lock, against the freshly-read form,
		// so a response submitted concurrently is preserved rather than
		// overwritten with a stale snapshot (#3). Note: 'responses' is NOT in
		// $allowedFields, so the live responses array read here is kept intact.
		// A version for a change to the form; marking it a favourite is none.
		$isChange = array_diff_key($data, ['favorite' => true]) !== [];
		$form = $this->lockManager->mutateFormFileWithLock($file, function (array &$form) use ($data, $expectedRevision, $saveId) {
			// Checked under the lock, against what is stored now.
			if ($expectedRevision !== null && FormRevision::of($form) !== $expectedRevision) {
				throw RequestException::changedElsewhere();
			}
			// Update allowed fields
			// Use array_key_exists instead of isset to allow null values (e.g., branding: null)
			// Responses and _index are written by the server only: _index feeds the capacity counts.
			$allowedFields = ['title', 'description', 'descriptionAlign', 'questions', 'pages', 'permissions', 'branding', 'favorite'];
			$creator = FormCreatorService::getCreatorId($form);
			foreach ($allowedFields as $field) {
				if (array_key_exists($field, $data)) {
					$form[$field] = $data[$field];
				}
			}
			$incoming = $data['settings'] ?? null;
			if (is_array($incoming)) {
				$stored = $form['settings'] ?? [];
				$form['settings'] = array_replace(is_array($stored) ? $stored : [], array_diff_key($incoming, array_flip(self::SERVER_OWNED_SETTINGS)));
			}

			// The creator decides who is notified, so a client save never changes it (#146).
			if (array_key_exists('permissions', $data)) {
				$form = FormCreatorService::withCreator($form, $creator);
			}

			// Handle public_token being cleared - also clear related share settings
			if (isset($form['settings']) && array_key_exists('public_token', $form['settings'])) {
				if (empty($form['settings']['public_token'])) {
					// Public link was deleted, also clear password hash and expiration
					unset($form['settings']['share_password_hash']);
					$form['settings']['share_expires_at'] = null;
				}
			}

			// Hash the share password if provided (store hash, never plaintext)
			// Use array_key_exists because isset() returns false for null values
			if (isset($form['settings']) && array_key_exists('share_password', $form['settings'])) {
				$password = $form['settings']['share_password'];
				if (is_string($password) && $password !== '') {
					// The client never holds the hash, so whatever it sends is a new password.
					$form['settings']['share_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
				} else {
					// Password was cleared (null or empty string)
					unset($form['settings']['share_password_hash']);
				}
				// Never store plaintext password
				unset($form['settings']['share_password']);
			}

			// The whole merged form, so a page edit is checked against the questions it names (V5). A refusal aborts the write.
			$form = $this->schema->check($form);
			if ($saveId !== null) {
				// With the revision it made: adopted only while no change, from any writer, came after it (review of 3b-4e).
				$form[FormRevision::LAST_SAVE] = ['id' => $saveId, 'revision' => FormRevision::of($form)];
			}

			if (is_array($form['settings'] ?? null)) {
				$form['settings'] = $this->secrets->sealSettings($form['settings']);
			}

			return $form;
		}, $isChange);

		// Rename file if title changed
		if (array_key_exists('title', $data)) {
			$newFilename = $this->fileNameFor((string)$data['title']);
			$currentFilename = $file->getName();

			if ($newFilename !== $currentFilename) {
				$parent = $file->getParent();
				// Check if file with new name already exists
				if (!$parent->nodeExists($newFilename)) {
					$this->rename($file, $parent->getPath() . '/' . $newFilename);
				}
				// If file exists, keep original name (avoid overwriting)
			}
		}

		return $form;
	}

	/**
	 * Server-side settings change (API keys, webhooks) under the form lock; secrets are sealed.
	 *
	 * @param callable(array): ?array $mutator gets the stored settings, returns the new ones or null for no change
	 * @return bool whether the form was written
	 */
	public function mutateSettings(int $fileId, callable $mutator): bool {
		$file = $this->fileLocator->getFileById($fileId);
		$this->converter?->convert($file);
		try {
			$this->lockManager->mutateFormFileWithLock($file, function (array &$form) use ($mutator) {
				$settings = $mutator(is_array($form['settings'] ?? null) ? $form['settings'] : []);
				if ($settings === null) {
					throw new FormUnchangedException();
				}
				$form['settings'] = $this->secrets->sealSettings($settings);
				return $form;
			}, true);
		} catch (FormUnchangedException) {
			return false;
		}
		return true;
	}

	/**
	 * The file takes the new title's name once the save went through. The form's lock is taken first, while Nextcloud
	 * holds none yet: taken inside the rename, it would wait on a submission that waits on the rename (review of 3b-3).
	 * A form that stays busy keeps its old name; the next title save renames it.
	 */
	/** Nextcloud's own rules on top: an administrator may forbid more characters and names. */
	private function fileNameFor(string $title): string {
		$name = $this->formFactory->sanitizeFilename($title) . '.' . Application::FILE_EXTENSION;
		try {
			return $this->filenames->sanitizeFilename($name);
		} catch (\InvalidArgumentException) {
			return $name;
		}
	}

	private function rename(File $file, string $to): void {
		$fileId = (int)$file->getId();
		$parent = $file->getParent();
		// What Nextcloud refuses on every save is not tried, its lock not taken: a folder this editor may not create in, as
		// Node::move judges it, or a name it does not allow (review of 3b-3c and 3b-3d).
		$movableRoot = $parent->getInternalPath() === '' && $parent->getMountPoint() instanceof IMovableMount;
		if ((!$parent->isCreatable() && !$movableRoot) || !$this->filenames->isFilenameValid(basename($to))) {
			return;
		}
		try {
			$this->hold->take($fileId, $this->lockManager->waitSeconds());
			// Asked again after the wait: a move onto a file that took the name meanwhile would delete it (review of 3b-3b).
			if ($parent->nodeExists(basename($to))) {
				return;
			}
			$file->move($to);
		} catch (FormLockedException|FormBusyHint $busy) {
			$this->logger->info('FormVox: form ' . $fileId . ' keeps its old file name for now: it stayed busy', ['app' => Application::APP_ID]);
		} catch (NotPermittedException|InvalidPathException $refused) {
			// A name Nextcloud refuses is refused again on every save: no trace each time.
			$this->logger->info('FormVox: form ' . $fileId . ' keeps its old file name: ' . $refused->getMessage(), ['app' => Application::APP_ID]);
		} catch (\Throwable $e) {
			// The save is written: only the name stays as it was.
			$this->logger->warning('FormVox: form ' . $fileId . ' keeps its old file name: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		} finally {
			$this->hold->release($fileId);
		}
	}

	/**
	 * Delete a form
	 */
	public function delete(int $fileId): void {
		$file = $this->fileLocator->getFileById($fileId);
		$file->delete();
	}

	/**
	 * List all forms accessible to the current user
	 *
	 * Only queries filecache rows on storages the user actually has mounted
	 * (matched by mimetype, which is indexed), instead of a name-LIKE scan
	 * over the whole instance. The old scan returned every user's forms and
	 * made getById() initialize foreign mounts — with slow external storage
	 * that stalled for minutes (#110).
	 */
	public function listForms(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$userFolder = $this->fileLocator->getUserFolder();
		$fileIds = $this->findFormFileIds($user->getUID(), $userFolder);

		$forms = [];
		foreach ($fileIds as $fileId) {
			try {
				// Reachable along several paths: the same rule as opening it (most rights).
				$file = FormFileLocator::mostPermissive($userFolder->getById($fileId));
				if ($file === null) {
					continue;
				}

				// Only load minimal data from file for the list view; a form unreadable this long is left out this once.
				$form = $this->reader->read($file, null, 0.5);

				$forms[] = [
					'fileId' => $file->getId(),
					'path' => $file->getPath(),
					'name' => $file->getName(),
					'title' => $form['title'] ?? $this->l->t('Untitled form'),
					'description' => $form['description'] ?? '',
					'responseCount' => $this->stored((int)$file->getId())?->count ?? $form['_index']['response_count'] ?? count($form['responses'] ?? []),
					'createdAt' => $form['created_at'] ?? null,
					'modifiedAt' => $form['modified_at'] ?? null,
					'canDelete' => $this->permissions->allows($file, FormPermission::DELETE),
				];
			} catch (\Exception $e) {
				// Skip files we can't access
				continue;
			}
		}

		return $forms;
	}

	/**
	 * Find fileids of .fvform files on the user's own mounts.
	 *
	 * Filecache is only filtered by (storage, mimetype) — both indexed, and no
	 * join against filecache, so this stays valid on sharded instances.
	 *
	 * @return int[]
	 */
	private function findFormFileIds(string $userId, Folder $userFolder): array {
		// Storages mounted for this user: home, received shares (owner's
		// storage), group folders and external mounts.
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('storage_id')
			->from('mounts')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$storageIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		if ($storageIds === []) {
			return [];
		}

		$mimeTypeId = $this->mimeTypeLoader->getId(Application::MIME_TYPE);

		$fileIds = [];
		foreach (array_chunk($storageIds, self::STORAGE_ID_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid')
				->from('filecache')
				->where($qb->expr()->in('storage', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter($mimeTypeId, IQueryBuilder::PARAM_INT)))
				// Version and trash copies keep the form mimetype but must
				// not surface in the list (they would be discarded by
				// getById() anyway — this just avoids the wasted lookups)
				->andWhere($qb->expr()->notLike('path', $qb->createNamedParameter($this->db->escapeLikeParameter('files_versions/') . '%')))
				->andWhere($qb->expr()->notLike('path', $qb->createNamedParameter($this->db->escapeLikeParameter('files_trashbin/') . '%')));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$fileIds[(int)$row['fileid']] = true;
			}
			$result->closeCursor();
		}

		// Safety net for .fvform files whose mimetype was never registered
		// (uploaded before the app was installed, or copied in from outside
		// Nextcloud). Restricted to the home storage so it can never touch
		// foreign or external mounts, and self-healing: found rows get their
		// mimetype fixed so they move to the fast path above.
		try {
			$homeStorageId = $userFolder->getStorage()->getCache()->getNumericStorageId();

			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid')
				->from('filecache')
				->where($qb->expr()->eq('storage', $qb->createNamedParameter($homeStorageId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->like('name', $qb->createNamedParameter('%.' . Application::FILE_EXTENSION)))
				->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($mimeTypeId, IQueryBuilder::PARAM_INT)))
				// A folder named like a form is no form (7a, 3a-6).
				->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($this->mimeTypeLoader->getId('httpd/unix-directory'), IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$unregistered = [];
			while ($row = $result->fetch()) {
				$unregistered[] = (int)$row['fileid'];
				$fileIds[(int)$row['fileid']] = true;
			}
			$result->closeCursor();

			foreach (array_chunk($unregistered, self::STORAGE_ID_CHUNK) as $chunk) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('filecache')
					->set('mimetype', $qb->createNamedParameter($mimeTypeId, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
		} catch (\Exception $e) {
			// The fallback is best-effort; the fast path already returned
			// every properly registered form.
		}

		return array_keys($fileIds);
	}

	/**
	 * Load a form by file ID (public access - no user context needed)
	 */
	public function loadPublic(int $fileId): array {
		$file = $this->fileLocator->getFileByIdPublic($fileId);
		// Read before the link token can be checked, so not without bound (secu#19).
		if ($file->getSize() > self::MAX_PUBLIC_BYTES) {
			throw new NotFoundException('Form not found');
		}
		$form = $this->reader->read($file, self::MAX_PUBLIC_BYTES);
		$stored = $this->stored($fileId);
		if ($stored !== null) {
			// Counts only: what the page and the rules read, never the responses themselves.
			$form['_index'] = $this->responses->index($stored->id, $form);
		}
		return $form;
	}

	/**
	 * A form file opened elsewhere, read like every form: never failed by a save in progress.
	 */
	public function read(File $file): array {
		return $this->withResponses((int)$file->getId(), $this->reader->read($file));
	}

	private function stored(int $fileId): ?StoredForm {
		return $this->responses === null ? null : $this->forms?->byFileId($fileId);
	}

	/** A form in the database with its counts, as the page and the rules read them. */
	private function withCounts(int $fileId, array $form): array {
		$stored = $this->stored($fileId);
		if ($stored !== null) {
			// What the file says of its responses counts for nothing once the form is in the database.
			$form = $stored->formOnly($form);
			$form['_index'] = $this->responses->index($stored->id, $form);
		}
		return $form;
	}

	/** A form in the database with its responses and index, as a form file holds them. */
	private function withResponses(int $fileId, array $form): array {
		$stored = $this->stored($fileId);
		if ($stored === null) {
			return $form;
		}
		$form['responses'] = $this->responses->all($stored->id);
		$this->index?->rebuildIndex($form);
		return $form;
	}

	/**
	 * Recursively find all .fvform files
	 */
	private function findFormsRecursive(Folder $folder, array &$forms): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && $node->getExtension() === Application::FILE_EXTENSION) {
				try {
					$content = $node->getContent();
					$form = json_decode($content, true);
					if ($form !== null) {
						$forms[] = [
							'fileId' => $node->getId(),
							'path' => $node->getPath(),
							'name' => $node->getName(),
							'title' => $form['title'] ?? $this->l->t('Untitled form'),
							'description' => $form['description'] ?? '',
							'responseCount' => $form['_index']['response_count'] ?? count($form['responses'] ?? []),
							'createdAt' => $form['created_at'] ?? null,
							'modifiedAt' => $form['modified_at'] ?? null,
						];
					}
				} catch (\Exception $e) {
					// Skip invalid files
				}
			} elseif ($node instanceof Folder) {
				$this->findFormsRecursive($node, $forms);
			}
		}
	}

}
