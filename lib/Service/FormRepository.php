<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;

class FormRepository {
	/**
	 * Chunk size for IN() clauses — stays below SQLite's 999 bound-parameter limit.
	 */
	private const STORAGE_ID_CHUNK = 500;

	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private IDBConnection $db;
	private IL10N $l;
	private IMimeTypeLoader $mimeTypeLoader;
	private FormFactory $formFactory;
	private FormFileLocator $fileLocator;
	private FormLockManager $lockManager;

	public function __construct(
		IRootFolder $rootFolder,
		IUserSession $userSession,
		IDBConnection $db,
		IL10N $l,
		IMimeTypeLoader $mimeTypeLoader,
		FormFactory $formFactory,
		FormFileLocator $fileLocator,
		FormLockManager $lockManager,
	) {
		$this->rootFolder = $rootFolder;
		$this->userSession = $userSession;
		$this->db = $db;
		$this->l = $l;
		$this->mimeTypeLoader = $mimeTypeLoader;
		$this->formFactory = $formFactory;
		$this->fileLocator = $fileLocator;
		$this->lockManager = $lockManager;
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
		$userFolder = $this->rootFolder->getUserFolder($userId);

		// Determine target folder
		$targetFolder = $userFolder;
		if (!empty($path)) {
			try {
				$targetFolder = $userFolder->get($path);
			} catch (NotFoundException $e) {
				$targetFolder = $userFolder->newFolder($path);
			}
		}

		// Generate filename
		$filename = $this->formFactory->sanitizeFilename($title) . '.' . Application::FILE_EXTENSION;
		$filename = $this->formFactory->getUniqueFilename($targetFolder, $filename);

		// Create form structure
		$form = $this->formFactory->createFormStructure($title, $userId, $template);

		// Apply prefilled content (e.g. from AI generation) on top of the template
		if (isset($prefilled['description']) && is_string($prefilled['description'])) {
			$form['description'] = $prefilled['description'];
		}
		if (isset($prefilled['questions']) && is_array($prefilled['questions']) && $prefilled['questions'] !== []) {
			$form['questions'] = $prefilled['questions'];
		}

		// Create file
		$file = $targetFolder->newFile($filename);
		$file->putContent(json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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
		$file = $this->getFileById($fileId);
		$content = $file->getContent();
		$form = json_decode($content, true);

		if ($form === null) {
			throw new \RuntimeException('Invalid form file format');
		}

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
	 * Load a form without responses (for public view)
	 */
	public function loadPublicData(int $fileId): array {
		$form = $this->load($fileId);

		// Remove sensitive data
		unset($form['responses']);
		unset($form['_index']);
		unset($form['permissions']);

		return $form;
	}

	/**
	 * Update a form
	 */
	public function update(int $fileId, array $data): array {
		$file = $this->getFileById($fileId);

		// Apply the edit under the shared lock, against the freshly-read form,
		// so a response submitted concurrently is preserved rather than
		// overwritten with a stale snapshot (#3). Note: 'responses' is NOT in
		// $allowedFields, so the live responses array read here is kept intact.
		$form = $this->lockManager->mutateFormFileWithLock($file, function (array &$form) use ($data) {
			// Update allowed fields
			// Use array_key_exists instead of isset to allow null values (e.g., branding: null)
			$allowedFields = ['title', 'description', 'descriptionAlign', 'settings', 'questions', 'pages', 'permissions', '_index', 'branding', 'favorite'];
			foreach ($allowedFields as $field) {
				if (array_key_exists($field, $data)) {
					$form[$field] = $data[$field];
				}
			}

			// Sanitize descriptionAlign to a known value to prevent CSS-class
			// injection through the API (#98).
			if (isset($form['descriptionAlign']) && !in_array($form['descriptionAlign'], ['left', 'center', 'right'], true)) {
				$form['descriptionAlign'] = 'left';
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
				if (!empty($password)) {
					// Only hash if it's not already hashed (new password)
					// Hashed passwords start with $2y$ (bcrypt)
					if (strpos($password, '$2y$') !== 0) {
						$form['settings']['share_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
					}
				} else {
					// Password was cleared (null or empty string)
					unset($form['settings']['share_password_hash']);
				}
				// Never store plaintext password
				unset($form['settings']['share_password']);
			}

			return $form;
		});

		// Rename file if title changed
		if (array_key_exists('title', $data)) {
			$newFilename = $this->formFactory->sanitizeFilename($data['title']) . '.' . Application::FILE_EXTENSION;
			$currentFilename = $file->getName();

			if ($newFilename !== $currentFilename) {
				$parent = $file->getParent();
				// Check if file with new name already exists
				if (!$parent->nodeExists($newFilename)) {
					$file->move($parent->getPath() . '/' . $newFilename);
				}
				// If file exists, keep original name (avoid overwriting)
			}
		}

		return $form;
	}

	/**
	 * Delete a form
	 */
	public function delete(int $fileId): void {
		$file = $this->getFileById($fileId);
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
				// Check if user has access to this file
				$nodes = $userFolder->getById($fileId);
				if (empty($nodes)) {
					continue;
				}

				$file = $nodes[0];
				if (!($file instanceof File)) {
					continue;
				}

				// Only load minimal data from file for the list view
				$content = $file->getContent();
				$form = json_decode($content, true);

				if ($form === null) {
					continue;
				}

				$forms[] = [
					'fileId' => $file->getId(),
					'path' => $file->getPath(),
					'name' => $file->getName(),
					'title' => $form['title'] ?? 'Untitled',
					'description' => $form['description'] ?? '',
					'responseCount' => $form['_index']['response_count'] ?? count($form['responses'] ?? []),
					'createdAt' => $form['created_at'] ?? null,
					'modifiedAt' => $form['modified_at'] ?? null,
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
				->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($mimeTypeId, IQueryBuilder::PARAM_INT)));
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
		$content = $file->getContent();
		$form = json_decode($content, true);

		if ($form === null) {
			throw new \RuntimeException('Invalid form file format');
		}

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
							'title' => $form['title'] ?? 'Untitled',
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
