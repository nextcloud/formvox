<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Security\FormPermission;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Storage\ISharedStorage;

class PermissionService {
	public const ROLE_NONE = 'none';
	public const ROLE_RESPONDENT = 'respondent';
	public const ROLE_VIEWER = 'viewer';
	public const ROLE_EDITOR = 'editor';
	public const ROLE_ADMIN = 'admin';

	/**
	 * Role from Nextcloud's permissions on the file, opened through the user's own view.
	 * No owner override: in a team folder getOwner() is whoever is looking (#146).
	 */
	public function getRoleFromFile(File $file): string {
		if (!$file->isReadable()) {
			return self::ROLE_NONE;
		}
		if (!$file->isUpdateable()) {
			return self::ROLE_VIEWER;
		}
		return $this->isDeletable($file) ? self::ROLE_ADMIN : self::ROLE_EDITOR;
	}

	/**
	 * Whether Nextcloud allows $permission on the form as the user opened it.
	 */
	public function allows(File $file, string $permission): bool {
		if (!$file->isReadable()) {
			return false;
		}
		return match ($permission) {
			FormPermission::READ => true,
			FormPermission::EDIT => (bool)$file->isUpdateable(),
			FormPermission::DELETE => $this->isDeletable($file),
			FormPermission::VIEW_CONTENT => $this->canSeeContent($file),
			FormPermission::DOWNLOAD => $this->canDownload($file),
			FormPermission::MANAGE_SHARING => (bool)$file->isUpdateable()
				&& $this->isDeletable($file)
				&& (bool)$file->isShareable(),
			default => false,
		};
	}

	/**
	 * For someone a form was shared with, Nextcloud's "delete" on the share itself means taking it out of their
	 * files, and it reports that only when the node was loaded through the view (e.g. after a write). What counts
	 * for the form is the share's own delete right, however the node was loaded.
	 */
	private function isDeletable(File $file): bool {
		if (!$file->isDeletable()) {
			return false;
		}
		$storage = $file->getStorage();
		if (!$storage->instanceOfStorage(ISharedStorage::class)) {
			return true;
		}
		/** @var ISharedStorage $storage */
		return ($storage->getShare()->getPermissions() & Constants::PERMISSION_DELETE) !== 0;
	}

	/**
	 * Same rule as IShare::canDownload(), which only exists from NC 34.
	 */
	public function canDownload(File $file): bool {
		$storage = $file->getStorage();
		if (!$storage->instanceOfStorage(ISharedStorage::class)) {
			return true;
		}
		/** @var ISharedStorage $storage */
		$share = $storage->getShare();
		if (($share->getPermissions() & Constants::PERMISSION_READ) === 0) {
			return false;
		}
		return $share->getAttributes()?->getAttribute('permissions', 'download') !== false;
	}

	/**
	 * Nextcloud's own verdict on seeing what a shared file holds: a share without download permission may, unless
	 * the administrator turned "allow preview even if download is disabled" off.
	 */
	public function canSeeContent(File $file): bool {
		$storage = $file->getStorage();
		if (!$storage->instanceOfStorage(ISharedStorage::class)) {
			return true;
		}
		/** @var ISharedStorage $storage */
		return $storage->getShare()->canSeeContent();
	}

	/**
	 * Flags the frontend uses to show or hide actions.
	 */
	public function getPermissionsForFile(File $file): array {
		$permissions = $this->getPermissionsForRole(
			$this->getRoleFromFile($file),
			$this->allows($file, FormPermission::MANAGE_SHARING)
		);
		$permissions['viewResponses'] = $permissions['viewResponses'] && $this->allows($file, FormPermission::VIEW_CONTENT);
		$permissions['canDownload'] = $this->allows($file, FormPermission::DOWNLOAD);
		return $permissions;
	}

	/**
	 * Check if user can respond to form
	 */
	public function canRespond(string $role): bool {
		return in_array($role, [
			self::ROLE_RESPONDENT,
			self::ROLE_VIEWER,
			self::ROLE_EDITOR,
			self::ROLE_ADMIN,
		]);
	}

	/**
	 * Check if user can view responses
	 */
	public function canViewResponses(string $role): bool {
		return in_array($role, [
			self::ROLE_VIEWER,
			self::ROLE_EDITOR,
			self::ROLE_ADMIN,
		]);
	}

	/**
	 * Check if user can edit questions
	 */
	public function canEditQuestions(string $role): bool {
		return in_array($role, [
			self::ROLE_EDITOR,
			self::ROLE_ADMIN,
		]);
	}

	/**
	 * Check if user can modify settings
	 * Editor and above can modify settings
	 */
	public function canEditSettings(string $role): bool {
		return in_array($role, [
			self::ROLE_EDITOR,
			self::ROLE_ADMIN,
		]);
	}

	/**
	 * Check if user can delete responses
	 * Editor and above can delete responses
	 */
	public function canDeleteResponses(string $role): bool {
		return in_array($role, [
			self::ROLE_EDITOR,
			self::ROLE_ADMIN,
		]);
	}

	/**
	 * Check if user can delete the form — Nextcloud's delete permission.
	 */
	public function canDeleteForm(string $role): bool {
		return $role === self::ROLE_ADMIN;
	}

	/**
	 * Check if user can manage permissions (open NC share dialog)
	 * Editor and above can manage permissions
	 */
	public function canManagePermissions(string $role): bool {
		return in_array($role, [
			self::ROLE_EDITOR,
			self::ROLE_ADMIN,
		]);
	}

	/**
	 * Prefer getPermissionsForFile(), which also fills canShare and canDownload.
	 */
	public function getPermissionsForRole(string $role, bool $canShare = false): array {
		return [
			'respond' => $this->canRespond($role),
			'viewResponses' => $this->canViewResponses($role),
			'editQuestions' => $this->canEditQuestions($role),
			'editSettings' => $this->canEditSettings($role),
			'deleteResponses' => $this->canDeleteResponses($role),
			'deleteForm' => $this->canDeleteForm($role),
			'managePermissions' => $this->canManagePermissions($role),
			'canShare' => $canShare,
		];
	}
}
