<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCA\FormVox\AppInfo\Application;

class FormService
{
    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private IndexService $indexService;
    private IDBConnection $db;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        IndexService $indexService,
        IDBConnection $db
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->indexService = $indexService;
        $this->db = $db;
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
     * Note: We don't use explicit locking here - Nextcloud's file system
     * handles locking internally during putContent()
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
        $allowedFields = ['title', 'description', 'settings', 'questions', 'pages', 'permissions', '_index', 'branding'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $form[$field] = $data[$field];
            }
        }

        // Hash the share password if provided (store hash, never plaintext)
        if (isset($form['settings']['share_password'])) {
            $password = $form['settings']['share_password'];
            if (!empty($password)) {
                // Only hash if it's not already hashed (new password)
                // Hashed passwords start with $2y$ (bcrypt)
                if (strpos($password, '$2y$') !== 0) {
                    $form['settings']['share_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
            } else {
                // Password was cleared
                unset($form['settings']['share_password_hash']);
            }
            // Never store plaintext password
            unset($form['settings']['share_password']);
        }

        $form['modified_at'] = date('c');

        // Save - Nextcloud handles locking internally
        $file->putContent(json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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
     */
    public function listForms(): array
    {
        $userFolder = $this->getUserFolder();
        $forms = [];

        $this->findFormsRecursive($userFolder, $forms);

        return $forms;
    }

    /**
     * Append a response to a form
     * Note: We don't use explicit locking - Nextcloud handles it internally
     */
    public function appendResponse(int $fileId, array $response): array
    {
        $file = $this->getFileById($fileId);
        $form = json_decode($file->getContent(), true);

        // Initialize responses array if not exists
        if (!isset($form['responses'])) {
            $form['responses'] = [];
        }

        // Add response
        $form['responses'][] = $response;

        // Update index
        $this->indexService->updateIndex($form, $response, count($form['responses']) - 1);

        $form['modified_at'] = date('c');

        // Save - Nextcloud handles locking internally
        $file->putContent(json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    /**
     * Delete a response from a form
     * Note: We don't use explicit locking - Nextcloud handles it internally
     */
    public function deleteResponse(int $fileId, string $responseId): void
    {
        $file = $this->getFileById($fileId);
        $form = json_decode($file->getContent(), true);

        // Find and remove response
        $found = false;
        foreach ($form['responses'] as $index => $response) {
            if ($response['id'] === $responseId) {
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

        // Save - Nextcloud handles locking internally
        $file->putContent(json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
     */
    public function appendResponsePublic(int $fileId, array $response): array
    {
        $file = $this->getFileByIdPublic($fileId);
        $form = json_decode($file->getContent(), true);

        // Initialize responses array if not exists
        if (!isset($form['responses'])) {
            $form['responses'] = [];
        }

        // Add response
        $form['responses'][] = $response;

        // Update index
        $this->indexService->updateIndex($form, $response, count($form['responses']) - 1);

        $form['modified_at'] = date('c');

        // Save
        $file->putContent(json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $response;
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
                'show_results' => 'after_submit',
                'require_login' => false,
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
                        'question' => 'How would you rate your overall experience?',
                        'required' => true,
                        'options' => [
                            ['id' => 'opt1', 'label' => 'Excellent', 'value' => '5'],
                            ['id' => 'opt2', 'label' => 'Good', 'value' => '4'],
                            ['id' => 'opt3', 'label' => 'Average', 'value' => '3'],
                            ['id' => 'opt4', 'label' => 'Poor', 'value' => '2'],
                            ['id' => 'opt5', 'label' => 'Very Poor', 'value' => '1'],
                        ],
                    ],
                    [
                        'id' => 'q2',
                        'type' => 'textarea',
                        'question' => 'Do you have any additional comments?',
                        'required' => false,
                    ],
                ],
            ],
            'poll' => [
                'questions' => [
                    [
                        'id' => 'q1',
                        'type' => 'choice',
                        'question' => 'What is your preferred option?',
                        'required' => true,
                        'options' => [
                            ['id' => 'opt1', 'label' => 'Option A', 'value' => 'a'],
                            ['id' => 'opt2', 'label' => 'Option B', 'value' => 'b'],
                            ['id' => 'opt3', 'label' => 'Option C', 'value' => 'c'],
                        ],
                    ],
                ],
                'settings' => [
                    'show_results' => 'always',
                ],
            ],
            'registration' => [
                'questions' => [
                    [
                        'id' => 'q1',
                        'type' => 'text',
                        'question' => 'Full name',
                        'required' => true,
                    ],
                    [
                        'id' => 'q2',
                        'type' => 'text',
                        'question' => 'Email address',
                        'required' => true,
                        'validation' => ['type' => 'email'],
                    ],
                    [
                        'id' => 'q3',
                        'type' => 'text',
                        'question' => 'Phone number',
                        'required' => false,
                    ],
                ],
                'settings' => [
                    'anonymous' => false,
                    'require_login' => false,
                ],
            ],
            'demo' => [
                'description' => 'This demo form showcases all FormVox features including different question types, conditional logic (branching), quiz mode with scoring, and various input validations.',
                'questions' => [
                    // Section 1: Basic Info
                    [
                        'id' => 'demo_name',
                        'type' => 'text',
                        'question' => 'What is your name?',
                        'description' => 'This is a simple text field',
                        'required' => true,
                        'placeholder' => 'Enter your full name',
                    ],
                    [
                        'id' => 'demo_email',
                        'type' => 'text',
                        'question' => 'What is your email address?',
                        'description' => 'Text field with email validation',
                        'required' => true,
                        'validation' => ['type' => 'email'],
                    ],
                    [
                        'id' => 'demo_bio',
                        'type' => 'textarea',
                        'question' => 'Tell us about yourself',
                        'description' => 'Multi-line text area for longer responses',
                        'required' => false,
                        'placeholder' => 'Write a short bio...',
                    ],
                    // Section 2: Choice Questions
                    [
                        'id' => 'demo_experience',
                        'type' => 'choice',
                        'question' => 'How much experience do you have with forms?',
                        'description' => 'Single choice (radio buttons)',
                        'required' => true,
                        'options' => [
                            ['id' => 'exp1', 'label' => 'Beginner - Just getting started', 'value' => 'beginner'],
                            ['id' => 'exp2', 'label' => 'Intermediate - Some experience', 'value' => 'intermediate'],
                            ['id' => 'exp3', 'label' => 'Expert - I create forms daily', 'value' => 'expert'],
                        ],
                    ],
                    // Conditional question - only shown for experts
                    [
                        'id' => 'demo_expert_tools',
                        'type' => 'multiple',
                        'question' => 'Which form tools have you used before?',
                        'description' => 'This question only appears if you selected "Expert" above (conditional logic)',
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
                        'question' => 'Which features are most important to you?',
                        'description' => 'Multiple choice (checkboxes) - select all that apply',
                        'required' => true,
                        'options' => [
                            ['id' => 'feat1', 'label' => 'Easy to use interface', 'value' => 'easy'],
                            ['id' => 'feat2', 'label' => 'Conditional logic / Branching', 'value' => 'branching'],
                            ['id' => 'feat3', 'label' => 'File-based storage', 'value' => 'files'],
                            ['id' => 'feat4', 'label' => 'Privacy / Self-hosted', 'value' => 'privacy'],
                            ['id' => 'feat5', 'label' => 'Export options', 'value' => 'export'],
                        ],
                    ],
                    [
                        'id' => 'demo_priority',
                        'type' => 'dropdown',
                        'question' => 'What is your top priority?',
                        'description' => 'Dropdown select for longer option lists',
                        'required' => true,
                        'options' => [
                            ['id' => 'pri1', 'label' => 'Speed', 'value' => 'speed'],
                            ['id' => 'pri2', 'label' => 'Security', 'value' => 'security'],
                            ['id' => 'pri3', 'label' => 'Flexibility', 'value' => 'flexibility'],
                            ['id' => 'pri4', 'label' => 'Integration', 'value' => 'integration'],
                            ['id' => 'pri5', 'label' => 'Cost', 'value' => 'cost'],
                        ],
                    ],
                    // Section 3: Date & Time
                    [
                        'id' => 'demo_date',
                        'type' => 'date',
                        'question' => 'When did you start using Nextcloud?',
                        'description' => 'Date picker',
                        'required' => false,
                    ],
                    [
                        'id' => 'demo_datetime',
                        'type' => 'datetime',
                        'question' => 'When would you like a demo call?',
                        'description' => 'Date and time picker',
                        'required' => false,
                    ],
                    [
                        'id' => 'demo_time',
                        'type' => 'time',
                        'question' => 'What time works best for you?',
                        'description' => 'Time picker only',
                        'required' => false,
                    ],
                    // Section 4: Numbers & Ratings
                    [
                        'id' => 'demo_number',
                        'type' => 'number',
                        'question' => 'How many forms do you create per month?',
                        'description' => 'Numeric input',
                        'required' => false,
                        'min' => 0,
                        'max' => 1000,
                    ],
                    [
                        'id' => 'demo_scale',
                        'type' => 'scale',
                        'question' => 'How likely are you to recommend FormVox?',
                        'description' => 'Linear scale (1-10)',
                        'required' => true,
                        'min' => 1,
                        'max' => 10,
                        'minLabel' => 'Not likely',
                        'maxLabel' => 'Very likely',
                    ],
                    [
                        'id' => 'demo_rating',
                        'type' => 'rating',
                        'question' => 'Rate this demo form',
                        'description' => 'Star rating (1-5 stars)',
                        'required' => true,
                        'max' => 5,
                    ],
                    // Section 5: Quiz Mode
                    [
                        'id' => 'demo_quiz1',
                        'type' => 'choice',
                        'question' => 'Quiz: What file extension does FormVox use?',
                        'description' => 'This is a quiz question with scoring - correct answer: .fvform',
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
                        'question' => 'Quiz: Where does FormVox store form data?',
                        'description' => 'Another quiz question - correct answer: In Nextcloud files',
                        'required' => true,
                        'options' => [
                            ['id' => 'quiz2a', 'label' => 'In a separate database', 'value' => 'database', 'score' => 0],
                            ['id' => 'quiz2b', 'label' => 'In the cloud', 'value' => 'cloud', 'score' => 0],
                            ['id' => 'quiz2c', 'label' => 'In Nextcloud files', 'value' => 'files', 'score' => 10],
                            ['id' => 'quiz2d', 'label' => 'On external servers', 'value' => 'external', 'score' => 0],
                        ],
                    ],
                    // Section 6: Matrix Question
                    [
                        'id' => 'demo_matrix',
                        'type' => 'matrix',
                        'question' => 'Rate these aspects of FormVox',
                        'description' => 'Matrix/grid question with multiple rows and columns',
                        'required' => false,
                        'rows' => [
                            ['id' => 'row1', 'label' => 'Ease of use'],
                            ['id' => 'row2', 'label' => 'Feature set'],
                            ['id' => 'row3', 'label' => 'Design'],
                            ['id' => 'row4', 'label' => 'Performance'],
                        ],
                        'columns' => [
                            ['id' => 'col1', 'label' => 'Poor', 'value' => '1'],
                            ['id' => 'col2', 'label' => 'Fair', 'value' => '2'],
                            ['id' => 'col3', 'label' => 'Good', 'value' => '3'],
                            ['id' => 'col4', 'label' => 'Excellent', 'value' => '4'],
                        ],
                    ],
                    // Section 7: Conditional Branching Demo
                    [
                        'id' => 'demo_want_contact',
                        'type' => 'choice',
                        'question' => 'Would you like us to contact you?',
                        'description' => 'This controls whether the next question is shown',
                        'required' => true,
                        'options' => [
                            ['id' => 'contact_yes', 'label' => 'Yes, please contact me', 'value' => 'yes'],
                            ['id' => 'contact_no', 'label' => 'No, thanks', 'value' => 'no'],
                        ],
                    ],
                    [
                        'id' => 'demo_contact_method',
                        'type' => 'choice',
                        'question' => 'How would you prefer to be contacted?',
                        'description' => 'This question only appears if you selected "Yes" above',
                        'required' => false,
                        'options' => [
                            ['id' => 'method1', 'label' => 'Email', 'value' => 'email'],
                            ['id' => 'method2', 'label' => 'Phone', 'value' => 'phone'],
                            ['id' => 'method3', 'label' => 'Video call', 'value' => 'video'],
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
                        'question' => 'Any final thoughts or feedback?',
                        'description' => 'Thank you for trying this demo form!',
                        'required' => false,
                        'placeholder' => 'Share your thoughts...',
                    ],
                ],
                'settings' => [
                    'anonymous' => true,
                    'allow_multiple' => true,
                    'show_results' => 'after_submit',
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
}
