<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\DAV;

use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;

/** Keeps the answer Sabre would send, for a WebDAV request made inside a test. */
class CapturingSapi extends Sapi {
	public static ?ResponseInterface $sent = null;

	public static function sendResponse(ResponseInterface $response) {
		self::$sent = $response;
	}
}
