<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\SettingsController;
use OCA\FormVox\Pdf\PdfMailPolicy;
use OCA\FormVox\Service\AiFormGeneratorService;
use OCA\FormVox\Service\MicrosoftFormsAuthService;
use OCA\FormVox\Service\TemplateService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase {
	/** @var array<string, string> */
	private array $saved = [];

	private function controller(bool $shared): SettingsController {
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->saved[$key] = $value;
		});
		$policy = $this->createMock(PdfMailPolicy::class);
		$policy->method('sharedCache')->willReturn($shared);
		return new SettingsController(
			$this->createMock(IRequest::class),
			$config,
			$this->createMock(MicrosoftFormsAuthService::class),
			$this->createMock(AiFormGeneratorService::class),
			$this->createMock(TemplateService::class),
			$policy,
			new EnglishL10N(),
		);
	}

	public function testThePdfForAnonymousRespondentsIsSwitchedOnWithASharedCache(): void {
		$response = $this->controller(true)->savePdf(true);

		$this->assertSame(['anonymous' => true], $response->getData());
		$this->assertSame('1', $this->saved[PdfMailPolicy::ANONYMOUS_SETTING]);
	}

	public function testWithoutOneItStaysOff(): void {
		$response = $this->controller(false)->savePdf(true);

		$this->assertSame(['anonymous' => false], $response->getData());
		$this->assertSame('0', $this->saved[PdfMailPolicy::ANONYMOUS_SETTING]);
	}
}
