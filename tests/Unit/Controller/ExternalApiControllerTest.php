<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\ExternalApiController;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\WebhookService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for ExternalApiController — the third-party HTTP API.
 *
 * Pins the observable behaviour of every public endpoint: the shared
 * authenticate() gate (missing key -> 401, form-not-found -> 404, no api_keys
 * configured -> 403, invalid key -> 401), the per-endpoint API-key permission
 * gates (403), bad-request validation (400), not-found response lookup (404),
 * and the response CRUD flows that MUST call formRepository->savePublic and fire a
 * webhook (RISK-CRITICAL: savePublic was once silently broken here).
 *
 * Everything is mocked; the controller runs against ocp stubs with no server.
 */
class ExternalApiControllerTest extends TestCase {
	private FormRepository $formRepository;
	private ResponsePersistenceService $responsePersistence;
	private ApiKeyService $apiKeyService;
	private WebhookService $webhookService;
	private IRequest $request;

	/** @var array<string,string> header name => value returned by request mock */
	private array $headers = [];
	/** @var array<string,mixed> param name => value returned by request mock */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->responsePersistence = $this->createMock(ResponsePersistenceService::class);
		$this->apiKeyService = $this->createMock(ApiKeyService::class);
		$this->webhookService = $this->createMock(WebhookService::class);

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $this->headers[$name] ?? ''
		);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $name, $default = null) => $this->params[$name] ?? $default
		);

		// Default: a valid API key is present in the header.
		$this->headers['X-FormVox-API-Key'] = 'secret-key';
	}

	private function controller(): ExternalApiController {
		return new ExternalApiController(
			$this->request,
			$this->formRepository,
			$this->responsePersistence,
			$this->apiKeyService,
			$this->webhookService
		);
	}

	/**
	 * Wire up a happy-path auth: form loads, has api_keys, key resolves to a
	 * config, and hasPermission returns true for every permission unless
	 * overridden by $grantedPermissions.
	 *
	 * @param array<string,mixed> $form
	 * @param array<string>|null $grantedPermissions null => all granted
	 */
	private function givenAuthenticated(array $form = [], ?array $grantedPermissions = null, array $keyConfig = ['id' => 'k1']): void {
		$form['settings'] = $form['settings'] ?? [];
		$form['settings']['api_keys'] = $form['settings']['api_keys'] ?? [['id' => 'k1']];

		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->apiKeyService->method('findValidKey')->willReturn($keyConfig);
		$this->apiKeyService->method('hasPermission')->willReturnCallback(
			fn (array $kc, string $perm): bool => $grantedPermissions === null
				? true
				: in_array($perm, $grantedPermissions, true)
		);
	}

	// ---- authenticate() gate (exercised via getForm) -----------------------

	public function testMissingApiKeyReturns401(): void {
		$this->headers['X-FormVox-API-Key'] = '';
		$this->formRepository->expects($this->never())->method('loadPublic');

		$resp = $this->controller()->getForm(1);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
		$this->assertSame(
			'Missing API key. Provide X-FormVox-API-Key header.',
			$resp->getData()['error']
		);
	}

	public function testFormNotFoundReturns404(): void {
		$this->formRepository->method('loadPublic')->willThrowException(new \RuntimeException('nope'));

		$resp = $this->controller()->getForm(1);
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('Form not found', $resp->getData()['error']);
	}

	public function testNoApiKeysConfiguredReturns403(): void {
		$this->formRepository->method('loadPublic')->willReturn(['settings' => ['api_keys' => []]]);

		$resp = $this->controller()->getForm(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API access not enabled for this form', $resp->getData()['error']);
	}

	public function testApiKeysMissingKeyEntirelyReturns403(): void {
		// settings has no api_keys key at all -> null coalesced to [] -> empty.
		$this->formRepository->method('loadPublic')->willReturn(['settings' => []]);

		$resp = $this->controller()->getForm(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testInvalidApiKeyReturns401(): void {
		$this->formRepository->method('loadPublic')->willReturn(['settings' => ['api_keys' => [['id' => 'k1']]]]);
		$this->apiKeyService->method('findValidKey')->willReturn(null);

		$resp = $this->controller()->getForm(1);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
		$this->assertSame('Invalid API key', $resp->getData()['error']);
	}

	// ---- getForm() ----------------------------------------------------------

	public function testGetFormDeniedWithoutReadFormPermission(): void {
		$this->givenAuthenticated([], []); // no permissions granted

		$resp = $this->controller()->getForm(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API key does not have read_form permission', $resp->getData()['error']);
	}

	public function testGetFormReturnsSanitizedShape(): void {
		$this->givenAuthenticated([
			'title' => 'My Form',
			'description' => 'Desc',
			'questions' => [['id' => 'q1']],
			'pages' => [['id' => 'p1']],
			'pageOrder' => ['p1'],
			'settings' => [
				'anonymous' => false,
				'allow_multiple' => true,
				'api_keys' => [['id' => 'k1']],
				'secret' => 'should-not-leak',
			],
		], ['read_form']);

		$resp = $this->controller()->getForm(7);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame(7, $data['id']);
		$this->assertSame('My Form', $data['title']);
		$this->assertSame('Desc', $data['description']);
		$this->assertSame([['id' => 'q1']], $data['questions']);
		$this->assertSame([['id' => 'p1']], $data['pages']);
		$this->assertSame(['p1'], $data['pageOrder']);
		$this->assertSame(['anonymous' => false, 'allow_multiple' => true], $data['settings']);
		// Only the two whitelisted settings keys are exposed.
		$this->assertArrayNotHasKey('secret', $data['settings']);
		$this->assertArrayNotHasKey('api_keys', $data['settings']);
	}

	public function testGetFormSettingsDefaults(): void {
		// No anonymous/allow_multiple in settings -> documented defaults.
		$this->givenAuthenticated(['title' => 'T'], ['read_form']);

		$data = $this->controller()->getForm(1)->getData();
		$this->assertTrue($data['settings']['anonymous']);
		$this->assertFalse($data['settings']['allow_multiple']);
		$this->assertSame('', $data['description']);
		$this->assertSame([], $data['questions']);
	}

	// ---- getSchema() --------------------------------------------------------

	public function testGetSchemaDeniedWithoutReadFormPermission(): void {
		$this->givenAuthenticated([], []);

		$resp = $this->controller()->getSchema(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API key does not have read_form permission', $resp->getData()['error']);
	}

	public function testGetSchemaBuildsJsonSchema(): void {
		$this->givenAuthenticated([
			'title' => 'Survey',
			'questions' => [
				['id' => 'name', 'type' => 'text', 'title' => 'Name', 'required' => true],
				['id' => 'age', 'type' => 'number', 'title' => 'Age'],
				['id' => 'when', 'type' => 'date'],
				['id' => 'clock', 'type' => 'time'],
				['id' => 'ts', 'type' => 'datetime'],
				['id' => 'color', 'type' => 'single_choice', 'options' => [['text' => 'Red'], ['text' => 'Blue']]],
				['id' => 'pick', 'type' => 'dropdown', 'options' => [['text' => 'A']]],
				['id' => 'tags', 'type' => 'multiple_choice', 'options' => [['text' => 'X'], ['text' => 'Y']]],
				['id' => 'rate', 'type' => 'rating', 'min' => 0, 'max' => 10],
				['id' => 'scale', 'type' => 'scale'],
				['id' => 'doc', 'type' => 'file'],
				['id' => 'weird', 'type' => 'unknown_type'],
			],
		], ['read_form']);

		$resp = $this->controller()->getSchema(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$schema = $resp->getData();

		$this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
		$this->assertSame('object', $schema['type']);
		$this->assertSame('Survey', $schema['title']);
		$this->assertSame(['name'], $schema['required']);

		$props = $schema['properties'];
		$this->assertSame('string', $props['name']['type']);
		$this->assertSame('Name', $props['name']['title']);
		$this->assertSame('number', $props['age']['type']);
		$this->assertSame(['type' => 'string', 'title' => '', 'description' => '', 'format' => 'date'], $this->pick($props['when'], ['type', 'title', 'description', 'format']));
		$this->assertSame('time', $props['clock']['format']);
		$this->assertSame('date-time', $props['ts']['format']);
		$this->assertSame('string', $props['color']['type']);
		$this->assertSame(['Red', 'Blue'], $props['color']['enum']);
		$this->assertSame(['A'], $props['pick']['enum']);
		$this->assertSame('array', $props['tags']['type']);
		$this->assertSame(['type' => 'string', 'enum' => ['X', 'Y']], $props['tags']['items']);
		$this->assertSame('integer', $props['rate']['type']);
		$this->assertSame(0, $props['rate']['minimum']);
		$this->assertSame(10, $props['rate']['maximum']);
		// scale with no min/max -> documented defaults 1..5
		$this->assertSame(1, $props['scale']['minimum']);
		$this->assertSame(5, $props['scale']['maximum']);
		$this->assertSame('array', $props['doc']['type']);
		$this->assertSame('object', $props['doc']['items']['type']);
		// unknown type falls through to string
		$this->assertSame('string', $props['weird']['type']);
	}

	public function testGetSchemaSingleChoiceWithoutOptionsOmitsEnum(): void {
		$this->givenAuthenticated([
			'questions' => [['id' => 'c', 'type' => 'single_choice']],
		], ['read_form']);

		$props = $this->controller()->getSchema(1)->getData()['properties'];
		$this->assertArrayNotHasKey('enum', $props['c']);
		$this->assertSame('string', $props['c']['type']);
	}

	public function testGetSchemaEmptyFormHasEmptyPropsAndRequired(): void {
		$this->givenAuthenticated([], ['read_form']);

		$schema = $this->controller()->getSchema(1)->getData();
		$this->assertSame([], $schema['properties']);
		$this->assertSame([], $schema['required']);
		$this->assertSame('Form', $schema['title']);
	}

	// ---- getResponses() -----------------------------------------------------

	public function testGetResponsesDeniedWithoutReadResponsesPermission(): void {
		$this->givenAuthenticated([], ['read_form']); // read_form only

		$resp = $this->controller()->getResponses(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API key does not have read_responses permission', $resp->getData()['error']);
	}

	public function testGetResponsesReturnsCountAndList(): void {
		$this->givenAuthenticated([
			'responses' => [['id' => 'r1'], ['id' => 'r2']],
		], ['read_responses']);

		$resp = $this->controller()->getResponses(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame(2, $data['count']);
		$this->assertSame([['id' => 'r1'], ['id' => 'r2']], $data['responses']);
	}

	public function testGetResponsesEmptyWhenNone(): void {
		$this->givenAuthenticated([], ['read_responses']);

		$data = $this->controller()->getResponses(1)->getData();
		$this->assertSame(0, $data['count']);
		$this->assertSame([], $data['responses']);
	}

	// ---- getResponse() ------------------------------------------------------

	public function testGetResponseDeniedWithoutReadResponsesPermission(): void {
		$this->givenAuthenticated([], ['read_form']);

		$resp = $this->controller()->getResponse(1, 'r1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testGetResponseReturnsMatch(): void {
		$this->givenAuthenticated([
			'responses' => [['id' => 'r1', 'answers' => ['a' => 1]], ['id' => 'r2']],
		], ['read_responses']);

		$resp = $this->controller()->getResponse(1, 'r2');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['id' => 'r2'], $resp->getData());
	}

	public function testGetResponseNotFoundReturns404(): void {
		$this->givenAuthenticated([
			'responses' => [['id' => 'r1']],
		], ['read_responses']);

		$resp = $this->controller()->getResponse(1, 'missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('Response not found', $resp->getData()['error']);
	}

	// ---- createResponse() ---------------------------------------------------

	public function testCreateResponseDeniedWithoutWritePermission(): void {
		$this->givenAuthenticated([], ['read_responses']);
		$this->params['answers'] = ['q1' => 'yes'];
		$this->responsePersistence->expects($this->never())->method('savePublic');

		$resp = $this->controller()->createResponse(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API key does not have write_responses permission', $resp->getData()['error']);
	}

	public function testCreateResponseRejectsNonArrayAnswers(): void {
		$this->givenAuthenticated([], ['write_responses']);
		// answers param absent -> null -> not array
		$this->responsePersistence->expects($this->never())->method('savePublic');

		$resp = $this->controller()->createResponse(1);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('answers parameter is required and must be an object', $resp->getData()['error']);
	}

	public function testCreateResponseSavesAndReturns201(): void {
		$this->givenAuthenticated([
			'responses' => [['id' => 'existing']],
		], ['write_responses'], ['id' => 'key-42']);
		$this->params['answers'] = ['q1' => 'hello'];

		// RISK-CRITICAL: savePublic must be called with the appended response.
		$this->responsePersistence->expects($this->once())
			->method('savePublic')
			->with(
				5,
				$this->callback(function (array $form): bool {
					// Existing response preserved and new one appended.
					return count($form['responses']) === 2
						&& $form['responses'][0]['id'] === 'existing'
						&& $form['responses'][1]['answers'] === ['q1' => 'hello'];
				})
			);

		$this->webhookService->expects($this->once())
			->method('trigger')
			->with($this->isType('array'), 'response.created', $this->isType('array'));

		$resp = $this->controller()->createResponse(5);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());

		$data = $resp->getData();
		$this->assertSame(['q1' => 'hello'], $data['answers']);
		$this->assertSame('api', $data['source']);
		$this->assertSame('key-42', $data['api_key_id']);
		$this->assertArrayHasKey('id', $data);
		$this->assertArrayHasKey('submitted_at', $data);
		$this->assertSame(32, strlen($data['id'])); // bin2hex(16 bytes)
	}

	public function testCreateResponseApiKeyIdNullWhenKeyConfigHasNoId(): void {
		$this->givenAuthenticated([], ['write_responses'], []); // keyConfig has no 'id'
		$this->params['answers'] = ['q' => 1];
		$this->responsePersistence->expects($this->once())->method('savePublic');

		$data = $this->controller()->createResponse(1)->getData();
		$this->assertNull($data['api_key_id']);
	}

	// ---- updateResponse() ---------------------------------------------------

	public function testUpdateResponseDeniedWithoutWritePermission(): void {
		$this->givenAuthenticated([], ['read_responses']);
		$this->params['answers'] = ['q1' => 'x'];
		$this->responsePersistence->expects($this->never())->method('savePublic');

		$resp = $this->controller()->updateResponse(1, 'r1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testUpdateResponseRejectsNonArrayAnswers(): void {
		$this->givenAuthenticated(['responses' => [['id' => 'r1']]], ['write_responses']);
		$this->responsePersistence->expects($this->never())->method('savePublic');

		$resp = $this->controller()->updateResponse(1, 'r1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testUpdateResponseNotFoundReturns404(): void {
		$this->givenAuthenticated(['responses' => [['id' => 'r1']]], ['write_responses']);
		$this->params['answers'] = ['q1' => 'x'];
		$this->responsePersistence->expects($this->never())->method('savePublic');
		$this->webhookService->expects($this->never())->method('trigger');

		$resp = $this->controller()->updateResponse(1, 'nope');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('Response not found', $resp->getData()['error']);
	}

	public function testUpdateResponseSavesAndReturnsUpdated(): void {
		$this->givenAuthenticated([
			'responses' => [
				['id' => 'r1', 'answers' => ['old' => true]],
				['id' => 'r2', 'answers' => ['keep' => true]],
			],
		], ['write_responses'], ['id' => 'key-9']);
		$this->params['answers'] = ['new' => 'value'];

		$this->responsePersistence->expects($this->once())
			->method('savePublic')
			->with(
				3,
				$this->callback(function (array $form): bool {
					return $form['responses'][0]['answers'] === ['new' => 'value']
						&& $form['responses'][1]['answers'] === ['keep' => true]
						&& $form['responses'][0]['updated_by'] === 'api:key-9'
						&& isset($form['responses'][0]['updated_at']);
				})
			);
		$this->webhookService->expects($this->once())
			->method('trigger')
			->with($this->isType('array'), 'response.updated', $this->isType('array'));

		$resp = $this->controller()->updateResponse(3, 'r1');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());

		$data = $resp->getData();
		$this->assertSame('r1', $data['id']);
		$this->assertSame(['new' => 'value'], $data['answers']);
		$this->assertSame('api:key-9', $data['updated_by']);
		$this->assertArrayHasKey('updated_at', $data);
	}

	public function testUpdateResponseUnknownKeyConfigIdMarker(): void {
		$this->givenAuthenticated([
			'responses' => [['id' => 'r1', 'answers' => []]],
		], ['write_responses'], []); // keyConfig has no 'id'
		$this->params['answers'] = ['a' => 1];
		$this->responsePersistence->expects($this->once())->method('savePublic');

		$data = $this->controller()->updateResponse(1, 'r1')->getData();
		$this->assertSame('api:unknown', $data['updated_by']);
	}

	// ---- deleteResponse() ---------------------------------------------------

	public function testDeleteResponseDeniedWithoutDeletePermission(): void {
		$this->givenAuthenticated(['responses' => [['id' => 'r1']]], ['write_responses']);
		$this->responsePersistence->expects($this->never())->method('savePublic');

		$resp = $this->controller()->deleteResponse(1, 'r1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API key does not have delete_responses permission', $resp->getData()['error']);
	}

	public function testDeleteResponseNotFoundReturns404(): void {
		$this->givenAuthenticated(['responses' => [['id' => 'r1']]], ['delete_responses']);
		$this->responsePersistence->expects($this->never())->method('savePublic');
		$this->webhookService->expects($this->never())->method('trigger');

		$resp = $this->controller()->deleteResponse(1, 'nope');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('Response not found', $resp->getData()['error']);
	}

	public function testDeleteResponseSavesAndReturnsSuccess(): void {
		$this->givenAuthenticated([
			'responses' => [['id' => 'r1'], ['id' => 'r2'], ['id' => 'r3']],
		], ['delete_responses']);

		$this->responsePersistence->expects($this->once())
			->method('savePublic')
			->with(
				8,
				$this->callback(function (array $form): bool {
					// r2 removed, r1 and r3 remain, reindexed.
					return count($form['responses']) === 2
						&& $form['responses'][0]['id'] === 'r1'
						&& $form['responses'][1]['id'] === 'r3';
				})
			);
		$this->webhookService->expects($this->once())
			->method('trigger')
			->with($this->isType('array'), 'response.deleted', ['id' => 'r2']);

		$resp = $this->controller()->deleteResponse(8, 'r2');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	/**
	 * Helper: pick a subset of keys from an assoc array (order preserved by
	 * $keys) for compact equality assertions.
	 *
	 * @param array<string,mixed> $arr
	 * @param array<string> $keys
	 * @return array<string,mixed>
	 */
	private function pick(array $arr, array $keys): array {
		$out = [];
		foreach ($keys as $k) {
			if (array_key_exists($k, $arr)) {
				$out[$k] = $arr[$k];
			}
		}
		return $out;
	}
}
