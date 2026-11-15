<?php

declare(strict_types=1);

namespace OCA\FormVox\Middleware;

use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\FormAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Enforces #[RequireFormPermission] before the controller runs. Its refusals (FormNotFoundException,
 * FormAccessDeniedException) are answered by ExceptionMiddleware, which is registered before this one.
 */
class FormAccessMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
		private FormAccessService $access,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		$attributes = (new \ReflectionMethod($controller, $methodName))->getAttributes(RequireFormPermission::class);
		if ($attributes !== []) {
			$this->access->require((int)$this->request->getParam('fileId'), $attributes[0]->newInstance()->permission);
		}
	}
}
