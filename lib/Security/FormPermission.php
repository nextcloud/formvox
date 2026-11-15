<?php

declare(strict_types=1);

namespace OCA\FormVox\Security;

/**
 * What an endpoint needs on a form; PermissionService::allows() maps each onto Nextcloud's permissions.
 */
final class FormPermission {
	public const READ = 'read';
	public const EDIT = 'edit';
	public const DELETE = 'delete';
	/** The responses and their summary; a share without download permission may not where Nextcloud forbids previews. */
	public const VIEW_CONTENT = 'view-content';
	/** Exports and upload downloads; a share without download permission may not. */
	public const DOWNLOAD = 'download';
	/** Public link, API keys and webhooks reach outside Nextcloud, so they need full rights. */
	public const MANAGE_SHARING = 'manage-sharing';

	public const ALL = [self::READ, self::EDIT, self::DELETE, self::VIEW_CONTENT, self::DOWNLOAD, self::MANAGE_SHARING];

	private function __construct() {
	}
}
