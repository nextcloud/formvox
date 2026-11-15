<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Security\FormPermission;
use OCP\Files\File;
use OCP\IUserManager;

/**
 * Whether an account can still use a form, by Nextcloud's own verdict. One rule for whoever acts on someone's
 * behalf: the creator who is notified, the creator of an API key or webhook (H2, M29).
 */
class AccountAccess {
	public const STATUS_OK = 'ok';
	public const STATUS_NONE = 'none';
	public const STATUS_DELETED = 'deleted';
	public const STATUS_DISABLED = 'disabled';
	public const STATUS_NO_ACCESS = 'no_access';

	public function __construct(
		private IUserManager $userManager,
		private FormFileLocator $fileLocator,
		private PermissionService $permissions,
	) {
	}

	/**
	 * @param string $permission what the account must be allowed with the form (FormPermission) to count as having access
	 * @return array{status: string, displayName: ?string}
	 */
	public function status(?string $userId, int $fileId, string $permission = FormPermission::READ): array {
		if ($userId === null || $userId === '') {
			return ['status' => self::STATUS_NONE, 'displayName' => null];
		}
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return ['status' => self::STATUS_DELETED, 'displayName' => $userId];
		}
		$displayName = $user->getDisplayName();
		if (!$user->isEnabled()) {
			return ['status' => self::STATUS_DISABLED, 'displayName' => $displayName];
		}
		// A file found in their view is readable; only a stronger right is asked of Nextcloud.
		$file = $this->fileLocator->getFileForUser($userId, $fileId);
		$allowed = $file !== null && ($permission === FormPermission::READ || $this->permissions->allows($file, $permission));
		return ['status' => $allowed ? self::STATUS_OK : self::STATUS_NO_ACCESS, 'displayName' => $displayName];
	}

	/**
	 * The form as $userId sees it, when they may do $permission with it (FormPermission); otherwise null.
	 */
	public function fileFor(?string $userId, int $fileId, string $permission = FormPermission::READ): ?File {
		if ($userId === null || $userId === '' || !($this->userManager->get($userId)?->isEnabled() ?? false)) {
			return null;
		}
		$file = $this->fileLocator->getFileForUser($userId, $fileId);
		return $file !== null && $this->permissions->allows($file, $permission) ? $file : null;
	}
}
