<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\ResponseController;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for ResponseController — split out of ApiController.
 * Pins: permission gating (403), not-found mapping (404), and the happy path
 * for getResponses / deleteAllResponses / deleteResponse.
 *
 * Everything is mocked; the controller runs against ocp stubs with no server.
 */
class ResponseControllerTest extends TestCase {
	private FormFileLocator $fileLocator;
	private ResponsePersistenceService $responsePersistence;
	private ResponseService $responseService;
	private PermissionService $permissionService;
	private IUserSession $userSession;

	protected function setUp(): void {
		parent::setUp();
		$this->fileLocator = $this->createMock(FormFileLocator::class);
		$this->responsePersistence = $this->createMock(ResponsePersistenceService::class);
		$this->responseService = $this->createMock(ResponseService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function controller(): ResponseController {
		return new ResponseController(
			$this->createMock(IRequest::class),
			$this->fileLocator,
			$this->responsePersistence,
			$this->responseService,
			$this->permissionService,
			$this->userSession
		);
	}

	// ---- getResponses() ----------------------------------------------------

	public function testGetResponsesDeniedWithoutViewPermission(): void {
		$this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_RESPONDENT);
		$this->permissionService->method('canViewResponses')->willReturn(false);
		$this->responseService->expects($this->never())->method('getResponses');

		$resp = $this->controller()->getResponses(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testGetResponsesReturnsResponsesAndSummary(): void {
		$this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_EDITOR);
		$this->permissionService->method('canViewResponses')->willReturn(true);
		$this->responseService->method('getResponses')->willReturn([['id' => 'r1']]);
		$this->responseService->method('getSummary')->willReturn(['count' => 1]);

		$resp = $this->controller()->getResponses(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame([['id' => 'r1']], $data['responses']);
		$this->assertSame(['count' => 1], $data['summary']);
	}

	public function testGetResponsesMapsExceptionTo500(): void {
		$this->fileLocator->method('getFileById')->willThrowException(new \Exception('boom'));

		$resp = $this->controller()->getResponses(1);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
	}

	// ---- deleteAllResponses() ----------------------------------------------

	public function testDeleteAllResponsesDeniedWithoutPermission(): void {
		$this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
		$this->permissionService->method('canDeleteResponses')->willReturn(false);
		$this->responsePersistence->expects($this->never())->method('deleteAllResponses');

		$resp = $this->controller()->deleteAllResponses(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testDeleteAllResponsesProceedsWhenAllowed(): void {
		$this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canDeleteResponses')->willReturn(true);
		$this->responsePersistence->expects($this->once())->method('deleteAllResponses')->with(1);

		$resp = $this->controller()->deleteAllResponses(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testDeleteAllResponsesMapsExceptionTo500(): void {
		$this->fileLocator->method('getFileById')->willThrowException(new \Exception('boom'));

		$resp = $this->controller()->deleteAllResponses(1);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
	}

	// ---- deleteResponse() --------------------------------------------------

	// Copied from ApiControllerTest::testDeleteResponseDeniedWithoutPermission,
	// rewired: getFileById -> fileLocator, deleteResponse -> responsePersistence.
	public function testDeleteResponseDeniedWithoutPermission(): void {
		$this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_VIEWER);
		$this->permissionService->method('canDeleteResponses')->willReturn(false);
		$this->responsePersistence->expects($this->never())->method('deleteResponse');

		$resp = $this->controller()->deleteResponse(1, 'r1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
	}

	public function testDeleteResponseProceedsWhenAllowed(): void {
		$this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canDeleteResponses')->willReturn(true);
		$this->responsePersistence->expects($this->once())->method('deleteResponse')->with(1, 'r1');

		$resp = $this->controller()->deleteResponse(1, 'r1');
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testDeleteResponseMapsNotFoundTo404(): void {
		$this->fileLocator->method('getFileById')->willReturn($this->createMock(File::class));
		$this->permissionService->method('getRoleFromFile')->willReturn(PermissionService::ROLE_OWNER);
		$this->permissionService->method('canDeleteResponses')->willReturn(true);
		$this->responsePersistence->method('deleteResponse')
			->willThrowException(new NotFoundException());

		$resp = $this->controller()->deleteResponse(1, 'missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}
}
