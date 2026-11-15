<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Middleware;

use OCA\FormVox\Middleware\FormAccessMiddleware;
use OCA\FormVox\Security\FormAccessDeniedException;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\FormAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class FakeFormController extends Controller {
	#[RequireFormPermission(FormPermission::EDIT)]
	public function edit(int $fileId): DataResponse {
		return new DataResponse();
	}

	#[RequireFormPermission(FormPermission::READ)]
	public function page(int $fileId): TemplateResponse {
		return new TemplateResponse('formvox', 'editor');
	}

	public function open(): DataResponse {
		return new DataResponse();
	}
}

/** Only the check; ExceptionMiddlewareTest pins how a refusal is answered. */
class FormAccessMiddlewareTest extends TestCase {
	private FormAccessService $access;
	private FakeFormController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->access = $this->createMock(FormAccessService::class);
		$this->controller = new FakeFormController('formvox', $this->createMock(IRequest::class));
	}

	private function middleware(): FormAccessMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('fileId')->willReturn('42');
		return new FormAccessMiddleware($request, $this->access);
	}

	public function testAnAttributedMethodIsCheckedWithItsPermissionAndFileId(): void {
		$this->access->expects($this->once())->method('require')->with(42, FormPermission::EDIT);

		$this->middleware()->beforeController($this->controller, 'edit');
	}

	public function testAMethodWithoutTheAttributeIsNotChecked(): void {
		$this->access->expects($this->never())->method('require');

		$this->middleware()->beforeController($this->controller, 'open');
	}

	public function testARefusalIsThrownForExceptionMiddlewareToAnswer(): void {
		$this->access->method('require')->willThrowException(new FormAccessDeniedException());

		$this->expectException(FormAccessDeniedException::class);
		$this->middleware()->beforeController($this->controller, 'edit');
	}
}
