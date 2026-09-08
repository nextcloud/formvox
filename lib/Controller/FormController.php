<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ShareTokenService;
use OCA\FormVox\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;

class FormController extends Controller {
	private FormRepository $formRepository;
	private FormFileLocator $fileLocator;
	private PermissionService $permissionService;
	private IndexService $indexService;
	private TemplateService $templateService;
	private ShareTokenService $shareTokenService;
	private IUserSession $userSession;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private INotificationManager $notificationManager;

	public function __construct(
		IRequest $request,
		FormRepository $formRepository,
		FormFileLocator $fileLocator,
		PermissionService $permissionService,
		IndexService $indexService,
		TemplateService $templateService,
		ShareTokenService $shareTokenService,
		IUserSession $userSession,
		IUserManager $userManager,
		IGroupManager $groupManager,
		INotificationManager $notificationManager,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->formRepository = $formRepository;
		$this->fileLocator = $fileLocator;
		$this->permissionService = $permissionService;
		$this->indexService = $indexService;
		$this->templateService = $templateService;
		$this->shareTokenService = $shareTokenService;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->notificationManager = $notificationManager;
	}

	/**
	 * List all forms accessible to the current user
	 */
	#[NoAdminRequired]
	public function list(): DataResponse {
		try {
			$forms = $this->formRepository->listForms();
			return new DataResponse($forms);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Create a new form
	 */
	/**
	 * Save an existing form as an admin-managed template (admin only). #100
	 */
	public function saveAsTemplate(int $fileId, string $title = '', string $description = ''): DataResponse {
		try {
			$form = $this->formRepository->load($fileId);
			$entry = $this->templateService->addTemplate(
				$title !== '' ? $title : ($form['title'] ?? 'Untitled template'),
				$description !== '' ? $description : ($form['description'] ?? ''),
				$form
			);
			return new DataResponse(['template' => $entry]);
		} catch (\Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function create(string $title, string $path = '', ?string $template = null, array $prefilled = [], bool $notifyOnReady = false, ?string $adminTemplateId = null): DataResponse {
		try {
			// If an admin template id is supplied, load its structure and
			// pass it as prefilled content so the new form starts as a copy
			// of the admin template (#100).
			if ($adminTemplateId !== null && $adminTemplateId !== '') {
				$tplForm = $this->templateService->getTemplate($adminTemplateId);
				if ($tplForm !== null) {
					if (!empty($tplForm['description']) && !isset($prefilled['description'])) {
						$prefilled['description'] = $tplForm['description'];
					}
					if (!empty($tplForm['questions']) && !isset($prefilled['questions'])) {
						$prefilled['questions'] = $tplForm['questions'];
					}
				}
			}

			$result = $this->formRepository->create($title, $path, $template, $prefilled);

			if ($notifyOnReady) {
				$this->sendAiReadyNotification($result, $title);
			}

			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	private function sendAiReadyNotification(array $result, string $title): void {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return;
		}
		$fileId = (int)($result['fileId'] ?? 0);
		if ($fileId <= 0) {
			return;
		}
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('form', (string)$fileId)
				->setSubject('ai_form_ready', [
					'formTitle' => $title,
					'fileId' => $fileId,
				]);
			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			// Notifications are best-effort; never block form creation
		}
	}

	/**
	 * Get a form by file ID
	 */
	#[NoAdminRequired]
	public function get(int $fileId): DataResponse {
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$form = $this->formRepository->load($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			// Deny access if user has no permissions
			if ($role === PermissionService::ROLE_NONE) {
				return new DataResponse(
					['error' => 'Access denied'],
					Http::STATUS_FORBIDDEN
				);
			}

			$permissions = $this->permissionService->getPermissionsForRole($role);

			// Remove responses if user can't view them
			if (!$permissions['viewResponses']) {
				unset($form['responses']);
				unset($form['_index']);
			}

			return new DataResponse([
				'form' => $form,
				'role' => $role,
				'permissions' => $permissions,
			]);
		} catch (\OCP\Files\NotFoundException $e) {
			return new DataResponse(
				['error' => 'Form not found'],
				Http::STATUS_NOT_FOUND
			);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Update a form
	 */
	#[NoAdminRequired]
	public function update(
		int $fileId,
		?string $title = null,
		?string $description = null,
		?array $questions = null,
		?array $settings = null,
		?array $pages = null,
		?array $permissions = null,
		?array $branding = null,
	): DataResponse {
		// Build data array from individual parameters
		$data = [];
		if ($title !== null) {
			$data['title'] = $title;
		}
		if ($description !== null) {
			$data['description'] = $description;
		}
		if ($questions !== null) {
			$data['questions'] = $questions;
		}
		if ($settings !== null) {
			$data['settings'] = $settings;
		}
		if ($pages !== null) {
			$data['pages'] = $pages;
		}
		if ($permissions !== null) {
			$data['permissions'] = $permissions;
		}
		// Branding can be null (use admin defaults) or an array (custom branding)
		// Check the raw request body to see if branding was explicitly sent
		$requestBody = file_get_contents('php://input');
		$requestData = json_decode($requestBody, true) ?? [];
		if (array_key_exists('branding', $requestData)) {
			// Use the value from request data since the parameter might not capture null correctly
			$data['branding'] = $requestData['branding'];
		}

		if (empty($data)) {
			return new DataResponse(
				['error' => 'No data provided'],
				Http::STATUS_BAD_REQUEST
			);
		}
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			if (!$this->permissionService->canEditQuestions($role)) {
				return new DataResponse(
					['error' => 'Permission denied'],
					Http::STATUS_FORBIDDEN
				);
			}

			// Check settings permission separately
			if (isset($data['settings']) && !$this->permissionService->canEditSettings($role)) {
				unset($data['settings']);
			}

			// The share token is server-owned: once a link exists its URL must stay
			// valid until someone deliberately revokes it. A generic save can never
			// change it, however stale the client's copy of `settings` is (#135).
			//
			// This mirrors Nextcloud core, which mints a token only when there is
			// none (Share20\Manager::createShare) and never touches it in
			// updateShare — changing a share's password, expiry or permissions
			// keeps the same link.
			//
			// Note this runs whenever settings are written, not only when the
			// client includes the key: FormRepository::update() replaces `settings`
			// wholesale, so a save that merely omits public_token would drop the
			// link just as effectively as one sending a stale value.
			if (isset($data['settings'])) {
				// Read the current token from the file we already fetched above
				// ($file, user-scoped and permission-checked) rather than a second
				// loadPublic() lookup, which resolves through a different path and
				// could fail independently of this save succeeding.
				$existing = null;
				$readOk = false;
				try {
					$currentForm = json_decode($file->getContent(), true);
					if (is_array($currentForm)) {
						$existing = $currentForm['settings']['public_token'] ?? null;
						$readOk = true;
					}
				} catch (\Throwable $e) {
					// Could not read the current form — fall through to fail-closed.
				}

				// The share-token rules live in ShareTokenService (#135). A null
				// return means "fail closed": we couldn't read the current form,
				// so we can't tell whether a link exists and must not risk
				// dropping it — skip the settings write entirely (every other
				// field still saves). Otherwise it returns the settings to store.
				$resolvedSettings = $this->shareTokenService->resolveTokenForUpdate(
					$data['settings'],
					$readOk,
					is_string($existing) ? $existing : null
				);
				if ($resolvedSettings === null) {
					unset($data['settings']);
				} else {
					$data['settings'] = $resolvedSettings;
				}
			}

			$updatedForm = $this->formRepository->update($fileId, $data);
			return new DataResponse(['form' => $updatedForm]);
		} catch (\OCP\Files\NotFoundException $e) {
			return new DataResponse(
				['error' => 'Form not found'],
				Http::STATUS_NOT_FOUND
			);
		} catch (\RuntimeException $e) {
			// Lock conflicts and other runtime errors
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_CONFLICT
			);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Set favorite status for a form
	 */
	#[NoAdminRequired]
	public function setFavorite(int $fileId, bool $favorite): DataResponse {
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			// Any user with at least view permission can favorite a form
			if ($role === PermissionService::ROLE_NONE) {
				return new DataResponse(
					['error' => 'Permission denied'],
					Http::STATUS_FORBIDDEN
				);
			}

			$this->formRepository->update($fileId, ['favorite' => $favorite]);
			return new DataResponse(['success' => true, 'favorite' => $favorite]);
		} catch (\OCP\Files\NotFoundException $e) {
			return new DataResponse(
				['error' => 'Form not found'],
				Http::STATUS_NOT_FOUND
			);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Delete a form
	 */
	#[NoAdminRequired]
	public function delete(int $fileId): DataResponse {
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			if (!$this->permissionService->canDeleteForm($role)) {
				return new DataResponse(
					['error' => 'Permission denied'],
					Http::STATUS_FORBIDDEN
				);
			}

			$this->formRepository->delete($fileId);
			return new DataResponse(['success' => true]);
		} catch (\OCP\Files\NotFoundException $e) {
			return new DataResponse(
				['error' => 'Form not found'],
				Http::STATUS_NOT_FOUND
			);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Rebuild form index
	 */
	#[NoAdminRequired]
	public function rebuildIndex(int $fileId): DataResponse {
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$form = $this->formRepository->load($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			if (!$this->permissionService->canEditSettings($role)) {
				return new DataResponse(
					['error' => 'Permission denied'],
					Http::STATUS_FORBIDDEN
				);
			}

			// Rebuild index
			$this->indexService->rebuildIndex($form);

			// Save updated form
			$this->formRepository->update($fileId, ['_index' => $form['_index']]);

			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Search users and groups for access restriction picker
	 */
	#[NoAdminRequired]
	public function searchSharees(string $search = '', int $limit = 10): DataResponse {
		try {
			$users = [];
			foreach ($this->userManager->search($search, $limit) as $user) {
				$users[] = [
					'id' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
				];
			}

			$groups = [];
			foreach ($this->groupManager->search($search, $limit) as $group) {
				$groups[] = [
					'id' => $group->getGID(),
					'displayName' => $group->getDisplayName(),
				];
			}

			return new DataResponse([
				'users' => $users,
				'groups' => $groups,
			]);
		} catch (\Exception $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Replace the share link with a freshly minted one.
	 *
	 * Rotating a link invalidates the URL everyone already has, so it must be a
	 * deliberate act with its own endpoint — never a side effect of saving the
	 * form (#135). Password, expiry and access restrictions are left untouched;
	 * only the token changes.
	 */
	#[NoAdminRequired]
	public function rotateShareToken(int $fileId): DataResponse {
		try {
			$file = $this->fileLocator->getFileById($fileId);
			$userId = $this->userSession->getUser()?->getUID() ?? '';
			$role = $this->permissionService->getRoleFromFile($file, $userId);

			if (!$this->permissionService->canEditSettings($role)) {
				return new DataResponse(
					['error' => 'Permission denied'],
					Http::STATUS_FORBIDDEN
				);
			}

			$form = $this->formRepository->loadPublic($fileId);

			// The "no link to replace" guard + fresh mint live in the service
			// (#135); it throws DomainException when there is nothing to rotate.
			try {
				$settings = $this->shareTokenService->rotate($form['settings'] ?? []);
			} catch (\DomainException $e) {
				return new DataResponse(
					['error' => $e->getMessage()],
					Http::STATUS_BAD_REQUEST
				);
			}

			$updatedForm = $this->formRepository->update($fileId, ['settings' => $settings]);
			return new DataResponse(['form' => $updatedForm]);
		} catch (\OCP\Files\NotFoundException $e) {
			return new DataResponse(['error' => 'Form not found'], Http::STATUS_NOT_FOUND);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}
}
