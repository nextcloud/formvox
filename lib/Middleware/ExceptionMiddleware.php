<?php

declare(strict_types=1);

namespace OCA\FormVox\Middleware;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\Service\FormBrokenException;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\InvalidAnswerException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\ParameterOutOfRangeException;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCS\OCSException;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Lock\LockedException;
use OCP\Security\Bruteforce\MaxDelayReached;
use Psr\Log\LoggerInterface;

/**
 * Turns every exception from a FormVox controller into an answer, so no controller catches for itself and
 * no exception text reaches a browser. Our own exceptions keep their message, translated in the user's
 * language, with a fixed reason code for integrations; anything else is logged and answered with a
 * generic error and the request id (the message only in debug mode). Pages keep Nextcloud's error page.
 * Registered before FormAccessMiddleware, so its refusals unwind into this one.
 */
class ExceptionMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
		private IConfig $config,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		// Nextcloud's own answers: too many wrong passwords is a 429 from its brute-force middleware.
		if ($exception instanceof OCSException || $exception instanceof MaxDelayReached) {
			throw $exception;
		}
		if ($this->returnsPage($controller, $methodName)) {
			return $this->page($exception);
		}

		if ($exception instanceof ClientErrorException) {
			return new JSONResponse(
				['error' => $this->l->t($exception->getMessage(), $exception->getParams()), 'reason' => $exception->getReason()] + $exception->getData(),
				$exception->getStatus(),
			);
		}
		if ($exception instanceof FormBrokenException) {
			// The file holds no form: nothing the server can mend, and nothing to log on every open.
			// TRANSLATORS: Error shown when a form file is damaged or is no form
			return new JSONResponse(['error' => $this->l->t('This file is not a FormVox form'), 'reason' => 'broken_form'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		if ($exception instanceof ParameterOutOfRangeException) {
			// A parameter outside what the method takes: the request is at fault, not the server.
			return new JSONResponse(['error' => $this->l->t('Something went wrong, please try again'), 'reason' => 'invalid_input'], Http::STATUS_BAD_REQUEST);
		}
		if ($exception instanceof InvalidAnswerException) {
			// Already in the respondent's language: AnswerValidator words it per question.
			return new JSONResponse(['error' => $exception->getMessage(), 'reason' => 'invalid_answer', 'question' => $exception->questionId], Http::STATUS_BAD_REQUEST);
		}
		if ($this->isBusy($exception)) {
			// A moment later it is free again: the browser and integrations may simply retry.
			$data = $exception instanceof FormLockedException ? $exception->getData() : [];
			return $this->busy(new JSONResponse(['error' => $this->busyMessage(), 'reason' => 'busy'] + $data));
		}
		if ($exception instanceof NotFoundException) {
			// TRANSLATORS: Error shown when something asked for does not exist or cannot be seen
			return new JSONResponse(['error' => $this->l->t('Not found'), 'reason' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$this->logger->error('FormVox: ' . $controller::class . '::' . $methodName . ' failed', ['app' => Application::APP_ID, 'exception' => $exception]);
		$body = [
			// TRANSLATORS: Error shown when the server failed for a reason it does not tell; the request id helps the admin find it in the log
			'error' => $this->l->t('Something went wrong, please try again'),
			'reason' => 'internal_error',
			'requestId' => $this->request->getId(),
		];
		if ($this->config->getSystemValueBool('debug', false)) {
			$body['message'] = $exception->getMessage();
		}
		return new JSONResponse($body, Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	/**
	 * A page answers with Nextcloud's own error pages: not found, no permission, or its generic one.
	 */
	private function page(\Exception $exception): Response {
		$status = $exception instanceof ClientErrorException ? $exception->getStatus() : 0;
		if ($status === Http::STATUS_NOT_FOUND || $exception instanceof NotFoundException) {
			return new NotFoundResponse();
		}
		if ($status === Http::STATUS_FORBIDDEN) {
			$page = new TemplateResponse('core', '403', [], TemplateResponse::RENDER_AS_GUEST);
			$page->setStatus(Http::STATUS_FORBIDDEN);
			return $page;
		}
		if ($this->isBusy($exception)) {
			return $this->busy(new TemplateResponse('core', 'error', ['errors' => [['error' => $this->busyMessage(), 'hint' => '']]], TemplateResponse::RENDER_AS_GUEST));
		}
		throw $exception;
	}

	/**
	 * Another save holds the form, or it stayed unreadable for a moment: never a not found, and never counted as an
	 * attack (7a, 3a-3). Nextcloud's own LockedException names a path, so only our message goes out.
	 */
	private function isBusy(\Exception $exception): bool {
		return $exception instanceof FormLockedException || $exception instanceof LockedException || $exception instanceof \OCA\FormVox\Service\FormBusyHint;
	}

	private function busyMessage(): string {
		// TRANSLATORS: Error shown when another save holds the form for a moment
		return $this->l->t('The form is busy, please try again');
	}

	private function busy(Response $response): Response {
		$response->setStatus(Http::STATUS_SERVICE_UNAVAILABLE);
		$response->addHeader('Retry-After', '2');
		return $response;
	}

	/**
	 * A method that may answer with a page, also as one of a union (TemplateResponse|RedirectResponse).
	 */
	private function returnsPage(Controller $controller, string $methodName): bool {
		try {
			$type = (new \ReflectionMethod($controller, $methodName))->getReturnType();
		} catch (\ReflectionException) {
			return false;
		}
		$types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
		foreach ($types as $one) {
			if ($one instanceof \ReflectionNamedType && is_a($one->getName(), TemplateResponse::class, true)) {
				return true;
			}
		}
		return false;
	}
}
