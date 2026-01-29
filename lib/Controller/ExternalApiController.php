<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\WebhookService;

/**
 * External API controller for third-party integrations
 * All endpoints require API key authentication via X-FormVox-API-Key header
 */
class ExternalApiController extends Controller
{
    private FormService $formService;
    private ApiKeyService $apiKeyService;
    private WebhookService $webhookService;

    public function __construct(
        IRequest $request,
        FormService $formService,
        ApiKeyService $apiKeyService,
        WebhookService $webhookService
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->formService = $formService;
        $this->apiKeyService = $apiKeyService;
        $this->webhookService = $webhookService;
    }

    /**
     * Authenticate request and return form data if valid
     * Returns [form, keyConfig] or DataResponse on error
     */
    private function authenticate(int $fileId): array|DataResponse
    {
        $apiKey = $this->request->getHeader('X-FormVox-API-Key');

        if (empty($apiKey)) {
            return new DataResponse(
                ['error' => 'Missing API key. Provide X-FormVox-API-Key header.'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            // Load form using admin access (API key grants access)
            $form = $this->formService->loadPublic($fileId);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => 'Form not found'],
                Http::STATUS_NOT_FOUND
            );
        }

        // Check API keys
        $apiKeys = $form['settings']['api_keys'] ?? [];
        if (empty($apiKeys)) {
            return new DataResponse(
                ['error' => 'API access not enabled for this form'],
                Http::STATUS_FORBIDDEN
            );
        }

        $keyConfig = $this->apiKeyService->findValidKey($apiKey, $apiKeys);
        if ($keyConfig === null) {
            return new DataResponse(
                ['error' => 'Invalid API key'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        return ['form' => $form, 'keyConfig' => $keyConfig];
    }

    /**
     * GET /api/v1/external/forms/{fileId}
     * Get form definition (questions, settings)
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 100, period: 60)]
    public function getForm(int $fileId): DataResponse
    {
        $auth = $this->authenticate($fileId);
        if ($auth instanceof DataResponse) {
            return $auth;
        }

        $form = $auth['form'];
        $keyConfig = $auth['keyConfig'];

        if (!$this->apiKeyService->hasPermission($keyConfig, 'read_form')) {
            return new DataResponse(
                ['error' => 'API key does not have read_form permission'],
                Http::STATUS_FORBIDDEN
            );
        }

        // Return sanitized form data
        return new DataResponse([
            'id' => $fileId,
            'title' => $form['title'] ?? '',
            'description' => $form['description'] ?? '',
            'questions' => $form['questions'] ?? [],
            'settings' => [
                'anonymous' => $form['settings']['anonymous'] ?? true,
                'allow_multiple' => $form['settings']['allow_multiple'] ?? false,
            ],
        ]);
    }

    /**
     * GET /api/v1/external/forms/{fileId}/schema
     * Get JSON Schema for form validation
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 100, period: 60)]
    public function getSchema(int $fileId): DataResponse
    {
        $auth = $this->authenticate($fileId);
        if ($auth instanceof DataResponse) {
            return $auth;
        }

        $form = $auth['form'];
        $keyConfig = $auth['keyConfig'];

        if (!$this->apiKeyService->hasPermission($keyConfig, 'read_form')) {
            return new DataResponse(
                ['error' => 'API key does not have read_form permission'],
                Http::STATUS_FORBIDDEN
            );
        }

        // Generate JSON Schema from questions
        $schema = $this->generateJsonSchema($form);

        return new DataResponse($schema);
    }

    /**
     * GET /api/v1/external/forms/{fileId}/responses
     * Get all responses
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 100, period: 60)]
    public function getResponses(int $fileId): DataResponse
    {
        $auth = $this->authenticate($fileId);
        if ($auth instanceof DataResponse) {
            return $auth;
        }

        $form = $auth['form'];
        $keyConfig = $auth['keyConfig'];

        if (!$this->apiKeyService->hasPermission($keyConfig, 'read_responses')) {
            return new DataResponse(
                ['error' => 'API key does not have read_responses permission'],
                Http::STATUS_FORBIDDEN
            );
        }

        $responses = $form['responses'] ?? [];

        return new DataResponse([
            'count' => count($responses),
            'responses' => $responses,
        ]);
    }

    /**
     * GET /api/v1/external/forms/{fileId}/responses/{responseId}
     * Get single response
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 100, period: 60)]
    public function getResponse(int $fileId, string $responseId): DataResponse
    {
        $auth = $this->authenticate($fileId);
        if ($auth instanceof DataResponse) {
            return $auth;
        }

        $form = $auth['form'];
        $keyConfig = $auth['keyConfig'];

        if (!$this->apiKeyService->hasPermission($keyConfig, 'read_responses')) {
            return new DataResponse(
                ['error' => 'API key does not have read_responses permission'],
                Http::STATUS_FORBIDDEN
            );
        }

        $responses = $form['responses'] ?? [];
        $response = null;

        foreach ($responses as $r) {
            if (($r['id'] ?? '') === $responseId) {
                $response = $r;
                break;
            }
        }

        if ($response === null) {
            return new DataResponse(
                ['error' => 'Response not found'],
                Http::STATUS_NOT_FOUND
            );
        }

        return new DataResponse($response);
    }

    /**
     * POST /api/v1/external/forms/{fileId}/responses
     * Create new response
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 50, period: 60)]
    public function createResponse(int $fileId): DataResponse
    {
        $auth = $this->authenticate($fileId);
        if ($auth instanceof DataResponse) {
            return $auth;
        }

        $form = $auth['form'];
        $keyConfig = $auth['keyConfig'];

        if (!$this->apiKeyService->hasPermission($keyConfig, 'write_responses')) {
            return new DataResponse(
                ['error' => 'API key does not have write_responses permission'],
                Http::STATUS_FORBIDDEN
            );
        }

        $answers = $this->request->getParam('answers');
        if (!is_array($answers)) {
            return new DataResponse(
                ['error' => 'answers parameter is required and must be an object'],
                Http::STATUS_BAD_REQUEST
            );
        }

        // Create the response
        $responseId = bin2hex(random_bytes(16));
        $newResponse = [
            'id' => $responseId,
            'submitted_at' => (new \DateTime())->format(\DateTime::ATOM),
            'answers' => $answers,
            'source' => 'api',
            'api_key_id' => $keyConfig['id'] ?? null,
        ];

        // Add to form
        $form['responses'] = $form['responses'] ?? [];
        $form['responses'][] = $newResponse;

        // Save
        $this->formService->savePublic($fileId, $form);

        // Trigger webhooks
        $this->webhookService->trigger($form, 'response.created', $newResponse);

        return new DataResponse($newResponse, Http::STATUS_CREATED);
    }

    /**
     * PUT /api/v1/external/forms/{fileId}/responses/{responseId}
     * Update existing response
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 50, period: 60)]
    public function updateResponse(int $fileId, string $responseId): DataResponse
    {
        $auth = $this->authenticate($fileId);
        if ($auth instanceof DataResponse) {
            return $auth;
        }

        $form = $auth['form'];
        $keyConfig = $auth['keyConfig'];

        if (!$this->apiKeyService->hasPermission($keyConfig, 'write_responses')) {
            return new DataResponse(
                ['error' => 'API key does not have write_responses permission'],
                Http::STATUS_FORBIDDEN
            );
        }

        $answers = $this->request->getParam('answers');
        if (!is_array($answers)) {
            return new DataResponse(
                ['error' => 'answers parameter is required and must be an object'],
                Http::STATUS_BAD_REQUEST
            );
        }

        // Find and update response
        $responses = $form['responses'] ?? [];
        $found = false;
        $updatedResponse = null;

        foreach ($responses as $i => $r) {
            if (($r['id'] ?? '') === $responseId) {
                $responses[$i]['answers'] = $answers;
                $responses[$i]['updated_at'] = (new \DateTime())->format(\DateTime::ATOM);
                $responses[$i]['updated_by'] = 'api:' . ($keyConfig['id'] ?? 'unknown');
                $updatedResponse = $responses[$i];
                $found = true;
                break;
            }
        }

        if (!$found) {
            return new DataResponse(
                ['error' => 'Response not found'],
                Http::STATUS_NOT_FOUND
            );
        }

        $form['responses'] = $responses;
        $this->formService->savePublic($fileId, $form);

        // Trigger webhooks
        $this->webhookService->trigger($form, 'response.updated', $updatedResponse);

        return new DataResponse($updatedResponse);
    }

    /**
     * DELETE /api/v1/external/forms/{fileId}/responses/{responseId}
     * Delete response
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 50, period: 60)]
    public function deleteResponse(int $fileId, string $responseId): DataResponse
    {
        $auth = $this->authenticate($fileId);
        if ($auth instanceof DataResponse) {
            return $auth;
        }

        $form = $auth['form'];
        $keyConfig = $auth['keyConfig'];

        if (!$this->apiKeyService->hasPermission($keyConfig, 'delete_responses')) {
            return new DataResponse(
                ['error' => 'API key does not have delete_responses permission'],
                Http::STATUS_FORBIDDEN
            );
        }

        // Find and remove response
        $responses = $form['responses'] ?? [];
        $found = false;
        $deletedResponse = null;

        foreach ($responses as $i => $r) {
            if (($r['id'] ?? '') === $responseId) {
                $deletedResponse = $r;
                array_splice($responses, $i, 1);
                $found = true;
                break;
            }
        }

        if (!$found) {
            return new DataResponse(
                ['error' => 'Response not found'],
                Http::STATUS_NOT_FOUND
            );
        }

        $form['responses'] = $responses;
        $this->formService->savePublic($fileId, $form);

        // Trigger webhooks
        $this->webhookService->trigger($form, 'response.deleted', $deletedResponse);

        return new DataResponse(['success' => true]);
    }

    /**
     * Generate JSON Schema from form questions
     */
    private function generateJsonSchema(array $form): array
    {
        $properties = [];
        $required = [];

        foreach ($form['questions'] ?? [] as $question) {
            $questionId = $question['id'] ?? '';
            $questionType = $question['type'] ?? 'text';

            $prop = [
                'title' => $question['title'] ?? '',
                'description' => $question['description'] ?? '',
            ];

            switch ($questionType) {
                case 'text':
                case 'textarea':
                    $prop['type'] = 'string';
                    break;
                case 'number':
                    $prop['type'] = 'number';
                    break;
                case 'date':
                    $prop['type'] = 'string';
                    $prop['format'] = 'date';
                    break;
                case 'time':
                    $prop['type'] = 'string';
                    $prop['format'] = 'time';
                    break;
                case 'datetime':
                    $prop['type'] = 'string';
                    $prop['format'] = 'date-time';
                    break;
                case 'single_choice':
                case 'dropdown':
                    $prop['type'] = 'string';
                    $options = array_column($question['options'] ?? [], 'text');
                    if (!empty($options)) {
                        $prop['enum'] = $options;
                    }
                    break;
                case 'multiple_choice':
                    $prop['type'] = 'array';
                    $prop['items'] = ['type' => 'string'];
                    $options = array_column($question['options'] ?? [], 'text');
                    if (!empty($options)) {
                        $prop['items']['enum'] = $options;
                    }
                    break;
                case 'rating':
                case 'scale':
                    $prop['type'] = 'integer';
                    $prop['minimum'] = $question['min'] ?? 1;
                    $prop['maximum'] = $question['max'] ?? 5;
                    break;
                case 'file':
                    $prop['type'] = 'array';
                    $prop['items'] = [
                        'type' => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string'],
                            'size' => ['type' => 'integer'],
                            'mimeType' => ['type' => 'string'],
                        ],
                    ];
                    break;
                default:
                    $prop['type'] = 'string';
            }

            $properties[$questionId] = $prop;

            if ($question['required'] ?? false) {
                $required[] = $questionId;
            }
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'title' => $form['title'] ?? 'Form',
            'properties' => $properties,
            'required' => $required,
        ];
    }
}
