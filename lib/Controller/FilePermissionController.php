<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class FilePermissionController extends Controller {
	public function __construct(
		IRequest $request,
		private FormAccessService $access,
		private PermissionService $permissionService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::READ)]
	public function getPermissions(int $fileId): DataResponse {
		$file = $this->access->getFile($fileId);
		return new DataResponse([
			'canRead' => true,
			'canEdit' => $this->permissionService->allows($file, FormPermission::EDIT),
			'canDelete' => $this->permissionService->allows($file, FormPermission::DELETE),
			'canDownload' => $this->permissionService->allows($file, FormPermission::DOWNLOAD),
			'canShare' => $this->permissionService->allows($file, FormPermission::MANAGE_SHARING),
			'role' => $this->permissionService->getRoleFromFile($file),
		]);
	}
}
