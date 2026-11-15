<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\PublicController;
use OCA\FormVox\Middleware\ExceptionMiddleware;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\AnswerValidator;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\ChallengeService;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\ResponseRefusedException;
use OCA\FormVox\Service\ResponseRules;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\SubmissionIds;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCA\FormVox\Tests\Unit\Support\ReversibleCrypto;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Characterization tests for PublicController — the anonymous-facing HTTP
 * surface. Pins the security-critical gates that real end users hit:
 * token validation, share-link expiry / not-yet-open, password gating,
 * user/group access restrictions, require_login, ALTCHA challenge on submit,
 * capacity/answer-count leaking, and the upload validation ladder (#90/#97/#135).
 *
 * Everything is mocked against the ocp stubs; no Nextcloud server runs.
 * We assert on observable outcomes: response class, HTTP status, and the
 * exact data shape handed back — never on rendered HTML.
 */
class PublicControllerTest extends TestCase {
	private IRequest $request;
	private IConfig $config;
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private IGroupManager $groupManager;
	private FormRepository $formRepository;
	private UploadService $uploadService;
	private ResponseService $responseService;
	private BrandingService $brandingService;
	private ChallengeService $challengeService;
	private bool $passesHold = true;
	/** The real one, for the client/server contract of the passes (review 3a-5). */
	private ?ChallengeService $realChallenges = null;
	/** @var array<string, mixed> request parameters, for tests that change them between requests */
	private array $params = [];
	private IJobList $jobList;
	private IInitialState $initialState;
	private IThrottler $throttler;
	private IDateTimeFormatter $dateTimeFormatter;
	private IDateTimeZone $timeZone;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->throttler = $this->createMock(IThrottler::class);
		$this->dateTimeFormatter = $this->createMock(IDateTimeFormatter::class);
		$this->timeZone = $this->createMock(IDateTimeZone::class);
		$this->timeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Amsterdam'));
		$this->config = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->uploadService = $this->createMock(UploadService::class);
		$this->responseService = $this->createMock(ResponseService::class);
		$this->brandingService = $this->createMock(BrandingService::class);
		$this->challengeService = $this->createMock(ChallengeService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->initialState = $this->createMock(IInitialState::class);

		// Sensible defaults so success paths don't crash on unmocked calls.
		$this->brandingService->method('getBranding')->willReturn(['color' => '#fff']);
		$this->challengeService->method('issuePasswordToken')->willReturn('pwtok');
		// Passes are valid unless a test says otherwise: an upload with its pass needs no challenge.
		$this->challengeService->method('verifyPass')->willReturnCallback(fn () => $this->passesHold);
		$this->challengeService->method('issuePass')->willReturnCallback(fn (string $purpose, string $subject, int $seconds) => "pass:$purpose:$subject");
		$this->urlGenerator->method('linkToRoute')->willReturn('/login');
	}

	private function controller(): PublicController {
		return new PublicController(
			$this->request,
			$this->config,
			$this->userSession,
			$this->urlGenerator,
			$this->groupManager,
			$this->formRepository,
			$this->uploadService,
			$this->responseService,
			$this->brandingService,
			$this->realChallenges ?? $this->challengeService,
			$this->initialState,
			ReversibleCrypto::secrets(),
			new FormSanitizer(),
			new ResponseRules(new AnswerValidator($this->createMock(UploadService::class), new EnglishL10N()), $this->createMock(IndexService::class)),
			new EnglishL10N(),
			$this->throttler,
			$this->dateTimeFormatter,
			$this->timeZone,
			$this->jobList,
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
			return (new ExceptionMiddleware($request, $this->config, new EnglishL10N(), $this->createMock(LoggerInterface::class)))->afterException($controller, $method, $e);
		}
	}

	/** A logged-in user mock returning the given uid. */
	private function loginAs(string $uid, string $displayName = 'Alice'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}

	/**
	 * Make loadPublic return a form whose stored public_token matches TOKEN,
	 * so loadAndValidateForm() passes. Any settings can be overlaid.
	 */
	private function formWith(array $settings = [], array $extra = []): array {
		return array_merge([
			'title' => 'My Form',
			'questions' => [],
			'settings' => array_merge(['public_token' => 'TOKEN'], $settings),
		], $extra);
	}

	// ======================================================================
	// showForm()
	// ======================================================================

	public function testShowFormNotFoundWhenTokenMismatch(): void {
		// Stored token differs from supplied → loadAndValidateForm returns null.
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['public_token' => 'OTHER']));
		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testShowFormNotFoundWhenTheFormIsGone(): void {
		$this->formRepository->method('loadPublic')->willThrowException(new NotFoundException('Form not found'));
		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	/** A form that stays busy is a page saying so, with a 503 to retry: never "not found" (7a, 3a-3). */
	public function testShowFormOfABusyFormIsA503PageToRetry(): void {
		$this->formRepository->method('loadPublic')->willThrowException(new FormLockedException('Form 1 stayed unreadable'));
		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, '2'], [$resp->getStatus(), $resp->getHeaders()['Retry-After'] ?? null]);
	}

	public function testShowFormNotYetOpenReturnsForbidden(): void {
		$future = (new \DateTime('+1 day'))->format(\DateTime::ATOM);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_starts_at' => $future]));
		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testShowFormExpiredReturnsGone(): void {
		$past = (new \DateTime('-1 day'))->format(\DateTime::ATOM);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => $past]));
		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_GONE, $resp->getStatus());
	}

	public function testShowFormRequireLoginRedirectsAnonymous(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['require_login' => true]));
		$this->userSession->method('getUser')->willReturn(null);
		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(RedirectResponse::class, $resp);
	}

	public function testShowFormRestrictionsRedirectAnonymous(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['allowed_users' => ['bob']]));
		$this->userSession->method('getUser')->willReturn(null);
		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(RedirectResponse::class, $resp);
	}

	public function testShowFormRestrictionsUnauthorizedWhenUserNotAllowed(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['allowed_users' => ['bob']]));
		$this->loginAs('carol');
		$this->groupManager->method('isInGroup')->willReturn(false);
		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testShowFormRestrictionsAllowedViaGroupSucceeds(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['allowed_groups' => ['staff']]));
		$this->loginAs('carol');
		$this->groupManager->method('isInGroup')->with('carol', 'staff')->willReturn(true);
		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testShowFormPasswordProtectedShowsPasswordFormWhenNoPassword(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getCookie')->willReturn(null);
		$this->challengeService->method('verifyPasswordToken')->willReturn(false);
		$this->request->method('getParam')->willReturn(null);

		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		// The password form is rendered with STATUS_OK (no explicit setStatus).
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testShowFormPasswordProtectedWrongPasswordShowsPasswordForm(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getCookie')->willReturn(null);
		$this->challengeService->method('verifyPasswordToken')->willReturn(false);
		$this->request->method('getParam')->willReturn('wrong');

		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testShowFormPasswordProtectedCorrectPasswordRenders(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getCookie')->willReturn(null);
		$this->challengeService->method('verifyPasswordToken')->willReturn(false);
		$this->request->method('getParam')->willReturn('secret');

		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testShowFormPasswordCookieBypassesPrompt(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getCookie')->willReturn('cookieval');
		$this->challengeService->method('verifyPasswordToken')->with('cookieval', 1, $this->anything())->willReturn(true);

		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testShowFormSuccessProvidesInitialStateWithStrippedSettings(): void {
		$form = $this->formWith(
			[
				'webhooks' => [['secret' => 's']],
				'api_keys' => ['k'],
				'allowed_users' => [],
				'allowed_groups' => [],
				'max_responses' => 50,
				'custom' => 'not on the list',
			],
			[
				'responses' => [['id' => 'r1']],
				'_index' => ['response_count' => 7, 'answer_counts' => ['q1' => ['a' => 3, 'typed junk' => 9]]],
				'permissions' => ['x'],
				'branding' => ['color' => '#123'],
				'questions' => [[
					'id' => 'q1',
					'type' => 'choice',
					'options' => [['value' => 'a', 'capacity' => 5]],
				]],
			]
		);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);

		$captured = [];
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $value) use (&$captured): void {
				$captured[$key] = $value;
			});

		$resp = $this->respond('showForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());

		$this->assertSame(1, $captured['fileId']);
		$this->assertSame('TOKEN', $captured['token']);
		// Form-specific branding wins over admin default.
		$this->assertSame(['color' => '#123'], $captured['branding']);

		$providedForm = $captured['form'];
		// Sensitive keys stripped.
		$this->assertArrayNotHasKey('public_token', $providedForm['settings']);
		$this->assertArrayNotHasKey('webhooks', $providedForm['settings']);
		$this->assertArrayNotHasKey('api_keys', $providedForm['settings']);
		// What the page needs is kept; anything not on the allowlist is not.
		$this->assertSame(50, $providedForm['settings']['max_responses']);
		$this->assertArrayNotHasKey('custom', $providedForm['settings']);
		// Response payload internals removed.
		$this->assertArrayNotHasKey('responses', $providedForm);
		$this->assertArrayNotHasKey('permissions', $providedForm);
		$this->assertArrayNotHasKey('branding', $providedForm);
		// _index rebuilt with count + capacity-only answer counts.
		$this->assertSame(7, $providedForm['_index']['response_count']);
		$this->assertSame(['q1' => ['a' => 3]], $providedForm['_index']['answer_counts']);
	}

	/** A signed-in respondent is written to at their account's address: the page learns it to say so. */
	public function testASignedInRespondentLearnsWhereTheirConfirmationGoes(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['sendConfirmationEmail' => true]));
		$this->loginAs('alice')->method('getEMailAddress')->willReturn('alice@school.example');
		$captured = [];
		$this->initialState->method('provideInitialState')->willReturnCallback(function (string $key, $value) use (&$captured): void {
			$captured[$key] = $value;
		});

		$this->respond('showForm', 1, 'TOKEN');

		$this->assertSame(['email' => 'alice@school.example'], $captured['respondent']);
	}

	/** @return array<string, array{array, bool}> the form's settings, and whether someone is signed in */
	public static function noConfirmationToSpeakOf(): array {
		return ['anonymous' => [['sendConfirmationEmail' => true], false], 'no confirmation email' => [[], true]];
	}

	/**
	 * @dataProvider noConfirmationToSpeakOf
	 */
	public function testOnlyASignedInRespondentOfAFormThatConfirmsLearnsIt(array $settings, bool $signedIn): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith($settings));
		if ($signedIn) {
			$this->loginAs('alice')->method('getEMailAddress')->willReturn('alice@school.example');
		} else {
			$this->userSession->method('getUser')->willReturn(null);
		}
		$captured = [];
		$this->initialState->method('provideInitialState')->willReturnCallback(function (string $key, $value) use (&$captured): void {
			$captured[$key] = $value;
		});

		$this->respond('showForm', 1, 'TOKEN');

		$this->assertArrayHasKey('form', $captured);
		$this->assertArrayNotHasKey('respondent', $captured);
	}

	private function quizForm(): array {
		return $this->formWith([], ['questions' => [[
			'id' => 'q1',
			'type' => 'choice',
			'options' => [['id' => 'a', 'label' => 'A', 'value' => 'a', 'score' => 10], ['id' => 'b', 'label' => 'B', 'value' => 'b', 'score' => 0]],
		]]]);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function publicPages(): array {
		return ['showForm' => ['showForm'], 'authenticate' => ['authenticate'], 'embedForm' => ['embedForm']];
	}

	/**
	 * The option scores are the quiz's answer key (M14).
	 *
	 * @dataProvider publicPages
	 */
	public function testThePublicFormDoesNotRevealQuizScores(string $page): void {
		$this->formRepository->method('loadPublic')->willReturn($this->quizForm());
		$this->userSession->method('getUser')->willReturn(null);
		$this->config->method('getAppValue')->willReturn('*');
		$captured = [];
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $value) use (&$captured): void {
				$captured[$key] = $value;
			});

		$this->controller()->$page(1, 'TOKEN');

		$this->assertSame([['id' => 'a', 'label' => 'A', 'value' => 'a'], ['id' => 'b', 'label' => 'B', 'value' => 'b']], $captured['form']['questions'][0]['options']);
	}

	public function testASubmissionIsScoredAgainstTheFormWithItsScores(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->quizForm());
		$this->responseService->expects($this->once())->method('submitAnonymousWithForm')
			->with(1, $this->callback(fn (array $form) => $form['questions'][0]['options'][0]['score'] === 10))
			->willReturn(['id' => 'r1', 'submitted_at' => 'now', 'score' => ['total' => 10, 'max' => 10, 'percentage' => 100]]);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);

		$this->assertSame(10, $resp->getData()['score']['total']);
	}

	public function testShowFormCapacityCountsExcludeNonCapacityQuestions(): void {
		$form = $this->formWith([], [
			'_index' => [
				'response_count' => 2,
				'answer_counts' => ['q1' => ['a' => 1], 'q2' => ['b' => 4]],
			],
			'questions' => [
				['id' => 'q1', 'type' => 'choice', 'options' => [['value' => 'a', 'capacity' => 3]]],
				['id' => 'q2', 'type' => 'choice', 'options' => [['label' => 'no cap']]],
			],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);

		$captured = [];
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $value) use (&$captured): void {
				$captured[$key] = $value;
			});

		$this->respond('showForm', 1, 'TOKEN');
		$counts = $captured['form']['_index']['answer_counts'];
		$this->assertArrayHasKey('q1', $counts);
		$this->assertArrayNotHasKey('q2', $counts);
	}

	// ======================================================================
	// challenge()
	// ======================================================================

	public function testChallengeReturnsIssuedPayload(): void {
		$this->challengeService->method('issue')->with(42)->willReturn(['challenge' => 'abc', 'difficulty' => 4]);
		$resp = $this->respond('challenge', 42, 'ignored');
		$this->assertInstanceOf(DataResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['challenge' => 'abc', 'difficulty' => 4], $resp->getData());
	}

	// ======================================================================
	// submit()
	// ======================================================================

	public function testSubmitAnonymousFailedChallengeReturns429(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(false);
		// Must fail before touching the form.
		$this->formRepository->expects($this->never())->method('loadPublic');

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['payload' => 'x']);
		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $resp->getStatus());
		$this->assertSame('Challenge verification failed. Please refresh the page.', $resp->getData()['error']);
	}

	public function testSubmitNotFoundWhenTokenMismatch(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['public_token' => 'OTHER']));

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('Form not found', $resp->getData()['error']);
		$this->assertTrue($resp->isThrottled(), 'guessing a link counts towards brute-force protection');
	}

	/**
	 * Only a form that is not there is a counted not found. A busy one is a 503 to retry and a broken one an internal
	 * error; neither counts, or a moment of load would block the respondents behind one address (7a, 3a-3).
	 *
	 * @return array<string, array{\Exception, int, string, bool}>
	 */
	public static function loadFailures(): array {
		return [
			'gone' => [new NotFoundException('Form not found'), Http::STATUS_NOT_FOUND, 'not_found', true],
			'busy' => [new FormLockedException('Form 1 stayed unreadable'), Http::STATUS_SERVICE_UNAVAILABLE, 'busy', false],
			'broken' => [new \RuntimeException('Invalid form file format'), Http::STATUS_INTERNAL_SERVER_ERROR, 'internal_error', false],
		];
	}

	/**
	 * @dataProvider loadFailures
	 */
	public function testSubmitCountsOnlyAGoneFormTowardsBruteForceProtection(\Exception $failure, int $status, string $reason, bool $counted): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willThrowException($failure);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);

		$this->assertSame([$status, $reason, $counted], [$resp->getStatus(), $resp->getData()['reason'], $resp->isThrottled()]);
	}

	public function testAnUploadToABusyFormIsA503ToRetry(): void {
		$this->formRepository->method('loadPublic')->willThrowException(new FormLockedException('Form 1 stayed unreadable'));

		$resp = $this->respond('uploadFile', 1, 'TOKEN');

		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, 'busy'], [$resp->getStatus(), $resp->getData()['reason']]);
	}

	public function testSubmitBlockedWhenNotYetOpen(): void {
		$future = (new \DateTime('+1 day'))->format(\DateTime::ATOM);
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_starts_at' => $future]));

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('This form is not yet open', $resp->getData()['error']);
		$this->assertArrayHasKey('opensAt', $resp->getData());
	}

	public function testSubmitBlockedWhenExpired(): void {
		$past = (new \DateTime('-1 day'))->format(\DateTime::ATOM);
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => $past]));

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame(Http::STATUS_GONE, $resp->getStatus());
		$this->assertSame('This form has expired', $resp->getData()['error']);
	}

	public function testSubmitPasswordRequiredWhenNoPasswordAndNoCookie(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->request->method('getCookie')->willReturn(null);
		$this->challengeService->method('verifyPasswordToken')->willReturn(false);
		$this->request->method('getParam')->willReturn('');

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
		$this->assertSame('Password required', $resp->getData()['error']);
	}

	public function testSubmitPasswordCookieBypassesPasswordGate(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->request->method('getCookie')->willReturn('cv');
		$this->challengeService->method('verifyPasswordToken')->with('cv', 1, $this->anything())->willReturn(true);
		$this->responseService->method('submitAnonymousWithForm')
			->willReturn(['id' => 'resp1', 'submitted_at' => '2026-01-01T00:00:00Z']);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
	}

	public function testSubmitRestrictionsForbiddenWhenUserNotAllowed(): void {
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['allowed_users' => ['bob']]));
		$this->loginAs('carol');
		$this->groupManager->method('isInGroup')->willReturn(false);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], null);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('You do not have permission to submit this form', $resp->getData()['error']);
	}

	public function testSubmitRequireLoginForbiddenWhenAnonymous(): void {
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['require_login' => true]));
		// No user → verify() skipped because getUser() null triggers challenge,
		// but challenge passes; then require_login gate rejects.
		$this->userSession->method('getUser')->willReturn(null);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame([Http::STATUS_UNAUTHORIZED, 'login_required'], [$resp->getStatus(), $resp->getData()['reason']]);
		$this->assertSame('This form requires you to be logged in', $resp->getData()['error']);
	}

	public function testSubmitAnonymousSuccessReturnsCreatedWithResponseShape(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
		$this->responseService->method('submitAnonymousWithForm')
			->willReturn(['id' => 'resp1', 'submitted_at' => '2026-01-01T00:00:00Z']);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$data = $resp->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('resp1', $data['response']['id']);
		$this->assertSame('2026-01-01T00:00:00Z', $data['response']['submitted_at']);
		$this->assertArrayNotHasKey('score', $data);
	}

	public function testSubmitIncludesScoreWhenQuizMode(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
		$this->responseService->method('submitAnonymousWithForm')
			->willReturn(['id' => 'r', 'submitted_at' => 'now', 'score' => 42]);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame(42, $resp->getData()['score']);
	}

	public function testSubmitAuthenticatedWhenNonAnonymousAndRequireLogin(): void {
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn(
			$this->formWith(['anonymous' => false, 'require_login' => true])
		);
		$this->loginAs('dave', 'Dave D');
		$this->responseService->expects($this->once())->method('submitAuthenticated')
			->with(1, $this->isType('array'), ['q1' => 'a'], 'dave', 'Dave D')
			->willReturn(['id' => 'ra', 'submitted_at' => 'now']);
		$this->responseService->expects($this->never())->method('submitAnonymousWithForm');

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], null);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$this->assertSame('ra', $resp->getData()['response']['id']);
	}

	/** Not anonymous means the response says who answered, so respondents always sign in (PO 2026-10-12). */
	public function testANonAnonymousFormRefusesARespondentWhoIsNotSignedIn(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => false]));
		$this->responseService->expects($this->never())->method('submitAnonymousWithForm');
		$this->responseService->expects($this->never())->method('submitAuthenticated');

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);

		$this->assertSame([Http::STATUS_UNAUTHORIZED, 'login_required'], [$resp->getStatus(), $resp->getData()['reason']]);
		$this->assertSame('This form requires you to be logged in', $resp->getData()['error']);
	}

	/** Review: with collecting anonymously off, a signed-in respondent was stored anonymously. */
	public function testANonAnonymousFormStoresWhoAnswered(): void {
		$this->loginAs('dave', 'Dave D');
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => false]));
		$this->responseService->expects($this->once())->method('submitAuthenticated')
			->with(1, $this->isType('array'), ['q1' => 'a'], 'dave', 'Dave D')
			->willReturn(['id' => 'x', 'submitted_at' => 'now']);
		$this->responseService->expects($this->never())->method('submitAnonymousWithForm');

		$this->assertSame(Http::STATUS_CREATED, $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x'])->getStatus());
	}

	public function testANonAnonymousFormSendsAVisitorToTheLoginPage(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => false]));
		$this->urlGenerator->method('linkToRoute')->willReturn('/login');

		$this->assertInstanceOf(RedirectResponse::class, $this->respond('showForm', 1, 'TOKEN'));
	}

	/** Signing in only proves membership; the response stays anonymous and is counted per account (M34). */
	public function testAnAnonymousFormForAGroupCountsPerAccountWithoutStoringTheName(): void {
		$this->loginAs('dave', 'Dave D');
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => true, 'allowed_groups' => ['staff']]));
		$this->responseService->expects($this->once())->method('submitAnonymousWithForm')
			->with(1, $this->anything(), ['q1' => 'a'], $this->anything(), 'dave')
			->willReturn(['id' => 'x', 'submitted_at' => 'now']);
		$this->responseService->expects($this->never())->method('submitAuthenticated');

		$this->assertSame(Http::STATUS_CREATED, $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x'])->getStatus());
	}

	/** Anonymous without a login requirement: whoever is signed in, they count like any visitor. */
	public function testAnAnonymousFormWithoutLoginDoesNotUseTheAccount(): void {
		$this->loginAs('dave', 'Dave D');
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
		$this->responseService->expects($this->once())->method('submitAnonymousWithForm')
			->with(1, $this->anything(), ['q1' => 'a'], $this->anything(), null)
			->willReturn(['id' => 'x', 'submitted_at' => 'now']);

		$this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
	}

	public function testAnAnonymousFormStaysAnonymousForASignedInRespondent(): void {
		$this->loginAs('dave', 'Dave D');
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
		$this->responseService->expects($this->once())->method('submitAnonymousWithForm')->willReturn(['id' => 'x', 'submitted_at' => 'now']);
		$this->responseService->expects($this->never())->method('submitAuthenticated');

		$this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
	}

	/** A refusal of the form's rules keeps its own words and a reason code integrations can rely on. */
	public function testARefusalOfTheFormsRulesIsAnsweredWithItsReason(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
		$this->responseService->method('submitAnonymousWithForm')->willThrowException(ResponseRefusedException::alreadyAnswered());

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame([Http::STATUS_CONFLICT, 'already_answered'], [$resp->getStatus(), $resp->getData()['reason']]);
		$this->assertSame('You have already submitted a response to this form', $resp->getData()['error']);
	}

	/** Review: a write or lock failure showed internal diagnostics to anonymous respondents. */
	public function testAnUnexpectedFailureTellsTheRespondentNothingInternal(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
		$this->responseService->method('submitAnonymousWithForm')
			->willThrowException(new \RuntimeException('Could not write form file (fileId 1): /var/www/data/anna/files/f.fvform [storage local]'));

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
		$this->assertSame(['error' => 'Something went wrong, please try again', 'reason' => 'internal_error', 'requestId' => 'req-1'], $resp->getData());
	}

	public function testSubmitLoggedInUserSkipsChallenge(): void {
		// A logged-in user bypasses the ALTCHA check entirely.
		$this->loginAs('eve');
		$this->challengeService->expects($this->never())->method('verify');
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
		$this->responseService->method('submitAnonymousWithForm')
			->willReturn(['id' => 'z', 'submitted_at' => 'now']);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], null);
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
	}

	private const SUBMISSION = '0f8b6c1e-aaaa-4bbb-8ccc-123456789abc';

	/** A retry after "busy" carries its pass: it paid the challenge already and is not asked again (7a, 3a-5). */
	public function testARetryWithItsPassNeedsNoNewChallenge(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->expects($this->never())->method('verify');
		$this->formRepository->method('loadPublic')->willReturn($this->formWith());
		$this->responseService->expects($this->once())->method('submitAnonymousWithForm')
			->with(1, $this->anything(), ['q1' => 'a'], $this->anything(), null, self::SUBMISSION)
			->willReturn(['id' => 'r1', 'submitted_at' => 'now']);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], null, self::SUBMISSION, 'pass');

		$this->assertSame([Http::STATUS_CREATED, 'r1'], [$resp->getStatus(), $resp->getData()['response']['id']]);
	}

	/** A retry whose pass ran out pays the challenge again; sent without one it is refused but not counted as an attack. */
	public function testARetryWhosePassRanOutPaysTheChallengeAgain(): void {
		$this->passesHold = false;
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(false);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], null, self::SUBMISSION, 'old-pass');

		$this->assertSame([Http::STATUS_TOO_MANY_REQUESTS, false], [$resp->getStatus(), $resp->isThrottled()]);
	}

	/**
	 * Only a wrong proof counts towards brute-force protection; none at all is what a browser whose solving failed
	 * sends, and many of those behind one school network would block each other (review 3a-5).
	 *
	 * @return array<string, array{?array, bool}>
	 */
	public static function proofs(): array {
		return ['none' => [null, false], 'a wrong one' => [['number' => 1, 'salt' => 'x'], true]];
	}

	/**
	 * @dataProvider proofs
	 */
	public function testOnlyAWrongProofCountsAsAnAttack(?array $altcha, bool $counted): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(false);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], $altcha);

		$this->assertSame([Http::STATUS_TOO_MANY_REQUESTS, 'challenge_failed', $counted], [$resp->getStatus(), $resp->getData()['reason'], $resp->isThrottled()]);
	}

	/** A retry of a stored submission is answered as stored, also when the form closed meanwhile (review 3a-5). */
	public function testARetryOfAStoredSubmissionIsAnsweredEvenAfterTheFormClosed(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$past = (new \DateTime('-1 day'))->format(\DateTime::ATOM);
		$answers = ['q1' => 'a'];
		$form = $this->formWith(['share_expires_at' => $past], ['responses' => [['id' => 'r1', 'submitted_at' => 'then']]]);
		$form[\OCA\FormVox\Service\SubmissionIds::KEY] = [\OCA\FormVox\Service\SubmissionIds::of(self::SUBMISSION, $answers) => 'r1'];
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->responseService->expects($this->never())->method('submitAnonymousWithForm');
		// What its first try set off may still wait (review of the 3a fixes).
		$this->responseService->expects($this->once())->method('retried')->with(1, $form, ['id' => 'r1', 'submitted_at' => 'then']);

		$resp = $this->respond('submit', 1, 'TOKEN', $answers, ['p' => 'x'], self::SUBMISSION);

		$this->assertSame([Http::STATUS_CREATED, 'r1'], [$resp->getStatus(), $resp->getData()['response']['id']]);
	}

	/**
	 * A busy form answers 503 with a pass bound to this fill, so the browser's retry with the same id needs no new
	 * challenge; without an id there is nothing to bind it to.
	 *
	 * @return array<string, array{\Exception, ?string, bool}>
	 */
	public static function busyMoments(): array {
		return [
			'FormVox held the form' => [new FormLockedException('Form 1 stayed busy'), self::SUBMISSION, true],
			'Nextcloud held the file' => [new LockedException('/anna/files/Survey.fvform'), self::SUBMISSION, true],
			'no submission id' => [new FormLockedException('Form 1 stayed busy'), null, false],
		];
	}

	/**
	 * @dataProvider busyMoments
	 */
	public function testABusySubmissionCarriesAPassToRetry(\Exception $busy, ?string $submissionId, bool $pass): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith());
		$this->responseService->method('submitAnonymousWithForm')->willThrowException($busy);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x'], $submissionId);

		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, 'busy', false], [$resp->getStatus(), $resp->getData()['reason'], $resp->isThrottled()]);
		$this->assertSame($pass, isset($resp->getData()['retryToken']));
		$this->assertStringNotContainsString('Survey', json_encode($resp->getData()));
	}

	/** Not an id the browser makes: no retry, no pass, stored like any submission. */
	public function testASubmissionIdThatIsNoneIsIgnored(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(true);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith());
		$this->responseService->expects($this->once())->method('submitAnonymousWithForm')
			->with(1, $this->anything(), ['q1' => 'a'], $this->anything(), null, null)
			->willReturn(['id' => 'r1', 'submitted_at' => 'now']);

		$this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], ['p' => 'x'], '../../etc');
	}

	// ======================================================================
	// embedForm() / embedAuthenticate()
	// ======================================================================

	public function testEmbedFormDelegatesToShowFormAndSetsFrameHeaders(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith());
		$this->userSession->method('getUser')->willReturn(null);
		$this->config->method('getAppValue')->willReturn('*');

		$resp = $this->respond('embedForm', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$headers = $resp->getHeaders();
		$this->assertSame('ALLOWALL', $headers['X-Frame-Options'] ?? null);
		// secu#6: frame-ancestors is added to the page's policy, which keeps everything else.
		$this->assertStringContainsString("frame-ancestors 'self' *", $headers['Content-Security-Policy'] ?? '');
		$this->assertStringContainsString("default-src 'none'", $headers['Content-Security-Policy'] ?? '');
		$this->assertStringContainsString('worker-src blob:', $headers['Content-Security-Policy'] ?? '');
	}

	public function testEmbedFormWithSpecificDomainsUsesSameOrigin(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith());
		$this->userSession->method('getUser')->willReturn(null);
		$this->config->method('getAppValue')->willReturn('example.com, https://foo.test');

		$resp = $this->respond('embedForm', 1, 'TOKEN');
		$headers = $resp->getHeaders();
		$this->assertSame('SAMEORIGIN', $headers['X-Frame-Options'] ?? null);
		$csp = $headers['Content-Security-Policy'] ?? '';
		$this->assertStringContainsString("'self'", $csp);
		$this->assertStringContainsString('https://example.com', $csp);
		$this->assertStringContainsString('https://foo.test', $csp);
		$this->assertStringContainsString("default-src 'none'", $csp);
	}

	public function testEmbedFormDoesNotSetFrameHeadersOnRedirect(): void {
		// require_login anonymous → RedirectResponse, setEmbedHeaders is a no-op.
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['require_login' => true]));
		$this->userSession->method('getUser')->willReturn(null);

		$resp = $this->respond('embedForm', 1, 'TOKEN');
		$this->assertInstanceOf(RedirectResponse::class, $resp);
	}

	public function testEmbedAuthenticateDelegatesAndSetsHeaders(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith());
		$this->config->method('getAppValue')->willReturn('*');
		$resp = $this->respond('embedAuthenticate', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertArrayHasKey('X-Frame-Options', $resp->getHeaders());
	}

	// ======================================================================
	// authenticate()
	// ======================================================================

	public function testAuthenticateNotFoundWhenTokenMismatch(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['public_token' => 'OTHER']));
		$resp = $this->respond('authenticate', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testAuthenticateNotYetOpenForbidden(): void {
		$future = (new \DateTime('+1 day'))->format(\DateTime::ATOM);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_starts_at' => $future]));
		$resp = $this->respond('authenticate', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testAuthenticateExpiredGone(): void {
		$past = (new \DateTime('-1 day'))->format(\DateTime::ATOM);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => $past]));
		$resp = $this->respond('authenticate', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_GONE, $resp->getStatus());
	}

	public function testAuthenticateWrongPasswordShowsPasswordFormAndThrottles(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->request->method('getParam')->willReturn('wrong');

		$resp = $this->respond('authenticate', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		// Password form itself has no explicit status → OK.
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testAuthenticateEmptyPasswordShowsPasswordForm(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->request->method('getParam')->willReturn('');

		$resp = $this->respond('authenticate', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testAuthenticateCorrectPasswordRendersFormAndSetsCookie(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->request->method('getParam')->willReturn('secret');
		$this->challengeService->expects($this->once())->method('issuePasswordToken')->with(1, $this->anything())->willReturn('tok');

		$captured = [];
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $value) use (&$captured): void {
				$captured[$key] = $value;
			});

		$resp = $this->respond('authenticate', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		// Cookie set for password verification carry-over.
		$cookies = $resp->getCookies();
		$this->assertArrayHasKey('formvox_pw_1', $cookies);
		// Sensitive settings stripped from provided state.
		$this->assertArrayNotHasKey('public_token', $captured['form']['settings']);
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function storedPasswordHashes(): array {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		return [
			'encrypted, right password' => [ReversibleCrypto::secrets()->seal($hash), 'secret', true],
			'encrypted, wrong password' => [ReversibleCrypto::secrets()->seal($hash), 'wrong', false],
			'from before encryption, right password' => [$hash, 'secret', true],
			'encrypted by another server' => ['enc:v1:foreign', 'secret', false],
		];
	}

	/**
	 * @dataProvider storedPasswordHashes
	 */
	public function testThePasswordIsCheckedAgainstTheDecryptedHash(string $stored, string $given, bool $letIn): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $stored]));
		$this->request->method('getParam')->willReturn($given);
		$this->challengeService->method('issuePasswordToken')->willReturn('tok');

		$resp = $this->respond('authenticate', 1, 'TOKEN');

		$this->assertSame($letIn, array_key_exists('formvox_pw_1', $resp->getCookies()));
	}

	public function testAuthenticateNoPasswordFormRendersWithoutCookie(): void {
		// Form is not password protected → passwordVerified stays false, no cookie.
		$this->formRepository->method('loadPublic')->willReturn($this->formWith());
		$this->challengeService->expects($this->never())->method('issuePasswordToken');

		$resp = $this->respond('authenticate', 1, 'TOKEN');
		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertArrayNotHasKey('formvox_pw_1', $resp->getCookies());
	}

	/** Review: an unreadable date showed a raw DateTime error here and left submissions open; now the form is closed. */
	public function testAnUnreadableClosingDateShowsTheClosedPage(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => 'not-a-date']));

		foreach (['authenticate', 'showForm'] as $page) {
			$resp = $this->respond($page, 1, 'TOKEN');
			$this->assertInstanceOf(TemplateResponse::class, $resp);
			$this->assertSame(Http::STATUS_GONE, $resp->getStatus(), $page);
		}
	}

	// ======================================================================
	// uploadFile()
	// ======================================================================

	public function testUploadFileNotFoundWhenTokenMismatch(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['public_token' => 'OTHER']));
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
		$this->assertSame('Form not found', $resp->getData()['error']);
	}

	public function testUploadFileBlockedWhenExpired(): void {
		$past = (new \DateTime('-1 day'))->format(\DateTime::ATOM);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => $past]));
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_GONE, $resp->getStatus());
	}

	public function testUploadFileForbiddenWhenUserNotAllowed(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['allowed_users' => ['bob']]));
		$this->loginAs('carol');
		$this->groupManager->method('isInGroup')->willReturn(false);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
		$this->assertSame('You do not have permission to upload files to this form', $resp->getData()['error']);
	}

	public function testUploadFileForbiddenWhenRequireLoginAnonymous(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['require_login' => true]));
		$this->userSession->method('getUser')->willReturn(null);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame([Http::STATUS_UNAUTHORIZED, 'login_required'], [$resp->getStatus(), $resp->getData()['reason']]);
		$this->assertSame('This form requires you to be logged in', $resp->getData()['error']);
	}

	public function testUploadFileBadRequestWhenNoQuestionId(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith());
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturn(null);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('Question ID is required', $resp->getData()['error']);
	}

	public function testUploadFileBadRequestWhenQuestionNotFileType(): void {
		$form = $this->formWith([], [
			'questions' => [['id' => 'q1', 'type' => 'text']],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
		]);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('Invalid question', $resp->getData()['error']);
	}

	public function testUploadFileBadRequestWhenQuestionMissing(): void {
		$form = $this->formWith([], ['questions' => []]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'nope'],
		]);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('Invalid question', $resp->getData()['error']);
	}

	public function testUploadFileBadRequestWhenNoFileUploaded(): void {
		$form = $this->formWith([], [
			'questions' => [['id' => 'q1', 'type' => 'file']],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
		]);
		$this->request->method('getUploadedFile')->willReturn(null);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('No file was uploaded', $resp->getData()['error']);
	}

	public function testUploadFileBadRequestWhenUploadErrorCode(): void {
		$form = $this->formWith([], [
			'questions' => [['id' => 'q1', 'type' => 'file']],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
		]);
		$this->request->method('getUploadedFile')->willReturn([
			'name' => 'x.pdf', 'type' => 'application/pdf', 'size' => 10, 'error' => UPLOAD_ERR_INI_SIZE,
		]);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('The file exceeds the maximum upload size', $resp->getData()['error']);
	}

	public function testUploadFileBadRequestWhenTooLarge(): void {
		$form = $this->formWith([], [
			'questions' => [['id' => 'q1', 'type' => 'file', 'maxFileSize' => 1]],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
		]);
		$this->request->method('getUploadedFile')->willReturn([
			'name' => 'x.pdf', 'type' => 'application/pdf',
			'size' => 2 * 1024 * 1024, 'error' => UPLOAD_ERR_OK,
		]);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('File is too large. The maximum size is 1 MB.', $resp->getData()['error']);
	}

	/** Review: nothing capped an upload on the server; a form allowing 500 MB from before the limit still gets 100. */
	public function testAnUploadAboveTheServersLimitIsRefusedWhateverTheFormSays(): void {
		$form = $this->formWith([], ['questions' => [['id' => 'q1', 'type' => 'file', 'maxFileSize' => 500]]]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([['questionId', null, 'q1']]);
		$this->request->method('getUploadedFile')->willReturn(['name' => 'x.pdf', 'type' => 'application/pdf', 'size' => 150 * 1024 * 1024, 'error' => UPLOAD_ERR_OK]);
		$this->uploadService->expects($this->never())->method('storeUploadPublic');

		$resp = $this->respond('uploadFile', 1, 'TOKEN');

		$this->assertSame([Http::STATUS_BAD_REQUEST, 'File is too large. The maximum size is 100 MB.'], [$resp->getStatus(), $resp->getData()['error']]);
	}

	public function testUploadFileBadRequestWhenDisallowedType(): void {
		$form = $this->formWith([], [
			'questions' => [[
				'id' => 'q1', 'type' => 'file',
				'allowedTypes' => ['application/pdf'],
			]],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
		]);
		$this->request->method('getUploadedFile')->willReturn([
			'name' => 'x.png', 'type' => 'image/png', 'size' => 10, 'error' => UPLOAD_ERR_OK,
		]);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('This file type is not allowed', $resp->getData()['error']);
	}

	public function testUploadFileBadRequestWhenDangerousTypeUnderWildcard(): void {
		// No allowedTypes → wildcard path blocks dangerous extensions.
		$form = $this->formWith([], [
			'questions' => [['id' => 'q1', 'type' => 'file']],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
		]);
		$this->request->method('getUploadedFile')->willReturn([
			'name' => 'evil.php', 'type' => 'application/octet-stream',
			'size' => 10, 'error' => UPLOAD_ERR_OK,
		]);
		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
		$this->assertSame('This file type is not allowed', $resp->getData()['error']);
	}

	public function testUploadFileSuccessStoresAndReturnsCreated(): void {
		$form = $this->formWith([], [
			'questions' => [['id' => 'q1', 'type' => 'file', 'allowedTypes' => ['application/pdf']]],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
			['tempResponseId', null, '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f'],
		]);
		$uploaded = [
			'name' => 'doc.pdf', 'type' => 'application/pdf',
			'size' => 100, 'error' => UPLOAD_ERR_OK,
		];
		$this->request->method('getUploadedFile')->willReturn($uploaded);
		$this->uploadService->expects($this->once())->method('storeUploadPublic')
			->with(1, '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', $uploaded)
			->willReturn(['fileId' => 99, 'name' => 'doc.pdf']);

		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame(99, $data['fileId']);
		$this->assertSame('5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', $data['tempResponseId']);
	}

	/**
	 * The temp id becomes a folder name next to the form (L18, T58).
	 *
	 * @return array<string, array{string}>
	 */
	public static function unsafeTempResponseIds(): array {
		return ['parent' => ['..'], 'path' => ['../../etc'], 'slash' => ['a/b'], 'not an id' => ['temp-abc']];
	}

	/**
	 * @dataProvider unsafeTempResponseIds
	 */
	public function testUploadFileRejectsUnsafeTempResponseId(string $tempResponseId): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith([], [
			'questions' => [['id' => 'q1', 'type' => 'file', 'allowedTypes' => ['*/*']]],
		]));
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
			['tempResponseId', null, $tempResponseId],
		]);
		$this->request->method('getUploadedFile')->willReturn(['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 1, 'error' => UPLOAD_ERR_OK]);
		$this->uploadService->expects($this->never())->method('storeUploadPublic');

		$resp = $this->respond('uploadFile', 1, 'TOKEN');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	private function fileForm(int $maxFiles = 1): array {
		return $this->formWith([], ['questions' => [['id' => 'q1', 'type' => 'file', 'maxFiles' => $maxFiles, 'allowedTypes' => ['application/pdf']]]]);
	}

	/** @param array<string, string> $params */
	private function uploadWith(array $params): void {
		$map = [];
		foreach (['questionId' => 'q1', 'tempResponseId' => '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f'] + $params as $key => $value) {
			$map[] = [$key, null, $value];
			$map[] = [$key, '', $value];
		}
		$this->request->method('getParam')->willReturnMap($map);
		$this->request->method('getUploadedFile')->willReturn(['name' => 'doc.pdf', 'type' => 'application/pdf', 'size' => 100, 'error' => UPLOAD_ERR_OK]);
	}

	/**
	 * The first file of a response pays the challenge and gets a pass for the others, instead of 50 uploads an hour per
	 * address that 30 people behind one school network ran into (7a, 3a-5).
	 */
	public function testTheFirstUploadPaysTheChallengeAndGetsAPassForTheRest(): void {
		$this->passesHold = false;
		$this->userSession->method('getUser')->willReturn(null);
		$this->formRepository->method('loadPublic')->willReturn($this->fileForm());
		$this->challengeService->expects($this->once())->method('verify')->with(['number' => 1], 1)->willReturn(true);
		$this->uploadWith(['altcha' => '{"number":1}']);
		$this->uploadService->method('storeUploadPublic')->willReturn(['fileId' => 99]);

		$resp = $this->respond('uploadFile', 1, 'TOKEN');

		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$this->assertSame('pass:upload:1:5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', $resp->getData()['uploadGrant']);
	}

	/**
	 * @dataProvider uploadProofs
	 */
	public function testAnUploadWithoutPassOrChallengeIsRefused(array $params, bool $counted): void {
		$this->passesHold = false;
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->method('verify')->willReturn(false);
		$this->uploadService->expects($this->never())->method('storeUploadPublic');
		$this->uploadWith($params);

		$resp = $this->respond('uploadFile', 1, 'TOKEN');

		$this->assertSame([Http::STATUS_TOO_MANY_REQUESTS, 'challenge_failed', $counted], [$resp->getStatus(), $resp->getData()['reason'], $resp->isThrottled()]);
	}

	/** @return array<string, array{array<string, string>, bool}> */
	public static function uploadProofs(): array {
		return ['no proof: refused, not counted' => [[], false], 'a wrong proof: counted' => [['altcha' => '{"number":1}'], true]];
	}

	/** The first file of a fill plans the clean-up of its folder, for when nobody submits it. */
	public function testTheFirstFileOfAFillPlansItsCleanUp(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->formRepository->method('loadPublic')->willReturn($this->fileForm());
		$this->uploadWith(['uploadGrant' => 'pass']);
		$this->uploadService->method('countUploadsPublic')->willReturn(0);
		$this->uploadService->method('storeUploadPublic')->willReturn(['fileId' => 99]);
		$this->jobList->expects($this->once())->method('scheduleAfter')
			->with(\OCA\FormVox\BackgroundJob\UploadCleanupJob::class, $this->greaterThan(time() + 86000), ['fileId' => 1, 'group' => '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f']);

		$this->respond('uploadFile', 1, 'TOKEN');
	}

	/** A pass is no open door: one response takes what its file questions take, with room for a retry. */
	public function testAResponseTakesNoMoreFilesThanItsQuestions(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->formRepository->method('loadPublic')->willReturn($this->fileForm(2));
		$this->uploadWith(['uploadGrant' => 'pass']);
		// Room for what its questions take, retries and replaced files: 3 x 2 + 5.
		$this->uploadService->method('countUploadsPublic')->willReturn(11);
		$this->uploadService->expects($this->never())->method('storeUploadPublic');

		$resp = $this->respond('uploadFile', 1, 'TOKEN');

		$this->assertSame([Http::STATUS_BAD_REQUEST, 'No more files can be added to this response'], [$resp->getStatus(), $resp->getData()['error']]);
	}

	public function testASignedInUploadNeedsNoChallengeAndGetsNoPass(): void {
		$this->loginAs('eve');
		$this->challengeService->expects($this->never())->method('verify');
		$this->formRepository->method('loadPublic')->willReturn($this->fileForm());
		$this->uploadWith([]);
		$this->uploadService->method('storeUploadPublic')->willReturn(['fileId' => 99]);

		$resp = $this->respond('uploadFile', 1, 'TOKEN');

		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		$this->assertArrayNotHasKey('uploadGrant', $resp->getData());
	}

	public function testUploadFileGeneratesTempResponseIdWhenMissing(): void {
		$form = $this->formWith([], [
			'questions' => [['id' => 'q1', 'type' => 'file', 'allowedTypes' => ['*/*']]],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
			['tempResponseId', null, null],
		]);
		$this->request->method('getUploadedFile')->willReturn([
			'name' => 'notes.txt', 'type' => 'text/plain', 'size' => 5, 'error' => UPLOAD_ERR_OK,
		]);
		$this->uploadService->method('storeUploadPublic')->willReturn(['fileId' => 1]);

		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
		// A 16-byte hex token (32 chars) was generated for grouping.
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $resp->getData()['tempResponseId']);
	}

	public function testUploadFileStoreExceptionMapsTo500(): void {
		$form = $this->formWith([], [
			'questions' => [['id' => 'q1', 'type' => 'file', 'allowedTypes' => ['application/pdf']]],
		]);
		$this->formRepository->method('loadPublic')->willReturn($form);
		$this->userSession->method('getUser')->willReturn(null);
		$this->request->method('getParam')->willReturnMap([
			['questionId', null, 'q1'],
			['tempResponseId', null, '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f'],
		]);
		$this->request->method('getUploadedFile')->willReturn([
			'name' => 'doc.pdf', 'type' => 'application/pdf', 'size' => 100, 'error' => UPLOAD_ERR_OK,
		]);
		$this->uploadService->method('storeUploadPublic')->willThrowException(new \RuntimeException('disk full'));

		$resp = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
		$this->assertSame('Something went wrong, please try again', $resp->getData()['error'], 'the storage error stays in the log');
	}

	// ======================================================================
	// One gate for every public entry point (step 6)
	// ======================================================================

	/** secu#18: the right password is no way past signing in. */
	public function testThePasswordFormStillAsksToSignInWhereTheFormDoes(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash, 'require_login' => true]));
		$this->request->method('getParam')->willReturn('secret');
		$this->urlGenerator->method('linkToRoute')->willReturn('/login');

		foreach (['authenticate', 'embedAuthenticate'] as $entry) {
			$this->assertInstanceOf(RedirectResponse::class, $this->respond($entry, 1, 'TOKEN'), $entry);
		}
	}

	public function testThePasswordFormStillChecksWhoMayAnswer(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash, 'allowed_users' => ['bob']]));
		$this->request->method('getParam')->willReturn('secret');
		$this->loginAs('alice');
		$this->groupManager->method('getUserGroupIds')->willReturn([]);
		$this->initialState->expects($this->never())->method('provideInitialState');

		$resp = $this->respond('authenticate', 1, 'TOKEN');

		$this->assertInstanceOf(TemplateResponse::class, $resp);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	/**
	 * @return array<string, array{string, list<mixed>}>
	 */
	public static function passwordEntries(): array {
		return [
			'the link with ?password=' => ['showForm', [1, 'TOKEN']],
			'the password form' => ['authenticate', [1, 'TOKEN']],
			'a submission' => ['submit', [1, 'TOKEN', []]],
			'an upload' => ['uploadFile', [1, 'TOKEN']],
		];
	}

	/**
	 * qual#10, secu#17: every entry point counts a wrong password towards Nextcloud's brute-force protection.
	 *
	 * @dataProvider passwordEntries
	 */
	public function testAWrongPasswordCountsOnEveryEntryPoint(string $entry, array $args): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $key === 'password' ? 'guess' : $default);
		$this->request->method('getRemoteAddress')->willReturn('203.0.113.7');
		// Past the anti-bot check, which a submission passes first.
		$this->challengeService->method('verify')->willReturn(true);
		$this->throttler->expects($this->once())->method('sleepDelayOrThrowOnMax')->with('203.0.113.7', 'formvox_password');
		$this->throttler->expects($this->once())->method('registerAttempt')->with('formvox_password', '203.0.113.7', ['fileId' => 1]);

		$this->respond($entry, ...$args);
	}

	/** After too many wrong passwords Nextcloud answers 429; FormVox lets that through. */
	public function testTooManyWrongPasswordsGoToNextcloudsAnswer(): void {
		$hash = password_hash('secret', PASSWORD_DEFAULT);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
		$this->request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $key === 'password' ? 'guess' : $default);
		$this->challengeService->method('verify')->willReturn(true);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willThrowException(new \OCP\Security\Bruteforce\MaxDelayReached('too many'));

		$this->expectException(\OCP\Security\Bruteforce\MaxDelayReached::class);
		$this->respond('submit', 1, 'TOKEN', []);
	}

	/** qual#120: when it opens, in a named zone, not bare UTC. */
	public function testANotYetOpenFormSaysWhenInANamedZone(): void {
		$future = (new \DateTime('+1 day'))->format(\DateTime::ATOM);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_starts_at' => $future]));
		$this->dateTimeFormatter->method('formatDateTime')->willReturn('21 October 2026, 09:00');

		$resp = $this->respond('showForm', 1, 'TOKEN');

		$this->assertSame('21 October 2026, 09:00 (Europe/Amsterdam)', $resp->getParams()['opensAt']);
	}

	/** secu#55: a date that cannot be read closes the form, on the page as on a submission. */
	public function testAnUnreadableDateClosesTheForm(): void {
		$this->formRepository->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => 'not a date']));
		$this->challengeService->method('verify')->willReturn(true);

		$this->assertSame(Http::STATUS_GONE, $this->respond('showForm', 1, 'TOKEN')->getStatus());
		$this->assertSame(Http::STATUS_GONE, $this->respond('submit', 1, 'TOKEN', [])->getStatus());
	}

	// ======================================================================
	// The passes, end to end with the real ChallengeService (review 3a-5)
	// ======================================================================

	private function realChallenges(): ChallengeService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$cache = $this->createMock(\OCP\ICacheFactory::class);
		return $this->realChallenges = new ChallengeService($config, $cache, $this->createMock(\OCP\Security\ISecureRandom::class));
	}

	/** What the browser does: fetch a challenge and find its number. */
	private function solved(int $fileId): array {
		$challenge = $this->realChallenges->issue($fileId);
		for ($n = 0; $n <= $challenge['maxnumber']; $n++) {
			if (hash('sha256', $challenge['salt'] . $n) === $challenge['challenge']) {
				return ['algorithm' => 'SHA-256', 'challenge' => $challenge['challenge'], 'salt' => $challenge['salt'], 'signature' => $challenge['signature'], 'number' => $n];
			}
		}
		$this->fail('unsolvable challenge');
	}

	/** A busy form after a paid proof answers with a pass for that fill, so the retry of the file needs no new challenge. */
	public function testABusyUploadAnswersWithAPassForTheRetry(): void {
		$this->realChallenges();
		$this->userSession->method('getUser')->willReturn(null);
		$busy = true;
		$this->formRepository->method('loadPublic')->willReturnCallback(function () use (&$busy) {
			if ($busy) {
				$busy = false;
				throw new FormLockedException('busy');
			}
			return $this->fileForm(3);
		});
		$this->request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
		$this->request->method('getUploadedFile')->willReturn(['name' => 'doc.pdf', 'type' => 'application/pdf', 'size' => 100, 'error' => UPLOAD_ERR_OK]);
		$this->uploadService->method('storeUploadPublic')->willReturn(['fileId' => 99]);
		$group = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

		$this->params = ['questionId' => 'q1', 'tempResponseId' => $group, 'altcha' => json_encode($this->solved(1))];
		$first = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $first->getStatus());
		$this->assertFalse($first->isThrottled(), 'busy is no attack');

		$this->params = ['questionId' => 'q1', 'tempResponseId' => $group, 'uploadGrant' => $first->getData()['uploadGrant']];
		$this->assertSame(Http::STATUS_CREATED, $this->respond('uploadFile', 1, 'TOKEN')->getStatus(), 'the retry needs no new challenge');

		$this->params['tempResponseId'] = '6f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';
		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $this->respond('uploadFile', 1, 'TOKEN')->getStatus(), 'not another fill');
	}

	public function testASignedInBusyUploadGetsNoPass(): void {
		$this->loginAs('eve');
		$this->formRepository->method('loadPublic')->willThrowException(new FormLockedException('busy'));
		$this->uploadWith([]);

		$resp = $this->respond('uploadFile', 1, 'TOKEN');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $resp->getStatus());
		$this->assertArrayNotHasKey('uploadGrant', $resp->getData());
	}

	public function testTheUploadPassTheFirstFileGetsCarriesTheOthersOfThatResponseOnly(): void {
		$this->realChallenges();
		$this->userSession->method('getUser')->willReturn(null);
		$this->formRepository->method('loadPublic')->willReturn($this->fileForm(3));
		$this->request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
		$this->request->method('getUploadedFile')->willReturn(['name' => 'doc.pdf', 'type' => 'application/pdf', 'size' => 100, 'error' => UPLOAD_ERR_OK]);
		$this->uploadService->method('storeUploadPublic')->willReturn(['fileId' => 99]);
		$group = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

		$this->params = ['questionId' => 'q1', 'tempResponseId' => $group, 'altcha' => json_encode($this->solved(1))];
		$first = $this->respond('uploadFile', 1, 'TOKEN');
		$this->assertSame(Http::STATUS_CREATED, $first->getStatus());

		$this->params = ['questionId' => 'q1', 'tempResponseId' => $group, 'uploadGrant' => $first->getData()['uploadGrant']];
		$this->assertSame(Http::STATUS_CREATED, $this->respond('uploadFile', 1, 'TOKEN')->getStatus(), 'the pass carries the next file');

		$this->params['tempResponseId'] = '6f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';
		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $this->respond('uploadFile', 1, 'TOKEN')->getStatus(), 'not another response\'s files');
		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $this->respond('uploadFile', 2, 'TOKEN')->getStatus(), 'not another form\'s');
	}

	public function testTheRetryPassOfABusyAnswerSparesTheChallengeForThatFillOnly(): void {
		$this->realChallenges();
		$this->userSession->method('getUser')->willReturn(null);
		$this->formRepository->method('loadPublic')->willReturn($this->formWith());
		$calls = 0;
		$this->responseService->method('submitAnonymousWithForm')->willReturnCallback(function () use (&$calls) {
			if (++$calls === 1) {
				throw new FormLockedException('busy');
			}
			return ['id' => 'r1', 'submitted_at' => 'now'];
		});

		$busy = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], $this->solved(1), self::SUBMISSION);
		$pass = $busy->getData()['retryToken'];

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $this->respond('submit', 1, 'TOKEN', ['q1' => 'b'], null, self::SUBMISSION, $pass)->getStatus(), 'not other answers under the same id');
		$this->assertSame(Http::STATUS_CREATED, $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], null, self::SUBMISSION, $pass)->getStatus());
		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], null, '1f8b6c1e-aaaa-4bbb-8ccc-123456789abc', $pass)->getStatus(), 'not another fill');
		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $this->respond('submit', 2, 'TOKEN', ['q1' => 'a'], null, self::SUBMISSION, $pass)->getStatus(), 'not another form');
	}

	/**
	 * Two passes for one fill, each with its own answers, used in turn: each stores its answers once, then is answered as
	 * stored. One paid proof never carries a second fill (review of the 3a fixes: the ids kept only the last answers).
	 */
	public function testTwoRetryPassesForOneFillStoreEachAnswerOnce(): void {
		$this->realChallenges();
		$this->userSession->method('getUser')->willReturn(null);
		$form = $this->formWith();
		$this->formRepository->method('loadPublic')->willReturnCallback(function () use (&$form) {
			return $form;
		});
		$busy = 2;
		$stored = 0;
		$this->responseService->method('submitAnonymousWithForm')->willReturnCallback(function (int $fileId, array $f, array $answers) use (&$form, &$busy, &$stored) {
			if ($busy-- > 0) {
				throw new FormLockedException('busy');
			}
			$id = 'r' . ++$stored;
			$form['responses'][] = ['id' => $id, 'submitted_at' => 'now'];
			$form[SubmissionIds::KEY][SubmissionIds::of(self::SUBMISSION, $answers)] = $id;
			return ['id' => $id, 'submitted_at' => 'now'];
		});
		$passA = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], $this->solved(1), self::SUBMISSION)->getData()['retryToken'];
		$passB = $this->respond('submit', 1, 'TOKEN', ['q1' => 'b'], $this->solved(1), self::SUBMISSION)->getData()['retryToken'];

		foreach ([['a', $passA], ['b', $passB], ['a', $passA], ['b', $passB], ['a', $passA]] as [$answer, $pass]) {
			$this->assertSame(Http::STATUS_CREATED, $this->respond('submit', 1, 'TOKEN', ['q1' => $answer], null, self::SUBMISSION, $pass)->getStatus());
		}
		$this->assertSame(2, $stored);
	}

	/** Without a pass nothing about the answers is worked out before the anti-spam proof (review of the 3a fixes). */
	public function testNoPassMeansNoWorkOnTheAnswersBeforeTheProof(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->challengeService->expects($this->never())->method('verifyPass');
		$this->challengeService->method('verify')->willReturn(false);

		$resp = $this->respond('submit', 1, 'TOKEN', ['q1' => 'a'], null, self::SUBMISSION, null);

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $resp->getStatus());
	}
}
