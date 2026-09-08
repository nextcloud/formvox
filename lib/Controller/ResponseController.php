<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ResponseController extends Controller {
	private FormFileLocator $fileLocator;
	private ResponsePersistenceService $responsePersistence;
	private ResponseService $responseService;
	private PermissionService $permissionService;
	private IUserSession $userSession;

	public function __construct(
		IRequest $request,
		FormFileLocator $fileLocator,
		ResponsePersistenceService $responsePersistence,
		ResponseService $responseService,
		PermissionService $permissionService,
		IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->fileLocator = $fileLocator;
		$this->responsePersistence = $responsePersistence;
		$this->responseService = $responseService;
		$this->permissionService = $permissionService;
		$this->userSession = $userSession;
	}

	/**
	 * Get responses for a form
	 */
	#[NoAdminRequired]
	public function getResponses(int $fileId, ?string $date = null): DataResponse {
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			if (!$this->permissionService->canViewResponses($role)) {
				return new DataResponse(
					['error' => 'Permission denied'],
					Http::STATUS_FORBIDDEN
				);
			}

			$responses = $this->responseService->getResponses($fileId, $date);
			$summary = $this->responseService->getSummary($fileId);

			return new DataResponse([
				'responses' => $responses,
				'summary' => $summary,
			]);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Delete all responses
	 */
	#[NoAdminRequired]
	public function deleteAllResponses(int $fileId): DataResponse {
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			if (!$this->permissionService->canDeleteResponses($role)) {
				return new DataResponse(
					['error' => 'Permission denied'],
					Http::STATUS_FORBIDDEN
				);
			}

			$this->responsePersistence->deleteAllResponses($fileId);
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Delete a response
	 */
	#[NoAdminRequired]
	public function deleteResponse(int $fileId, string $responseId): DataResponse {
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			if (!$this->permissionService->canDeleteResponses($role)) {
				return new DataResponse(
					['error' => 'Permission denied'],
					Http::STATUS_FORBIDDEN
				);
			}

			$this->responsePersistence->deleteResponse($fileId, $responseId);
			return new DataResponse(['success' => true]);
		} catch (\OCP\Files\NotFoundException $e) {
			return new DataResponse(
				['error' => 'Response not found'],
				Http::STATUS_NOT_FOUND
			);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}
}
