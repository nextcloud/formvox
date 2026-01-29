<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\IRequest;

class ResponseService
{
    private FormService $formService;
    private IndexService $indexService;
    private WebhookService $webhookService;

    public function __construct(
        FormService $formService,
        IndexService $indexService,
        WebhookService $webhookService
    ) {
        $this->formService = $formService;
        $this->indexService = $indexService;
        $this->webhookService = $webhookService;
    }

    /**
     * Submit a response as an anonymous user
     */
    public function submitAnonymous(int $fileId, array $answers, IRequest $request, string $shareToken): array
    {
        $form = $this->formService->load($fileId);
        return $this->submitAnonymousWithForm($fileId, $form, $answers, $request, $shareToken);
    }

    /**
     * Submit a response as an anonymous user (with form already loaded)
     */
    public function submitAnonymousWithForm(int $fileId, array $form, array $answers, IRequest $request, string $shareToken): array
    {
        // Check if form accepts responses
        $this->validateFormAcceptsResponses($form);

        // Calculate fingerprint
        $fingerprint = $this->calculateFingerprint($request, $shareToken);

        // Check for duplicate submission
        if (!($form['settings']['allow_multiple'] ?? false)) {
            if ($this->indexService->hasFingerprint($form, $fingerprint)) {
                throw new \RuntimeException('You have already submitted a response to this form');
            }
        }

        // Validate answers
        $this->validateAnswers($form, $answers);

        // Create response
        $response = [
            'id' => $this->generateUuid(),
            'submitted_at' => date('c'),
            'respondent' => [
                'type' => 'anonymous',
                'fingerprint' => $fingerprint,
            ],
            'answers' => $answers,
        ];

        // Calculate score if quiz mode
        if ($this->isQuizMode($form)) {
            $response['score'] = $this->calculateScore($form, $answers);
        }

        // Append response (use public method since no user is logged in)
        $result = $this->formService->appendResponsePublic($fileId, $response);

        // Trigger webhook
        $this->webhookService->trigger($form, 'response.created', $response);

        return $result;
    }

    /**
     * Submit a response as an authenticated user
     */
    public function submitAuthenticated(int $fileId, array $answers, string $userId, string $displayName): array
    {
        $form = $this->formService->load($fileId);

        // Check if form accepts responses
        $this->validateFormAcceptsResponses($form);

        // Check for duplicate submission
        if (!($form['settings']['allow_multiple'] ?? false)) {
            if ($this->indexService->hasUserResponse($form, $userId)) {
                throw new \RuntimeException('You have already submitted a response to this form');
            }
        }

        // Validate answers
        $this->validateAnswers($form, $answers);

        // Create response
        $response = [
            'id' => $this->generateUuid(),
            'submitted_at' => date('c'),
            'respondent' => [
                'type' => 'user',
                'user_id' => $userId,
                'display_name' => $displayName,
            ],
            'answers' => $answers,
        ];

        // Calculate score if quiz mode
        if ($this->isQuizMode($form)) {
            $response['score'] = $this->calculateScore($form, $answers);
        }

        // Append response
        $result = $this->formService->appendResponse($fileId, $response);

        // Trigger webhook
        $this->webhookService->trigger($form, 'response.created', $response);

        return $result;
    }

    /**
     * Get summary statistics for a form
     */
    public function getSummary(int $fileId): array
    {
        $form = $this->formService->load($fileId);
        return $this->buildSummary($form);
    }

    /**
     * Get summary statistics for a form (public access)
     */
    public function getSummaryPublic(int $fileId): array
    {
        $form = $this->formService->loadPublic($fileId);
        return $this->buildSummary($form);
    }

    /**
     * Build summary from form data
     */
    private function buildSummary(array $form): array
    {

        $summary = [
            'responseCount' => $this->indexService->getResponseCount($form),
            'lastResponseAt' => $form['_index']['last_response_at'] ?? null,
            'questions' => [],
        ];

        foreach ($form['questions'] ?? [] as $question) {
            $questionId = $question['id'];
            $questionSummary = [
                'id' => $questionId,
                'type' => $question['type'],
                'question' => $question['question'],
                'answerCounts' => $this->indexService->getAnswerStats($form, $questionId),
            ];

            // For numeric types, calculate average
            if (in_array($question['type'], ['number', 'scale', 'rating'])) {
                $stats = $this->calculateNumericStats($form, $questionId);
                $questionSummary['average'] = $stats['average'];
                $questionSummary['min'] = $stats['min'];
                $questionSummary['max'] = $stats['max'];
            }

            $summary['questions'][] = $questionSummary;
        }

        return $summary;
    }

    /**
     * Get all responses for a form
     */
    public function getResponses(int $fileId, ?string $dateFilter = null): array
    {
        $form = $this->formService->load($fileId);

        if ($dateFilter !== null) {
            return $this->indexService->getResponsesByDate($form, $dateFilter);
        }

        return $form['responses'] ?? [];
    }

    /**
     * Export responses to CSV format
     */
    public function exportCsv(int $fileId): string
    {
        $form = $this->formService->load($fileId);
        $responses = $form['responses'] ?? [];

        if (empty($responses)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Header row
        $headers = ['Response ID', 'Submitted At', 'Respondent Type', 'Respondent ID'];
        foreach ($form['questions'] ?? [] as $question) {
            $headers[] = $question['question'];
        }
        fputcsv($output, $headers);

        // Data rows
        foreach ($responses as $response) {
            $row = [
                $response['id'],
                $response['submitted_at'],
                $response['respondent']['type'],
                $response['respondent']['user_id'] ?? $response['respondent']['fingerprint'] ?? '',
            ];

            foreach ($form['questions'] ?? [] as $question) {
                $answer = $response['answers'][$question['id']] ?? '';
                if (is_array($answer)) {
                    $answer = implode(', ', $answer);
                }
                $row[] = $answer;
            }

            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export responses to JSON format
     */
    public function exportJson(int $fileId): string
    {
        $form = $this->formService->load($fileId);

        return json_encode([
            'title' => $form['title'],
            'exportedAt' => date('c'),
            'questions' => $form['questions'],
            'responses' => $form['responses'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validate that the form accepts responses
     */
    private function validateFormAcceptsResponses(array $form): void
    {
        $settings = $form['settings'] ?? [];

        // Check expiration
        if (!empty($settings['expires_at'])) {
            $expiresAt = new \DateTime($settings['expires_at']);
            if ($expiresAt < new \DateTime()) {
                throw new \RuntimeException('This form has expired');
            }
        }
    }

    /**
     * Validate answers against form questions
     */
    private function validateAnswers(array $form, array $answers): void
    {
        $questionIds = array_column($form['questions'] ?? [], 'id');
        $questionsById = [];
        foreach ($form['questions'] ?? [] as $question) {
            $questionsById[$question['id']] = $question;
        }

        // Check required questions
        foreach ($form['questions'] ?? [] as $question) {
            $questionId = $question['id'];

            // Skip if question is conditionally hidden
            if ($this->isQuestionHidden($question, $answers, $questionsById)) {
                continue;
            }

            if ($question['required'] ?? false) {
                if (!isset($answers[$questionId]) || $answers[$questionId] === '' || $answers[$questionId] === []) {
                    throw new \RuntimeException("Question '{$question['question']}' is required");
                }
            }
        }

        // Validate answer types
        foreach ($answers as $questionId => $answer) {
            if (!in_array($questionId, $questionIds)) {
                continue; // Skip unknown questions
            }

            $question = $questionsById[$questionId];
            $this->validateAnswerType($question, $answer);
        }
    }

    /**
     * Validate answer matches question type
     */
    private function validateAnswerType(array $question, $answer): void
    {
        $type = $question['type'];

        switch ($type) {
            case 'number':
            case 'scale':
            case 'rating':
                if ($answer !== '' && !is_numeric($answer)) {
                    throw new \RuntimeException("Invalid answer type for question '{$question['question']}'");
                }
                break;

            case 'multiple':
                if (!is_array($answer)) {
                    throw new \RuntimeException("Multiple choice question requires array answer");
                }
                break;

            case 'date':
                if ($answer !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $answer)) {
                    throw new \RuntimeException("Invalid date format for question '{$question['question']}'");
                }
                break;

            case 'datetime':
                if ($answer !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $answer)) {
                    throw new \RuntimeException("Invalid datetime format for question '{$question['question']}'");
                }
                break;

            case 'time':
                if ($answer !== '' && !preg_match('/^\d{2}:\d{2}$/', $answer)) {
                    throw new \RuntimeException("Invalid time format for question '{$question['question']}'");
                }
                break;
        }
    }

    /**
     * Check if a question is hidden due to conditional logic
     */
    private function isQuestionHidden(array $question, array $answers, array $questionsById): bool
    {
        if (!isset($question['showIf'])) {
            return false;
        }

        return !$this->evaluateCondition($question['showIf'], $answers);
    }

    /**
     * Evaluate a conditional expression
     */
    private function evaluateCondition(array $condition, array $answers): bool
    {
        // Combined conditions (AND/OR)
        if (isset($condition['operator']) && isset($condition['conditions'])) {
            $op = strtolower($condition['operator']);
            $results = array_map(
                fn($c) => $this->evaluateCondition($c, $answers),
                $condition['conditions']
            );

            if ($op === 'and') {
                return !in_array(false, $results, true);
            } elseif ($op === 'or') {
                return in_array(true, $results, true);
            }
        }

        // Simple condition
        if (isset($condition['questionId'])) {
            $questionId = $condition['questionId'];
            $operator = $condition['operator'];
            $value = $condition['value'] ?? null;
            $answer = $answers[$questionId] ?? null;

            switch ($operator) {
                case 'equals':
                    return $answer === $value;
                case 'notEquals':
                    return $answer !== $value;
                case 'contains':
                    return is_string($answer) && str_contains($answer, $value);
                case 'notContains':
                    return !is_string($answer) || !str_contains($answer, $value);
                case 'isEmpty':
                    return $answer === null || $answer === '' || $answer === [];
                case 'isNotEmpty':
                    return $answer !== null && $answer !== '' && $answer !== [];
                case 'greaterThan':
                    return is_numeric($answer) && $answer > $value;
                case 'lessThan':
                    return is_numeric($answer) && $answer < $value;
                case 'in':
                    return is_array($value) && in_array($answer, $value);
                case 'notIn':
                    return is_array($value) && !in_array($answer, $value);
            }
        }

        return true;
    }

    /**
     * Calculate fingerprint for anonymous users
     */
    private function calculateFingerprint(IRequest $request, string $shareToken): string
    {
        $data = implode('|', [
            $request->getRemoteAddress(),
            $request->getHeader('User-Agent'),
            $shareToken,
        ]);

        return 'sha256:' . hash('sha256', $data);
    }

    /**
     * Check if form is in quiz mode
     */
    private function isQuizMode(array $form): bool
    {
        foreach ($form['questions'] ?? [] as $question) {
            if (isset($question['options'])) {
                foreach ($question['options'] as $option) {
                    if (isset($option['score'])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Calculate score for quiz mode
     */
    private function calculateScore(array $form, array $answers): array
    {
        $totalScore = 0;
        $maxScore = 0;
        $questionScores = [];

        foreach ($form['questions'] ?? [] as $question) {
            if (!isset($question['options'])) {
                continue;
            }

            $questionId = $question['id'];
            $answer = $answers[$questionId] ?? null;
            $questionScore = 0;
            $questionMaxScore = 0;

            foreach ($question['options'] as $option) {
                $optionScore = $option['score'] ?? 0;
                $questionMaxScore = max($questionMaxScore, $optionScore);

                if ($question['type'] === 'multiple') {
                    if (is_array($answer) && in_array($option['value'], $answer)) {
                        $questionScore += $optionScore;
                    }
                } else {
                    if ($answer === $option['value']) {
                        $questionScore = $optionScore;
                    }
                }
            }

            $totalScore += $questionScore;
            $maxScore += $questionMaxScore;
            $questionScores[$questionId] = $questionScore;
        }

        return [
            'total' => $totalScore,
            'max' => $maxScore,
            'percentage' => $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 1) : 0,
            'byQuestion' => $questionScores,
        ];
    }

    /**
     * Calculate numeric statistics for a question
     */
    private function calculateNumericStats(array $form, string $questionId): array
    {
        $values = [];

        foreach ($form['responses'] ?? [] as $response) {
            $answer = $response['answers'][$questionId] ?? null;
            if ($answer !== null && is_numeric($answer)) {
                $values[] = (float)$answer;
            }
        }

        if (empty($values)) {
            return ['average' => null, 'min' => null, 'max' => null];
        }

        return [
            'average' => round(array_sum($values) / count($values), 2),
            'min' => min($values),
            'max' => max($values),
        ];
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
}
