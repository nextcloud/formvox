<?php

declare(strict_types=1);

/**
 * Minimal stubs for the handful of internal `OC\` symbols that the public
 * `nextcloud/ocp` interfaces hard-reference (via `extends`/`implements`), which
 * the ocp package itself does not ship.
 *
 * Without these, PHPUnit's mock generator cannot reflect e.g. IRootFolder
 * (which `extends Folder, OC\Hooks\Emitter`). Everything is guarded so the real
 * class wins when the suite runs inside a Nextcloud install.
 *
 * Only symbols in HARD positions (extends/implements/catch) need stubbing;
 * docblock/@throws references autoload lazily and never bite in unit tests.
 */

namespace OC\Hooks {
	if (!interface_exists(Emitter::class)) {
		/** Stub of the internal event-emitter mixin IRootFolder extends. */
		interface Emitter {
		}
	}
}

namespace OC {
	if (!class_exists(AppScriptDependency::class)) {
		/**
		 * Stub of the internal dependency node OCP\Util::addScript() builds
		 * (`new OC\AppScriptDependency(...)`) while registering scripts.
		 * Only its existence matters for the no-op asset path in unit tests.
		 */
		class AppScriptDependency {
			public function __construct($id = null, $deps = []) {
			}

			public function addDep($dep): void {
			}

			public function setVisited(): void {
			}

			public function isVisited(): bool {
				return false;
			}

			public function getId() {
				return null;
			}

			public function getDeps(): array {
				return [];
			}
		}
	}
}

namespace OC\User {
	if (!class_exists(NoUserException::class)) {
		/** Stub of the exception several Files interfaces declare via @throws. */
		class NoUserException extends \Exception {
		}
	}
}

namespace OC\Share {
	if (!class_exists(Constants::class)) {
		/** Stub of the share-constants holder referenced by OCP\Share. */
		class Constants {
		}
	}
}

namespace {
	// OCP\Util::addScript()/addStyle() delegate to these global internal
	// classes, which the ocp package does not ship. Controllers under test
	// (e.g. PublicController) call Util::addScript/addStyle while building a
	// TemplateResponse; without these stubs those calls fatal with
	// "Class OC_Util not found". No-ops here — asset registration is a
	// side effect we don't assert on. Guarded so the real classes win inside
	// a running Nextcloud.
	if (!class_exists('OC_Util')) {
		/** Stub of the internal OC_Util asset registrar. */
		class OC_Util {
			public static function addScript($application, $file = null, $prepend = false): void {
			}

			public static function addStyle($application, $file = null, $prepend = false): void {
			}

			public static function addTranslations($application, $languageCode = null, $init = false): void {
			}
		}
	}

	if (!class_exists('OC')) {
		/**
		 * Stub of the internal OC bootstrap holder. OCP\Server::get() reads
		 * OC::$server; a few Util code paths reach through it. The container
		 * returns null for any service, which is enough for the no-op asset
		 * helpers above.
		 */
		class OC {
			public static $server;
		}
		// A permissive stub service that swallows any method call and returns
		// a benign value. OCP\Util::addScript() reaches
		// Server::get(IFactory::class)->findLanguage($app); returning 'en'
		// keeps the no-op asset path from fataling.
		$ocStubService = new class {
			public function __call($name, $args) {
				// Response::getHeaders() calls Server::get(IUserSession)->getUser();
				// it must be null so the X-User-Id branch is skipped. Everything
				// else (e.g. IFactory::findLanguage) wants a harmless string.
				if ($name === 'getUser') {
					return null;
				}
				// Response::cacheFor() calls
				// Server::get(ITimeFactory::class)->getTime() and passes the
				// result to DateTime::setTimestamp(int). Give it a real int.
				if ($name === 'getTime') {
					return time();
				}
				return 'en';
			}
		};
		OC::$server = new class($ocStubService) {
			private $service;

			public function __construct($service) {
				$this->service = $service;
			}

			public function get(string $service) {
				return $this->service;
			}

			public function query(string $service) {
				return $this->service;
			}
		};
	}
}
