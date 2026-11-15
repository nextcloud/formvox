<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\SecretStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Server;

/**
 * Against a real database: a form's secrets in and out in the shape of its settings, and a form found by its link.
 *
 * @group DB
 */
class SecretStoreIntegrationTest extends IntegrationTestCase {
	private SecretStore $secrets;
	private FormStore $forms;
	private int $formId = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->secrets = Server::get(SecretStore::class);
		$this->forms = Server::get(FormStore::class);
		$this->formId = $this->forms->create(bin2hex(random_bytes(18)), 910001, 7)->id;
	}

	protected function tearDown(): void {
		$this->forms->delete($this->formId);
		parent::tearDown();
	}

	public function testSecretsComeBackAsTheyWereSaved(): void {
		$settings = [
			'public_token' => 'tok-' . bin2hex(random_bytes(8)),
			'share_password_hash' => 'enc:v1:sealed-hash',
			'api_keys' => [['id' => 'k1', 'hash' => 'enc:v1:a'], ['id' => 'k2', 'hash' => 'enc:v1:b']],
			'webhooks' => [['id' => 'w1', 'url' => 'https://example.com/?t=x', 'secret' => 'enc:v1:s']],
		];

		$this->secrets->save($this->formId, $settings);

		$this->assertSame($settings, $this->secrets->load($this->formId));
	}

	public function testWhatIsEmptyOrLeftOutIsNoLongerKept(): void {
		$this->secrets->save($this->formId, ['public_token' => 'tok-a', 'share_password_hash' => 'enc:v1:h', 'webhooks' => [['id' => 'w1']]]);

		$this->secrets->save($this->formId, ['public_token' => null, 'share_password_hash' => '', 'webhooks' => []]);

		$this->assertSame([], $this->secrets->load($this->formId));
	}
}
