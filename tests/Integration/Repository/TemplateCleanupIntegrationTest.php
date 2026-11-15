<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Repository;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Migration\CleanStoredTemplates;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * The upgrade's repair step rewrites admin templates saved before 1.6 in Nextcloud's own appconfig (3+ D).
 *
 * @group DB
 */
class TemplateCleanupIntegrationTest extends IntegrationTestCase {
	public function testTheRepairStepLeavesNoSecretsOrPeopleInStoredTemplates(): void {
		$config = Server::get(IConfig::class);
		$before = $config->getAppValue(Application::APP_ID, 'templates', '');
		$config->setAppValue(Application::APP_ID, 'templates', json_encode([[
			'id' => 't-old',
			'title' => 'Old intake',
			'form' => [
				'title' => 'Old intake',
				'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
				'settings' => [
					'allow_multiple' => true,
					'public_token' => 'OLDTOKEN',
					'api_keys' => [['id' => 'k1', 'hash' => 'enc:v1:hash']],
					'webhooks' => [['id' => 'w1', 'secret' => 'enc:v1:secret']],
					'notify_recipients' => [['type' => 'user', 'id' => 'carol']],
				],
			],
		]]));
		try {
			Server::get(CleanStoredTemplates::class)->run($this->createMock(IOutput::class));

			$stored = $config->getAppValue(Application::APP_ID, 'templates', '');
			foreach (['enc:v1:', 'OLDTOKEN', 'carol'] as $leftover) {
				$this->assertStringNotContainsString($leftover, $stored);
			}
			$this->assertSame('Name', json_decode($stored, true)[0]['form']['questions'][0]['question']);
		} finally {
			$config->setAppValue(Application::APP_ID, 'templates', $before);
		}
	}
}
