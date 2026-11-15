<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Service\FormSchemaValidator;
use OCA\FormVox\Service\TemplateService;
use OCA\FormVox\Tests\Unit\Support\EnglishL10N;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TemplateServiceTest extends TestCase {
	private string $stored = '';

	private function service(): TemplateService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn () => $this->stored);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->stored = $value;
		});
		return new TemplateService($config, $this->createMock(LoggerInterface::class), new FormSanitizer(), new FormSchemaValidator(new EnglishL10N(), $this->createMock(LoggerInterface::class)));
	}

	private function formWithIntegrations(): array {
		return [
			'title' => 'Intake',
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
			'responses' => [['id' => 'r1']],
			'branding' => ['blocks' => [['type' => 'logo', 'settings' => ['imageId' => 'logo.png']]]],
			'settings' => [
				'allow_multiple' => true,
				'public_token' => 'TOK',
				'share_password_hash' => 'enc:v1:pw',
				'api_keys' => [['id' => 'k1', 'hash' => 'enc:v1:key']],
				'webhooks' => [['id' => 'w1', 'url' => 'https://example.com', 'secret' => 'enc:v1:wh']],
				'notify_recipients' => [['type' => 'user', 'id' => 'carol']],
			],
		];
	}

	public function testASavedTemplateCarriesNoLinkIntegrationsBrandingOrResponses(): void {
		$service = $this->service();
		$entry = $service->addTemplate('Intake', '', $this->formWithIntegrations());

		$form = $service->getTemplate($entry['id']);

		$this->assertSame(['allow_multiple' => true], $form['settings']);
		$this->assertArrayNotHasKey('responses', $form);
		$this->assertArrayNotHasKey('branding', $form, 'its images live beside the original form');
		$this->assertStringNotContainsString('enc:v1:', $this->stored);
	}

	/** Templates from before this kept secrets, people and links in appconfig; the repair step rewrites them once. */
	public function testStoredTemplatesAreCleanedOnceAndCleanOnesAreLeftAlone(): void {
		$service = $this->service();
		$clean = $service->addTemplate('Clean', '', ['title' => 'Clean', 'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']]]);
		$list = json_decode($this->stored, true);
		$list[] = ['id' => 't-old', 'title' => 'Old', 'form' => $this->formWithIntegrations()];
		$this->stored = json_encode($list);

		$this->assertSame(1, $service->cleanStored());
		$this->assertStringNotContainsString('enc:v1:', $this->stored);
		$this->assertStringNotContainsString('carol', $this->stored);
		$this->assertStringNotContainsString('TOK', $this->stored);
		$this->assertSame(['allow_multiple' => true], $service->getTemplate('t-old')['settings']);
		$this->assertSame('Clean', $service->getTemplate($clean['id'])['title']);

		$this->assertSame(0, $service->cleanStored(), 'nothing left to clean the second time');
	}

	public function testATemplateStoredBeforeThisIsCleanedWhenUsed(): void {
		$this->stored = json_encode([['id' => 't1', 'title' => 'Old', 'form' => $this->formWithIntegrations()]]);

		$form = $this->service()->getTemplate('t1');

		$this->assertSame(['allow_multiple' => true], $form['settings']);
	}
}
