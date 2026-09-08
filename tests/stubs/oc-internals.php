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
        interface Emitter
        {
        }
    }
}

namespace OC\User {
    if (!class_exists(NoUserException::class)) {
        /** Stub of the exception several Files interfaces declare via @throws. */
        class NoUserException extends \Exception
        {
        }
    }
}

namespace OC\Share {
    if (!class_exists(Constants::class)) {
        /** Stub of the share-constants holder referenced by OCP\Share. */
        class Constants
        {
        }
    }
}
