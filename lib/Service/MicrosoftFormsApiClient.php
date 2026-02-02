<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Client for Microsoft Forms via Graph API
 *
 * Note: The Forms API has limited availability. For full functionality,
 * your tenant needs Microsoft 365 with Forms licenses.
 * Falls back to the undocumented forms.office.com API if Graph doesn't work.
 */
class MicrosoftFormsApiClient
{
    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0';
    private const FORMS_URL = 'https://forms.office.com/formapi/api';

    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * List all forms for the authenticated user
     * Uses the undocumented Microsoft Forms API at forms.office.com
     */
    public function listForms(string $accessToken): array
    {
        // The Forms API endpoint for listing user's forms
        // Reference: https://forms.office.com/formapi/api/forms
        $response = $this->requestForms('GET', '/forms', $accessToken);

        return array_map(function ($form) {
            return [
                'id' => $form['id'] ?? '',
                'title' => $form['title'] ?? 'Untitled',
                'description' => $form['description'] ?? '',
                'createdDate' => $form['createdDate'] ?? null,
                'modifiedDate' => $form['modifiedDate'] ?? null,
                'responseCount' => $form['responseCount'] ?? 0,
            ];
        }, $response['value'] ?? $response ?? []);
    }

    /**
     * Get a specific form
     */
    public function getForm(string $accessToken, string $formId): array
    {
        $response = $this->requestForms('GET', "/forms('{$formId}')", $accessToken);

        $this->logger->debug('MS Forms getForm response', ['response' => $response]);

        return [
            'id' => $response['id'] ?? $formId,
            'title' => $response['title'] ?? 'Untitled',
            'description' => $response['description'] ?? '',
            'createdDate' => $response['createdDate'] ?? null,
            'modifiedDate' => $response['modifiedDate'] ?? null,
            'settings' => [
                'isPublic' => $response['isPublic'] ?? false,
                'isAnonymous' => $response['settings']['isAnonymous'] ?? true,
                'allowMultipleResponses' => $response['settings']['allowMultipleResponses'] ?? false,
            ],
            // Store raw questions if embedded in form response
            '_rawQuestions' => $response['questions'] ?? [],
        ];
    }

    /**
     * Get questions for a form
     */
    public function getQuestions(string $accessToken, string $formId): array
    {
        // First try the dedicated questions endpoint
        try {
            $response = $this->requestForms('GET', "/forms('{$formId}')/questions", $accessToken);
            $this->logger->debug('MS Forms getQuestions response', ['response' => $response]);

            // Debug: Write raw response to file
            $debugFile = '/tmp/ms_forms_questions_raw.json';
            file_put_contents($debugFile, json_encode($response, JSON_PRETTY_PRINT));

            $questions = $response['value'] ?? $response ?? [];
            if (!empty($questions)) {
                return array_map(function ($question) {
                    return $this->normalizeQuestion($question);
                }, $questions);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Questions endpoint failed, trying alternative', ['error' => $e->getMessage()]);
        }

        // Fallback: get form with expanded questions
        $formResponse = $this->requestForms('GET', "/forms('{$formId}')?expand=questions", $accessToken);
        $this->logger->debug('MS Forms expanded form response', ['response' => $formResponse]);

        // Debug: Write raw response to file
        $debugFile = '/tmp/ms_forms_expanded_raw.json';
        file_put_contents($debugFile, json_encode($formResponse, JSON_PRETTY_PRINT));

        $questions = $formResponse['questions'] ?? [];
        return array_map(function ($question) {
            return $this->normalizeQuestion($question);
        }, $questions);
    }

    /**
     * Get responses for a form
     */
    public function getResponses(string $accessToken, string $formId): array
    {
        $response = $this->requestForms('GET', "/forms('{$formId}')/responses", $accessToken);

        return array_map(function ($resp) {
            return [
                'id' => $resp['id'] ?? '',
                'submitDate' => $resp['submitDate'] ?? null,
                'responder' => $resp['responder'] ?? null,
                'answers' => $this->normalizeAnswers($resp['answers'] ?? []),
            ];
        }, $response['value'] ?? $response ?? []);
    }

    /**
     * Normalize a question from MS Forms format
     */
    private function normalizeQuestion(array $question): array
    {
        // Parse questionInfo - it's a JSON-encoded STRING in the MS Forms API response!
        $questionInfo = [];
        if (!empty($question['questionInfo'])) {
            if (is_string($question['questionInfo'])) {
                $questionInfo = json_decode($question['questionInfo'], true) ?? [];
            } elseif (is_array($question['questionInfo'])) {
                $questionInfo = $question['questionInfo'];
            }
        }

        // MS Forms uses different field names - try multiple options
        $type = $question['type'] ?? $question['questionType'] ?? $question['@odata.type'] ?? 'Question.Text';

        $normalized = [
            'id' => $question['id'] ?? '',
            'msType' => $type,
            'title' => $question['title'] ?? $questionInfo['Title'] ?? $question['questionText'] ?? $question['text'] ?? '',
            'subtitle' => $question['subtitle'] ?? $questionInfo['Subtitle'] ?? $question['description'] ?? '',
            'isRequired' => $question['isRequired'] ?? $questionInfo['Required'] ?? $question['required'] ?? false,
            'order' => $question['order'] ?? $questionInfo['Order'] ?? $question['sequence'] ?? 0,
            'groupId' => $question['groupId'] ?? null, // For matrix/likert row questions
        ];

        // Debug: Log full questionInfo to understand branching structure
        $this->logger->debug('MS Forms questionInfo for ' . ($normalized['title'] ?? 'unknown'), [
            'questionInfo_keys' => is_array($questionInfo) ? array_keys($questionInfo) : 'not_array',
            'question_keys' => array_keys($question),
            'questionInfo' => $questionInfo,
        ]);

        // Extract branching/conditional logic
        // MS Forms stores branching in multiple ways:
        // 1. BranchInfo.TargetQuestionId - "goto" style (jump to question X after this)
        // 2. BranchingRules - conditional show/hide rules
        // 3. FormulaInfo - visibility formulas
        // 4. Choices can have BranchTarget per choice for conditional branching

        $branchingRules = $questionInfo['BranchingRules'] ?? $questionInfo['branchingRules'] ??
                          $question['branchingRules'] ?? [];
        $formulaInfo = $questionInfo['FormulaInfo'] ?? $questionInfo['formulaInfo'] ??
                       $question['formulaInfo'] ?? null;
        $branchInfo = $questionInfo['BranchInfo'] ?? $questionInfo['branchInfo'] ??
                      $question['branchInfo'] ?? null;

        if (!empty($branchingRules)) {
            $normalized['branchingRules'] = $branchingRules;
        }
        if (!empty($formulaInfo)) {
            $normalized['formulaInfo'] = $formulaInfo;
        }
        if (!empty($branchInfo)) {
            $normalized['branchInfo'] = $branchInfo;
        }

        // Also check for ShowIf conditions directly
        $showIf = $questionInfo['ShowIf'] ?? $questionInfo['showIf'] ??
                  $question['showIf'] ?? null;
        if (!empty($showIf)) {
            $normalized['showIf'] = $showIf;
        }

        // Get choices from parsed questionInfo - MS Forms uses "Choices" with capital C
        // Each choice has "Description" field for the text
        $choices = $questionInfo['Choices'] ?? $questionInfo['choices'] ??
                   $question['choices'] ?? $question['options'] ?? [];

        // Handle different question types
        switch ($type) {
            case 'Question.Choice':
            case 'choice':
            case 'multipleChoice':
                $normalized['choices'] = array_map(function ($choice) {
                    $choiceData = [
                        'id' => $choice['id'] ?? $choice['Id'] ?? '',
                        'text' => $choice['Description'] ?? $choice['text'] ?? $choice['displayText'] ?? $choice['value'] ?? '',
                        'value' => $choice['Description'] ?? $choice['value'] ?? $choice['text'] ?? '',
                    ];
                    // Capture branching target per choice (for conditional branching)
                    $branchTarget = $choice['BranchTarget'] ?? $choice['branchTarget'] ??
                                   $choice['TargetQuestionId'] ?? $choice['targetQuestionId'] ?? null;
                    if ($branchTarget !== null) {
                        $choiceData['branchTarget'] = $branchTarget;
                    }
                    return $choiceData;
                }, $choices);
                // ChoiceType: 0 = single, 1 = multiple (checkbox)
                $isMultiple = ($questionInfo['ChoiceType'] ?? 0) === 1 ||
                              $question['allowMultipleSelection'] ?? $question['isMultiSelect'] ?? false;
                $normalized['allowMultipleSelection'] = $isMultiple;
                $normalized['allowOther'] = $questionInfo['AllowOtherAnswer'] ?? $question['allowOther'] ?? $question['hasOtherOption'] ?? false;
                break;

            case 'Question.Text':
            case 'Question.TextField':
                $normalized['isMultiline'] = $questionInfo['Multiline'] ?? $question['isMultiline'] ?? false;
                $normalized['maxLength'] = $questionInfo['MaxLength'] ?? $question['maxLength'] ?? null;
                break;

            case 'Question.Rating':
                $ratingType = $questionInfo['RatingType'] ?? $question['ratingType'] ?? 'Star';
                $normalized['ratingType'] = $ratingType;
                $normalized['ratingScale'] = $questionInfo['RatingScale'] ?? $question['ratingScale'] ?? 5;
                $normalized['ratingLowLabel'] = $questionInfo['RatingLowLabel'] ?? $question['ratingLowLabel'] ?? '';
                $normalized['ratingHighLabel'] = $questionInfo['RatingHighLabel'] ?? $question['ratingHighLabel'] ?? '';
                break;

            case 'Question.Date':
                $normalized['includeTime'] = $questionInfo['IncludeTime'] ?? $question['includeTime'] ?? false;
                break;

            case 'Question.Likert':
                $statements = $questionInfo['Statements'] ?? $question['statements'] ?? [];
                $options = $questionInfo['Options'] ?? $question['options'] ?? [];
                $normalized['statements'] = array_map(function ($stmt) {
                    return [
                        'id' => $stmt['Id'] ?? $stmt['id'] ?? '',
                        'text' => $stmt['Description'] ?? $stmt['text'] ?? '',
                    ];
                }, $statements);
                $normalized['options'] = array_map(function ($opt) {
                    return [
                        'id' => $opt['Id'] ?? $opt['id'] ?? '',
                        'text' => $opt['Description'] ?? $opt['text'] ?? '',
                    ];
                }, $options);
                break;

            case 'Question.Ranking':
                $items = $questionInfo['Items'] ?? $questionInfo['Choices'] ?? $question['items'] ?? [];
                $normalized['items'] = array_map(function ($item) {
                    return [
                        'id' => $item['Id'] ?? $item['id'] ?? '',
                        'text' => $item['Description'] ?? $item['text'] ?? '',
                    ];
                }, $items);
                break;

            case 'Question.Net Promoter Score':
            case 'Question.NPS':
                $normalized['lowLabel'] = $questionInfo['LowLabel'] ?? $question['lowLabel'] ?? 'Not at all likely';
                $normalized['highLabel'] = $questionInfo['HighLabel'] ?? $question['highLabel'] ?? 'Extremely likely';
                break;

            case 'Question.File':
                $normalized['allowedFileTypes'] = $questionInfo['AllowedFileTypes'] ?? $question['allowedFileTypes'] ?? [];
                $normalized['maxFileSize'] = $questionInfo['MaxFileSize'] ?? $question['maxFileSize'] ?? null;
                break;

            case 'Question.MatrixChoiceGroup':
                // This is a matrix/likert group header - rows come as separate MatrixChoice questions with groupId
                $normalized['isMatrixGroup'] = true;
                break;

            case 'Question.MatrixChoice':
                // This is a row within a matrix/likert group
                $normalized['isMatrixRow'] = true;
                break;

            case 'Question.ColumnGroup':
                // Section header - skip or convert to description
                $normalized['isSectionHeader'] = true;
                break;
        }

        return $normalized;
    }

    /**
     * Normalize answers from MS Forms format
     */
    private function normalizeAnswers(array $answers): array
    {
        $normalized = [];

        foreach ($answers as $answer) {
            $questionId = $answer['questionId'] ?? '';
            $value = $answer['answer'] ?? $answer['value'] ?? '';

            // Handle array answers (multiple choice, ranking)
            if (isset($answer['answers']) && is_array($answer['answers'])) {
                $value = $answer['answers'];
            }

            $normalized[$questionId] = $value;
        }

        return $normalized;
    }

    /**
     * Make a request to the MS Forms API (forms.office.com)
     */
    private function requestForms(string $method, string $endpoint, string $accessToken, array $data = []): array
    {
        return $this->doRequest($method, self::FORMS_URL . $endpoint, $accessToken, $data);
    }

    /**
     * Make a request to Microsoft Graph API
     */
    private function requestGraph(string $method, string $endpoint, string $accessToken, array $data = []): array
    {
        return $this->doRequest($method, self::GRAPH_URL . $endpoint, $accessToken, $data);
    }

    /**
     * Execute HTTP request
     */
    private function doRequest(string $method, string $url, string $accessToken, array $data = []): array
    {
        $client = $this->clientService->newClient();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $options['body'] = json_encode($data);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, $options),
                'POST' => $client->post($url, $options),
                'PUT' => $client->put($url, $options),
                'DELETE' => $client->delete($url, $options),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: $method"),
            };

            $body = $response->getBody();
            return json_decode($body, true) ?? [];
        } catch (\Exception $e) {
            $this->logger->error('API request failed', [
                'method' => $method,
                'url' => $url,
                'exception' => $e,
            ]);
            throw new \RuntimeException('API request failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
