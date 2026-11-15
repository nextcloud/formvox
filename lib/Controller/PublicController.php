<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\BackgroundJob\UploadCleanupJob;
use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Security\FormNotFoundException;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Security\FormSecrets;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\ChallengeService;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\FormSchemaValidator;
use OCA\FormVox\Service\ResponseRefusedException;
use OCA\FormVox\Service\ResponseRules;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\SubmissionIds;
use OCA\FormVox\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use OCP\Util;

class PublicController extends Controller {
	/** Wrong link passwords count under one name, whichever entry point they came through. */
	private const PASSWORD_ACTION = 'formvox_password';
	private const PASSWORD_NOT_ASKED = 'not_asked';
	private const PASSWORD_REMEMBERED = 'remembered';
	private const PASSWORD_GIVEN = 'given';
	private const PASSWORD_MISSING = 'missing';
	private const PASSWORD_WRONG = 'wrong';
	/** Passes that spare the challenge (ChallengeService::issuePass): a retry after "busy", more files for one response. */
	private const RETRY_PASS = 'retry';
	private const RETRY_PASS_SECONDS = 120;
	private const UPLOAD_PASS = 'upload';
	private const UPLOAD_PASS_SECONDS = 7200;

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IGroupManager $groupManager,
		private FormRepository $formRepository,
		private UploadService $uploadService,
		private ResponseService $responseService,
		private BrandingService $brandingService,
		private ChallengeService $challengeService,
		private IInitialState $initialState,
		private FormSecrets $secrets,
		private FormSanitizer $sanitizer,
		private ResponseRules $rules,
		private IL10N $l,
		private IThrottler $throttler,
		private IDateTimeFormatter $dateTimeFormatter,
		private IDateTimeZone $timeZone,
		private IJobList $jobList,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Check if user is allowed to access form based on user/group restrictions
	 */
	private function isUserAllowed(array $form, ?IUser $user): bool {
		$allowedUsers = $form['settings']['allowed_users'] ?? [];
		$allowedGroups = $form['settings']['allowed_groups'] ?? [];

		// No restrictions = everyone allowed
		if (empty($allowedUsers) && empty($allowedGroups)) {
			return true;
		}

		// Restrictions exist but no user = not allowed
		if ($user === null) {
			return false;
		}

		$userId = $user->getUID();

		// Check if user is directly allowed
		if (in_array($userId, $allowedUsers, true)) {
			return true;
		}

		// Check if user is in any allowed group
		foreach ($allowedGroups as $groupId) {
			if ($this->groupManager->isInGroup($userId, $groupId)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Show unauthorized access page
	 */
	private function showUnauthorized(string $title): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'public');
		$response = new TemplateResponse(
			Application::APP_ID,
			'public/unauthorized',
			[
				'appId' => Application::APP_ID,
				'title' => $title,
			],
			'public'
		);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		return $response;
	}

	private function showNotYetOpenForm(string $title, \DateTime $opensAt): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'public');
		$response = new TemplateResponse(
			Application::APP_ID,
			'public/notyetopen',
			[
				'appId' => Application::APP_ID,
				'title' => $title,
				// In the visitor's zone where Nextcloud knows it, else the server's, and named: not bare UTC (qual#120).
				'opensAt' => $this->dateTimeFormatter->formatDateTime($opensAt, 'long', 'short', $this->timeZone->getTimeZone(), $this->l)
					. ' (' . $this->timeZone->getTimeZone()->getName() . ')',
			],
			'public'
		);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		return $response;
	}

	/**
	 * The form behind a link, or null when there is no such form or link: only that is a not found that counts towards
	 * brute-force protection. A busy or unreadable form is not; it reaches ExceptionMiddleware as a 503 to retry (7a, 3a-3).
	 */
	private function loadAndValidateForm(int $fileId, string $token): ?array {
		try {
			$form = $this->formRepository->loadPublic($fileId);
		} catch (NotFoundException) {
			return null;
		}

		// Validate token matches (timing-safe)
		$storedToken = $form['settings']['public_token'] ?? null;
		if ($storedToken === null || !is_string($storedToken) || !hash_equals($storedToken, $token)) {
			return null;
		}

		return $form;
	}

	/**
	 * The gates a submission and an upload share: the share dialog's dates, the link password, who may answer,
	 * and signing in. Refusals are answered by ExceptionMiddleware.
	 *
	 * @throws ClientErrorException
	 */
	private function admit(array $form, int $fileId, string $notAllowed): void {
		$this->rules->assertOpen($form['settings'] ?? []);

		$user = $this->userSession->getUser();
		$restricted = $this->isRestricted($form);
		if (($restricted || $this->requiresLogin($form)) && $user === null) {
			throw RequestException::loginRequired();
		}
		if ($restricted && !$this->isUserAllowed($form, $user)) {
			throw RequestException::forbidden($notAllowed);
		}

		if (in_array($this->checkPassword($form, $fileId), [self::PASSWORD_MISSING, self::PASSWORD_WRONG], true)) {
			throw RequestException::passwordRequired();
		}
	}

	private function isRestricted(array $form): bool {
		return !empty($form['settings']['allowed_users'] ?? []) || !empty($form['settings']['allowed_groups'] ?? []);
	}

	/**
	 * The link password: the cookie from an earlier visit, or the password itself (#82). Every wrong one counts
	 * towards Nextcloud's brute-force protection, on every entry point (qual#10, secu#17); after too many it
	 * answers 429.
	 *
	 * @return string one of the PASSWORD_ constants
	 * @throws MaxDelayReached
	 */
	private function checkPassword(array $form, int $fileId): string {
		$settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
		if (empty($settings['share_password_hash'])) {
			return self::PASSWORD_NOT_ASKED;
		}
		if ($this->challengeService->verifyPasswordToken($this->request->getCookie("formvox_pw_{$fileId}"), $fileId, $settings)) {
			return self::PASSWORD_REMEMBERED;
		}
		$provided = (string)$this->request->getParam('password', '');
		if ($provided === '') {
			return self::PASSWORD_MISSING;
		}
		$ip = $this->request->getRemoteAddress();
		$this->throttler->sleepDelayOrThrowOnMax($ip, self::PASSWORD_ACTION);
		if (!$this->passwordMatches($provided, $form)) {
			$this->throttler->registerAttempt(self::PASSWORD_ACTION, $ip, ['fileId' => $fileId]);
			return self::PASSWORD_WRONG;
		}
		return self::PASSWORD_GIVEN;
	}

	/**
	 * A refusal that also counts towards brute-force protection, which only a returned response can do.
	 */
	private function throttledError(string $message, string $reason, int $status): DataResponse {
		$response = new DataResponse(['error' => $message, 'reason' => $reason], $status);
		$response->throttle();
		return $response;
	}

	private function isAnonymous(array $form): bool {
		return ($form['settings']['anonymous'] ?? true) !== false;
	}

	/**
	 * A form that does not collect anonymously knows who answered, so it always needs a signed-in respondent.
	 */
	private function requiresLogin(array $form): bool {
		return ($form['settings']['require_login'] ?? false) || !$this->isAnonymous($form);
	}

	private function passwordMatches(string $password, array $form): bool {
		$hash = $this->secrets->open((string)($form['settings']['share_password_hash'] ?? ''));
		return $password !== '' && $hash !== '' && password_verify($password, $hash);
	}

	/**
	 * Show public form for anonymous submission
	 * @return TemplateResponse|RedirectResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function showForm(int $fileId, string $token): TemplateResponse|RedirectResponse {
		return $this->formPage($fileId, $token, 'formvox.public.showForm', false);
	}

	/**
	 * The one way to the form page, for the link, the password form and embeds alike: open, signed in and
	 * allowed where the form asks (also after the password form — secu#18), then the link password.
	 *
	 * @param string $route where signing in leads back to
	 * @param bool $fromPasswordForm an empty password there is a wrong one
	 * @throws MaxDelayReached
	 */
	private function formPage(int $fileId, string $token, string $route, bool $fromPasswordForm): TemplateResponse|RedirectResponse {
		$form = $this->loadAndValidateForm($fileId, $token);
		if ($form === null) {
			return $this->errorResponse($this->l->t('Form not found'), Http::STATUS_NOT_FOUND);
		}
		$closed = $this->closedPage($form);
		if ($closed !== null) {
			return $closed;
		}

		$user = $this->userSession->getUser();
		if (($this->isRestricted($form) || $this->requiresLogin($form)) && $user === null) {
			$back = $this->urlGenerator->linkToRoute($route, ['fileId' => $fileId, 'token' => $token]);
			return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm', ['redirect_url' => $back]));
		}
		if ($this->isRestricted($form) && !$this->isUserAllowed($form, $user)) {
			// TRANSLATORS: Fallback page title for a form without a title
			return $this->showUnauthorized($form['title'] ?? $this->l->t('Form'));
		}

		$password = $this->checkPassword($form, $fileId);
		// TRANSLATORS: Page title of the password prompt for a form without a title
		$title = $form['title'] ?? $this->l->t('Protected form');
		if ($password === self::PASSWORD_WRONG || ($password === self::PASSWORD_MISSING && $fromPasswordForm)) {
			// TRANSLATORS: Error on the password prompt of a protected form
			return $this->showPasswordForm($fileId, $token, $title, $this->l->t('Incorrect password'));
		}
		if ($password === self::PASSWORD_MISSING) {
			return $this->showPasswordForm($fileId, $token, $title);
		}

		$branding = $this->getEffectiveBranding($form);
		$public = $this->sanitizer->forPublic($form);
		$this->initialState->provideInitialState('fileId', $fileId);
		$this->initialState->provideInitialState('token', $token);
		$this->initialState->provideInitialState('form', $public);
		$this->initialState->provideInitialState('branding', $branding);
		if ($user !== null && !empty($form['settings']['sendConfirmationEmail'])) {
			// A signed-in respondent is written to at their account's address: the page says so.
			$this->initialState->provideInitialState('respondent', ['email' => (string)($user->getEMailAddress() ?? '')]);
		}

		Util::addScript(Application::APP_ID, 'formvox-public');
		Util::addStyle(Application::APP_ID, 'public');

		$response = new TemplateResponse(Application::APP_ID, 'public/respond', ['appId' => Application::APP_ID], 'public');
		$this->allowBlobWorker($response);
		if ($password === self::PASSWORD_GIVEN) {
			// So submit and upload can tell the password was given without asking again (#82).
			$this->setPasswordCookie($response, $fileId, $form);
		}
		return $response;
	}

	/**
	 * Allow loading the ALTCHA proof-of-work Web Worker from a Blob URL.
	 *
	 * Without this, Nextcloud instances that don't explicitly set
	 * `worker-src` in their CSP fall back to the strict nonce-based
	 * `script-src` and block the Blob worker — meaning the submit hangs
	 * on "Submitting…" and the form can't be filled in at all (#95).
	 */
	private function allowBlobWorker(TemplateResponse $response): void {
		$csp = new \OCP\AppFramework\Http\ContentSecurityPolicy();
		$csp->addAllowedWorkerSrcDomain('blob:');
		$response->setContentSecurityPolicy($csp);
	}

	/**
	 * Get effective branding for a form (form-specific or admin defaults)
	 */
	private function getEffectiveBranding(array $form): array {
		// If form has custom branding, use it
		if (!empty($form['branding'])) {
			return $form['branding'];
		}
		// Otherwise use admin defaults
		return $this->brandingService->getBranding();
	}

	/**
	 * Issue an ALTCHA proof-of-work challenge for a public form.
	 *
	 * Replaces per-IP rate limiting on submit (NAT-unfriendly) with a
	 * per-browser PoW token. Difficulty adapts to the per-form submit rate.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function challenge(int $fileId, string $token): DataResponse {
		// $token is required for route binding (so /public/{fileId}/challenge
		// doesn't collide with /public/{fileId}/{token}); we don't validate
		// it here — verification happens at submit time.
		unset($token);
		return new DataResponse($this->challengeService->issue($fileId));
	}

	/**
	 * Submit response (anonymous or authenticated based on form settings)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 25000, period: 3600)]
	#[BruteForceProtection(action: 'formvox_submit')]
	public function submit(int $fileId, string $token, array $answers, ?array $altcha = null, ?string $submissionId = null, ?string $retryToken = null, ?string $timezone = null): DataResponse {
		// The browser's id for this fill: a retry of a stored submission is answered as stored, not stored twice.
		$submissionId = SubmissionIds::isValid($submissionId) ? $submissionId : null;
		// Anti-bot: a valid ALTCHA proof-of-work token before any work. Cost is per browser, so 500 people
		// behind one NAT address each pay their own (tiny) cost (#76). A retry after "busy" paid it already.
		// Only with a pass: without one nothing about the answers is worked out before the proof.
		$retrying = $submissionId !== null && $retryToken !== null && $retryToken !== ''
			&& $this->challengeService->verifyPass($retryToken, self::RETRY_PASS, fn (): string => $this->retrySubject($fileId, $submissionId, $answers));
		if ($this->userSession->getUser() === null && !$retrying) {
			$refused = $this->challengeRefusal($altcha, $fileId);
			if ($refused !== null) {
				return $refused;
			}
		}

		try {
			return $this->store($fileId, $token, $answers, $submissionId, $timezone);
		} catch (FormLockedException|LockedException $busy) {
			// Busy for a moment: the browser retries with the same id, and this pass spares it a new challenge.
			throw FormLockedException::withData($busy, $submissionId === null ? [] : [
				'retryToken' => $this->challengeService->issuePass(self::RETRY_PASS, $this->retrySubject($fileId, $submissionId, $answers), self::RETRY_PASS_SECONDS),
			]);
		}
	}

	/**
	 * The anti-spam proof: null when it holds. A wrong proof counts towards brute-force protection; none at all does not,
	 * as a browser whose solving failed sends none, and many of those behind one school network would block each other.
	 */
	private function challengeRefusal(?array $altcha, int $fileId): ?DataResponse {
		if ($this->challengeService->verify($altcha, $fileId)) {
			return null;
		}
		// TRANSLATORS: Error shown when the anti-spam check of a public form failed
		$message = $this->l->t('Challenge verification failed. Please refresh the page.');
		if (empty($altcha)) {
			return new DataResponse(['error' => $message, 'reason' => 'challenge_failed'], Http::STATUS_TOO_MANY_REQUESTS);
		}
		return $this->throttledError($message, 'challenge_failed', Http::STATUS_TOO_MANY_REQUESTS);
	}

	/**
	 * A retry pass holds for this form, this fill with these answers, and this browser: a retry sends the same answers,
	 * so the pass never carries other answers past the challenge (review of the 3a fixes).
	 */
	private function retrySubject(int $fileId, string $submissionId, array $answers): string {
		return $fileId . ':' . SubmissionIds::of($submissionId, $answers) . ':' . hash('sha256', $this->request->getHeader('User-Agent'));
	}

	/** @param string|null $timezone the respondent's, as their browser says: their times in the PDF are at their clock */
	private function store(int $fileId, string $token, array $answers, ?string $submissionId, ?string $timezone = null): DataResponse {
		$form = $this->loadAndValidateForm($fileId, $token);
		if ($form === null) {
			return $this->throttledError($this->l->t('Form not found'), 'not_found', Http::STATUS_NOT_FOUND);
		}
		// A retry of what was stored is answered as stored, also when the form closed meanwhile; only its own fill has
		// the id and these answers (review 3a-5).
		$seen = SubmissionIds::stored($form, SubmissionIds::of($submissionId, $answers));
		if ($seen !== null) {
			$this->responseService->retried($fileId, $form, $seen);
			return $this->created($seen);
		}
		// TRANSLATORS: Error shown when a form only takes answers from certain people or groups
		$this->admit($form, $fileId, Translatable::t('You do not have permission to submit this form'));

		$user = $this->userSession->getUser();
		if (!$this->isAnonymous($form)) {
			// Not anonymous means signed in (admit), so the response carries who answered.
			$response = $this->responseService->submitAuthenticated($fileId, $form, $answers, $user->getUID(), $user->getDisplayName(), $submissionId, $timezone);
		} else {
			// Signed in only where the form limits who may answer; then counted per account, still without a name.
			$restricted = !empty($form['settings']['allowed_users'] ?? []) || !empty($form['settings']['allowed_groups'] ?? []);
			$account = ($restricted || ($form['settings']['require_login'] ?? false)) ? $user : null;
			$response = $this->responseService->submitAnonymousWithForm($fileId, $form, $answers, $this->request, $account?->getUID(), $submissionId, $timezone, $user?->getUID());
		}

		return $this->created($response);
	}

	private function created(array $response): DataResponse {
		$result = ['success' => true, 'response' => ['id' => $response['id'], 'submitted_at' => $response['submitted_at']]];
		if (isset($response['score'])) {
			$result['score'] = $response['score'];
		}
		return new DataResponse($result, Http::STATUS_CREATED);
	}

	/**
	 * Show embeddable form (allows framing in iframes)
	 * @return TemplateResponse|RedirectResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function embedForm(int $fileId, string $token): TemplateResponse|RedirectResponse {
		$response = $this->formPage($fileId, $token, 'formvox.public.embedForm', false);

		// Set embed headers based on allowed domains
		$this->setEmbedHeaders($response);

		return $response;
	}

	/**
	 * Authenticate password-protected form in embed mode (POST handler)
	 * @return TemplateResponse|RedirectResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function embedAuthenticate(int $fileId, string $token): TemplateResponse|RedirectResponse {
		$response = $this->formPage($fileId, $token, 'formvox.public.embedForm', true);

		// Set embed headers based on allowed domains
		$this->setEmbedHeaders($response);

		return $response;
	}

	/**
	 * Lets the configured sites frame the page by adding them to Nextcloud's own policy; replacing the header kept
	 * frame-ancestors only and dropped every other protection of the page (secu#6, qual#45).
	 */
	private function setEmbedHeaders(TemplateResponse|RedirectResponse $response): void {
		if (!($response instanceof TemplateResponse)) {
			return;
		}
		$configured = trim($this->config->getAppValue(Application::APP_ID, 'embed_allowed_domains', '*'));
		$domains = preg_split('/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: ['*'];

		$csp = $response->getContentSecurityPolicy();
		foreach (in_array('*', $domains, true) ? ['*'] : $domains as $domain) {
			// A bare host means its https site.
			$csp->addAllowedFrameAncestorDomain($domain === '*' || str_contains($domain, '://') ? $domain : 'https://' . $domain);
		}
		$response->setContentSecurityPolicy($csp);
		// Browsers that know frame-ancestors ignore this; older ones would otherwise refuse every frame.
		$response->addHeader('X-Frame-Options', in_array('*', $domains, true) ? 'ALLOWALL' : 'SAMEORIGIN');
	}

	/**
	 * The password form posts here.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function authenticate(int $fileId, string $token): TemplateResponse|RedirectResponse {
		return $this->formPage($fileId, $token, 'formvox.public.showForm', true);
	}

	private function setPasswordCookie(\OCP\AppFramework\Http\Response $response, int $fileId, array $form): void {
		$token = $this->challengeService->issuePasswordToken($fileId, is_array($form['settings'] ?? null) ? $form['settings'] : []);
		$response->addCookie(
			"formvox_pw_{$fileId}",
			$token,
			new \DateTime('@' . (time() + 3600)),
			'Lax',
		);
	}

	/**
	 * Show password form for protected forms
	 */
	private function showPasswordForm(int $fileId, string $token, string $title, ?string $error = null): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'public');
		return new TemplateResponse(
			Application::APP_ID,
			'public/password',
			[
				'appId' => Application::APP_ID,
				'fileId' => $fileId,
				'token' => $token,
				'title' => $title,
				'error' => $error,
			],
			'public'
		);
	}

	/**
	 * The page for a form whose share dialog dates keep it closed now, or null while it is open.
	 */
	private function closedPage(array $form): ?TemplateResponse {
		try {
			$this->rules->assertOpen($form['settings'] ?? []);
			return null;
		} catch (ResponseRefusedException $e) {
			if ($e->getReason() === 'form_not_open') {
				return $this->showNotYetOpenForm($form['title'] ?? $this->l->t('Form'), new \DateTime((string)$e->getData()['opensAt']));
			}
			return $this->errorResponse($this->l->t('This form has expired'), Http::STATUS_GONE);
		}
	}

	/**
	 * Return an error template response
	 */
	private function errorResponse(string $message, int $status = Http::STATUS_BAD_REQUEST): TemplateResponse {
		$response = new TemplateResponse(
			Application::APP_ID,
			'error',
			['message' => $message],
			'public'
		);
		$response->setStatus($status);
		return $response;
	}

	/**
	 * Upload a file for a form response
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// A backstop only: a class behind one school network uploads far less; each response's files take a pass (7a, 3a-5).
	#[AnonRateLimit(limit: 2500, period: 3600)]
	#[BruteForceProtection(action: 'formvox_upload')]
	public function uploadFile(int $fileId, string $token): DataResponse {
		// Groups the files of one response; it becomes a folder name in the form's upload folder (L18).
		$tempResponseId = $this->request->getParam('tempResponseId');
		if (empty($tempResponseId)) {
			$tempResponseId = bin2hex(random_bytes(16));
		} elseif (!is_string($tempResponseId) || !UploadService::isValidResponseId($tempResponseId)) {
			// TRANSLATORS: Error shown when an upload names an upload group that is not valid
			throw RequestException::invalid(Translatable::t('Invalid upload id'));
		}
		// The first file of a response pays the anti-spam challenge; the pass it gets carries the others.
		$anonymous = $this->userSession->getUser() === null;
		$passSubject = $fileId . ':' . $tempResponseId;
		if ($anonymous && !$this->challengeService->verifyPass((string)$this->request->getParam('uploadGrant', ''), self::UPLOAD_PASS, $passSubject)) {
			$altcha = json_decode((string)$this->request->getParam('altcha', ''), true);
			$refused = $this->challengeRefusal(is_array($altcha) ? $altcha : null, $fileId);
			if ($refused !== null) {
				return $refused;
			}
		}

		try {
			return $this->storeUpload($fileId, $token, $tempResponseId, $anonymous ? $passSubject : null);
		} catch (FormLockedException|LockedException $busy) {
			// Busy for a moment: the browser tries again, and this pass spares it a new challenge for the same file.
			throw FormLockedException::withData($busy, $anonymous ? ['uploadGrant' => $this->challengeService->issuePass(self::UPLOAD_PASS, $passSubject, self::UPLOAD_PASS_SECONDS)] : []);
		}
	}

	/** @param string|null $passSubject what the pass for the next file is for; null for a signed-in respondent, who needs none */
	private function storeUpload(int $fileId, string $token, string $tempResponseId, ?string $passSubject): DataResponse {
		$form = $this->loadAndValidateForm($fileId, $token) ?? throw new FormNotFoundException();
		// TRANSLATORS: Error shown when a form only takes files from certain people or groups
		$this->admit($form, $fileId, Translatable::t('You do not have permission to upload files to this form'));

		$questionId = (string)$this->request->getParam('questionId');
		if ($questionId === '') {
			// TRANSLATORS: Error shown when an upload does not say which question it is for
			throw RequestException::invalid(Translatable::t('Question ID is required'));
		}
		$question = $this->findQuestion($form, $questionId);
		if ($question === null || ($question['type'] ?? '') !== 'file') {
			// TRANSLATORS: Error shown when a file is sent for a question that does not take files
			throw RequestException::invalid(Translatable::t('Invalid question'));
		}

		$uploadedFile = $this->request->getUploadedFile('file');
		if ($uploadedFile === null || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
			throw RequestException::invalid($this->uploadErrorMessage($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE));
		}

		// The question's own limit, never above the server's (PO); nothing capped it here before.
		$maxSizeMB = min(is_numeric($question['maxFileSize'] ?? null) ? (float)$question['maxFileSize'] : 10.0, (float)FormSchemaValidator::MAX_FILE_SIZE_MB);
		if ($uploadedFile['size'] > $maxSizeMB * 1024 * 1024) {
			// TRANSLATORS: Error shown when an uploaded file is too large; %s is the limit in megabytes
			throw RequestException::invalid(Translatable::t('File is too large. The maximum size is %s MB.'), [(string)$maxSizeMB]);
		}
		if (!$this->isAllowedFileType($uploadedFile, $question)) {
			// TRANSLATORS: Error shown when an uploaded file is of a type the question does not take
			throw RequestException::invalid(Translatable::t('This file type is not allowed'));
		}

		// What one response may hold, with room for retries whose answer got lost and files replaced after a failed try;
		// a pass is no open door. A response's files nobody submits are cleared after a day (UploadCleanupJob).
		$stored = $this->uploadService->countUploadsPublic($fileId, $tempResponseId);
		if ($stored >= 3 * $this->filesPerResponse($form) + 5) {
			// TRANSLATORS: Error shown when more files are sent for one response than the form's questions take
			throw RequestException::invalid(Translatable::t('No more files can be added to this response'));
		}

		$fileMetadata = $this->uploadService->storeUploadPublic($fileId, $tempResponseId, $uploadedFile);
		if ($stored === 0) {
			$this->jobList->scheduleAfter(UploadCleanupJob::class, time() + UploadCleanupJob::AFTER_SECONDS, ['fileId' => $fileId, 'group' => $tempResponseId]);
		}
		$fileMetadata['tempResponseId'] = $tempResponseId;
		if ($passSubject !== null) {
			$fileMetadata['uploadGrant'] = $this->challengeService->issuePass(self::UPLOAD_PASS, $passSubject, self::UPLOAD_PASS_SECONDS);
		}
		return new DataResponse($fileMetadata, Http::STATUS_CREATED);
	}

	/** The files one response may hold: what its file questions take together. */
	private function filesPerResponse(array $form): int {
		$total = 0;
		foreach ($form['questions'] ?? [] as $question) {
			if (($question['type'] ?? '') === 'file') {
				$total += max(1, (int)($question['maxFiles'] ?? 1));
			}
		}
		return $total;
	}

	/**
	 * Find a question in the form by ID
	 */
	private function findQuestion(array $form, string $questionId): ?array {
		foreach ($form['questions'] ?? [] as $question) {
			if (($question['id'] ?? '') === $questionId) {
				return $question;
			}
		}
		return null;
	}

	/**
	 * Check if uploaded file type is allowed for the question
	 */
	private function isAllowedFileType(array $uploadedFile, array $question): bool {
		$allowedTypes = $question['allowedTypes'] ?? [];

		// If no restrictions, allow all (except dangerous types)
		if (empty($allowedTypes) || in_array('*/*', $allowedTypes)) {
			// Block dangerous file types
			$dangerousTypes = [
				'application/x-executable',
				'application/x-msdownload',
				'application/x-msdos-program',
				'application/x-sh',
				'application/x-php',
			];
			$dangerousExtensions = ['exe', 'bat', 'cmd', 'sh', 'php', 'phar', 'ps1', 'vbs', 'js'];

			$mimeType = $uploadedFile['type'] ?? '';
			$extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

			if (in_array($mimeType, $dangerousTypes) || in_array($extension, $dangerousExtensions)) {
				return false;
			}
			return true;
		}

		$mimeType = $uploadedFile['type'] ?? '';
		$extension = '.' . strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

		foreach ($allowedTypes as $allowed) {
			// Check exact MIME type match
			if ($mimeType === $allowed) {
				return true;
			}

			// Check wildcard MIME type (e.g., image/*)
			if (str_ends_with($allowed, '/*')) {
				$prefix = substr($allowed, 0, -1);
				if (str_starts_with($mimeType, $prefix)) {
					return true;
				}
			}

			// Check extension (e.g., .pdf)
			if (str_starts_with($allowed, '.') && $extension === strtolower($allowed)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Why PHP refused an upload, in words a respondent can act on.
	 */
	private function uploadErrorMessage(int $errorCode): string {
		return match ($errorCode) {
			// TRANSLATORS: Error shown when an uploaded file is larger than the server allows
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => Translatable::t('The file exceeds the maximum upload size'),
			// TRANSLATORS: Error shown when an upload was interrupted
			UPLOAD_ERR_PARTIAL => Translatable::t('The file was only partially uploaded'),
			// TRANSLATORS: Error shown when no file was sent
			UPLOAD_ERR_NO_FILE => Translatable::t('No file was uploaded'),
			// TRANSLATORS: Error shown when the server could not store an upload for a reason of its own
			default => Translatable::t('The server could not store the file'),
		};
	}
}
