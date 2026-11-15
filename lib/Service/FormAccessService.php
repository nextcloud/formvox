<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Security\FormAccessDeniedException;
use OCA\FormVox\Security\FormNotFoundException;
use OCA\FormVox\Security\FormPermission;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IUserSession;

/**
 * Opens a form through the session user's own view and checks what Nextcloud allows them.
 */
class FormAccessService {
	/** @var array<string, array<int, File>> keyed by uid, so a user switch never reuses another user's File */
	private array $files = [];

	public function __construct(
		private FormFileLocator $fileLocator,
		private PermissionService $permissions,
		private IUserSession $userSession,
	) {
	}

	/**
	 * @throws FormNotFoundException
	 */
	public function getFile(int $fileId): File {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if (!isset($this->files[$uid][$fileId])) {
			try {
				$this->files[$uid][$fileId] = $this->fileLocator->getFileById($fileId);
			} catch (NotFoundException|\RuntimeException $e) {
				throw new FormNotFoundException('Form not found', 0, $e);
			}
		}
		return $this->files[$uid][$fileId];
	}

	/**
	 * @throws FormNotFoundException no access (404)
	 * @throws FormAccessDeniedException access, but not $permission (403)
	 */
	public function require(int $fileId, string $permission): File {
		$file = $this->getFile($fileId);
		if (!$this->permissions->allows($file, FormPermission::READ)) {
			throw new FormNotFoundException('Form not found');
		}
		if (!$this->permissions->allows($file, $permission)) {
			throw new FormAccessDeniedException('Permission denied');
		}
		return $file;
	}

	public function allows(File $file, string $permission): bool {
		return $this->permissions->allows($file, $permission);
	}
}
