<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\PublicController;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\ChallengeService;
use OCA\FormVox\Service\FormService;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Service\ResponseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

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
class PublicControllerTest extends TestCase
{
    private IRequest $request;
    private IConfig $config;
    private IUserSession $userSession;
    private IURLGenerator $urlGenerator;
    private IGroupManager $groupManager;
    private FormService $formService;
    private UploadService $uploadService;
    private ResponseService $responseService;
    private BrandingService $brandingService;
    private ChallengeService $challengeService;
    private IInitialState $initialState;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IConfig::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->formService = $this->createMock(FormService::class);
        $this->uploadService = $this->createMock(UploadService::class);
        $this->responseService = $this->createMock(ResponseService::class);
        $this->brandingService = $this->createMock(BrandingService::class);
        $this->challengeService = $this->createMock(ChallengeService::class);
        $this->initialState = $this->createMock(IInitialState::class);

        // Sensible defaults so success paths don't crash on unmocked calls.
        $this->brandingService->method('getBranding')->willReturn(['color' => '#fff']);
        $this->challengeService->method('issuePasswordToken')->willReturn('pwtok');
        $this->urlGenerator->method('linkToRoute')->willReturn('/login');
    }

    private function controller(): PublicController
    {
        return new PublicController(
            $this->request,
            $this->config,
            $this->userSession,
            $this->urlGenerator,
            $this->groupManager,
            $this->formService,
            $this->uploadService,
            $this->responseService,
            $this->brandingService,
            $this->challengeService,
            $this->initialState
        );
    }

    /** A logged-in user mock returning the given uid. */
    private function loginAs(string $uid, string $displayName = 'Alice'): IUser
    {
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
    private function formWith(array $settings = [], array $extra = []): array
    {
        return array_merge([
            'title' => 'My Form',
            'questions' => [],
            'settings' => array_merge(['public_token' => 'TOKEN'], $settings),
        ], $extra);
    }

    // ======================================================================
    // showForm()
    // ======================================================================

    public function testShowFormNotFoundWhenTokenMismatch(): void
    {
        // Stored token differs from supplied → loadAndValidateForm returns null.
        $this->formService->method('loadPublic')->willReturn($this->formWith(['public_token' => 'OTHER']));
        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    public function testShowFormNotFoundWhenLoadThrows(): void
    {
        $this->formService->method('loadPublic')->willThrowException(new \RuntimeException('boom'));
        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    public function testShowFormNotYetOpenReturnsForbidden(): void
    {
        $future = (new \DateTime('+1 day'))->format(\DateTime::ATOM);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_starts_at' => $future]));
        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testShowFormExpiredReturnsGone(): void
    {
        $past = (new \DateTime('-1 day'))->format(\DateTime::ATOM);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => $past]));
        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_GONE, $resp->getStatus());
    }

    public function testShowFormRequireLoginRedirectsAnonymous(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith(['require_login' => true]));
        $this->userSession->method('getUser')->willReturn(null);
        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(RedirectResponse::class, $resp);
    }

    public function testShowFormRestrictionsRedirectAnonymous(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith(['allowed_users' => ['bob']]));
        $this->userSession->method('getUser')->willReturn(null);
        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(RedirectResponse::class, $resp);
    }

    public function testShowFormRestrictionsUnauthorizedWhenUserNotAllowed(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith(['allowed_users' => ['bob']]));
        $this->loginAs('carol');
        $this->groupManager->method('isInGroup')->willReturn(false);
        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testShowFormRestrictionsAllowedViaGroupSucceeds(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith(['allowed_groups' => ['staff']]));
        $this->loginAs('carol');
        $this->groupManager->method('isInGroup')->with('carol', 'staff')->willReturn(true);
        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testShowFormPasswordProtectedShowsPasswordFormWhenNoPassword(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getCookie')->willReturn(null);
        $this->challengeService->method('verifyPasswordToken')->willReturn(false);
        $this->request->method('getParam')->willReturn(null);

        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        // The password form is rendered with STATUS_OK (no explicit setStatus).
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testShowFormPasswordProtectedWrongPasswordShowsPasswordForm(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getCookie')->willReturn(null);
        $this->challengeService->method('verifyPasswordToken')->willReturn(false);
        $this->request->method('getParam')->willReturn('wrong');

        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testShowFormPasswordProtectedCorrectPasswordRenders(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getCookie')->willReturn(null);
        $this->challengeService->method('verifyPasswordToken')->willReturn(false);
        $this->request->method('getParam')->willReturn('secret');

        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testShowFormPasswordCookieBypassesPrompt(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getCookie')->willReturn('cookieval');
        $this->challengeService->method('verifyPasswordToken')->with('cookieval', 1)->willReturn(true);

        $resp = $this->controller()->showForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testShowFormSuccessProvidesInitialStateWithStrippedSettings(): void
    {
        $form = $this->formWith(
            [
                'webhooks' => [['secret' => 's']],
                'api_keys' => ['k'],
                'allowed_users' => [],
                'allowed_groups' => [],
                'custom' => 'keepme',
            ],
            [
                'responses' => [['id' => 'r1']],
                '_index' => ['response_count' => 7, 'answer_counts' => ['q1' => ['a' => 3]]],
                'permissions' => ['x'],
                'branding' => ['color' => '#123'],
                'questions' => [[
                    'id' => 'q1',
                    'type' => 'choice',
                    'options' => [['capacity' => 5]],
                ]],
            ]
        );
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);

        $captured = [];
        $this->initialState->method('provideInitialState')
            ->willReturnCallback(function (string $key, $value) use (&$captured): void {
                $captured[$key] = $value;
            });

        $resp = $this->controller()->showForm(1, 'TOKEN');
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
        // Non-sensitive settings retained.
        $this->assertSame('keepme', $providedForm['settings']['custom']);
        // Response payload internals removed.
        $this->assertArrayNotHasKey('responses', $providedForm);
        $this->assertArrayNotHasKey('permissions', $providedForm);
        $this->assertArrayNotHasKey('branding', $providedForm);
        // _index rebuilt with count + capacity-only answer counts.
        $this->assertSame(7, $providedForm['_index']['response_count']);
        $this->assertSame(['q1' => ['a' => 3]], $providedForm['_index']['answer_counts']);
    }

    public function testShowFormCapacityCountsExcludeNonCapacityQuestions(): void
    {
        $form = $this->formWith([], [
            '_index' => [
                'response_count' => 2,
                'answer_counts' => ['q1' => ['a' => 1], 'q2' => ['b' => 4]],
            ],
            'questions' => [
                ['id' => 'q1', 'type' => 'choice', 'options' => [['capacity' => 3]]],
                ['id' => 'q2', 'type' => 'choice', 'options' => [['label' => 'no cap']]],
            ],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);

        $captured = [];
        $this->initialState->method('provideInitialState')
            ->willReturnCallback(function (string $key, $value) use (&$captured): void {
                $captured[$key] = $value;
            });

        $this->controller()->showForm(1, 'TOKEN');
        $counts = $captured['form']['_index']['answer_counts'];
        $this->assertArrayHasKey('q1', $counts);
        $this->assertArrayNotHasKey('q2', $counts);
    }

    // ======================================================================
    // challenge()
    // ======================================================================

    public function testChallengeReturnsIssuedPayload(): void
    {
        $this->challengeService->method('issue')->with(42)->willReturn(['challenge' => 'abc', 'difficulty' => 4]);
        $resp = $this->controller()->challenge(42, 'ignored');
        $this->assertInstanceOf(DataResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['challenge' => 'abc', 'difficulty' => 4], $resp->getData());
    }

    // ======================================================================
    // submit()
    // ======================================================================

    public function testSubmitAnonymousFailedChallengeReturns429(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(false);
        // Must fail before touching the form.
        $this->formService->expects($this->never())->method('loadPublic');

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['payload' => 'x']);
        $this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $resp->getStatus());
        $this->assertSame('Challenge verification failed. Please refresh the page.', $resp->getData()['error']);
    }

    public function testSubmitNotFoundWhenTokenMismatch(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['public_token' => 'OTHER']));

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
        $this->assertSame('Form not found', $resp->getData()['error']);
    }

    public function testSubmitBlockedWhenNotYetOpen(): void
    {
        $future = (new \DateTime('+1 day'))->format(\DateTime::ATOM);
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_starts_at' => $future]));

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
        $this->assertSame('This form is not yet open', $resp->getData()['error']);
        $this->assertArrayHasKey('opensAt', $resp->getData());
    }

    public function testSubmitBlockedWhenExpired(): void
    {
        $past = (new \DateTime('-1 day'))->format(\DateTime::ATOM);
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => $past]));

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_GONE, $resp->getStatus());
        $this->assertSame('This form has expired', $resp->getData()['error']);
    }

    public function testSubmitPasswordRequiredWhenNoPasswordAndNoCookie(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
        $this->request->method('getCookie')->willReturn(null);
        $this->challengeService->method('verifyPasswordToken')->willReturn(false);
        $this->request->method('getParam')->willReturn('');

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
        $this->assertSame('Password required', $resp->getData()['error']);
    }

    public function testSubmitPasswordCookieBypassesPasswordGate(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
        $this->request->method('getCookie')->willReturn('cv');
        $this->challengeService->method('verifyPasswordToken')->with('cv', 1)->willReturn(true);
        $this->responseService->method('submitAnonymousWithForm')
            ->willReturn(['id' => 'resp1', 'submitted_at' => '2026-01-01T00:00:00Z']);

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
    }

    public function testSubmitRestrictionsForbiddenWhenUserNotAllowed(): void
    {
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['allowed_users' => ['bob']]));
        $this->loginAs('carol');
        $this->groupManager->method('isInGroup')->willReturn(false);

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], null);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
        $this->assertSame('You do not have permission to submit this form', $resp->getData()['error']);
    }

    public function testSubmitRequireLoginForbiddenWhenAnonymous(): void
    {
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['require_login' => true]));
        // No user → verify() skipped because getUser() null triggers challenge,
        // but challenge passes; then require_login gate rejects.
        $this->userSession->method('getUser')->willReturn(null);

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
        $this->assertSame('This form requires you to be logged in', $resp->getData()['error']);
    }

    public function testSubmitAnonymousSuccessReturnsCreatedWithResponseShape(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
        $this->responseService->method('submitAnonymousWithForm')
            ->willReturn(['id' => 'resp1', 'submitted_at' => '2026-01-01T00:00:00Z']);

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
        $data = $resp->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('resp1', $data['response']['id']);
        $this->assertSame('2026-01-01T00:00:00Z', $data['response']['submitted_at']);
        $this->assertArrayNotHasKey('score', $data);
    }

    public function testSubmitIncludesScoreWhenQuizMode(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
        $this->responseService->method('submitAnonymousWithForm')
            ->willReturn(['id' => 'r', 'submitted_at' => 'now', 'score' => 42]);

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(42, $resp->getData()['score']);
    }

    public function testSubmitAuthenticatedWhenNonAnonymousAndRequireLogin(): void
    {
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn(
            $this->formWith(['anonymous' => false, 'require_login' => true])
        );
        $this->loginAs('dave', 'Dave D');
        $this->responseService->expects($this->once())->method('submitAuthenticated')
            ->with(1, ['q1' => 'a'], 'dave', 'Dave D')
            ->willReturn(['id' => 'ra', 'submitted_at' => 'now']);
        $this->responseService->expects($this->never())->method('submitAnonymousWithForm');

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], null);
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
        $this->assertSame('ra', $resp->getData()['response']['id']);
    }

    public function testSubmitNonAnonymousNoLoginFallsBackToAnonymousSubmit(): void
    {
        // anonymous=false but no restrictions and no require_login → last else branch.
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['anonymous' => false]));
        $this->responseService->expects($this->once())->method('submitAnonymousWithForm')
            ->willReturn(['id' => 'x', 'submitted_at' => 'now']);
        $this->responseService->expects($this->never())->method('submitAuthenticated');

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
    }

    public function testSubmitRuntimeExceptionMapsTo400(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
        $this->responseService->method('submitAnonymousWithForm')
            ->willThrowException(new \RuntimeException('This option is full'));

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame('This option is full', $resp->getData()['error']);
    }

    public function testSubmitGenericExceptionMapsTo500(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->challengeService->method('verify')->willReturn(true);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
        $this->responseService->method('submitAnonymousWithForm')
            ->willThrowException(new \LogicException('unexpected'));

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], ['p' => 'x']);
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
        $this->assertSame('unexpected', $resp->getData()['error']);
    }

    public function testSubmitLoggedInUserSkipsChallenge(): void
    {
        // A logged-in user bypasses the ALTCHA check entirely.
        $this->loginAs('eve');
        $this->challengeService->expects($this->never())->method('verify');
        $this->formService->method('loadPublic')->willReturn($this->formWith(['anonymous' => true]));
        $this->responseService->method('submitAnonymousWithForm')
            ->willReturn(['id' => 'z', 'submitted_at' => 'now']);

        $resp = $this->controller()->submit(1, 'TOKEN', ['q1' => 'a'], null);
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
    }

    // ======================================================================
    // embedForm() / embedAuthenticate()
    // ======================================================================

    public function testEmbedFormDelegatesToShowFormAndSetsFrameHeaders(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith());
        $this->userSession->method('getUser')->willReturn(null);
        $this->config->method('getAppValue')->willReturn('*');

        $resp = $this->controller()->embedForm(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $headers = $resp->getHeaders();
        $this->assertSame('ALLOWALL', $headers['X-Frame-Options'] ?? null);
        $this->assertSame('frame-ancestors *', $headers['Content-Security-Policy'] ?? null);
    }

    public function testEmbedFormWithSpecificDomainsUsesSameOrigin(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith());
        $this->userSession->method('getUser')->willReturn(null);
        $this->config->method('getAppValue')->willReturn('example.com, https://foo.test');

        $resp = $this->controller()->embedForm(1, 'TOKEN');
        $headers = $resp->getHeaders();
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options'] ?? null);
        $csp = $headers['Content-Security-Policy'] ?? '';
        $this->assertStringContainsString("'self'", $csp);
        $this->assertStringContainsString('https://example.com', $csp);
        $this->assertStringContainsString('https://foo.test', $csp);
    }

    public function testEmbedFormDoesNotSetFrameHeadersOnRedirect(): void
    {
        // require_login anonymous → RedirectResponse, setEmbedHeaders is a no-op.
        $this->formService->method('loadPublic')->willReturn($this->formWith(['require_login' => true]));
        $this->userSession->method('getUser')->willReturn(null);

        $resp = $this->controller()->embedForm(1, 'TOKEN');
        $this->assertInstanceOf(RedirectResponse::class, $resp);
    }

    public function testEmbedAuthenticateDelegatesAndSetsHeaders(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith());
        $this->config->method('getAppValue')->willReturn('*');
        $resp = $this->controller()->embedAuthenticate(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertArrayHasKey('X-Frame-Options', $resp->getHeaders());
    }

    // ======================================================================
    // authenticate()
    // ======================================================================

    public function testAuthenticateNotFoundWhenTokenMismatch(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith(['public_token' => 'OTHER']));
        $resp = $this->controller()->authenticate(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    public function testAuthenticateNotYetOpenForbidden(): void
    {
        $future = (new \DateTime('+1 day'))->format(\DateTime::ATOM);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_starts_at' => $future]));
        $resp = $this->controller()->authenticate(1, 'TOKEN');
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testAuthenticateExpiredGone(): void
    {
        $past = (new \DateTime('-1 day'))->format(\DateTime::ATOM);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => $past]));
        $resp = $this->controller()->authenticate(1, 'TOKEN');
        $this->assertSame(Http::STATUS_GONE, $resp->getStatus());
    }

    public function testAuthenticateWrongPasswordShowsPasswordFormAndThrottles(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
        $this->request->method('getParam')->willReturn('wrong');

        $resp = $this->controller()->authenticate(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        // Password form itself has no explicit status → OK.
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testAuthenticateEmptyPasswordShowsPasswordForm(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
        $this->request->method('getParam')->willReturn('');

        $resp = $this->controller()->authenticate(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testAuthenticateCorrectPasswordRendersFormAndSetsCookie(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_password_hash' => $hash]));
        $this->request->method('getParam')->willReturn('secret');
        $this->challengeService->expects($this->once())->method('issuePasswordToken')->with(1)->willReturn('tok');

        $captured = [];
        $this->initialState->method('provideInitialState')
            ->willReturnCallback(function (string $key, $value) use (&$captured): void {
                $captured[$key] = $value;
            });

        $resp = $this->controller()->authenticate(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        // Cookie set for password verification carry-over.
        $cookies = $resp->getCookies();
        $this->assertArrayHasKey('formvox_pw_1', $cookies);
        // Sensitive settings stripped from provided state.
        $this->assertArrayNotHasKey('public_token', $captured['form']['settings']);
    }

    public function testAuthenticateNoPasswordFormRendersWithoutCookie(): void
    {
        // Form is not password protected → passwordVerified stays false, no cookie.
        $this->formService->method('loadPublic')->willReturn($this->formWith());
        $this->challengeService->expects($this->never())->method('issuePasswordToken');

        $resp = $this->controller()->authenticate(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertArrayNotHasKey('formvox_pw_1', $resp->getCookies());
    }

    public function testAuthenticateExceptionMapsToErrorResponse(): void
    {
        // Expired check uses new DateTime() on the raw string; an invalid
        // expires string throws inside the try and is caught → errorResponse (400).
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => 'not-a-date']));
        $resp = $this->controller()->authenticate(1, 'TOKEN');
        $this->assertInstanceOf(TemplateResponse::class, $resp);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    // ======================================================================
    // uploadFile()
    // ======================================================================

    public function testUploadFileNotFoundWhenTokenMismatch(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith(['public_token' => 'OTHER']));
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
        $this->assertSame('Form not found', $resp->getData()['error']);
    }

    public function testUploadFileBlockedWhenExpired(): void
    {
        $past = (new \DateTime('-1 day'))->format(\DateTime::ATOM);
        $this->formService->method('loadPublic')->willReturn($this->formWith(['share_expires_at' => $past]));
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_GONE, $resp->getStatus());
    }

    public function testUploadFileForbiddenWhenUserNotAllowed(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith(['allowed_users' => ['bob']]));
        $this->loginAs('carol');
        $this->groupManager->method('isInGroup')->willReturn(false);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
        $this->assertSame('You do not have permission to upload files to this form', $resp->getData()['error']);
    }

    public function testUploadFileForbiddenWhenRequireLoginAnonymous(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith(['require_login' => true]));
        $this->userSession->method('getUser')->willReturn(null);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
        $this->assertSame('This form requires you to be logged in', $resp->getData()['error']);
    }

    public function testUploadFileBadRequestWhenNoQuestionId(): void
    {
        $this->formService->method('loadPublic')->willReturn($this->formWith());
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturn(null);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame('Question ID is required', $resp->getData()['error']);
    }

    public function testUploadFileBadRequestWhenQuestionNotFileType(): void
    {
        $form = $this->formWith([], [
            'questions' => [['id' => 'q1', 'type' => 'text']],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'q1'],
        ]);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame('Invalid question', $resp->getData()['error']);
    }

    public function testUploadFileBadRequestWhenQuestionMissing(): void
    {
        $form = $this->formWith([], ['questions' => []]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'nope'],
        ]);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame('Invalid question', $resp->getData()['error']);
    }

    public function testUploadFileBadRequestWhenNoFileUploaded(): void
    {
        $form = $this->formWith([], [
            'questions' => [['id' => 'q1', 'type' => 'file']],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'q1'],
        ]);
        $this->request->method('getUploadedFile')->willReturn(null);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame('No file was uploaded', $resp->getData()['error']);
    }

    public function testUploadFileBadRequestWhenUploadErrorCode(): void
    {
        $form = $this->formWith([], [
            'questions' => [['id' => 'q1', 'type' => 'file']],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'q1'],
        ]);
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'x.pdf', 'type' => 'application/pdf', 'size' => 10, 'error' => UPLOAD_ERR_INI_SIZE,
        ]);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame('The file exceeds the maximum upload size', $resp->getData()['error']);
    }

    public function testUploadFileBadRequestWhenTooLarge(): void
    {
        $form = $this->formWith([], [
            'questions' => [['id' => 'q1', 'type' => 'file', 'maxFileSize' => 1]],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'q1'],
        ]);
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'x.pdf', 'type' => 'application/pdf',
            'size' => 2 * 1024 * 1024, 'error' => UPLOAD_ERR_OK,
        ]);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame('File is too large. Maximum size is 1 MB', $resp->getData()['error']);
    }

    public function testUploadFileBadRequestWhenDisallowedType(): void
    {
        $form = $this->formWith([], [
            'questions' => [[
                'id' => 'q1', 'type' => 'file',
                'allowedTypes' => ['application/pdf'],
            ]],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'q1'],
        ]);
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'x.png', 'type' => 'image/png', 'size' => 10, 'error' => UPLOAD_ERR_OK,
        ]);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame('This file type is not allowed', $resp->getData()['error']);
    }

    public function testUploadFileBadRequestWhenDangerousTypeUnderWildcard(): void
    {
        // No allowedTypes → wildcard path blocks dangerous extensions.
        $form = $this->formWith([], [
            'questions' => [['id' => 'q1', 'type' => 'file']],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'q1'],
        ]);
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'evil.php', 'type' => 'application/octet-stream',
            'size' => 10, 'error' => UPLOAD_ERR_OK,
        ]);
        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame('This file type is not allowed', $resp->getData()['error']);
    }

    public function testUploadFileSuccessStoresAndReturnsCreated(): void
    {
        $form = $this->formWith([], [
            'questions' => [['id' => 'q1', 'type' => 'file', 'allowedTypes' => ['application/pdf']]],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'q1'],
            ['tempResponseId', null, 'temp-abc'],
        ]);
        $uploaded = [
            'name' => 'doc.pdf', 'type' => 'application/pdf',
            'size' => 100, 'error' => UPLOAD_ERR_OK,
        ];
        $this->request->method('getUploadedFile')->willReturn($uploaded);
        $this->uploadService->expects($this->once())->method('storeUpload')
            ->with(1, 'temp-abc', $uploaded)
            ->willReturn(['fileId' => 99, 'name' => 'doc.pdf']);

        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
        $data = $resp->getData();
        $this->assertSame(99, $data['fileId']);
        $this->assertSame('temp-abc', $data['tempResponseId']);
    }

    public function testUploadFileGeneratesTempResponseIdWhenMissing(): void
    {
        $form = $this->formWith([], [
            'questions' => [['id' => 'q1', 'type' => 'file', 'allowedTypes' => ['*/*']]],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'q1'],
            ['tempResponseId', null, null],
        ]);
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'notes.txt', 'type' => 'text/plain', 'size' => 5, 'error' => UPLOAD_ERR_OK,
        ]);
        $this->uploadService->method('storeUpload')->willReturn(['fileId' => 1]);

        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
        // A 16-byte hex token (32 chars) was generated for grouping.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $resp->getData()['tempResponseId']);
    }

    public function testUploadFileStoreExceptionMapsTo500(): void
    {
        $form = $this->formWith([], [
            'questions' => [['id' => 'q1', 'type' => 'file', 'allowedTypes' => ['application/pdf']]],
        ]);
        $this->formService->method('loadPublic')->willReturn($form);
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getParam')->willReturnMap([
            ['questionId', null, 'q1'],
            ['tempResponseId', null, 'temp-abc'],
        ]);
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'doc.pdf', 'type' => 'application/pdf', 'size' => 100, 'error' => UPLOAD_ERR_OK,
        ]);
        $this->uploadService->method('storeUpload')->willThrowException(new \RuntimeException('disk full'));

        $resp = $this->controller()->uploadFile(1, 'TOKEN');
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
        $this->assertSame('disk full', $resp->getData()['error']);
    }
}
