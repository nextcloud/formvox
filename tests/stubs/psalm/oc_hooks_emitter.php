<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Psalm stub for the internal OC\Hooks\Emitter that OCP\Files\IRootFolder
 * extends. The nextcloud/ocp package references it but does not ship it, so
 * without this stub every IRootFolder consumer reports a MissingDependency.
 * Mirrors nextcloud/forms' tests/stubs/oc_hooks_emitter.php.
 */

namespace OC\Hooks {
	class Emitter {
		public function emit(string $class, string $value, array $option) {
		}
		/** Closure $closure */
		public function listen(string $class, string $value, $closure) {
		}
	}
}
