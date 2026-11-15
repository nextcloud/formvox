<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Middleware;

use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Middleware\ExceptionMiddleware;
use OCA\FormVox\Security\FormAccessDeniedException;
use OCA\FormVox\Security\FormNotFoundException;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\InvalidAnswerException;
use OCA\FormVox\Service\ResponseRefusedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/FormAccessMiddlewareTest.php';

/**
 * Every exception from a FormVox controller becomes an answer: our own ones translated with a reason code,
 * anything else logged and hidden behind a generic error with the request id (review V1/V4, PO 4a).
 */
class ExceptionMiddlewareTest extends TestCase {
	private LoggerInterface $logger;
	private bool $debug = false;
	private FakeFormController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new FakeFormController('formvox', $this->createMock(IRequest::class));
	}

	private function middleware(): ExceptionMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-123');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(fn (string $key, bool $default) => $key === 'debug' ? $this->debug : $default);
		// A Dutch user: the answer is in their language.
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $params = []) => '[nl] ' . vsprintf($text, $params));
		return new ExceptionMiddleware($request, $config, $l, $this->logger);
	}

	private function answer(\Exception $exception, string $method = 'edit'): JSONResponse {
		$response = $this->middleware()->afterException($this->controller, $method, $exception);
		$this->assertInstanceOf(JSONResponse::class, $response);
		return $response;
	}

	/**
	 * @return array<string, array{\Exception, int, array<string, mixed>}>
	 */
	public static function ownErrors(): array {
		return [
			'no access looks like not there' => [new FormNotFoundException(), Http::STATUS_NOT_FOUND, ['error' => '[nl] Form not found', 'reason' => 'not_found']],
			'visible but not allowed' => [new FormAccessDeniedException(), Http::STATUS_FORBIDDEN, ['error' => '[nl] Permission denied', 'reason' => 'forbidden']],
			'not signed in' => [RequestException::loginRequired(), Http::STATUS_UNAUTHORIZED, ['error' => '[nl] This form requires you to be logged in', 'reason' => 'login_required']],
			'closed' => [ResponseRefusedException::closed(), Http::STATUS_GONE, ['error' => '[nl] This form has expired', 'reason' => 'form_closed']],
			'not open yet, with when' => [ResponseRefusedException::notYetOpen('2030-01-01T00:00:00+00:00'), Http::STATUS_FORBIDDEN, ['error' => '[nl] This form is not yet open', 'reason' => 'form_not_open', 'opensAt' => '2030-01-01T00:00:00+00:00']],
			'already answered' => [ResponseRefusedException::alreadyAnswered(), Http::STATUS_CONFLICT, ['error' => '[nl] You have already submitted a response to this form', 'reason' => 'already_answered']],
			'Microsoft not connected' => [RequestException::notConnected(), Http::STATUS_CONFLICT, ['error' => '[nl] Connect your Microsoft account first', 'reason' => 'not_connected', 'needsAuth' => true]],
			'invalid input with its value' => [RequestException::invalid('Unknown type %s', ['x']), Http::STATUS_BAD_REQUEST, ['error' => '[nl] Unknown type x', 'reason' => 'invalid_input']],
		];
	}

	/**
	 * @dataProvider ownErrors
	 * @param array<string, mixed> $body
	 */
	public function testOurOwnErrorsAreTranslatedWithAReasonCode(\Exception $exception, int $status, array $body): void {
		$response = $this->answer($exception);

		$this->assertSame([$status, $body], [$response->getStatus(), $response->getData()]);
	}

	public function testAnInvalidAnswerNamesTheQuestionAndIsAlreadyWorded(): void {
		$response = $this->answer(new InvalidAnswerException("Question 'Name' is required", 'q1'));

		$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => "Question 'Name' is required", 'reason' => 'invalid_answer', 'question' => 'q1']], [$response->getStatus(), $response->getData()]);
	}

	/** A busy form is a moment's wait: 503 with Retry-After, so the browser and integrations retry (7a, 3a-2). */
	public function testABusyFormIs503WithRetryAfterWithoutTheInternals(): void {
		$response = $this->answer(new FormLockedException('Form 7 stayed busy'));

		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, 'busy'], [$response->getStatus(), $response->getData()['reason']]);
		$this->assertSame('2', $response->getHeaders()['Retry-After'] ?? null);
		$this->assertStringNotContainsString('stayed busy', json_encode($response->getData()));
	}

	/** Nextcloud's own lock refusal is the same moment's wait, and its message names a path that stays inside. */
	public function testNextcloudsLockedExceptionIsTheSameBusy503(): void {
		$response = $this->answer(new LockedException('/anna/files/Survey.fvform'));

		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, 'busy', '2'], [$response->getStatus(), $response->getData()['reason'], $response->getHeaders()['Retry-After'] ?? null]);
		$this->assertStringNotContainsString('Survey', json_encode($response->getData()));
	}

	/** A page of a busy form says so with a 503 to retry, instead of Nextcloud's generic error (7a, 3a-3). */
	public function testABusyPageIsA503PageToRetry(): void {
		$page = $this->middleware()->afterException($this->controller, 'page', new FormLockedException('Form 7 stayed unreadable'));

		$this->assertInstanceOf(TemplateResponse::class, $page);
		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, '2'], [$page->getStatus(), $page->getHeaders()['Retry-After'] ?? null]);
		$this->assertSame('[nl] The form is busy, please try again', $page->getParams()['errors'][0]['error']);
	}

	/** Outside the range a method declares, or Nextcloud's own for a `limit`: the request's fault, and nothing to log. */
	/** A file that holds no form: nothing the server can mend, so no error in the log on every open. */
	public function testABrokenFormIs422(): void {
		$this->logger->expects($this->never())->method('error');
		$response = $this->answer(new \OCA\FormVox\Service\FormBrokenException());

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('broken_form', $response->getData()['reason']);
	}

	public function testAParameterOutOfRangeIs400(): void {
		$this->logger->expects($this->never())->method('error');
		$response = $this->answer(new \OCP\AppFramework\Http\ParameterOutOfRangeException('limit', 2001, 1, 2000));

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid_input', $response->getData()['reason']);
	}

	public function testANotFoundInsideTheEndpointIs404(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->answer(new NotFoundException('/var/www/data/x not found'))->getStatus());
	}

	public function testAnythingElseIsLoggedAndHiddenBehindTheRequestId(): void {
		$this->logger->expects($this->once())->method('error');

		$response = $this->answer(new \RuntimeException('Could not write form file (fileId 7): /var/www/data/anna/files/x.fvform'));

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => '[nl] Something went wrong, please try again', 'reason' => 'internal_error', 'requestId' => 'req-123'], $response->getData());
	}

	public function testDebugModeAlsoShowsTheMessage(): void {
		$this->debug = true;

		$this->assertSame('boom', $this->answer(new \RuntimeException('boom'))->getData()['message']);
	}

	public function testPagesKeepNextcloudsErrorPages(): void {
		$notFound = $this->middleware()->afterException($this->controller, 'page', new FormNotFoundException());
		$denied = $this->middleware()->afterException($this->controller, 'page', new FormAccessDeniedException());

		$this->assertInstanceOf(NotFoundResponse::class, $notFound);
		$this->assertInstanceOf(TemplateResponse::class, $denied);
		$this->assertSame(Http::STATUS_FORBIDDEN, $denied->getStatus());

		$this->expectException(\RuntimeException::class);
		$this->middleware()->afterException($this->controller, 'page', new \RuntimeException('boom'));
	}

	public function testOcsExceptionsAreLeftToNextcloud(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->middleware()->afterException($this->controller, 'edit', new OCSNotFoundException());
	}
}
