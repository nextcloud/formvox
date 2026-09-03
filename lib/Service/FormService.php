<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\IL10N;
use OCA\FormVox\AppInfo\Application;

class FormService
{
    /**
     * Chunk size for IN() clauses — stays below SQLite's 999 bound-parameter limit.
     */
    private const STORAGE_ID_CHUNK = 500;

    /**
     * How long (seconds) a response-write lock row may live before it is
     * considered stale and reclaimed. Guards against a worker dying mid-write
     * (timeout/OOM) leaving a lock that would otherwise DoS the form forever.
     */
    private const LOCK_TTL_SECONDS = 60;

    /**
     * How many groups / accounts to consider when looking for an account that
     * can open a form on a shared mount. Each candidate costs a filesystem
     * setup, so the search is bounded; candidates are ordered deterministically
     * and the caller stops at the first that can actually write. A groupfolders
     * ACL can grant write on a path to a single member of an otherwise low-
     * permission group, so the per-group cap is generous rather than tight —
     * otherwise the only writable account could sit just outside it (#90).
     */
    private const ACCESS_CANDIDATE_GROUPS = 20;
    private const ACCESS_CANDIDATE_USERS_PER_GROUP = 50;

    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private IndexService $indexService;
    private IDBConnection $db;
    private IL10N $l;
    private IMimeTypeLoader $mimeTypeLoader;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        IndexService $indexService,
        IDBConnection $db,
        IL10N $l,
        IMimeTypeLoader $mimeTypeLoader
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->indexService = $indexService;
        $this->db = $db;
        $this->l = $l;
        $this->mimeTypeLoader = $mimeTypeLoader;
    }

    /**
     * Get the user's root folder
     */
    private function getUserFolder(): Folder
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('No user logged in');
        }
        return $this->rootFolder->getUserFolder($user->getUID());
    }

    /**
     * Create a new form
     */
    public function create(string $title, string $path = '', ?string $template = null, array $prefilled = []): array
    {
        $user = $this->userSession->getUser();
        return $this->createAsUser($user->getUID(), $title, $path, $template, $prefilled);
    }

    /**
     * Same as create(), but with an explicit userId so it can be invoked from
     * a background context (event listener) where there is no session.
     */
    public function createAsUser(string $userId, string $title, string $path = '', ?string $template = null, array $prefilled = []): array
    {
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
        $filename = $this->sanitizeFilename($title) . '.' . Application::FILE_EXTENSION;
        $filename = $this->getUniqueFilename($targetFolder, $filename);

        // Create form structure
        $form = $this->createFormStructure($title, $userId, $template);

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
    public function load(int $fileId): array
    {
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
    public function loadPublicData(int $fileId): array
    {
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
    public function update(int $fileId, array $data): array
    {
        $file = $this->getFileById($fileId);

        // Apply the edit under the shared lock, against the freshly-read form,
        // so a response submitted concurrently is preserved rather than
        // overwritten with a stale snapshot (#3). Note: 'responses' is NOT in
        // $allowedFields, so the live responses array read here is kept intact.
        $form = $this->mutateFormFileWithLock($file, function (array &$form) use ($data) {
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
            $newFilename = $this->sanitizeFilename($data['title']) . '.' . Application::FILE_EXTENSION;
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
    public function delete(int $fileId): void
    {
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
    public function listForms(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        $userFolder = $this->getUserFolder();
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
    private function findFormFileIds(string $userId, Folder $userFolder): array
    {
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
     * Append a response to a form with proper file locking
     * Uses exclusive lock to prevent race conditions during concurrent submissions
     */
    public function appendResponse(int $fileId, array $response): array
    {
        $file = $this->getFileById($fileId);

        return $this->appendResponseWithLock($file, $response);
    }

    /**
     * Append response with database-based locking to prevent race conditions
     * Uses direct storage access to avoid creating new file versions for each response
     */
    private function appendResponseWithLock(File $file, array $response, ?callable $guard = null): array
    {
        $this->mutateFormFileWithLock($file, function (array &$form) use ($response, $guard) {
            // Re-validate limits (capacity / max_responses / duplicates) against
            // the fresh, locked form state before appending, so concurrent
            // submissions can't each pass a pre-lock snapshot check and blow
            // past the limit (#4 TOCTOU). The guard throws to abort the write.
            if ($guard !== null) {
                $guard($form);
            }
            if (!isset($form['responses'])) {
                $form['responses'] = [];
            }
            $form['responses'][] = $response;
            $this->indexService->updateIndex($form, $response, \count($form['responses']) - 1);
        });
        return $response;
    }

    /**
     * Serialize a read-modify-write of a form file behind the per-file lock.
     *
     * This is the single mutual-exclusion point for every writer of a .fvform
     * file (append, update, delete): they all go through here so concurrent
     * writes to the same form can never clobber each other (last-writer-wins was
     * a real data-loss bug for update()/delete* which used to write unlocked).
     *
     * The $mutator receives the freshly-read form array by reference and mutates
     * it in place; it runs UNDER the lock against current on-disk state, so any
     * capacity / duplicate / limit re-checks it performs are race-free. Throwing
     * from the mutator aborts the write (nothing is persisted) and the exception
     * propagates to the caller.
     *
     * @param callable $mutator function(array &$form): void
     * @return mixed whatever $mutator returns (usually null)
     */
    private function mutateFormFileWithLock(File $file, callable $mutator)
    {
        $lockKey = 'formvox_response_' . $file->getId();
        $maxRetries = 30;
        $retryDelay = 100000; // 100ms in microseconds

        for ($retry = 0; $retry < $maxRetries; $retry++) {
            // Reclaim a stale lock before trying to take it, so a worker that
            // died mid-write (timeout/OOM) can't DoS the form forever (#7).
            $this->reclaimStaleLock($lockKey);

            // Try to acquire lock via database using unique constraint
            $qb = $this->db->getQueryBuilder();
            $qb->insert('preferences')
                ->values([
                    'userid' => $qb->createNamedParameter('__formvox_lock__'),
                    'appid' => $qb->createNamedParameter('formvox'),
                    'configkey' => $qb->createNamedParameter($lockKey),
                    'configvalue' => $qb->createNamedParameter((string)time()),
                ]);

            try {
                $qb->executeStatement();

                try {
                    // Get storage for direct access (bypasses versioning)
                    $storage = $file->getStorage();
                    $internalPath = $file->getInternalPath();

                    // Read content directly from storage
                    $content = $storage->file_get_contents($internalPath);
                    $form = json_decode($content, true);

                    if ($form === null) {
                        throw new \RuntimeException('Invalid form file format');
                    }

                    // Apply the caller's mutation under the lock, against the
                    // freshly-read form. May throw to abort the write.
                    $result = $mutator($form);
                    $form['modified_at'] = date('c');

                    // Write through the node API rather than the raw storage.
                    // Several storage wrappers (the group-folder permission
                    // mask, the groupfolders ACL wrapper, some object stores)
                    // return false from file_put_contents() instead of
                    // throwing, so a refused write looked like success and the
                    // response was lost while the respondent saw "Thank you!"
                    // (#90, #97, #101). putContent() raises a typed exception
                    // instead. Versions created by this write are removed
                    // below, which is what the raw-storage call used to avoid.
                    $payload = json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $expectedBytes = strlen($payload);
                    try {
                        $file->putContent($payload);
                    } catch (\Throwable $e) {
                        throw new \RuntimeException(
                            'Could not write form file (fileId ' . $file->getId() . '): ' . $e->getMessage()
                            . ' [' . $this->describeWriteContext($file, $storage, $internalPath) . ']',
                            0,
                            $e
                        );
                    }

                    // Verify the write actually persisted in full. putContent()
                    // turns a false storage return into an exception, but a
                    // backend that reports a positive-but-truncated write would
                    // slip through — so confirm the stored size matches, keeping
                    // the full #97 short-write guarantee. filesize() reflects the
                    // size putContent() just wrote (object stores update the
                    // cache synchronously to the real byte count before returning;
                    // the encryption wrapper reports the plaintext size, which is
                    // what we compare against). The `!== false` guard tolerates a
                    // backend that can't report a size rather than false-failing.
                    $writtenBytes = $storage->filesize($internalPath);
                    if ($writtenBytes !== false && $writtenBytes < $expectedBytes) {
                        throw new \RuntimeException(
                            'Short write for fileId ' . $file->getId()
                            . ' (stored ' . var_export($writtenBytes, true) . ' of ' . $expectedBytes . ' bytes)'
                            . ' [' . $this->describeWriteContext($file, $storage, $internalPath) . ']'
                        );
                    }

                    // Delete any versions created during this write
                    $this->deleteVersionsForFile($file);

                    return $result;
                } finally {
                    // Always release lock
                    $this->releaseLock($lockKey);
                }
            } catch (\OCP\DB\Exception $e) {
                // Lock acquisition failed (unique violation) → retry. Use
                // Nextcloud's typed reason codes where available; fall back
                // to string matching for older NC versions that don't
                // populate the reason. Covers MySQL ("Duplicate entry"),
                // SQLite ("UNIQUE constraint") and Postgres ("duplicate key").
                if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    usleep($retryDelay * ($retry + 1));
                    continue;
                }
                $msg = $e->getMessage();
                if (strpos($msg, 'Duplicate') !== false ||
                    strpos($msg, 'UNIQUE constraint') !== false ||
                    strpos($msg, 'duplicate key') !== false ||
                    strpos($msg, '23505') !== false) {
                    usleep($retryDelay * ($retry + 1));
                    continue;
                }
                throw $e;
            }
        }

        throw new \RuntimeException('Could not acquire lock after ' . $maxRetries . ' retries');
    }

    /**
     * Delete the lock row for $lockKey if its stored timestamp is older than
     * LOCK_TTL_SECONDS. The timestamp is written at acquisition time; without
     * this reclaim a crashed worker's row would live forever and block every
     * future submission for that form (#7).
     */
    private function reclaimStaleLock(string $lockKey): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('configvalue')
                ->from('preferences')
                ->where($qb->expr()->eq('userid', $qb->createNamedParameter('__formvox_lock__')))
                ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('formvox')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($lockKey)));
            $row = $qb->executeQuery()->fetch();
            if ($row === false) {
                return; // no lock held
            }
            $acquiredAt = (int)($row['configvalue'] ?? 0);
            if ($acquiredAt > 0 && (time() - $acquiredAt) < self::LOCK_TTL_SECONDS) {
                return; // lock is fresh, leave it
            }
            // Stale (or unparseable timestamp) → reclaim.
            $this->releaseLock($lockKey);
        } catch (\Exception $e) {
            // Best-effort; if the reclaim probe fails we just fall through to
            // the normal insert-and-retry path.
        }
    }

    /**
     * Release a database lock
     */
    private function releaseLock(string $lockKey): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('preferences')
                ->where($qb->expr()->eq('userid', $qb->createNamedParameter('__formvox_lock__')))
                ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('formvox')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($lockKey)));
            $qb->executeStatement();
        } catch (\Exception $e) {
            // Log but don't throw - lock will expire anyway
        }
    }

    /**
     * Describe the storage context of a failed response write (#90, #101).
     *
     * Both the group-folder PermissionsMask (built from the group's
     * permissions column) and the innermost ACLStorageWrapper return false
     * from file_put_contents() instead of throwing, so a denied write is
     * indistinguishable from a full disk by the return value alone. This
     * records which layer refused: an unwritable node points at the mask or
     * the ACL, a writable one at the backend itself.
     *
     * Diagnostics only — must never throw, or it would mask the real error.
     */
    private function describeWriteContext(File $file, $storage, string $internalPath): string
    {
        $parts = [];

        try {
            $parts[] = 'storage=' . get_class($storage);
        } catch (\Throwable $e) {
            $parts[] = 'storage=?';
        }

        try {
            $parts[] = 'updatable=' . var_export($file->isUpdateable(), true);
        } catch (\Throwable $e) {
            $parts[] = 'updatable=?';
        }

        try {
            $parts[] = 'storagePerms=' . var_export($storage->getPermissions($internalPath), true);
        } catch (\Throwable $e) {
            $parts[] = 'storagePerms=?';
        }

        try {
            $parts[] = 'freeSpace=' . var_export($storage->free_space($internalPath), true);
        } catch (\Throwable $e) {
            $parts[] = 'freeSpace=?';
        }

        try {
            $parts[] = 'user=' . ($this->userSession->getUser()?->getUID() ?? 'anonymous');
        } catch (\Throwable $e) {
            $parts[] = 'user=?';
        }

        return implode(' ', $parts);
    }

    /**
     * Delete all versions for a file to prevent version history from responses
     * Uses IVersionManager to properly delete versions including physical files
     */
    private function deleteVersionsForFile(File $file): void
    {
        try {
            $versionsBackend = \OCP\Server::get(\OCA\Files_Versions\Versions\IVersionManager::class);
            $user = $this->userSession->getUser();

            if ($user === null) {
                // Try to get user from file owner for public submissions
                $owner = $file->getOwner();
                if ($owner === null) {
                    return;
                }
                // Get IUser object from owner
                $userManager = \OCP\Server::get(\OCP\IUserManager::class);
                $user = $userManager->get($owner->getUID());
                if ($user === null) {
                    return;
                }
            }

            // Get all versions for this file
            $versions = $versionsBackend->getVersionsForFile($user, $file);

            // Delete each version
            foreach ($versions as $version) {
                $versionsBackend->deleteVersion($version);
            }
        } catch (\Exception $e) {
            // Versions app might not be available or other error, ignore
        }
    }

    /**
     * Delete a response from a form
     * Uses direct storage access to avoid creating new file versions
     */
    public function deleteResponse(int $fileId, string $responseId): void
    {
        $file = $this->getFileById($fileId);

        // Serialize with the shared lock so a concurrent public submit can't be
        // lost, and so the storage write is return-value-checked (#3, #8).
        $fileUploadResponseIds = $this->mutateFormFileWithLock($file, function (array &$form) use ($responseId) {
            $collected = [];
            $found = false;
            foreach ($form['responses'] ?? [] as $index => $response) {
                if (($response['id'] ?? null) === $responseId) {
                    // Collect file upload responseIds from answers before deleting
                    if (isset($response['answers'])) {
                        foreach ($response['answers'] as $answer) {
                            $collected = array_merge($collected, $this->extractFileResponseIds($answer));
                        }
                    }
                    array_splice($form['responses'], $index, 1);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new NotFoundException('Response not found');
            }
            // Rebuild index after deletion
            $this->indexService->rebuildIndex($form);
            return $collected;
        });

        // Delete uploaded files for this response
        foreach (array_unique($fileUploadResponseIds) as $uploadResponseId) {
            $this->deleteResponseUploads($fileId, $uploadResponseId);
        }
    }

    /**
     * Delete all responses from a form
     * Uses direct storage access to avoid creating new file versions
     */
    public function deleteAllResponses(int $fileId): void
    {
        $file = $this->getFileById($fileId);

        // Serialize with the shared lock + checked write (#3, #8).
        $this->mutateFormFileWithLock($file, function (array &$form) {
            $form['responses'] = [];
            // Rebuild index (will be empty)
            $this->indexService->rebuildIndex($form);
        });

        // Delete all uploaded files for this form
        $this->deleteAllUploads($fileId);
    }

    /**
     * Get file by ID with permission check
     */
    public function getFileById(int $fileId): File
    {
        $userFolder = $this->getUserFolder();
        $nodes = $userFolder->getById($fileId);

        if (empty($nodes)) {
            throw new NotFoundException('Form not found');
        }

        $file = $nodes[0];
        if (!($file instanceof File)) {
            throw new \RuntimeException('Not a file');
        }

        return $file;
    }

    /**
     * Get file by ID without user context (for public/system access)
     * Uses database lookup to find the owner and then accesses via their folder
     * Supports personal folders, group folders, and external storage
     *
     * A public submission has no session of its own, so the file has to be
     * opened through *some* account that can see it. For shared mounts (group/
     * team folders, external storage) several accounts qualify, and they do not
     * all have the same rights: a read-only member, or one blocked by a
     * groupfolders ACL rule, yields a File whose writes are silently refused —
     * both the group-folder PermissionsMask and the ACLStorageWrapper return
     * false from file_put_contents() rather than throwing. That lost responses
     * while the respondent saw "Thank you!" (#90, #101).
     *
     * Callers that intend to write must therefore pass $requireWrite, which
     * skips candidates that cannot update the file instead of picking the first
     * one that can merely see it. Read-only callers leave it false so that
     * viewing a form keeps working even where nobody can write.
     */
    public function getFileByIdPublic(int $fileId, bool $requireWrite = false): File
    {
        // Look up the file in the database to find the storage
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.id', 's.numeric_id')
            ->from('filecache', 'fc')
            ->innerJoin('fc', 'storages', 's', 'fc.storage = s.numeric_id')
            ->where($qb->expr()->eq('fc.fileid', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new NotFoundException('Form not found');
        }

        $storageId = $row['id'];

        // Case 1: Personal folder (home::username)
        // Exactly one account owns the mount, so there is nothing to choose.
        if (str_starts_with($storageId, 'home::')) {
            $userId = substr($storageId, 6);
            $file = $this->resolveFileAsUser($userId, $fileId, $requireWrite);
            if ($file !== null) {
                return $file;
            }

            throw $this->cannotAccessException($fileId, $requireWrite);
        }

        // Case 2: Group folder / Team folder
        //   - Local:         local::.../__groupfolders/{id}/
        //   - Object store:  object::groupfolder:{id}.{objectstore_id}
        if (
            preg_match('#__groupfolders/(\d+)/#', $storageId, $matches)
            || preg_match('#^object::groupfolder:(\d+)#', $storageId, $matches)
        ) {
            $groupFolderId = (int)$matches[1];

            foreach ($this->findUsersWithGroupFolderAccess($groupFolderId) as $userId) {
                $file = $this->resolveFileAsUser($userId, $fileId, $requireWrite);
                if ($file !== null) {
                    return $file;
                }
            }

            throw $this->cannotAccessException($fileId, $requireWrite);
        }

        // Case 3: External storage (SMB, SFTP, S3, local mounts, etc.)
        foreach ($this->findUsersWithStorage((int)$row['numeric_id']) as $userId) {
            $file = $this->resolveFileAsUser($userId, $fileId, $requireWrite);
            if ($file !== null) {
                return $file;
            }
        }

        throw $this->cannotAccessException($fileId, $requireWrite);
    }

    /**
     * Open a file through one account's view of the filesystem.
     *
     * Returns null when that account cannot see the file at all, or — when
     * $requireWrite is set — when it can see but not update it. Checking
     * isUpdateable() here covers both refusing layers at once: the group
     * permission mask and the per-path ACL.
     */
    private function resolveFileAsUser(string $userId, int $fileId, bool $requireWrite): ?File
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable $e) {
            // Account disabled or otherwise unusable — try the next candidate.
            return null;
        }

        $nodes = $userFolder->getById($fileId);
        if (empty($nodes) || !($nodes[0] instanceof File)) {
            return null;
        }

        $file = $nodes[0];
        if ($requireWrite && !$file->isUpdateable()) {
            return null;
        }

        return $file;
    }

    /**
     * Build the failure for a form nobody could open.
     *
     * Distinguishes "no such form" from "found it, but no account may write to
     * it", so a misconfigured share surfaces as a real error instead of a
     * silently dropped response.
     */
    private function cannotAccessException(int $fileId, bool $requireWrite): \Exception
    {
        if ($requireWrite) {
            return new \RuntimeException(
                'No account with write access to the form file (fileId ' . $fileId . ') could be found. '
                . 'If the form is in a team or group folder, check that the folder grants write permission '
                . 'and that no advanced-permission rule blocks writing to it.'
            );
        }

        return new NotFoundException('Form not found');
    }

    /**
     * Candidate accounts for opening a file in a group/team folder.
     *
     * Groups whose membership grants write are listed first, so a writable
     * account is normally found on the first try. The permission column is
     * only a hint though — a groupfolders ACL rule can still deny writing to
     * this particular path — so the caller confirms with isUpdateable() and
     * falls through to the next candidate. Read-only groups stay in the list
     * because read-only callers must keep working (#90).
     *
     * @return list<string> user ids, best candidates first
     */
    private function findUsersWithGroupFolderAccess(int $groupFolderId): array
    {
        // Get groups that have access to this group folder, most-permissive
        // first. Members of several groups get their permissions OR'd together
        // by groupfolders, so a write-capable group is the better bet.
        $qb = $this->db->getQueryBuilder();
        $qb->select('group_id', 'permissions')
            ->from('group_folders_groups')
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupFolderId, \PDO::PARAM_INT)))
            ->orderBy('permissions', 'DESC')
            ->setMaxResults(self::ACCESS_CANDIDATE_GROUPS);

        $result = $qb->executeQuery();
        $groups = [];
        while ($row = $result->fetch()) {
            $groups[] = $row['group_id'];
        }
        $result->closeCursor();

        $userIds = [];
        foreach ($groups as $groupId) {
            $qb = $this->db->getQueryBuilder();
            // ORDER BY uid so the members we consider are deterministic across
            // requests — without it the DB returns an arbitrary subset, and if
            // the only ACL-writable member falls outside that subset the write
            // is lost even though a valid writer exists (#90).
            $qb->select('uid')
                ->from('group_user')
                ->where($qb->expr()->eq('gid', $qb->createNamedParameter($groupId)))
                ->orderBy('uid', 'ASC')
                ->setMaxResults(self::ACCESS_CANDIDATE_USERS_PER_GROUP);

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $userIds[] = $row['uid'];
            }
            $result->closeCursor();
        }

        return array_values(array_unique($userIds));
    }

    /**
     * Candidate accounts for opening a file on an external storage mount.
     *
     * As with group folders, several accounts may mount the same storage with
     * differing rights, so return a list (deterministically ordered) and let
     * the caller confirm writability. (#90)
     *
     * @return list<string> user ids
     */
    private function findUsersWithStorage(int $storageNumericId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from('mounts')
            ->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($storageNumericId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->neq('user_id', $qb->createNamedParameter('')))
            ->orderBy('user_id', 'ASC')
            ->setMaxResults(self::ACCESS_CANDIDATE_USERS_PER_GROUP);

        $result = $qb->executeQuery();
        $userIds = [];
        while ($row = $result->fetch()) {
            $userIds[] = $row['user_id'];
        }
        $result->closeCursor();

        return array_values(array_unique($userIds));
    }

    /**
     * Load a form by file ID (public access - no user context needed)
     */
    public function loadPublic(int $fileId): array
    {
        $file = $this->getFileByIdPublic($fileId);
        $content = $file->getContent();
        $form = json_decode($content, true);

        if ($form === null) {
            throw new \RuntimeException('Invalid form file format');
        }

        return $form;
    }

    /**
     * Append a response to a form (public access - no user context needed)
     * Uses same locking mechanism as appendResponse to prevent race conditions
     */
    public function appendResponsePublic(int $fileId, array $response, ?callable $guard = null): array
    {
        $file = $this->getFileByIdPublic($fileId, true);

        return $this->appendResponseWithLock($file, $response, $guard);
    }

    /**
     * Persist a form's responses from an external-API mutation (create/update/
     * delete a response). Used by ExternalApiController, which reads the form,
     * mutates $form['responses'] in memory, and hands the result here to save.
     *
     * The External API replaces the whole responses array, so we apply exactly
     * that under the shared lock (with a checked write and index rebuild), which
     * both makes the write actually happen — it previously called a nonexistent
     * savePublic() and silently lost every write — and serializes it against
     * concurrent public submissions.
     */
    public function savePublic(int $fileId, array $form): void
    {
        $file = $this->getFileByIdPublic($fileId, true);
        $newResponses = $form['responses'] ?? [];

        $this->mutateFormFileWithLock($file, function (array &$current) use ($newResponses) {
            $current['responses'] = $newResponses;
            $this->indexService->rebuildIndex($current);
        });
    }

    /**
     * Create form structure
     */
    private function createFormStructure(string $title, string $userId, ?string $template = null): array
    {
        $form = [
            'version' => '1.0',
            'id' => $this->generateUuid(),
            'title' => $title,
            'description' => '',
            'created_at' => date('c'),
            'modified_at' => date('c'),
            'settings' => [
                'anonymous' => true,
                'allow_multiple' => false,
                'expires_at' => null,
                'require_login' => false,
                'allowed_users' => [],
                'allowed_groups' => [],
            ],
            'permissions' => [
                'owner' => $userId,
                'roles' => [],
            ],
            'questions' => [],
            'pages' => null,
            'branding' => null, // null = use admin defaults, object = custom branding
            '_index' => [
                '_checksum' => '',
                'response_count' => 0,
                'last_response_at' => null,
                'fingerprints' => [],
                'user_ids' => [],
                'by_date' => [],
                'answer_counts' => [],
            ],
            'responses' => [],
        ];

        // Apply template if specified
        if ($template !== null) {
            $form = $this->applyTemplate($form, $template);
        }

        return $form;
    }

    /**
     * Apply a template to a form
     */
    private function applyTemplate(array $form, string $template): array
    {
        $templates = [
            'survey' => [
                'questions' => [
                    [
                        'id' => 'q1',
                        'type' => 'choice',
                        'question' => $this->l->t('How would you rate your overall experience?'),
                        'required' => true,
                        'options' => [
                            ['id' => 'opt1', 'label' => $this->l->t('Excellent'), 'value' => '5'],
                            ['id' => 'opt2', 'label' => $this->l->t('Good'), 'value' => '4'],
                            ['id' => 'opt3', 'label' => $this->l->t('Average'), 'value' => '3'],
                            ['id' => 'opt4', 'label' => $this->l->t('Poor'), 'value' => '2'],
                            ['id' => 'opt5', 'label' => $this->l->t('Very poor'), 'value' => '1'],
                        ],
                    ],
                    [
                        'id' => 'q2',
                        'type' => 'textarea',
                        'question' => $this->l->t('Do you have any additional comments?'),
                        'required' => false,
                    ],
                ],
            ],
            'poll' => [
                'questions' => [
                    [
                        'id' => 'q1',
                        'type' => 'choice',
                        'question' => $this->l->t('What is your preferred option?'),
                        'required' => true,
                        'options' => [
                            ['id' => 'opt1', 'label' => $this->l->t('Option A'), 'value' => 'a'],
                            ['id' => 'opt2', 'label' => $this->l->t('Option B'), 'value' => 'b'],
                            ['id' => 'opt3', 'label' => $this->l->t('Option C'), 'value' => 'c'],
                        ],
                    ],
                ],
            ],
            'registration' => [
                'questions' => [
                    [
                        'id' => 'q1',
                        'type' => 'text',
                        'question' => $this->l->t('Full name'),
                        'required' => true,
                    ],
                    [
                        'id' => 'q2',
                        'type' => 'text',
                        'question' => $this->l->t('Email address'),
                        'required' => true,
                        'validation' => ['type' => 'email'],
                    ],
                    [
                        'id' => 'q3',
                        'type' => 'text',
                        'question' => $this->l->t('Phone number'),
                        'required' => false,
                    ],
                ],
                'settings' => [
                    'anonymous' => false,
                    'require_login' => false,
                ],
            ],
            'demo' => [
                'description' => $this->l->t('This demo form showcases all FormVox features including different question types, conditional logic (branching), quiz mode with scoring, and various input validations.'),
                'questions' => [
                    // Section 1: Basic Info
                    [
                        'id' => 'demo_name',
                        'type' => 'text',
                        'question' => $this->l->t('What is your name?'),
                        'description' => $this->l->t('This is a simple text field'),
                        'required' => true,
                        'placeholder' => $this->l->t('Enter your full name'),
                    ],
                    [
                        'id' => 'demo_email',
                        'type' => 'text',
                        'question' => $this->l->t('What is your email address?'),
                        'description' => $this->l->t('Text field with email validation'),
                        'required' => true,
                        'validation' => ['type' => 'email'],
                    ],
                    [
                        'id' => 'demo_bio',
                        'type' => 'textarea',
                        'question' => $this->l->t('Tell us about yourself'),
                        'description' => $this->l->t('Multi-line text area for longer responses'),
                        'required' => false,
                        'placeholder' => $this->l->t('Write a short bio …'),
                    ],
                    // Section 2: Choice Questions
                    [
                        'id' => 'demo_experience',
                        'type' => 'choice',
                        'question' => $this->l->t('How much experience do you have with forms?'),
                        'description' => $this->l->t('Single choice (radio buttons)'),
                        'required' => true,
                        'options' => [
                            ['id' => 'exp1', 'label' => $this->l->t('Beginner - Just getting started'), 'value' => 'beginner'],
                            ['id' => 'exp2', 'label' => $this->l->t('Intermediate - Some experience'), 'value' => 'intermediate'],
                            ['id' => 'exp3', 'label' => $this->l->t('Expert - I create forms daily'), 'value' => 'expert'],
                        ],
                    ],
                    // Conditional question - only shown for experts
                    [
                        'id' => 'demo_expert_tools',
                        'type' => 'multiple',
                        'question' => $this->l->t('Which form tools have you used before?'),
                        'description' => $this->l->t('This question only appears if you selected "Expert" above (conditional logic)'),
                        'required' => false,
                        'options' => [
                            ['id' => 'tool1', 'label' => 'Google Forms', 'value' => 'google'],
                            ['id' => 'tool2', 'label' => 'Microsoft Forms', 'value' => 'microsoft'],
                            ['id' => 'tool3', 'label' => 'Typeform', 'value' => 'typeform'],
                            ['id' => 'tool4', 'label' => 'SurveyMonkey', 'value' => 'surveymonkey'],
                            ['id' => 'tool5', 'label' => 'Nextcloud Forms', 'value' => 'nextcloud'],
                        ],
                        'showIf' => [
                            'questionId' => 'demo_experience',
                            'operator' => 'equals',
                            'value' => 'expert',
                        ],
                    ],
                    [
                        'id' => 'demo_features',
                        'type' => 'multiple',
                        'question' => $this->l->t('Which features are most important to you?'),
                        'description' => $this->l->t('Multiple choice (checkboxes) - select all that apply'),
                        'required' => true,
                        'options' => [
                            ['id' => 'feat1', 'label' => $this->l->t('Easy to use interface'), 'value' => 'easy'],
                            ['id' => 'feat2', 'label' => $this->l->t('Conditional logic / Branching'), 'value' => 'branching'],
                            ['id' => 'feat3', 'label' => $this->l->t('File-based storage'), 'value' => 'files'],
                            ['id' => 'feat4', 'label' => $this->l->t('Privacy / Self-hosted'), 'value' => 'privacy'],
                            ['id' => 'feat5', 'label' => $this->l->t('Export options'), 'value' => 'export'],
                        ],
                    ],
                    [
                        'id' => 'demo_priority',
                        'type' => 'dropdown',
                        'question' => $this->l->t('What is your top priority?'),
                        'description' => $this->l->t('Dropdown select for longer option lists'),
                        'required' => true,
                        'options' => [
                            ['id' => 'pri1', 'label' => $this->l->t('Speed'), 'value' => 'speed'],
                            ['id' => 'pri2', 'label' => $this->l->t('Security'), 'value' => 'security'],
                            ['id' => 'pri3', 'label' => $this->l->t('Flexibility'), 'value' => 'flexibility'],
                            ['id' => 'pri4', 'label' => $this->l->t('Integration'), 'value' => 'integration'],
                            ['id' => 'pri5', 'label' => $this->l->t('Cost'), 'value' => 'cost'],
                        ],
                    ],
                    // Section 3: Date & Time
                    [
                        'id' => 'demo_date',
                        'type' => 'date',
                        'question' => $this->l->t('When did you start using Nextcloud?'),
                        'description' => $this->l->t('Date picker'),
                        'required' => false,
                    ],
                    [
                        'id' => 'demo_datetime',
                        'type' => 'datetime',
                        'question' => $this->l->t('When would you like a demo call?'),
                        'description' => $this->l->t('Date and time picker'),
                        'required' => false,
                    ],
                    [
                        'id' => 'demo_time',
                        'type' => 'time',
                        'question' => $this->l->t('What time works best for you?'),
                        'description' => $this->l->t('Time picker only'),
                        'required' => false,
                    ],
                    // Section 4: Numbers & Ratings
                    [
                        'id' => 'demo_number',
                        'type' => 'number',
                        'question' => $this->l->t('How many forms do you create per month?'),
                        'description' => $this->l->t('Numeric input'),
                        'required' => false,
                        'min' => 0,
                        'max' => 1000,
                    ],
                    [
                        'id' => 'demo_scale',
                        'type' => 'scale',
                        'question' => $this->l->t('How likely are you to recommend FormVox?'),
                        'description' => $this->l->t('Linear scale (1-10)'),
                        'required' => true,
                        'min' => 1,
                        'max' => 10,
                        'minLabel' => $this->l->t('Not likely'),
                        'maxLabel' => $this->l->t('Very likely'),
                    ],
                    [
                        'id' => 'demo_rating',
                        'type' => 'rating',
                        'question' => $this->l->t('Rate this demo form'),
                        'description' => $this->l->t('Star rating (1-5 stars)'),
                        'required' => true,
                        'max' => 5,
                    ],
                    // Section 5: Quiz Mode
                    [
                        'id' => 'demo_quiz1',
                        'type' => 'choice',
                        'question' => $this->l->t('Quiz: What file extension does FormVox use?'),
                        'description' => $this->l->t('This is a quiz question with scoring - correct answer: .fvform'),
                        'required' => true,
                        'options' => [
                            ['id' => 'quiz1a', 'label' => '.docx', 'value' => 'docx', 'score' => 0],
                            ['id' => 'quiz1b', 'label' => '.fvform', 'value' => 'fvform', 'score' => 10],
                            ['id' => 'quiz1c', 'label' => '.json', 'value' => 'json', 'score' => 5],
                            ['id' => 'quiz1d', 'label' => '.xml', 'value' => 'xml', 'score' => 0],
                        ],
                    ],
                    [
                        'id' => 'demo_quiz2',
                        'type' => 'choice',
                        'question' => $this->l->t('Quiz: Where does FormVox store form data?'),
                        'description' => $this->l->t('Another quiz question - correct answer: In Nextcloud files'),
                        'required' => true,
                        'options' => [
                            ['id' => 'quiz2a', 'label' => $this->l->t('In a separate database'), 'value' => 'database', 'score' => 0],
                            ['id' => 'quiz2b', 'label' => $this->l->t('In the cloud'), 'value' => 'cloud', 'score' => 0],
                            ['id' => 'quiz2c', 'label' => $this->l->t('In Nextcloud files'), 'value' => 'files', 'score' => 10],
                            ['id' => 'quiz2d', 'label' => $this->l->t('On external servers'), 'value' => 'external', 'score' => 0],
                        ],
                    ],
                    // Section 6: Matrix Question
                    [
                        'id' => 'demo_matrix',
                        'type' => 'matrix',
                        'question' => $this->l->t('Rate these aspects of FormVox'),
                        'description' => $this->l->t('Matrix/grid question with multiple rows and columns'),
                        'required' => false,
                        'rows' => [
                            ['id' => 'row1', 'label' => $this->l->t('Ease of use')],
                            ['id' => 'row2', 'label' => $this->l->t('Feature set')],
                            ['id' => 'row3', 'label' => $this->l->t('Design')],
                            ['id' => 'row4', 'label' => $this->l->t('Performance')],
                        ],
                        'columns' => [
                            ['id' => 'col1', 'label' => $this->l->t('Poor'), 'value' => '1'],
                            ['id' => 'col2', 'label' => $this->l->t('Fair'), 'value' => '2'],
                            ['id' => 'col3', 'label' => $this->l->t('Good'), 'value' => '3'],
                            ['id' => 'col4', 'label' => $this->l->t('Excellent'), 'value' => '4'],
                        ],
                    ],
                    // Section 7: Table (Dynamic Rows)
                    [
                        'id' => 'demo_table',
                        'type' => 'table',
                        'question' => $this->l->t('Expense declaration'),
                        'description' => $this->l->t('Add your expenses below. Click "+ Add row" to add more items.'),
                        'required' => false,
                        'columns' => [
                            ['id' => 'col_desc', 'label' => $this->l->t('Description'), 'inputType' => 'text', 'options' => []],
                            ['id' => 'col_amount', 'label' => $this->l->t('Amount'), 'inputType' => 'number', 'options' => []],
                            ['id' => 'col_date', 'label' => $this->l->t('Date'), 'inputType' => 'date', 'options' => []],
                            ['id' => 'col_cat', 'label' => $this->l->t('Category'), 'inputType' => 'dropdown', 'options' => ['Travel', 'Food', 'Office', 'Other']],
                        ],
                        'minRows' => 1,
                        'maxRows' => 20,
                    ],

                    // Section 8: Conditional Branching Demo
                    [
                        'id' => 'demo_want_contact',
                        'type' => 'choice',
                        'question' => $this->l->t('Would you like us to contact you?'),
                        'description' => $this->l->t('This controls whether the next question is shown'),
                        'required' => true,
                        'options' => [
                            ['id' => 'contact_yes', 'label' => $this->l->t('Yes, please contact me'), 'value' => 'yes'],
                            ['id' => 'contact_no', 'label' => $this->l->t('No, thanks'), 'value' => 'no'],
                        ],
                    ],
                    [
                        'id' => 'demo_contact_method',
                        'type' => 'choice',
                        'question' => $this->l->t('How would you prefer to be contacted?'),
                        'description' => $this->l->t('This question only appears if you selected "Yes" above'),
                        'required' => false,
                        'options' => [
                            ['id' => 'method1', 'label' => $this->l->t('Email'), 'value' => 'email'],
                            ['id' => 'method2', 'label' => $this->l->t('Phone'), 'value' => 'phone'],
                            ['id' => 'method3', 'label' => $this->l->t('Video call'), 'value' => 'video'],
                        ],
                        'showIf' => [
                            'questionId' => 'demo_want_contact',
                            'operator' => 'equals',
                            'value' => 'yes',
                        ],
                    ],
                    // Final feedback
                    [
                        'id' => 'demo_feedback',
                        'type' => 'textarea',
                        'question' => $this->l->t('Any final thoughts or feedback?'),
                        'description' => $this->l->t('Thank you for trying this demo form!'),
                        'required' => false,
                        'placeholder' => $this->l->t('Share your thoughts …'),
                    ],
                ],
                'settings' => [
                    'anonymous' => true,
                    'allow_multiple' => true,
                ],
            ],
        ];

        if (isset($templates[$template])) {
            $templateData = $templates[$template];
            if (isset($templateData['description'])) {
                $form['description'] = $templateData['description'];
            }
            if (isset($templateData['questions'])) {
                $form['questions'] = $templateData['questions'];
            }
            if (isset($templateData['settings'])) {
                $form['settings'] = array_merge($form['settings'], $templateData['settings']);
            }
            if (isset($templateData['responses'])) {
                $form['responses'] = $templateData['responses'];
            }
        }

        return $form;
    }

    /**
     * Recursively find all .fvform files
     */
    private function findFormsRecursive(Folder $folder, array &$forms): void
    {
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

    /**
     * Generate a UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Sanitize filename
     */
    private function sanitizeFilename(string $name): string
    {
        // Remove invalid characters
        $name = preg_replace('/[\/\\\\:*?"<>|]/', '', $name);
        // Replace spaces with dashes
        $name = preg_replace('/\s+/', '-', $name);
        // Lowercase
        $name = strtolower($name);
        // Limit length
        $name = mb_substr($name, 0, 50, 'UTF-8');
        // Default if empty
        if (empty($name)) {
            $name = 'form';
        }
        return $name;
    }

    /**
     * Get unique filename in folder
     */
    private function getUniqueFilename(Folder $folder, string $filename): string
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $counter = 1;

        while ($folder->nodeExists($filename)) {
            $filename = $baseName . '-' . $counter . '.' . $extension;
            $counter++;
        }

        return $filename;
    }

    /**
     * Get the uploads folder for a form
     * Creates it if it doesn't exist
     * Uses file ID in the folder name so renaming the form doesn't break uploads
     */
    public function getUploadsFolder(int $fileId, bool $requireWrite = false): Folder
    {
        // Only writing callers need a write-capable account; a read-only caller
        // (e.g. downloading an upload) must keep working on shares where the
        // reachable account can read but not write (#90).
        $formFile = $this->getFileByIdPublic($fileId, $requireWrite);
        $formFolder = $formFile->getParent();

        // Hidden folder with file ID - never changes even if form is renamed
        $uploadsFolderName = ".formvox-uploads-{$fileId}";

        try {
            $uploadsFolder = $formFolder->get($uploadsFolderName);
            if (!($uploadsFolder instanceof Folder)) {
                throw new \RuntimeException('Uploads path is not a folder');
            }
            return $uploadsFolder;
        } catch (NotFoundException $e) {
            // Read-only callers must not try to create the folder — signal
            // "nothing here" so they can 404 cleanly instead of erroring.
            if (!$requireWrite) {
                throw $e;
            }
            return $formFolder->newFolder($uploadsFolderName);
        }
    }

    /**
     * Hidden per-form folder for branding images (logo/header pictures).
     * Sibling of the .fvform file, so it travels along on rename/move and
     * is reachable from public form rendering as well as the editor.
     */
    public function getBrandingFolder(int $fileId, bool $requireWrite = false): Folder
    {
        // Read-only by default so public form rendering can still show branding
        // images on shares where the reachable account cannot write (#90).
        $formFile = $this->getFileByIdPublic($fileId, $requireWrite);
        $formFolder = $formFile->getParent();
        $brandingFolderName = ".formvox-branding-{$fileId}";

        try {
            $brandingFolder = $formFolder->get($brandingFolderName);
            if (!($brandingFolder instanceof Folder)) {
                throw new \RuntimeException('Branding path is not a folder');
            }
            return $brandingFolder;
        } catch (NotFoundException $e) {
            if (!$requireWrite) {
                throw $e;
            }
            return $formFolder->newFolder($brandingFolderName);
        }
    }

    /**
     * Store an uploaded file for a form response
     *
     * @param int $fileId The form file ID
     * @param string $responseId The response ID (temporary or final)
     * @param array $uploadedFile The uploaded file from $_FILES
     * @return array File metadata
     */
    public function storeUpload(int $fileId, string $responseId, array $uploadedFile): array
    {
        $uploadsFolder = $this->getUploadsFolder($fileId, true);

        // Create response subfolder
        try {
            $responseFolder = $uploadsFolder->get($responseId);
            if (!($responseFolder instanceof Folder)) {
                throw new \RuntimeException('Response path is not a folder');
            }
        } catch (NotFoundException $e) {
            $responseFolder = $uploadsFolder->newFolder($responseId);
        }

        // Sanitize filename but keep original for display
        $originalName = $uploadedFile['name'];
        $safeName = $this->sanitizeUploadFilename($originalName);

        // Ensure unique filename in folder
        $safeName = $this->getUniqueFilename($responseFolder, $safeName);

        // Create the file
        $newFile = $responseFolder->newFile($safeName);
        $newFile->putContent(file_get_contents($uploadedFile['tmp_name']));

        return [
            'fileId' => $newFile->getId(),
            'filename' => $safeName,
            'originalName' => $originalName,
            'size' => $uploadedFile['size'],
            'mimeType' => $uploadedFile['type'],
            'responseId' => $responseId,
        ];
    }

    /**
     * Get an uploaded file
     *
     * @param int $formFileId The form file ID
     * @param string $responseId The response ID
     * @param string $filename The filename
     * @return File The file
     */
    public function getUpload(int $formFileId, string $responseId, string $filename): File
    {
        $uploadsFolder = $this->getUploadsFolder($formFileId);

        try {
            $responseFolder = $uploadsFolder->get($responseId);
            if (!($responseFolder instanceof Folder)) {
                throw new NotFoundException('Response folder not found');
            }

            $file = $responseFolder->get($filename);
            if (!($file instanceof File)) {
                throw new NotFoundException('File not found');
            }

            return $file;
        } catch (NotFoundException $e) {
            throw new NotFoundException('Upload not found');
        }
    }

    /**
     * Extract file upload responseIds from an answer
     * Handles both single file and multiple file uploads
     *
     * @param mixed $answer The answer value
     * @return array Array of responseId strings
     */
    private function extractFileResponseIds($answer): array
    {
        if (!is_array($answer)) {
            return [];
        }

        // Single file upload (has responseId directly)
        if (isset($answer['responseId'])) {
            return [$answer['responseId']];
        }

        // Multiple file uploads (array of file objects)
        $responseIds = [];
        foreach ($answer as $item) {
            if (is_array($item) && isset($item['responseId'])) {
                $responseIds[] = $item['responseId'];
            }
        }

        return $responseIds;
    }

    /**
     * Delete all uploads for a response
     *
     * @param int $formFileId The form file ID
     * @param string $responseId The response ID
     */
    public function deleteResponseUploads(int $formFileId, string $responseId): void
    {
        try {
            $uploadsFolder = $this->getUploadsFolder($formFileId, true);
            $responseFolder = $uploadsFolder->get($responseId);
            if ($responseFolder instanceof Folder) {
                $responseFolder->delete();
            }
        } catch (NotFoundException $e) {
            // No uploads to delete
        }
    }

    /**
     * Delete the entire uploads folder for a form
     *
     * @param int $fileId The form file ID
     */
    public function deleteAllUploads(int $fileId): void
    {
        try {
            $formFile = $this->getFileByIdPublic($fileId, true);
            $formFolder = $formFile->getParent();

            // Use file ID based folder name
            $uploadsFolderName = ".formvox-uploads-{$fileId}";

            try {
                $uploadsFolder = $formFolder->get($uploadsFolderName);
                if ($uploadsFolder instanceof Folder) {
                    $uploadsFolder->delete();
                }
            } catch (NotFoundException $e) {
                // No uploads folder
            }
        } catch (\Exception $e) {
            // Form file not found or other error
        }
    }

    /**
     * Create a ZIP file containing all uploads for a form
     *
     * @param int $fileId The form file ID
     * @return string The ZIP file content
     * @throws NotFoundException If no uploads exist
     */
    public function createUploadsZip(int $fileId): string
    {
        $formFile = $this->getFileByIdPublic($fileId);
        $formFolder = $formFile->getParent();

        // Get uploads folder
        $uploadsFolderName = ".formvox-uploads-{$fileId}";
        try {
            $uploadsFolder = $formFolder->get($uploadsFolderName);
            if (!($uploadsFolder instanceof Folder)) {
                throw new NotFoundException('No uploads found');
            }
        } catch (NotFoundException $e) {
            throw new NotFoundException('No uploads found');
        }

        // Create temporary ZIP file
        $tempFile = tempnam(sys_get_temp_dir(), 'formvox_uploads_');
        $zip = new \ZipArchive();

        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP file');
        }

        // Add all files from uploads folder
        $this->addFolderToZip($zip, $uploadsFolder, '');

        $zip->close();

        // Read ZIP content
        $content = file_get_contents($tempFile);

        // Clean up temp file
        unlink($tempFile);

        if ($content === false || strlen($content) === 0) {
            throw new NotFoundException('No uploads found');
        }

        return $content;
    }

    /**
     * Recursively add a folder's contents to a ZIP archive
     *
     * @param \ZipArchive $zip The ZIP archive
     * @param Folder $folder The folder to add
     * @param string $basePath The base path in the ZIP
     */
    private function addFolderToZip(\ZipArchive $zip, Folder $folder, string $basePath): void
    {
        foreach ($folder->getDirectoryListing() as $node) {
            $nodePath = $basePath === '' ? $node->getName() : $basePath . '/' . $node->getName();

            if ($node instanceof File) {
                $zip->addFromString($nodePath, $node->getContent());
            } elseif ($node instanceof Folder) {
                $this->addFolderToZip($zip, $node, $nodePath);
            }
        }
    }

    /**
     * Sanitize upload filename
     * Keeps extension, removes unsafe characters
     */
    private function sanitizeUploadFilename(string $filename): string
    {
        $info = pathinfo($filename);
        $extension = $info['extension'] ?? 'bin';

        // Clean the base name - allow more characters than form filenames
        $name = $info['filename'];
        // Remove path traversal characters and null bytes
        $name = str_replace(['..', "\0", '/', '\\'], '', $name);
        // Replace problematic characters with underscores
        $name = preg_replace('/[<>:"|?*]/', '_', $name);
        // Collapse multiple underscores/spaces
        $name = preg_replace('/[_\s]+/', '_', $name);
        // Trim
        $name = trim($name, '._');
        // Limit length
        $name = substr($name, 0, 100);
        // Default if empty
        if (empty($name)) {
            $name = 'upload';
        }

        // Clean extension
        $extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);
        if (empty($extension)) {
            $extension = 'bin';
        }

        return $name . '.' . $extension;
    }

    /**
     * Get the templates folder for a form
     * Creates it if it doesn't exist
     */
    public function getTemplatesFolder(int $fileId, bool $requireWrite = false): Folder
    {
        // Read-only by default so ODT export can find a template on shares
        // where the reachable account cannot write (#90).
        $formFile = $this->getFileByIdPublic($fileId, $requireWrite);
        $formFolder = $formFile->getParent();
        $templatesFolderName = ".formvox-templates-{$fileId}";

        try {
            $templatesFolder = $formFolder->get($templatesFolderName);
            if (!($templatesFolder instanceof Folder)) {
                throw new \RuntimeException('Templates path is not a folder');
            }
            return $templatesFolder;
        } catch (NotFoundException $e) {
            if (!$requireWrite) {
                throw $e;
            }
            return $formFolder->newFolder($templatesFolderName);
        }
    }

    /**
     * Store an ODT template for a form
     */
    public function storeOdtTemplate(int $fileId, array $uploadedFile): void
    {
        $folder = $this->getTemplatesFolder($fileId, true);

        // Remove existing template if present
        try {
            $existing = $folder->get('template.odt');
            $existing->delete();
        } catch (NotFoundException $e) {
            // No existing template
        }

        $newFile = $folder->newFile('template.odt');
        $newFile->putContent(file_get_contents($uploadedFile['tmp_name']));
    }

    /**
     * Get the ODT template file for a form
     */
    public function getOdtTemplate(int $fileId, bool $requireWrite = false): File
    {
        $folder = $this->getTemplatesFolder($fileId, $requireWrite);
        $file = $folder->get('template.odt');
        if (!($file instanceof File)) {
            throw new NotFoundException('Template not found');
        }
        return $file;
    }

    /**
     * Check if a form has an ODT template
     */
    public function hasOdtTemplate(int $fileId): bool
    {
        try {
            $this->getOdtTemplate($fileId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete the ODT template for a form
     */
    public function deleteOdtTemplate(int $fileId): void
    {
        try {
            // Resolve through a write-capable account so the delete succeeds.
            $file = $this->getOdtTemplate($fileId, true);
            $file->delete();
        } catch (NotFoundException $e) {
            // No template to delete
        }
    }
}
