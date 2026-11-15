<?php

declare(strict_types=1);

namespace OCA\FormVox\DAV;

use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\Server;

/**
 * A form another save holds for a moment, over WebDAV: 503 with Retry-After, as the API answers it, so a sync client
 * tries again instead of giving up on the file.
 */
class FormBusy extends ServiceUnavailable {
	public function getHTTPHeaders(Server $server) {
		return ['Retry-After' => '2'];
	}
}
