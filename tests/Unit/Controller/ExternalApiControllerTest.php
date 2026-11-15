<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\ExternalApiController;
use OCA\FormVox\Middleware\ExceptionMiddleware;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\AccountAccess;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\InvalidAnswerException;
use OCA\FormVox\Service\ResponseRefusedException;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Characterization tests for ExternalApiController — the third-party HTTP API.
 *
 * Pins the observable behaviour of every public endpoint: the shared
 * authenticate() gate (missing key -> 401, form-not-found -> 404, no api_keys
 * configured -> 403, invalid key -> 401), the per-endpoint API-key permission
 * gates (403), bad-request validation (400), not-found response lookup (404),
 * and the response writes, which go through ResponseService: one response at a time,
 * under the lock, with the public form's checks (H8, M16).
 *
 * Everything is mocked; the controller runs against ocp stubs with no server.
 */
class ExternalApiControllerTest extends TestCase {
	private FormRepository $formRepository;
	private ResponseService $responseService;
	private ApiKeyService $apiKeyService;
	private AccountAccess $access;
	private IRequest $request;
	/** @var array<string, string> which permission the creator check was asked for, by call */
	private array $askedPermissions = [];

	/** @var array<string,string> header name => value returned by request mock */
	private array $headers = [];
	/** @var array<string,mixed> param name => value returned by request mock */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->responseService = $this->createMock(ResponseService::class);
		$this->apiKeyService = $this->createMock(ApiKeyService::class);
		$this->access = $this->createMock(AccountAccess::class);

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

	private ?\OCA\FormVox\Pdf\ResponsePdf $pdfs = null;

	private function controller(): ExternalApiController {
		return new ExternalApiController(
			$this->request,
			$this->formRepository,
			$this->responseService,
			$this->apiKeyService,
			$this->access,
			new FormSanitizer(),
			new EnglishL10N(),
			$this->pdfs ?? $this->createMock(\OCA\FormVox\Pdf\ResponsePdf::class),
		);
	}

	/**
	 * Call an endpoint the way Nextcloud's dispatcher does: what it throws is answered by ExceptionMiddleware.
	 */
	private function respond(string $method, mixed ...$args): Response {
		$controller = $this->controller();
		try {
			return $controller->$method(...$args);
		} catch (\Exception $e) {
			$request = $this->createMock(IRequest::class);
			$request->method('getId')->willReturn('req-1');
			return (new ExceptionMiddleware($request, $this->createMock(IConfig::class), new EnglishL10N(), $this->createMock(LoggerInterface::class)))->afterException($controller, $method, $e);
		}
	}

	/**
	 * Wire up a happy-path auth: form loads, has api_keys, key resolves to a
	 * config, and hasPermission returns true for every permission unless
	 * overridden by $grantedPermissions.
	 *
	 * @param array<string,mixed> $form
	 * @param array<string>|null $grantedPermissions null => all granted
	 */
	private function givenAuthenticated(array $form = [], ?array $grantedPermissions = null, array $keyConfig = ['id' => 'k1', 'created_by' => 'anna']): void {
		$form['settings'] = $form['settings'] ?? [];
		$form['settings']['api_keys'] = $form['settings']['api_keys'] ?? [['id' => 'k1']];

		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->apiKeyService->method('findValidKey')->willReturn($keyConfig);
		// The creator can still use the form; the form is read through their own access.
		$creatorsFile = $this->createMock(File::class);
		$this->formRepository->method('readForm')->with($creatorsFile)->willReturn($form);
		$this->formRepository->method('page')->willReturnCallback(fn (int $fileId, array $read) => ['responses' => $read['responses'] ?? [], 'next' => null]);
		$this->formRepository->method('response')->willReturnCallback(fn (int $fileId, array $read, string $id) => array_values(array_filter($read['responses'] ?? [], fn ($r) => ($r['id'] ?? null) === $id))[0] ?? null);
		$this->access->method('fileFor')->willReturnCallback(function (?string $uid, int $fileId, string $permission) use ($creatorsFile) {
			$this->askedPermissions[] = $permission;
			return $creatorsFile;
		});
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

		$resp = $this->respond('getForm', 1);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
		$this->assertSame(
			'Missing API key. Provide X-FormVox-API-Key header.',
			$resp->getData()['error']
		);
	}

	/**
	 * A missing form, a form without keys, a wrong key and a key whose creator lost access all answer
	 * the same (L36); all but the real key count for Nextcloud's brute-force protection.
	 *
	 * @return array<string, array{string}>
	 */
	public static function refusals(): array {
		return ['form not found' => ['missing form'], 'no keys on the form' => ['no keys'], 'wrong key' => ['wrong key'], 'creator can no longer use the form' => ['creator gone']];
	}

	/**
	 * @dataProvider refusals
	 */
	public function testEveryRefusalIsTheSame401AndThrottled(string $case): void {
		match ($case) {
			'missing form' => $this->formRepository->method('loadPublic')->willThrowException(new NotFoundException('nope')),
			'no keys' => $this->formRepository->method('loadPublic')->willReturn(['settings' => []]),
			default => $this->formRepository->method('loadPublic')->willReturn(['settings' => ['api_keys' => [['id' => 'k1']]]]),
		};
		$this->apiKeyService->method('findValidKey')->willReturn($case === 'creator gone' ? ['id' => 'k1', 'created_by' => 'anna'] : null);
		$this->access->method('fileFor')->willReturn(null);

		$resp = $this->respond('getForm', 1);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
		$this->assertSame(['error' => 'Invalid API key', 'reason' => 'invalid_api_key'], $resp->getData());
		// Brute-force protection is for guessing, not for a real key whose creator lost access.
		$this->assertSame($case !== 'creator gone', $resp->isThrottled());
	}

	/**
	 * A busy form is a 503 to retry, never a wrong key that counts as guessing: one save in progress would otherwise
	 * slow every integration on the server (7a, 3a-3).
	 *
	 * @return array<string, array{string}>
	 */
	public static function busyMoments(): array {
		return ['reading the keys' => ['keys'], 'reading through the creator' => ['creator']];
	}

	/**
	 * @dataProvider busyMoments
	 */
	public function testABusyFormIsA503AndNotCountedAsGuessing(string $when): void {
		if ($when === 'keys') {
			$this->formRepository->method('loadPublic')->willThrowException(new FormLockedException('Form 1 stayed unreadable'));
		} else {
			$this->formRepository->method('loadPublic')->willReturn(['settings' => ['api_keys' => [['id' => 'k1']]]]);
			$this->apiKeyService->method('findValidKey')->willReturn(['id' => 'k1', 'created_by' => 'anna']);
			$this->access->method('fileFor')->willReturn($this->createMock(File::class));
			$this->formRepository->method('readForm')->willThrowException(new FormLockedException('Form 1 stayed unreadable'));
		}

		$resp = $this->respond('getForm', 1);

		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, 'busy', false], [$resp->getStatus(), $resp->getData()['reason'], $resp->isThrottled()]);
	}

	public function testTheKeyIsCheckedAgainstItsCreatorsRights(): void {
		$this->formRepository->method('loadPublic')->willReturn(['settings' => ['api_keys' => [['id' => 'k1']]]]);
		$this->apiKeyService->method('findValidKey')->willReturn(['id' => 'k1', 'created_by' => 'anna']);
		$this->apiKeyService->method('hasPermission')->willReturn(true);
		$this->access->expects($this->once())->method('fileFor')->with('anna', 1, FormPermission::READ)->willReturn(null);

		$this->respond('getForm', 1);
	}

	/**
	 * The form needs the creator's read right, its responses their download right as an export in the app does (PO
	 * 2026-11-03), writing and deleting their edit right (PO 2026-10-07).
	 */
	public function testEachCallNeedsTheCreatorsMatchingRight(): void {
		$this->givenAuthenticated(['questions' => [], 'responses' => []]);
		$this->params['answers'] = [];
		$this->responseService->method('submitViaApi')->willReturn([]);

		$this->respond('getForm', 1);
		$this->respond('getResponses', 1);
		$this->respond('getResponse', 1, 'r');
		$this->respond('getResponsePdf', 1, 'r');
		$this->respond('createResponse', 1);
		$this->respond('deleteResponse', 1, 'r');

		$this->assertSame([
			FormPermission::READ,
			FormPermission::DOWNLOAD, FormPermission::DOWNLOAD, FormPermission::DOWNLOAD,
			FormPermission::EDIT, FormPermission::EDIT,
		], $this->askedPermissions);
	}

	/** The write goes through the creator's own access, not a borrowed account. */
	public function testAWriteUsesTheCreatorsFile(): void {
		$creatorsFile = $this->createMock(File::class);
		$this->formRepository->method('read')->with($creatorsFile)->willReturn(['questions' => []]);
		$this->formRepository->method('loadPublic')->willReturn(['settings' => ['api_keys' => [['id' => 'k1']]]]);
		$this->apiKeyService->method('findValidKey')->willReturn(['id' => 'k1', 'created_by' => 'anna']);
		$this->apiKeyService->method('hasPermission')->willReturn(true);
		$this->access->method('fileFor')->willReturn($creatorsFile);
		$this->params['answers'] = [];
		$this->responseService->expects($this->once())->method('submitViaApi')->with(1, $this->identicalTo($creatorsFile))->willReturn([]);

		$this->respond('createResponse', 1);
	}

	// ---- getForm() ----------------------------------------------------------

	public function testGetFormDeniedWithoutReadFormPermission(): void {
		$this->givenAuthenticated([], []); // no permissions granted

		$resp = $this->respond('getForm', 1);
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

		$resp = $this->respond('getForm', 7);
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

		$data = $this->respond('getForm', 1)->getData();
		$this->assertTrue($data['settings']['anonymous']);
		$this->assertFalse($data['settings']['allow_multiple']);
		$this->assertSame('', $data['description']);
		$this->assertSame([], $data['questions']);
	}

	// ---- getSchema() --------------------------------------------------------

	public function testGetSchemaDeniedWithoutReadFormPermission(): void {
		$this->givenAuthenticated([], []);

		$resp = $this->respond('getSchema', 1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API key does not have read_form permission', $resp->getData()['error']);
	}

	/**
	 * The schema describes what AnswerValidator accepts, in FormVox's own question types (M16, Q7).
	 */
	public function testGetSchemaDescribesTheRealQuestionTypes(): void {
		$this->givenAuthenticated([
			'title' => 'Survey',
			'questions' => [
				['id' => 'intro', 'type' => 'section', 'question' => 'About you'],
				['id' => 'name', 'type' => 'text', 'question' => 'Name', 'required' => true, 'maxLength' => 80],
				['id' => 'age', 'type' => 'number', 'question' => 'Age', 'min' => 0, 'max' => 120],
				['id' => 'when', 'type' => 'date'],
				['id' => 'color', 'type' => 'choice', 'options' => [['value' => 'r', 'label' => 'Red'], ['value' => 'b', 'label' => 'Blue']]],
				['id' => 'pick', 'type' => 'dropdown', 'options' => [['id' => 'a', 'label' => 'A']]],
				['id' => 'tags', 'type' => 'multiple', 'maxSelections' => 2, 'options' => [['value' => 'x', 'label' => 'X']]],
				['id' => 'rate', 'type' => 'rating', 'ratingMax' => 10],
				['id' => 'scale', 'type' => 'scale'],
				['id' => 'ok', 'type' => 'consent'],
				['id' => 'grid', 'type' => 'matrix', 'rows' => [['id' => 'r1']], 'columns' => [['value' => '1'], ['value' => '2']]],
				['id' => 'doc', 'type' => 'file'],
			],
		], ['read_form']);

		$schema = $this->respond('getSchema', 1)->getData();
		$props = (array)$schema['properties'];

		$this->assertSame('Survey', $schema['title']);
		$this->assertSame(['name'], $schema['required']);
		$this->assertFalse($schema['additionalProperties']);
		$this->assertArrayNotHasKey('intro', $props, 'a section takes no answer');
		$this->assertSame(['title' => 'Name', 'type' => 'string', 'maxLength' => 80], $props['name']);
		$this->assertSame(['title' => 'Age', 'type' => 'number', 'minimum' => 0, 'maximum' => 120], $props['age']);
		$this->assertSame('date', $props['when']['format']);
		$this->assertSame([['const' => 'r', 'title' => 'Red'], ['const' => 'b', 'title' => 'Blue']], $props['color']['oneOf']);
		$this->assertSame([['const' => 'a', 'title' => 'A']], $props['pick']['oneOf']);
		$this->assertSame(['type' => 'array', 'items' => ['type' => 'string', 'oneOf' => [['const' => 'x', 'title' => 'X']]], 'uniqueItems' => true, 'maxItems' => 2], $props['tags']);
		$this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 10], $props['rate']);
		$this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 5], $props['scale']);
		$this->assertSame(['type' => 'boolean'], $props['ok']);
		$this->assertSame(['type' => 'string', 'enum' => ['1', '2']], ((array)$props['grid']['properties'])['r1']);
		$this->assertTrue($props['doc']['readOnly'], 'files are uploaded through the public form');
	}

	/** The limits come from AnswerValidator, so the schema says what the server accepts. */
	public function testGetSchemaGivesTheServersLimitsWhereAQuestionSetsNone(): void {
		$this->givenAuthenticated([
			'questions' => [['id' => 't', 'type' => 'textarea'], ['id' => 'tb', 'type' => 'table', 'columns' => [['id' => 'a']]]],
		], ['read_form']);

		$props = (array)$this->respond('getSchema', 1)->getData()['properties'];
		$this->assertSame(10000, $props['t']['maxLength']);
		$this->assertSame(200, $props['tb']['maxItems']);
	}

	public function testGetSchemaChoiceWithoutOptionsOffersNone(): void {
		$this->givenAuthenticated([
			'questions' => [['id' => 'c', 'type' => 'choice']],
		], ['read_form']);

		$props = (array)$this->respond('getSchema', 1)->getData()['properties'];
		$this->assertSame(['type' => 'string', 'oneOf' => []], $props['c']);
	}

	public function testGetSchemaEmptyFormHasEmptyPropsAndRequired(): void {
		$this->givenAuthenticated([], ['read_form']);

		$schema = $this->respond('getSchema', 1)->getData();
		$this->assertSame([], (array)$schema['properties']);
		$this->assertSame([], $schema['required']);
		$this->assertSame('Form', $schema['title']);
	}

	// ---- getResponses() -----------------------------------------------------

	public function testGetResponsesDeniedWithoutReadResponsesPermission(): void {
		$this->givenAuthenticated([], ['read_form']); // read_form only

		$resp = $this->respond('getResponses', 1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API key does not have read_responses permission', $resp->getData()['error']);
	}

	public function testGetResponsesReturnsCountAndList(): void {
		$this->givenAuthenticated([
			'responses' => [['id' => 'r1'], ['id' => 'r2']],
		], ['read_responses']);

		$resp = $this->respond('getResponses', 1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame(2, $data['count']);
		$this->assertSame([['id' => 'r1'], ['id' => 'r2']], $data['responses']);
	}

	public function testTheApiDoesNotHandOutTheFingerprint(): void {
		$this->givenAuthenticated(['responses' => [['id' => 'r1', 'respondent' => ['type' => 'anonymous', 'fingerprint' => 'v2:abc']]]], ['read_responses']);

		$this->assertStringNotContainsString('v2:abc', json_encode($this->respond('getResponses', 1)->getData()));
		$this->assertStringNotContainsString('v2:abc', json_encode($this->respond('getResponse', 1, 'r1')->getData()));
	}

	/** An integration is told which answers the respondent was shown, in the list and for one response alike. */
	public function testTheApiSaysWhichAnswersApplied(): void {
		$this->givenAuthenticated([
			'questions' => [['id' => 'q1', 'type' => 'choice'], ['id' => 'q2', 'type' => 'text', 'showIf' => ['questionId' => 'q1', 'operator' => 'equals', 'value' => 'yes']]],
			'responses' => [['id' => 'r1', 'answers' => ['q1' => 'no', 'q2' => 'stale']], ['id' => 'r2', 'answers' => ['q1' => 'yes', 'q2' => 'told']]],
		], ['read_responses']);

		$list = $this->respond('getResponses', 1)->getData()['responses'];
		$one = $this->respond('getResponse', 1, 'r1')->getData();

		$this->assertSame(['q1' => 'no', 'q2' => 'stale'], $one['answers'], 'answers stay as stored');
		$this->assertSame([['q1' => 'no'], ['q2']], [$one['applicable_answers'], $one['not_applicable']]);
		$this->assertSame([[['q1' => 'no'], ['q2']], [['q1' => 'yes', 'q2' => 'told'], []]], array_map(fn (array $r) => [$r['applicable_answers'], $r['not_applicable']], $list));
	}

	public function testGetResponsesEmptyWhenNone(): void {
		$this->givenAuthenticated([], ['read_responses']);

		$data = $this->respond('getResponses', 1)->getData();
		$this->assertSame(0, $data['count']);
		$this->assertSame([], $data['responses']);
	}

	// ---- getResponse() ------------------------------------------------------

	public function testGetResponseDeniedWithoutReadResponsesPermission(): void {
		$this->givenAuthenticated([], ['read_form']);

		$resp = $this->respond('getResponse', 1, 'r1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testGetResponseReturnsMatch(): void {
		$this->givenAuthenticated([
			'responses' => [['id' => 'r1', 'answers' => ['a' => 1]], ['id' => 'r2']],
		], ['read_responses']);

		$resp = $this->respond('getResponse', 1, 'r2');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['id' => 'r2'], $resp->getData());
	}

	public function testGetResponseNotFoundReturns404(): void {
		$this->givenAuthenticated([
			'responses' => [['id' => 'r1']],
		], ['read_responses']);

		$resp = $this->respond('getResponse', 1, 'missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('Response not found', $resp->getData()['error']);
	}

	// ---- getResponsePdf() ---------------------------------------------------

	/** A form that keeps no PDFs has none to give: 409, told apart from a PDF still to come, and nothing is made. */
	public function testGetResponsePdfIsRefusedWhereTheFormKeepsNone(): void {
		$this->pdfs = $this->createMock(\OCA\FormVox\Pdf\ResponsePdf::class);
		$this->pdfs->expects($this->never())->method('download');
		$this->pdfs->expects($this->never())->method('of');
		$this->pdfs->expects($this->never())->method('stored');
		$this->givenAuthenticated(['responses' => [['id' => 'r1']]], ['read_responses']);

		$resp = $this->respond('getResponsePdf', 1, 'r1');

		$this->assertSame(Http::STATUS_CONFLICT, $resp->getStatus());
		$this->assertSame(['error' => 'PDF generation is disabled for this form', 'reason' => 'pdf_disabled'], $resp->getData());
	}

	/** The API gives only the PDF the job kept, never one made on demand: before the job ran, it is not ready. */
	public function testGetResponsePdfGivesOnlyTheKeptOne(): void {
		$this->pdfs = $this->createMock(\OCA\FormVox\Pdf\ResponsePdf::class);
		$this->pdfs->expects($this->never())->method('download');
		$this->pdfs->expects($this->never())->method('of');
		$this->pdfs->method('stored')->willReturn(null);
		$this->givenAuthenticated(['settings' => ['keepResponsePdf' => true], 'responses' => [['id' => 'r1']]], ['read_responses']);

		$resp = $this->respond('getResponsePdf', 1, 'r1');

		$this->assertSame(Http::STATUS_CONFLICT, $resp->getStatus());
		$this->assertSame(['error' => 'PDF is not ready yet', 'reason' => 'pdf_not_ready'], $resp->getData());
	}

	public function testGetResponsePdfOfAMissingResponseIs404(): void {
		$this->pdfs = $this->createMock(\OCA\FormVox\Pdf\ResponsePdf::class);
		$this->pdfs->expects($this->never())->method('stored');
		$this->givenAuthenticated(['settings' => ['keepResponsePdf' => true], 'responses' => [['id' => 'r1']]], ['read_responses']);

		$resp = $this->respond('getResponsePdf', 1, 'missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('Response not found', $resp->getData()['error']);
	}

	// ---- createResponse() ---------------------------------------------------

	public function testCreateResponseDeniedWithoutWritePermission(): void {
		$this->givenAuthenticated([], ['read_responses']);
		$this->params['answers'] = ['q1' => 'yes'];
		$this->responseService->expects($this->never())->method('submitViaApi');

		$resp = $this->respond('createResponse', 1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API key does not have write_responses permission', $resp->getData()['error']);
	}

	public function testCreateResponseRejectsNonArrayAnswers(): void {
		$this->givenAuthenticated([], ['write_responses']);
		$this->responseService->expects($this->never())->method('submitViaApi');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->respond('createResponse', 1)->getStatus());
	}

	public function testCreateResponseGoesThroughTheSubmitPath(): void {
		$form = ['questions' => [['id' => 'q1', 'type' => 'text']]];
		$this->givenAuthenticated($form, ['write_responses'], ['id' => 'key-42']);
		$this->params['answers'] = ['q1' => 'hello'];
		$this->responseService->expects($this->once())->method('submitViaApi')
			->with(5, $this->isInstanceOf(File::class), $this->callback(fn ($f) => $f['questions'] === $form['questions']), ['q1' => 'hello'], ['id' => 'key-42'])
			->willReturn(['id' => 'new', 'answers' => ['q1' => 'hello']]);

		$resp = $this->respond('createResponse', 5);

		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$this->assertSame(['id' => 'new', 'answers' => ['q1' => 'hello']], $resp->getData());
	}

	public function testCreateResponseAnswersARefusedAnswerWith400AndTheQuestion(): void {
		$this->givenAuthenticated([], ['write_responses']);
		$this->params['answers'] = ['colour' => 'purple'];
		$this->responseService->method('submitViaApi')->willThrowException(new InvalidAnswerException("'purple' is not an option", 'colour'));

		$resp = $this->respond('createResponse', 5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame(['error' => "'purple' is not an option", 'reason' => 'invalid_answer', 'question' => 'colour'], $resp->getData());
	}

	/** The same rules as the public form (Q7). */
	/** The same answer the public form gives, with a reason integrations can rely on (review V4: 410). */
	public function testCreateResponseOnAClosedFormIsGoneLikeThePublicForm(): void {
		$this->givenAuthenticated([], ['write_responses']);
		$this->params['answers'] = [];
		$this->responseService->method('submitViaApi')->willThrowException(ResponseRefusedException::closed());

		$resp = $this->respond('createResponse', 5);

		$this->assertSame([Http::STATUS_GONE, ['error' => 'This form has expired', 'reason' => 'form_closed']], [$resp->getStatus(), $resp->getData()]);
	}

	// ---- updateResponse() ---------------------------------------------------

	public function testUpdateResponseDeniedWithoutWritePermission(): void {
		$this->givenAuthenticated([], ['read_responses']);
		$this->params['answers'] = ['q1' => 'x'];
		$this->responseService->expects($this->never())->method('updateViaApi');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->respond('updateResponse', 1, 'r1')->getStatus());
	}

	public function testUpdateResponseRejectsNonArrayAnswers(): void {
		$this->givenAuthenticated([], ['write_responses']);
		$this->responseService->expects($this->never())->method('updateViaApi');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->respond('updateResponse', 1, 'r1')->getStatus());
	}

	public function testUpdateResponseNotFoundReturns404(): void {
		$this->givenAuthenticated([], ['write_responses']);
		$this->params['answers'] = ['q1' => 'x'];
		$this->responseService->method('updateViaApi')->willThrowException(new NotFoundException('Response not found'));

		$resp = $this->respond('updateResponse', 1, 'nope');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('not_found', $resp->getData()['reason']);
	}

	public function testUpdateResponseGoesThroughTheUpdatePath(): void {
		$this->givenAuthenticated([], ['write_responses'], ['id' => 'key-9']);
		$this->params['answers'] = ['new' => 'value'];
		$this->responseService->expects($this->once())->method('updateViaApi')
			->with(1, $this->isInstanceOf(File::class), $this->isType('array'), 'r2', ['new' => 'value'], ['id' => 'key-9'])
			->willReturn(['id' => 'r2', 'updated_by' => 'api:key-9']);

		$resp = $this->respond('updateResponse', 1, 'r2');

		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame('api:key-9', $resp->getData()['updated_by']);
	}

	public function testUpdateResponseAnswersARefusedAnswerWith400(): void {
		$this->givenAuthenticated([], ['write_responses']);
		$this->params['answers'] = ['c' => 'x'];
		$this->responseService->method('updateViaApi')->willThrowException(new InvalidAnswerException('nope', 'c'));

		$this->assertSame(['error' => 'nope', 'reason' => 'invalid_answer', 'question' => 'c'], $this->respond('updateResponse', 1, 'r1')->getData());
	}

	// ---- deleteResponse() ---------------------------------------------------

	public function testDeleteResponseDeniedWithoutDeletePermission(): void {
		$this->givenAuthenticated([], ['write_responses']);
		$this->responseService->expects($this->never())->method('deleteResponse');

		$resp = $this->respond('deleteResponse', 1, 'r1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('API key does not have delete_responses permission', $resp->getData()['error']);
	}

	public function testDeleteResponseNotFoundReturns404(): void {
		$this->givenAuthenticated([], ['delete_responses']);
		$this->responseService->method('deleteResponse')->willThrowException(new NotFoundException('Response not found'));

		$resp = $this->respond('deleteResponse', 1, 'nope');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('not_found', $resp->getData()['reason']);
	}

	/** The shared delete path: one response under the lock, its uploads too (L32). */
	public function testDeleteResponseGoesThroughTheSharedDeletePath(): void {
		$this->givenAuthenticated([], ['delete_responses']);
		$this->responseService->expects($this->once())->method('deleteResponse')->with(8, $this->isInstanceOf(File::class), 'r2');

		$resp = $this->respond('deleteResponse', 8, 'r2');
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
