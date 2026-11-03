<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ResponseLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Manage a form's unique response links (e.g. 250 single-use links).
 * The rules live in ResponseLinkService; this keeps the HTTP concerns.
 */
class ResponseLinkController extends Controller {
	private FormRepository $formRepository;
	private FormFileLocator $fileLocator;
	private PermissionService $permissionService;
	private ResponseLinkService $responseLinkService;
	private IUserSession $userSession;
	private IL10N $l;

	public function __construct(
		IRequest $request,
		FormRepository $formRepository,
		FormFileLocator $fileLocator,
		PermissionService $permissionService,
		ResponseLinkService $responseLinkService,
		IUserSession $userSession,
		IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->formRepository = $formRepository;
		$this->fileLocator = $fileLocator;
		$this->permissionService = $permissionService;
		$this->responseLinkService = $responseLinkService;
		$this->userSession = $userSession;
		$this->l = $l;
	}

	/**
	 * List the form's response links with how often each was used
	 */
	#[NoAdminRequired]
	public function list(int $fileId): DataResponse {
		return $this->withSettingsPermission($fileId, function () use ($fileId) {
			$form = $this->formRepository->load($fileId);
			return new DataResponse(['links' => $this->responseLinkService->listWithUsage($form)]);
		});
	}

	/**
	 * Generate a batch of response links
	 */
	#[NoAdminRequired]
	public function create(int $fileId, int $count = 1, int $maxUses = 1, string $label = ''): DataResponse {
		return $this->withSettingsPermission($fileId, function () use ($fileId, $count, $maxUses, $label) {
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			try {
				$this->responseLinkService->generate($fileId, $count, $maxUses, $label, $userId);
			} catch (\InvalidArgumentException $e) {
				return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
			}
			$form = $this->formRepository->load($fileId);
			return new DataResponse(
				['links' => $this->responseLinkService->listWithUsage($form)],
				Http::STATUS_CREATED
			);
		});
	}

	/**
	 * Delete one response link
	 */
	#[NoAdminRequired]
	public function delete(int $fileId, string $linkId): DataResponse {
		return $this->withSettingsPermission($fileId, function () use ($fileId, $linkId) {
			if ($this->responseLinkService->delete($fileId, [$linkId]) === 0) {
				return new DataResponse(['error' => $this->l->t('Link not found')], Http::STATUS_NOT_FOUND);
			}
			return new DataResponse(['success' => true]);
		});
	}

	/**
	 * Delete all response links of the form
	 */
	#[NoAdminRequired]
	public function deleteAll(int $fileId): DataResponse {
		return $this->withSettingsPermission($fileId, function () use ($fileId) {
			$deleted = $this->responseLinkService->delete($fileId, null);
			return new DataResponse(['success' => true, 'deleted' => $deleted]);
		});
	}

	/**
	 * Run $action only when the current user may edit the form's settings,
	 * mapping the usual failures to HTTP responses.
	 */
	private function withSettingsPermission(int $fileId, callable $action): DataResponse {
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			if (!$this->permissionService->canEditSettings($role)) {
				return new DataResponse(
					['error' => $this->l->t('Permission denied')],
					Http::STATUS_FORBIDDEN
				);
			}

			return $action();
		} catch (\OCP\Files\NotFoundException $e) {
			return new DataResponse(['error' => $this->l->t('Form not found')], Http::STATUS_NOT_FOUND);
		} catch (\RuntimeException $e) {
			// Lock conflicts and other runtime errors
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
