<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\ExternalImagesPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class ExternalImagesPolicyTest extends TestCase {
	private function imgSrc(string $stored): string {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->with('formvox', 'allow_external_images', 'false')->willReturn($stored);
		$response = new TemplateResponse('formvox', 'editor');
		(new ExternalImagesPolicy($config))->applyTo($response);
		preg_match('/img-src [^;]*/', $response->getContentSecurityPolicy()->buildPolicy(), $m);
		return $m[0];
	}

	public function testLeavesPolicyAloneByDefault(): void {
		$this->assertStringNotContainsString(' https:', $this->imgSrc('false'));
	}

	public function testAllowsHttpsImagesWhenEnabled(): void {
		$this->assertStringContainsString(' https:', $this->imgSrc('true'));
	}
}
