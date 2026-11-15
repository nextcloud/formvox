<?php

declare(strict_types=1);

namespace OC;

// Nextcloud's private class, for unit tests that run without the server; never loaded when it exists.
if (!class_exists(ServerNotAvailableException::class, false)) {
	class ServerNotAvailableException extends \Exception {
	}
}
