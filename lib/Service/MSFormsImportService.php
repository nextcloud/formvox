<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Service for importing Microsoft Forms into FormVox
 */
class MSFormsImportService
{
    public function __construct(
        private FormService $formService,
        private MicrosoftFormsApiClient $apiClient,
        private ISecureRandom $secureRandom,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Import a Microsoft Form into FormVox
     */
    public function importForm(
        string $userId,
        string $accessToken,
        string $msFormId,
        string $path = '/',
        bool $includeResponses = true
    ): array {
        // Fetch form data from MS Forms
        $msForm = $this->apiClient->getForm($accessToken, $msFormId);
        $msQuestions = $this->apiClient->getQuestions($accessToken, $msFormId);

        $warnings = [];

        // Sort all questions by order first
        usort($msQuestions, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        // First pass: identify pages (ColumnGroups) and group matrix questions
        $matrixGroups = []; // groupId => ['header' => ..., 'rows' => [...]]
        $pages = []; // Array of pages, each with title and questions
        $currentPageIndex = -1; // Index of current page, -1 means no page yet
        $questionsWithoutPage = [];

        foreach ($msQuestions as $msQuestion) {
            $msType = $msQuestion['msType'] ?? '';
            $groupId = $msQuestion['groupId'] ?? null;

            if ($msType === 'Question.ColumnGroup') {
                // This is a page/section header - start a new page
                // Clean up the title (remove HTML tags like <b>)
                $title = $msQuestion['title'] ?? 'Page';
                $title = strip_tags($title);

                $pages[] = [
                    'id' => 'p' . $this->secureRandom->generate(8, ISecureRandom::CHAR_ALPHANUMERIC),
                    'title' => $title,
                    'order' => $msQuestion['order'] ?? 0,
                    'questions' => [],
                ];
                $currentPageIndex = count($pages) - 1;
                continue;
            }

            if ($msType === 'Question.MatrixChoiceGroup') {
                // This is a matrix group header
                $matrixGroups[$msQuestion['id']] = [
                    'header' => $msQuestion,
                    'rows' => [],
                    'page' => $currentPageIndex >= 0 ? $currentPageIndex : null,
                ];
            } elseif ($msType === 'Question.MatrixChoice' && $groupId !== null) {
                // This is a row within a matrix group
                if (!isset($matrixGroups[$groupId])) {
                    $matrixGroups[$groupId] = [
                        'header' => null,
                        'rows' => [],
                        'page' => $currentPageIndex >= 0 ? $currentPageIndex : null,
                    ];
                }
                $matrixGroups[$groupId]['rows'][] = $msQuestion;
            } else {
                // Regular question - add to current page or questions without page
                if ($currentPageIndex >= 0) {
                    $pages[$currentPageIndex]['questions'][] = $msQuestion;
                } else {
                    $questionsWithoutPage[] = $msQuestion;
                }
            }
        }

        // Map questions to FormVox format
        $questionIdMap = []; // MS Form ID -> FormVox ID
        $choiceMap = []; // MS Form Question ID -> [MS Choice ID -> FormVox value]

        // Helper function to process a question
        $processQuestion = function ($msQuestion) use (&$questionIdMap, &$choiceMap, &$warnings) {
            $mapped = $this->mapQuestionType($msQuestion);
            $questionIdMap[$msQuestion['id']] = $mapped['question']['id'];
            if (!empty($mapped['warnings'])) {
                $warnings = array_merge($warnings, $mapped['warnings']);
            }

            // Build choice mapping for branching resolution
            if (!empty($msQuestion['choices'])) {
                $choiceMap[$msQuestion['id']] = [];
                foreach ($msQuestion['choices'] as $idx => $choice) {
                    $msChoiceId = $choice['id'] ?? $choice['Id'] ?? null;
                    $fvValue = $mapped['question']['options'][$idx]['value'] ?? $choice['value'] ?? $choice['text'] ?? '';
                    if ($msChoiceId !== null) {
                        $choiceMap[$msQuestion['id']][$msChoiceId] = $fvValue;
                    }
                }
            }

            return $mapped['question'];
        };

        // Helper function to process a matrix group
        $processMatrixGroup = function ($group) use (&$questionIdMap, &$warnings) {
            $mapped = $this->mapMatrixGroup($group);
            if ($mapped !== null) {
                if (isset($group['header']['id'])) {
                    $questionIdMap[$group['header']['id']] = $mapped['question']['id'];
                }
                foreach ($group['rows'] as $row) {
                    $questionIdMap[$row['id']] = $mapped['question']['id'];
                }
                if (!empty($mapped['warnings'])) {
                    $warnings = array_merge($warnings, $mapped['warnings']);
                }
                return $mapped['question'];
            }
            return null;
        };

        // Check if we have pages
        $hasPages = count($pages) > 0;

        // FormVox stores questions as a flat array, and pages reference question IDs
        $allQuestions = [];
        $formPages = [];

        if ($hasPages) {
            // Process pages with their questions
            foreach ($pages as $pageIndex => $page) {
                $pageQuestionIds = [];

                // Process regular questions for this page
                foreach ($page['questions'] as $msQuestion) {
                    $q = $processQuestion($msQuestion);
                    $allQuestions[] = $q;
                    $pageQuestionIds[] = $q['id'];
                }

                // Process matrix groups that belong to this page
                foreach ($matrixGroups as $groupId => $group) {
                    if (($group['page'] ?? null) === $pageIndex) {
                        $matrixQuestion = $processMatrixGroup($group);
                        if ($matrixQuestion !== null) {
                            $allQuestions[] = $matrixQuestion;
                            $pageQuestionIds[] = $matrixQuestion['id'];
                        }
                    }
                }

                // Sort questions within page by order, then extract IDs in sorted order
                $pageQuestionsWithOrder = [];
                foreach ($pageQuestionIds as $qId) {
                    foreach ($allQuestions as $q) {
                        if ($q['id'] === $qId) {
                            $pageQuestionsWithOrder[] = $q;
                            break;
                        }
                    }
                }
                usort($pageQuestionsWithOrder, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                $sortedIds = array_map(fn($q) => $q['id'], $pageQuestionsWithOrder);

                $formPages[] = [
                    'id' => $page['id'],
                    'title' => $page['title'],
                    'questions' => $sortedIds, // Array of question IDs
                ];
            }

            // Handle any questions that appeared before the first page
            if (!empty($questionsWithoutPage)) {
                $introQuestionIds = [];
                foreach ($questionsWithoutPage as $msQuestion) {
                    $q = $processQuestion($msQuestion);
                    $allQuestions[] = $q;
                    $introQuestionIds[] = $q['id'];
                }
                // Process matrix groups without a page
                foreach ($matrixGroups as $groupId => $group) {
                    if ($group['page'] === null) {
                        $matrixQuestion = $processMatrixGroup($group);
                        if ($matrixQuestion !== null) {
                            $allQuestions[] = $matrixQuestion;
                            $introQuestionIds[] = $matrixQuestion['id'];
                        }
                    }
                }

                // Sort and get IDs
                $introQuestionsWithOrder = [];
                foreach ($introQuestionIds as $qId) {
                    foreach ($allQuestions as $q) {
                        if ($q['id'] === $qId) {
                            $introQuestionsWithOrder[] = $q;
                            break;
                        }
                    }
                }
                usort($introQuestionsWithOrder, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                $sortedIntroIds = array_map(fn($q) => $q['id'], $introQuestionsWithOrder);

                // Prepend as first page
                array_unshift($formPages, [
                    'id' => 'p' . $this->secureRandom->generate(8, ISecureRandom::CHAR_ALPHANUMERIC),
                    'title' => 'Introduction',
                    'questions' => $sortedIntroIds,
                ]);
            }

            $questions = $allQuestions;
        } else {
            // No pages - process all questions flat
            $questions = [];

            foreach ($questionsWithoutPage as $msQuestion) {
                $questions[] = $processQuestion($msQuestion);
            }

            // Process all matrix groups
            foreach ($matrixGroups as $group) {
                $matrixQuestion = $processMatrixGroup($group);
                if ($matrixQuestion !== null) {
                    $questions[] = $matrixQuestion;
                }
            }

            // Sort questions by order
            usort($questions, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        }

        // Create the FormVox form
        $createResult = $this->formService->create(
            $msForm['title'] ?? 'Imported Form',
            $path,
            null // No template
        );
        $fileId = $createResult['fileId'];

        // Apply branching rules to convert MS Forms conditions to FormVox showIf
        $questions = $this->applyBranchingRules($questions, $questionIdMap, $choiceMap);

        // Count how many questions have branching
        $branchingCount = 0;
        foreach ($questions as $q) {
            if (!empty($q['showIf'])) {
                $branchingCount++;
            }
        }
        if ($branchingCount > 0) {
            $this->logger->info("Imported $branchingCount questions with branching logic");
        }

        // Update with questions and settings
        $formData = [
            'description' => $msForm['description'] ?? '',
            'settings' => [
                'anonymous' => $msForm['settings']['isAnonymous'] ?? true,
                'allow_multiple' => $msForm['settings']['allowMultipleResponses'] ?? false,
            ],
        ];

        // FormVox always needs questions as a flat array
        // Pages only contain question IDs that reference this array
        $formData['questions'] = $questions;
        $totalQuestionCount = count($questions);

        // Add pages if we have them
        if ($hasPages && !empty($formPages)) {
            $formData['pages'] = $formPages;
        }

        $this->formService->update($fileId, $formData);

        // Import responses if requested
        $responsesImported = 0;
        if ($includeResponses) {
            try {
                $msResponses = $this->apiClient->getResponses($accessToken, $msFormId);
                $responsesImported = $this->importResponses($fileId, $msResponses, $questionIdMap);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to import responses', ['exception' => $e]);
                $warnings[] = 'Could not import responses: ' . $e->getMessage();
            }
        }

        return [
            'fileId' => $fileId,
            'title' => $msForm['title'] ?? 'Imported Form',
            'questionsImported' => $totalQuestionCount,
            'pagesImported' => $hasPages ? count($formPages) : 0,
            'responsesImported' => $responsesImported,
            'warnings' => $warnings,
        ];
    }

    /**
     * Map a MS Forms matrix group (Likert) to FormVox matrix format
     */
    private function mapMatrixGroup(array $group): ?array
    {
        $header = $group['header'];
        $rows = $group['rows'];

        if (empty($rows)) {
            return null;
        }

        // Sort rows by order
        usort($rows, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        $warnings = [];
        $question = [
            'id' => $this->generateQuestionId(),
            'type' => 'matrix',
            'question' => $header['title'] ?? 'Matrix Question',
            'description' => $header['subtitle'] ?? '',
            'required' => $header['isRequired'] ?? false,
            'order' => $header['order'] ?? 0,
        ];

        // Rows are the MatrixChoice questions
        $question['rows'] = array_map(function ($row, $idx) {
            return [
                'id' => 'r' . ($idx + 1),
                'label' => $row['title'] ?? '',
            ];
        }, $rows, array_keys($rows));

        // Columns are not available via the MS Forms API
        // We'll create placeholder columns that the user can edit
        $question['columns'] = [
            ['id' => 'c1', 'label' => 'Option 1', 'value' => '1'],
            ['id' => 'c2', 'label' => 'Option 2', 'value' => '2'],
            ['id' => 'c3', 'label' => 'Option 3', 'value' => '3'],
        ];

        $warnings[] = "Question '{$question['question']}': Matrix/Likert columns could not be imported from MS Forms API. Please edit the column options manually.";

        return [
            'question' => $question,
            'warnings' => $warnings,
        ];
    }

    /**
     * Map a MS Forms question type to FormVox format
     */
    public function mapQuestionType(array $msQuestion): array
    {
        $warnings = [];
        $msType = $msQuestion['msType'] ?? 'Question.Text';

        $question = [
            'id' => $this->generateQuestionId(),
            'question' => $msQuestion['title'] ?? '',
            'description' => $msQuestion['subtitle'] ?? '',
            'required' => $msQuestion['isRequired'] ?? false,
            'order' => $msQuestion['order'] ?? 0,
        ];

        switch ($msType) {
            case 'Question.Choice':
                $question['type'] = $msQuestion['allowMultipleSelection'] ? 'multiple' : 'choice';
                $question['options'] = array_map(function ($choice, $idx) {
                    $option = [
                        'id' => 'opt' . ($idx + 1),
                        'label' => $choice['text'] ?? '',
                        'value' => $choice['value'] ?? $choice['text'] ?? '',
                    ];
                    // Preserve branchTarget for conditional branching resolution
                    if (!empty($choice['branchTarget'])) {
                        $option['_msBranchTarget'] = $choice['branchTarget'];
                    }
                    return $option;
                }, $msQuestion['choices'] ?? [], array_keys($msQuestion['choices'] ?? []));

                if ($msQuestion['allowOther'] ?? false) {
                    $question['allowOther'] = true;
                }
                break;

            case 'Question.Text':
            case 'Question.TextField':
                $question['type'] = ($msQuestion['isMultiline'] ?? false) ? 'textarea' : 'text';
                if (isset($msQuestion['maxLength'])) {
                    $question['maxLength'] = $msQuestion['maxLength'];
                }
                break;

            case 'Question.Rating':
                $ratingType = $msQuestion['ratingType'] ?? 'Star';
                if ($ratingType === 'Star') {
                    $question['type'] = 'rating';
                    $question['ratingMax'] = $msQuestion['ratingScale'] ?? 5;
                } else {
                    $question['type'] = 'scale';
                    $question['scaleMin'] = 1;
                    $question['scaleMax'] = $msQuestion['ratingScale'] ?? 10;
                    $question['scaleMinLabel'] = $msQuestion['ratingLowLabel'] ?? '';
                    $question['scaleMaxLabel'] = $msQuestion['ratingHighLabel'] ?? '';
                }
                break;

            case 'Question.Date':
                $question['type'] = ($msQuestion['includeTime'] ?? false) ? 'datetime' : 'date';
                break;

            case 'Question.Likert':
                $question['type'] = 'matrix';
                $question['rows'] = array_map(function ($stmt, $idx) {
                    return [
                        'id' => 'r' . ($idx + 1),
                        'label' => $stmt['text'] ?? '',
                    ];
                }, $msQuestion['statements'] ?? [], array_keys($msQuestion['statements'] ?? []));
                $question['columns'] = array_map(function ($opt, $idx) {
                    return [
                        'id' => 'c' . ($idx + 1),
                        'label' => $opt['text'] ?? '',
                        'value' => (string) ($idx + 1),
                    ];
                }, $msQuestion['options'] ?? [], array_keys($msQuestion['options'] ?? []));
                break;

            case 'Question.Ranking':
                // Ranking doesn't have a direct equivalent, convert to matrix
                $question['type'] = 'matrix';
                $question['rows'] = array_map(function ($item, $idx) {
                    return [
                        'id' => 'r' . ($idx + 1),
                        'label' => $item['text'] ?? '',
                    ];
                }, $msQuestion['items'] ?? [], array_keys($msQuestion['items'] ?? []));

                $itemCount = count($msQuestion['items'] ?? []);
                $question['columns'] = [];
                for ($i = 1; $i <= $itemCount; $i++) {
                    $question['columns'][] = [
                        'id' => 'c' . $i,
                        'label' => (string) $i,
                        'value' => (string) $i,
                    ];
                }

                $warnings[] = "Question '{$question['question']}': Ranking converted to matrix (ranking functionality lost)";
                break;

            case 'Question.Net Promoter Score':
                $question['type'] = 'scale';
                $question['scaleMin'] = 0;
                $question['scaleMax'] = 10;
                $question['scaleMinLabel'] = $msQuestion['lowLabel'] ?? 'Not at all likely';
                $question['scaleMaxLabel'] = $msQuestion['highLabel'] ?? 'Extremely likely';
                break;

            case 'Question.File':
                $question['type'] = 'file';
                $question['allowedTypes'] = $msQuestion['allowedFileTypes'] ?? [];
                if (isset($msQuestion['maxFileSize'])) {
                    $question['maxFileSize'] = $msQuestion['maxFileSize'];
                }
                $warnings[] = "Question '{$question['question']}': File upload question imported, but uploaded files cannot be migrated";
                break;

            default:
                // Unknown type, default to text
                $question['type'] = 'text';
                $warnings[] = "Question '{$question['question']}': Unknown type '$msType' converted to text";
                break;
        }

        // Store raw branching info for later processing (needs question ID mapping)
        if (!empty($msQuestion['branchingRules'])) {
            $question['_msBranchingRules'] = $msQuestion['branchingRules'];
        }
        if (!empty($msQuestion['formulaInfo'])) {
            $question['_msFormulaInfo'] = $msQuestion['formulaInfo'];
        }
        if (!empty($msQuestion['showIf'])) {
            $question['_msShowIf'] = $msQuestion['showIf'];
        }
        if (!empty($msQuestion['branchInfo'])) {
            $question['_msBranchInfo'] = $msQuestion['branchInfo'];
        }
        // Store original MS Forms question ID for branching resolution
        $question['_msQuestionId'] = $msQuestion['id'] ?? '';

        return [
            'question' => $question,
            'warnings' => $warnings,
        ];
    }

    /**
     * Apply branching rules to questions after all questions are mapped
     * This converts MS Forms branching to FormVox showIf format
     *
     * MS Forms uses "goto" style branching (BranchInfo.TargetQuestionId = "jump to X after this")
     * We need to reverse-engineer this into FormVox's "showIf" style (show X when condition Y is met)
     *
     * @param array $questions FormVox questions array
     * @param array $msQuestionIdToFvId Mapping from MS Forms question ID to FormVox question ID
     * @param array $msQuestionIdToChoices Mapping from MS Forms question ID to choice mappings
     * @return array Updated questions with showIf conditions
     */
    public function applyBranchingRules(array $questions, array $msQuestionIdToFvId, array $msQuestionIdToChoices): array
    {
        // First pass: collect all branching info and build a map of
        // target question -> [source question, condition]
        $branchTargets = []; // MS target question ID -> [['sourceId' => ..., 'choiceValue' => ...], ...]

        foreach ($questions as $question) {
            $msQuestionId = $question['_msQuestionId'] ?? null;
            $branchInfo = $question['_msBranchInfo'] ?? null;

            if ($msQuestionId === null) {
                continue;
            }

            // Check for BranchInfo (simple goto branching)
            if (!empty($branchInfo)) {
                $targetId = $branchInfo['TargetQuestionId'] ?? $branchInfo['targetQuestionId'] ?? null;
                if ($targetId !== null) {
                    // This is a simple "goto" - after this question, go to target
                    // We can't directly map this to showIf, but we note it
                    $this->logger->debug('Found BranchInfo goto', [
                        'source' => $msQuestionId,
                        'target' => $targetId,
                    ]);
                }
            }

            // Check for per-choice branching in options
            // This is the real conditional branching in MS Forms
            $options = $question['options'] ?? [];
            foreach ($options as $idx => $option) {
                $choiceBranchTarget = $option['_msBranchTarget'] ?? null;
                if ($choiceBranchTarget !== null) {
                    if (!isset($branchTargets[$choiceBranchTarget])) {
                        $branchTargets[$choiceBranchTarget] = [];
                    }
                    $branchTargets[$choiceBranchTarget][] = [
                        'sourceId' => $msQuestionId,
                        'choiceValue' => $option['value'] ?? $option['label'] ?? '',
                        'choiceIndex' => $idx,
                    ];
                }
            }
        }

        // Log branch targets for debugging
        if (!empty($branchTargets)) {
            $this->logger->info('Found per-choice branching targets', [
                'targets' => array_keys($branchTargets),
            ]);
        }

        // Second pass: apply showIf conditions and clean up temporary fields
        foreach ($questions as $idx => &$question) {
            $msQuestionId = $question['_msQuestionId'] ?? null;
            $branchingRules = $question['_msBranchingRules'] ?? null;
            $formulaInfo = $question['_msFormulaInfo'] ?? null;
            $msShowIf = $question['_msShowIf'] ?? null;

            // Clean up temporary fields
            unset($question['_msBranchingRules']);
            unset($question['_msFormulaInfo']);
            unset($question['_msShowIf']);
            unset($question['_msBranchInfo']);
            unset($question['_msQuestionId']);

            // Clean up _msBranchTarget from options
            if (isset($question['options'])) {
                foreach ($question['options'] as &$option) {
                    unset($option['_msBranchTarget']);
                }
            }

            // Try to convert branching rules to showIf
            $showIf = null;

            // Check if this question is a branch target
            if ($msQuestionId !== null && isset($branchTargets[$msQuestionId])) {
                $showIf = $this->convertBranchTargetsToShowIf(
                    $branchTargets[$msQuestionId],
                    $msQuestionIdToFvId,
                    $msQuestionIdToChoices
                );
            }

            // MS Forms branching format: { "Rules": [{ "Condition": {...}, "Action": {...} }] }
            if ($showIf === null && !empty($branchingRules)) {
                $showIf = $this->convertBranchingRulesToShowIf($branchingRules, $msQuestionIdToFvId, $msQuestionIdToChoices);
            }

            // MS Forms FormulaInfo format (older style)
            if ($showIf === null && !empty($formulaInfo)) {
                $showIf = $this->convertFormulaInfoToShowIf($formulaInfo, $msQuestionIdToFvId, $msQuestionIdToChoices);
            }

            // Direct showIf (if available)
            if ($showIf === null && !empty($msShowIf)) {
                $showIf = $this->convertMsShowIfToFormVox($msShowIf, $msQuestionIdToFvId, $msQuestionIdToChoices);
            }

            if ($showIf !== null) {
                $question['showIf'] = $showIf;
            }
        }

        return $questions;
    }

    /**
     * Convert per-choice branch targets to FormVox showIf
     */
    private function convertBranchTargetsToShowIf(array $branchTargets, array $idMap, array $choiceMap): ?array
    {
        $conditions = [];

        foreach ($branchTargets as $target) {
            $sourceId = $target['sourceId'] ?? null;
            $choiceValue = $target['choiceValue'] ?? null;

            if ($sourceId === null) {
                continue;
            }

            $fvSourceId = $idMap[$sourceId] ?? null;
            if ($fvSourceId === null) {
                continue;
            }

            // Resolve choice value from choiceMap if available
            if ($choiceValue !== null && isset($choiceMap[$sourceId])) {
                // Try to find the choice value in the map
                foreach ($choiceMap[$sourceId] as $msChoiceId => $fvValue) {
                    if ($fvValue === $choiceValue) {
                        $choiceValue = $fvValue;
                        break;
                    }
                }
            }

            if ($choiceValue !== null) {
                $conditions[] = [
                    'questionId' => $fvSourceId,
                    'operator' => 'equals',
                    'value' => $choiceValue,
                ];
            }
        }

        if (empty($conditions)) {
            return null;
        }

        // Single condition
        if (count($conditions) === 1) {
            return $conditions[0];
        }

        // Multiple conditions - combine with OR (any condition met shows the question)
        return [
            'operator' => 'or',
            'conditions' => $conditions,
        ];
    }

    /**
     * Convert MS Forms BranchingRules to FormVox showIf
     */
    private function convertBranchingRulesToShowIf(array $branchingRules, array $idMap, array $choiceMap): ?array
    {
        // MS Forms branching: show this question when condition is met
        // Format: { "Rules": [{ "Condition": { "QuestionId": "...", "ChoiceId": "..." }, "Action": "Show" }] }
        $rules = $branchingRules['Rules'] ?? $branchingRules['rules'] ?? $branchingRules;

        if (!is_array($rules) || empty($rules)) {
            return null;
        }

        $conditions = [];
        foreach ($rules as $rule) {
            $condition = $rule['Condition'] ?? $rule['condition'] ?? null;
            $action = $rule['Action'] ?? $rule['action'] ?? 'Show';

            if ($condition === null || strtolower($action) !== 'show') {
                continue;
            }

            $msQuestionId = $condition['QuestionId'] ?? $condition['questionId'] ?? null;
            $msChoiceId = $condition['ChoiceId'] ?? $condition['choiceId'] ?? null;
            $msValue = $condition['Value'] ?? $condition['value'] ?? null;

            if ($msQuestionId === null) {
                continue;
            }

            $fvQuestionId = $idMap[$msQuestionId] ?? null;
            if ($fvQuestionId === null) {
                continue;
            }

            // Determine the value to compare
            $compareValue = null;
            if ($msChoiceId !== null && isset($choiceMap[$msQuestionId][$msChoiceId])) {
                $compareValue = $choiceMap[$msQuestionId][$msChoiceId];
            } elseif ($msValue !== null) {
                $compareValue = $msValue;
            } elseif ($msChoiceId !== null) {
                // Use choice ID as fallback
                $compareValue = $msChoiceId;
            }

            if ($compareValue !== null) {
                $conditions[] = [
                    'questionId' => $fvQuestionId,
                    'operator' => 'equals',
                    'value' => $compareValue,
                ];
            }
        }

        if (empty($conditions)) {
            return null;
        }

        // Single condition
        if (count($conditions) === 1) {
            return $conditions[0];
        }

        // Multiple conditions - combine with OR (any condition met shows the question)
        return [
            'operator' => 'or',
            'conditions' => $conditions,
        ];
    }

    /**
     * Convert MS Forms FormulaInfo to FormVox showIf
     */
    private function convertFormulaInfoToShowIf(array $formulaInfo, array $idMap, array $choiceMap): ?array
    {
        // FormulaInfo can contain visibility formulas
        // Format varies, try common patterns
        $formula = $formulaInfo['Formula'] ?? $formulaInfo['formula'] ??
                   $formulaInfo['VisibilityFormula'] ?? $formulaInfo['visibilityFormula'] ?? null;

        if ($formula === null) {
            return null;
        }

        // Simple pattern: "QuestionId == 'value'" or "QuestionId contains ChoiceId"
        // This is a simplified parser - MS Forms formulas can be complex
        if (is_string($formula)) {
            // Try to parse simple equality: "r12345 == 'value'"
            if (preg_match('/^([a-zA-Z0-9_-]+)\s*==\s*[\'"]?([^\'\"]+)[\'"]?$/', $formula, $matches)) {
                $msQuestionId = $matches[1];
                $value = $matches[2];

                $fvQuestionId = $idMap[$msQuestionId] ?? null;
                if ($fvQuestionId !== null) {
                    return [
                        'questionId' => $fvQuestionId,
                        'operator' => 'equals',
                        'value' => $value,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Convert MS Forms showIf to FormVox format
     */
    private function convertMsShowIfToFormVox(array $msShowIf, array $idMap, array $choiceMap): ?array
    {
        $msQuestionId = $msShowIf['questionId'] ?? $msShowIf['QuestionId'] ?? null;
        $operator = $msShowIf['operator'] ?? $msShowIf['Operator'] ?? 'equals';
        $value = $msShowIf['value'] ?? $msShowIf['Value'] ?? $msShowIf['choiceId'] ?? $msShowIf['ChoiceId'] ?? null;

        if ($msQuestionId === null) {
            return null;
        }

        $fvQuestionId = $idMap[$msQuestionId] ?? null;
        if ($fvQuestionId === null) {
            return null;
        }

        // Map operator
        $fvOperator = match (strtolower($operator)) {
            'equals', 'eq', '==' => 'equals',
            'notequals', 'neq', '!=' => 'notEquals',
            'contains' => 'contains',
            'notcontains' => 'notContains',
            'isempty', 'empty' => 'isEmpty',
            'isnotempty', 'notempty' => 'isNotEmpty',
            default => 'equals',
        };

        // Resolve choice value if needed
        if ($value !== null && isset($choiceMap[$msQuestionId][$value])) {
            $value = $choiceMap[$msQuestionId][$value];
        }

        $condition = [
            'questionId' => $fvQuestionId,
            'operator' => $fvOperator,
        ];

        if ($value !== null && !in_array($fvOperator, ['isEmpty', 'isNotEmpty'])) {
            $condition['value'] = $value;
        }

        return $condition;
    }

    /**
     * Import responses from MS Forms
     */
    private function importResponses(int $fileId, array $msResponses, array $questionIdMap): int
    {
        $count = 0;

        foreach ($msResponses as $msResponse) {
            // Map answers to FormVox question IDs
            $answers = [];
            foreach ($msResponse['answers'] ?? [] as $msQuestionId => $value) {
                $fvQuestionId = $questionIdMap[$msQuestionId] ?? null;
                if ($fvQuestionId !== null) {
                    $answers[$fvQuestionId] = $value;
                }
            }

            $response = [
                'id' => $this->generateResponseId(),
                'submitted_at' => $msResponse['submitDate'] ?? date('c'),
                'respondent' => [
                    'type' => 'anonymous',
                    'fingerprint' => 'ms-import:' . ($msResponse['id'] ?? $this->secureRandom->generate(16)),
                ],
                'answers' => $answers,
            ];

            // If we have responder info, add it
            if (!empty($msResponse['responder'])) {
                $response['respondent'] = [
                    'type' => 'external',
                    'display_name' => $msResponse['responder'],
                    'source' => 'ms-forms',
                ];
            }

            try {
                $this->formService->appendResponse($fileId, $response);
                $count++;
            } catch (\Exception $e) {
                $this->logger->warning('Failed to import response', [
                    'responseId' => $msResponse['id'] ?? 'unknown',
                    'exception' => $e,
                ]);
            }
        }

        return $count;
    }

    /**
     * Generate a unique question ID
     */
    private function generateQuestionId(): string
    {
        return 'q' . $this->secureRandom->generate(8, ISecureRandom::CHAR_ALPHANUMERIC);
    }

    /**
     * Generate a unique response ID
     */
    private function generateResponseId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
