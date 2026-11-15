<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Pdf;

use PHPUnit\Framework\TestCase;

/**
 * lib/Vendor is what ships; composer.lock is what was asked for. An update of dompdf that did not run
 * `composer vendor:pdf` would ship the old code while the lock file says otherwise.
 */
class VendorPdfTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	public function testWhatShipsIsWhatTheLockFileNames(): void {
		$lock = json_decode((string)file_get_contents(self::ROOT . '/composer.lock'), true);
		$locked = [];
		foreach ($lock['packages'] as $package) {
			$locked[$package['name']] = ltrim($package['version'], 'v');
		}
		$shipped = require self::ROOT . '/lib/Vendor/composer/installed.php';
		foreach ($shipped['versions'] as $name => $package) {
			if ($name === $shipped['root']['name']) {
				continue;
			}
			$this->assertSame($locked[$name] ?? null, ltrim($package['pretty_version'], 'v'), "$name: run composer vendor:pdf and commit lib/Vendor");
		}
		$this->assertArrayHasKey('dompdf/dompdf', $shipped['versions']);
	}

	public function testNothingButWhatRunsShips(): void {
		$this->assertDirectoryDoesNotExist(self::ROOT . '/lib/Vendor/masterminds/html5/bin');
		$this->assertFileExists(self::ROOT . '/lib/Vendor/NOTICE.md');
		$this->assertStringNotContainsString('"FontLib\\\\', (string)file_get_contents(self::ROOT . '/lib/Vendor/dompdf/php-font-lib/src/FontLib/TrueType/File.php'));
	}
}
