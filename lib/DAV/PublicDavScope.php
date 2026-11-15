<?php

declare(strict_types=1);

namespace OCA\FormVox\DAV;

/**
 * Whether this request is served by one of Nextcloud's public WebDAV servers (public.php/webdav, public.php/dav),
 * whatever kind of token opened it.
 */
class PublicDavScope {
	private bool $public = false;

	public function enter(): void {
		$this->public = true;
	}

	public function isPublic(): bool {
		return $this->public;
	}

	/** Tests serve several requests in one process. */
	public function leave(): void {
		$this->public = false;
	}
}
