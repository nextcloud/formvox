<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\AccountAccess;
use OCA\FormVox\Service\AnswerValidator;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;

/**
 * External API controller for third-party integrations
 * All endpoints require API key authentication via X-FormVox-API-Key header
 */
class ExternalApiController extends Controller {
	/** The most responses one page holds. */
	private const MAX_PAGE = 1000;

	public function __construct(
		IRequest $request,
		private FormRepository $formRepository,
		private ResponseService $responseService,
		private ApiKeyService $apiKeyService,
		private AccountAccess $access,
		private FormSanitizer $sanitizer,
		private IL10N $l,
		private \OCA\FormVox\Pdf\ResponsePdf $pdfs,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * A key works only while its creator may still do this with the form in Nextcloud (H2): the form needs their read
	 * right, its responses their download right as for an export in the app, writing their edit right; the form is used
	 * through their own access.
	 * Every refusal looks the same, so outsiders cannot learn which forms exist (L36).
	 *
	 * @return array{form: array, keyConfig: array, file: File}|DataResponse
	 */
	private function authenticate(int $fileId, string $permission): array|DataResponse {
		$apiKey = $this->request->getHeader('X-FormVox-API-Key');
		if ($apiKey === '') {
			// TRANSLATORS: Error for integrations that call the API without their key
			return new DataResponse(['error' => $this->l->t('Missing API key. Provide X-FormVox-API-Key header.'), 'reason' => 'missing_api_key'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$keys = $this->formRepository->loadPublic($fileId)['settings']['api_keys'] ?? [];
		} catch (NotFoundException) {
			// Only a form that is not there is a wrong key; a busy one is a 503 to retry (7a, 3a-3).
			$keys = [];
		}
		if (!is_array($keys) || $keys === []) {
			// As slow as checking a real key (L36): one bcrypt round, as findValidKey() would do.
			password_hash($apiKey, PASSWORD_BCRYPT);
			$keys = [];
		}
		$keyConfig = $keys === [] ? null : $this->apiKeyService->findValidKey($apiKey, $keys);
		$file = $keyConfig === null ? null : $this->access->fileFor($keyConfig['created_by'] ?? null, $fileId, $permission);
		// The form and its counts: a route that needs responses asks for those it needs.
		$form = $file === null ? null : $this->formRepository->readForm($file);
		if (!is_array($form)) {
			// TRANSLATORS: Error for integrations whose API key is not valid for this form (any reason looks the same)
			$response = new DataResponse(['error' => $this->l->t('Invalid API key'), 'reason' => 'invalid_api_key'], Http::STATUS_UNAUTHORIZED);
			if ($keyConfig === null) {
				// Guessing counts; a real key whose creator lost access does not, or one stale key slows every integration on its server.
				$response->throttle(['fileId' => $fileId]);
			}
			return $response;
		}

		return ['form' => $form, 'keyConfig' => $keyConfig, 'file' => $file];
	}

	/**
	 * GET /api/v1/external/forms/{fileId}
	 * Get form definition (questions, settings)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'formvox_api_key')]
	#[AnonRateLimit(limit: 100, period: 60)]
	public function getForm(int $fileId): DataResponse {
		$auth = $this->authenticate($fileId, FormPermission::READ);
		if ($auth instanceof DataResponse) {
			return $auth;
		}

		$form = $auth['form'];
		$keyConfig = $auth['keyConfig'];

		if (!$this->apiKeyService->hasPermission($keyConfig, 'read_form')) {
			throw RequestException::keyLacks('read_form');
		}

		// Return sanitized form data
		return new DataResponse([
			'id' => $fileId,
			'title' => $form['title'] ?? '',
			'description' => $form['description'] ?? '',
			'questions' => $form['questions'] ?? [],
			'pages' => $form['pages'] ?? [],
			'pageOrder' => $form['pageOrder'] ?? [],
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
	#[BruteForceProtection(action: 'formvox_api_key')]
	#[AnonRateLimit(limit: 100, period: 60)]
	public function getSchema(int $fileId): DataResponse {
		$auth = $this->authenticate($fileId, FormPermission::READ);
		if ($auth instanceof DataResponse) {
			return $auth;
		}

		$form = $auth['form'];
		$keyConfig = $auth['keyConfig'];

		if (!$this->apiKeyService->hasPermission($keyConfig, 'read_form')) {
			throw RequestException::keyLacks('read_form');
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
	#[BruteForceProtection(action: 'formvox_api_key')]
	#[AnonRateLimit(limit: 100, period: 60)]
	public function getResponses(int $fileId): DataResponse {
		$auth = $this->authenticate($fileId, FormPermission::DOWNLOAD);
		if ($auth instanceof DataResponse) {
			return $auth;
		}

		$form = $auth['form'];
		$keyConfig = $auth['keyConfig'];

		if (!$this->apiKeyService->hasPermission($keyConfig, 'read_responses')) {
			throw RequestException::keyLacks('read_responses');
		}

		// A page when asked for one: `limit` responses after the cursor `after`, oldest first.
		$limit = $this->request->getParam('limit');
		$limit = $limit === null || $limit === '' ? null : max(1, min(self::MAX_PAGE, (int)$limit));
		$after = $this->request->getParam('after');
		$after = is_string($after) && $after !== '' ? $after : null;
		$page = $this->formRepository->page($fileId, $form, $limit, $after);
		$data = [
			'count' => (int)($form['_index']['response_count'] ?? count($page['responses'])),
			'responses' => $this->sanitizer->responses($page['responses'], $form),
		];
		if ($limit !== null) {
			$data['next'] = $page['next'];
		}
		return new DataResponse($data);
	}

	/**
	 * GET /api/v1/external/forms/{fileId}/responses/{responseId}
	 * Get single response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'formvox_api_key')]
	#[AnonRateLimit(limit: 100, period: 60)]
	public function getResponse(int $fileId, string $responseId): DataResponse {
		$auth = $this->authenticate($fileId, FormPermission::DOWNLOAD);
		if ($auth instanceof DataResponse) {
			return $auth;
		}

		$form = $auth['form'];
		$keyConfig = $auth['keyConfig'];

		if (!$this->apiKeyService->hasPermission($keyConfig, 'read_responses')) {
			throw RequestException::keyLacks('read_responses');
		}

		$response = $this->formRepository->response($fileId, $form, $responseId);
		if ($response === null) {
			// TRANSLATORS: Error for integrations asking for a response that does not exist
			throw RequestException::notFound(Translatable::t('Response not found'));
		}

		return new DataResponse($this->sanitizer->response($response, $form));
	}

	/**
	 * GET /api/v1/external/forms/{fileId}/responses/{responseId}/pdf
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'formvox_api_key')]
	#[AnonRateLimit(limit: 100, period: 60)]
	public function getResponsePdf(int $fileId, string $responseId): DataResponse|\OCP\AppFramework\Http\DataDownloadResponse {
		$auth = $this->authenticate($fileId, FormPermission::DOWNLOAD);
		if ($auth instanceof DataResponse) {
			return $auth;
		}
		if (!$this->apiKeyService->hasPermission($auth['keyConfig'], 'read_responses')) {
			throw RequestException::keyLacks('read_responses');
		}
		if (!\OCA\FormVox\Pdf\ResponsePdf::keptFor($auth['form'])) {
			throw RequestException::pdfOff();
		}
		$response = $this->formRepository->response($fileId, $auth['form'], $responseId);
		if ($response === null) {
			// TRANSLATORS: Error for integrations asking for a response that does not exist
			throw RequestException::notFound(Translatable::t('Response not found'));
		}
		// Only what the job kept: an integration fetches what its webhook named, never a document made on demand.
		$stored = $this->pdfs->stored($auth['file'], $response);
		if ($stored === null) {
			throw RequestException::pdfNotReady();
		}
		return new \OCP\AppFramework\Http\DataDownloadResponse($stored->getContent(), $this->pdfs->fileName($auth['form'], $response), 'application/pdf');
	}

	/**
	 * POST /api/v1/external/forms/{fileId}/responses
	 * Create new response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'formvox_api_key')]
	#[AnonRateLimit(limit: 50, period: 60)]
	public function createResponse(int $fileId): DataResponse {
		$auth = $this->authenticate($fileId, FormPermission::EDIT);
		if ($auth instanceof DataResponse) {
			return $auth;
		}

		$form = $auth['form'];
		$keyConfig = $auth['keyConfig'];

		if (!$this->apiKeyService->hasPermission($keyConfig, 'write_responses')) {
			throw RequestException::keyLacks('write_responses');
		}

		$answers = $this->request->getParam('answers');
		if (!is_array($answers)) {
			// TRANSLATORS: Error for integrations that send a response without its answers
			throw RequestException::invalid(Translatable::t('answers parameter is required and must be an object'));
		}

		// Closed, full or out of capacity: the same answer the public form gets (ExceptionMiddleware).
		return new DataResponse($this->responseService->submitViaApi($fileId, $auth['file'], $form, $answers, $keyConfig), Http::STATUS_CREATED);
	}

	/**
	 * PUT /api/v1/external/forms/{fileId}/responses/{responseId}
	 * Update existing response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'formvox_api_key')]
	#[AnonRateLimit(limit: 50, period: 60)]
	public function updateResponse(int $fileId, string $responseId): DataResponse {
		$auth = $this->authenticate($fileId, FormPermission::EDIT);
		if ($auth instanceof DataResponse) {
			return $auth;
		}

		$form = $auth['form'];
		$keyConfig = $auth['keyConfig'];

		if (!$this->apiKeyService->hasPermission($keyConfig, 'write_responses')) {
			throw RequestException::keyLacks('write_responses');
		}

		$answers = $this->request->getParam('answers');
		if (!is_array($answers)) {
			// TRANSLATORS: Error for integrations that send a response without its answers
			throw RequestException::invalid(Translatable::t('answers parameter is required and must be an object'));
		}

		return new DataResponse($this->sanitizer->response($this->responseService->updateViaApi($fileId, $auth['file'], $form, $responseId, $answers, $keyConfig), $form));
	}

	/**
	 * DELETE /api/v1/external/forms/{fileId}/responses/{responseId}
	 * Delete response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'formvox_api_key')]
	#[AnonRateLimit(limit: 50, period: 60)]
	public function deleteResponse(int $fileId, string $responseId): DataResponse {
		$auth = $this->authenticate($fileId, FormPermission::EDIT);
		if ($auth instanceof DataResponse) {
			return $auth;
		}

		$form = $auth['form'];
		$keyConfig = $auth['keyConfig'];

		if (!$this->apiKeyService->hasPermission($keyConfig, 'delete_responses')) {
			throw RequestException::keyLacks('delete_responses');
		}

		$this->responseService->deleteResponse($fileId, $auth['file'], $responseId);
		return new DataResponse(['success' => true]);
	}

	/**
	 * JSON Schema of the answers, in the question types AnswerValidator checks (M16).
	 */
	private function generateJsonSchema(array $form): array {
		$properties = [];
		$required = [];

		foreach ($form['questions'] ?? [] as $question) {
			$type = $question['type'] ?? 'text';
			if (!isset($question['id']) || in_array($type, ['section', 'descriptor'], true)) {
				continue;
			}
			$prop = array_filter([
				'title' => $question['question'] ?? '',
				'description' => $question['description'] ?? '',
			]);
			$options = array_values(array_filter(array_map(
				fn ($o) => isset($o['value']) || isset($o['id']) ? ['const' => (string)($o['value'] ?? $o['id']), 'title' => (string)($o['label'] ?? '')] : null,
				$question['options'] ?? []
			)));

			$prop += match ($type) {
				'text', 'textarea' => ['type' => 'string', 'maxLength' => (int)($question['maxLength'] ?? 0) ?: AnswerValidator::MAX_TEXT_LENGTH],
				'number' => array_filter(['type' => 'number', 'minimum' => $question['min'] ?? null, 'maximum' => $question['max'] ?? null], fn ($v) => $v !== null),
				'scale' => ['type' => 'integer', 'minimum' => (int)($question['scaleMin'] ?? $question['min'] ?? 1), 'maximum' => (int)($question['scaleMax'] ?? $question['max'] ?? 5)],
				'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int)($question['ratingMax'] ?? $question['max'] ?? 5)],
				'choice', 'dropdown' => ['type' => 'string', 'oneOf' => $options],
				'multiple' => array_filter([
					'type' => 'array',
					'items' => ['type' => 'string', 'oneOf' => $options],
					'uniqueItems' => true,
					'minItems' => (int)($question['minSelections'] ?? 0) ?: null,
					'maxItems' => (int)($question['maxSelections'] ?? 0) ?: null,
				], fn ($v) => $v !== null),
				'consent' => ['type' => 'boolean'],
				'date' => ['type' => 'string', 'format' => 'date'],
				'time' => ['type' => 'string', 'pattern' => '^\\d{2}:\\d{2}$'],
				'datetime' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}'],
				'matrix' => [
					'type' => 'object',
					'properties' => (object)array_fill_keys(
						array_map(fn ($r) => (string)($r['id'] ?? ''), $question['rows'] ?? []),
						['type' => 'string', 'enum' => array_map(fn ($c) => (string)($c['value'] ?? $c['id'] ?? ''), $question['columns'] ?? [])]
					),
					'additionalProperties' => false,
				],
				'table' => [
					'type' => 'array',
					'maxItems' => min((int)($question['maxRows'] ?? 0) ?: AnswerValidator::MAX_TABLE_ROWS, AnswerValidator::MAX_TABLE_ROWS),
					'items' => [
						'type' => 'object',
						'properties' => (object)array_fill_keys(array_map(fn ($c) => (string)($c['id'] ?? ''), $question['columns'] ?? []), ['type' => 'string']),
						'additionalProperties' => false,
					],
				],
				// Files are uploaded through the public form; the API has no upload.
				'file' => ['readOnly' => true],
				default => ['type' => 'string'],
			};

			$properties[(string)$question['id']] = $prop;
			if ($question['required'] ?? false) {
				$required[] = (string)$question['id'];
			}
		}

		return [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'type' => 'object',
			'title' => $form['title'] ?? 'Form',
			'properties' => (object)$properties,
			'required' => $required,
			'additionalProperties' => false,
		];
	}
}
