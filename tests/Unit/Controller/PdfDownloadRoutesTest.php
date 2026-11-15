<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\ExportController;
use OCA\FormVox\Controller\ResponseController;
use OCP\AppFramework\Http\Attribute\StrictCookiesRequired;
use PHPUnit\Framework\TestCase;

/** A PDF is heavy to make: the app's PDF downloads work only from Nextcloud's own pages, not from a link on another site. */
class PdfDownloadRoutesTest extends TestCase {
	/**
	 * @return array<string, array{class-string, string}>
	 */
	public static function routes(): array {
		return [
			'one response' => [ResponseController::class, 'getResponsePdf'],
			'all of them as a ZIP' => [ExportController::class, 'exportPdfs'],
		];
	}

	/**
	 * @dataProvider routes
	 * @param class-string $class
	 */
	public function testItNeedsNextcloudsStrictCookies(string $class, string $method): void {
		$this->assertNotEmpty((new \ReflectionMethod($class, $method))->getAttributes(StrictCookiesRequired::class));
	}
}
