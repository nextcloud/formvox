<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\IL10N;
use OCA\FormVox\AppInfo\Application;

class FormService
{
    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private IndexService $indexService;
    private IDBConnection $db;
    private IL10N $l;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        IndexService $indexService,
        IDBConnection $db,
        IL10N $l
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->indexService = $indexService;
        $this->db = $db;
        $this->l = $l;
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
    public function create(string $title, string $path = '', ?string $template = null): array
    {
        $user = $this->userSession->getUser();
        $userId = $user->getUID();
        $userFolder = $this->getUserFolder();

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
        $form = json_decode($file->getContent(), true);

        if ($form === null) {
            throw new \RuntimeException('Invalid form file format');
        }

        // Update allowed fields
        // Use array_key_exists instead of isset to allow null values (e.g., branding: null)
        $allowedFields = ['title', 'description', 'settings', 'questions', 'pages', 'permissions', '_index', 'branding', 'favorite'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $form[$field] = $data[$field];
            }
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

        $form['modified_at'] = date('c');

        $file->putContent(json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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
     * Uses database query for fast lookups instead of recursive folder scanning
     */
    public function listForms(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }
        $userId = $user->getUID();

        // Query database directly for .fvform files - much faster than recursive scan
        // Just search by filename extension, mimetype may not be registered yet
        $qb = $this->db->getQueryBuilder();
        $qb->select('fc.fileid', 'fc.name', 'fc.path', 'fc.mtime', 'fc.size')
            ->from('filecache', 'fc')
            ->where($qb->expr()->like('fc.name', $qb->createNamedParameter('%.fvform')));

        $result = $qb->executeQuery();
        $forms = [];
        $userFolder = $this->getUserFolder();

        while ($row = $result->fetch()) {
            try {
                // Check if user has access to this file
                $nodes = $userFolder->getById((int)$row['fileid']);
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
        $result->closeCursor();

        return $forms;
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
    private function appendResponseWithLock(File $file, array $response): array
    {
        $lockKey = 'formvox_response_' . $file->getId();
        $maxRetries = 30;
        $retryDelay = 100000; // 100ms in microseconds

        for ($retry = 0; $retry < $maxRetries; $retry++) {
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

                    if (!isset($form['responses'])) {
                        $form['responses'] = [];
                    }

                    $form['responses'][] = $response;
                    $this->indexService->updateIndex($form, $response, \count($form['responses']) - 1);
                    $form['modified_at'] = date('c');

                    // Write directly to storage (bypasses versioning)
                    $storage->file_put_contents($internalPath, json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                    // Touch the file to update mtime in cache
                    $storage->touch($internalPath);

                    // Delete any versions created during this write
                    $this->deleteVersionsForFile($file);

                    return $response;
                } finally {
                    // Always release lock
                    $this->releaseLock($lockKey);
                }
            } catch (\Exception $e) {
                // Lock acquisition failed (duplicate key), retry
                $msg = $e->getMessage();
                if (strpos($msg, 'Duplicate') !== false ||
                    strpos($msg, 'UNIQUE constraint') !== false ||
                    strpos($msg, 'duplicate key') !== false) {
                    usleep($retryDelay * ($retry + 1));
                    continue;
                }
                throw $e;
            }
        }

        throw new \RuntimeException('Could not acquire lock after ' . $maxRetries . ' retries');
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
        $storage = $file->getStorage();
        $internalPath = $file->getInternalPath();

        $form = json_decode($storage->file_get_contents($internalPath), true);

        // Find and remove response
        $found = false;
        $fileUploadResponseIds = [];
        foreach ($form['responses'] as $index => $response) {
            if ($response['id'] === $responseId) {
                // Collect file upload responseIds from answers before deleting
                if (isset($response['answers'])) {
                    foreach ($response['answers'] as $answer) {
                        $fileUploadResponseIds = array_merge(
                            $fileUploadResponseIds,
                            $this->extractFileResponseIds($answer)
                        );
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

        $form['modified_at'] = date('c');

        // Write directly to storage (bypasses versioning)
        $storage->file_put_contents($internalPath, json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $storage->touch($internalPath);

        // Delete any versions created during this write
        $this->deleteVersionsForFile($file);

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
        $storage = $file->getStorage();
        $internalPath = $file->getInternalPath();

        $form = json_decode($storage->file_get_contents($internalPath), true);

        // Clear all responses
        $form['responses'] = [];

        // Rebuild index (will be empty)
        $this->indexService->rebuildIndex($form);

        $form['modified_at'] = date('c');

        // Write directly to storage (bypasses versioning)
        $storage->file_put_contents($internalPath, json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $storage->touch($internalPath);

        // Delete any versions created during this write
        $this->deleteVersionsForFile($file);

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
     * Supports both personal folders (home::) and group folders
     */
    public function getFileByIdPublic(int $fileId): File
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
        $storageNumericId = (int)$row['numeric_id'];

        // Case 1: Personal folder (home::username)
        if (strpos($storageId, 'home::') === 0) {
            $userId = substr($storageId, 6);
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);

            if (!empty($nodes)) {
                $file = $nodes[0];
                if ($file instanceof File) {
                    return $file;
                }
            }
            throw new NotFoundException('Form not found');
        }

        // Case 2: Group folder (local::.../__groupfolders/{id}/)
        if (preg_match('#__groupfolders/(\d+)/#', $storageId, $matches)) {
            $groupFolderId = (int)$matches[1];

            // Find a user who has access to this group folder
            $userId = $this->findUserWithGroupFolderAccess($groupFolderId);
            if ($userId !== null) {
                $userFolder = $this->rootFolder->getUserFolder($userId);
                $nodes = $userFolder->getById($fileId);

                if (!empty($nodes)) {
                    $file = $nodes[0];
                    if ($file instanceof File) {
                        return $file;
                    }
                }
            }
            throw new NotFoundException('Form not found');
        }

        throw new NotFoundException('Form not found');
    }

    /**
     * Find a user who has access to a group folder
     */
    private function findUserWithGroupFolderAccess(int $groupFolderId): ?string
    {
        // Get groups that have access to this group folder
        $qb = $this->db->getQueryBuilder();
        $qb->select('group_id')
            ->from('group_folders_groups')
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupFolderId, \PDO::PARAM_INT)))
            ->setMaxResults(10);

        $result = $qb->executeQuery();
        $groups = [];
        while ($row = $result->fetch()) {
            $groups[] = $row['group_id'];
        }
        $result->closeCursor();

        if (empty($groups)) {
            return null;
        }

        // Find a user in one of these groups
        foreach ($groups as $groupId) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('uid')
                ->from('group_user')
                ->where($qb->expr()->eq('gid', $qb->createNamedParameter($groupId)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row !== false) {
                return $row['uid'];
            }
        }

        return null;
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
    public function appendResponsePublic(int $fileId, array $response): array
    {
        $file = $this->getFileByIdPublic($fileId);

        return $this->appendResponseWithLock($file, $response);
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
                            ['id' => 'opt5', 'label' => $this->l->t('Very Poor'), 'value' => '1'],
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
                        'placeholder' => $this->l->t('Write a short bio...'),
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
                    // Section 7: Conditional Branching Demo
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
                        'placeholder' => $this->l->t('Share your thoughts...'),
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
        $name = substr($name, 0, 50);
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
    public function getUploadsFolder(int $fileId): Folder
    {
        $formFile = $this->getFileByIdPublic($fileId);
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
            return $formFolder->newFolder($uploadsFolderName);
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
        $uploadsFolder = $this->getUploadsFolder($fileId);

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
            $uploadsFolder = $this->getUploadsFolder($formFileId);
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
            $formFile = $this->getFileByIdPublic($fileId);
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
}
