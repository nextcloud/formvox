<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for FormVox INTEGRATION tests.
 *
 * Unlike tests/bootstrap.php (which loads OCP stubs and runs standalone), this
 * boots a REAL Nextcloud server: the app must be checked out at
 * <ncroot>/apps/formvox or <ncroot>/custom_apps/formvox (both are three levels
 * above this file), enabled (`occ app:enable formvox`), and the server must be a
 * source checkout of nextcloud/server (a release tarball lacks tests/autoload.php
 * and therefore \Test\TestCase). Run via: composer test:integration.
 *
 * If the app is deployed at a different depth, adjust the three `../` below.
 */

use OCP\App\IAppManager;
use OCP\Server;

if (!defined('PHPUNIT_RUN')) {
	// Must be defined BEFORE base.php — it changes how the server boots for tests.
	define('PHPUNIT_RUN', 1);
}

require_once __DIR__ . '/../../../lib/base.php';        // <ncroot>/lib/base.php
require_once __DIR__ . '/../../../tests/autoload.php';  // registers \Test\ namespace
require_once __DIR__ . '/../vendor/autoload.php';       // FormVox OCA\FormVox\Tests\ PSR-4

Server::get(IAppManager::class)->loadApp('formvox');
