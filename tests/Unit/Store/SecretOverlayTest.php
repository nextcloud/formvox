<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Store;

use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\SecretOverlay;
use OCA\FormVox\Store\SecretStore;
use OCA\FormVox\Store\StoredForm;
use PHPUnit\Framework\TestCase;

/**
 * A form's changed secrets are kept in two steps around the file write: what is gone is taken away first, what is new
 * or changed counts only after. The link password is the one secret whose absence opens access, so it only ever changes
 * after the write.
 */
class SecretOverlayTest extends TestCase {
	/** @var list<array<string, mixed>> */
	private array $saved = [];

	private function overlay(): SecretOverlay {
		$secrets = $this->createMock(SecretStore::class);
		$secrets->method('save')->willReturnCallback(function (int $formId, array $settings): void {
			$this->saved[] = $settings;
		});
		return new SecretOverlay($this->createMock(FormStore::class), $secrets);
	}

	/** @return array{0: array, 1: ?callable, 2: ?callable} */
	private function split(array $before, array $after): array {
		return $this->overlay()->split(new StoredForm(1, 'u', 7, 3), ['title' => 'T', 'settings' => $after + ['anonymous' => true]], $before);
	}

	public function testARemovedKeyIsGoneBeforeTheWriteAndANewOneOnlyAfterIt(): void {
		$old = ['id' => 'k1', 'hash' => 'h1'];
		$new = ['id' => 'k2', 'hash' => 'h2'];
		$gone = ['id' => 'k0', 'hash' => 'h0'];

		[$form, $before, $after] = $this->split(['api_keys' => [$gone, $old]], ['api_keys' => [$old, $new]]);
		$before();
		$after();

		$this->assertSame(['title' => 'T', 'settings' => ['anonymous' => true]], $form, 'no secret in the file');
		$this->assertSame([['api_keys' => [$old]], ['api_keys' => [$old, $new]]], $this->saved);
	}

	public function testANewLinkStopsTheOldOneFirstAndWorksOnlyOnceWritten(): void {
		[, $before, $after] = $this->split(['public_token' => 'old'], ['public_token' => 'new']);
		$before();
		$after();

		$this->assertSame([[], ['public_token' => 'new']], $this->saved);
	}

	public function testOnlyAddingWaitsForTheWriteAndOnlyRemovingDoesNot(): void {
		[, $before, $after] = $this->split([], ['public_token' => 't']);
		$this->assertNull($before);
		$after();

		[, $before, $after] = $this->split(['public_token' => 't'], []);
		$this->assertNull($after);
		$before();

		$this->assertSame([['public_token' => 't'], []], $this->saved);
	}

	public function testAChangedPasswordKeepsTheOldOneUntilTheWriteIsDone(): void {
		[, $before, $after] = $this->split(['public_token' => 't', 'share_password_hash' => 'h1'], ['public_token' => 't', 'share_password_hash' => 'h2']);

		$this->assertNull($before, 'nothing is taken away first');
		$after();
		$this->assertSame([['public_token' => 't', 'share_password_hash' => 'h2']], $this->saved);
	}

	public function testARemovedPasswordStaysUntilTheWriteIsDone(): void {
		[, $before, $after] = $this->split(['public_token' => 't', 'share_password_hash' => 'h'], ['public_token' => 't']);

		$this->assertNull($before, 'nothing is taken away first');
		$after();
		$this->assertSame([['public_token' => 't']], $this->saved);
	}

	public function testRevokingTheLinkStopsItFirstWhileItsPasswordWaits(): void {
		[, $before, $after] = $this->split(['public_token' => 't', 'share_password_hash' => 'h'], []);
		$before();
		$after();

		$this->assertSame([['share_password_hash' => 'h'], []], $this->saved);
	}

	public function testAWebhookChangedInPlaceKeepsItsOldVersionUntilTheWriteIsDone(): void {
		$old = ['id' => 'w1', 'url' => 'https://a.example', 'secret' => 's1'];
		$new = ['id' => 'w1', 'url' => 'https://b.example', 'secret' => 's2'];
		$gone = ['id' => 'w2', 'url' => 'https://c.example', 'secret' => 's3'];

		[, $before, $after] = $this->split(['webhooks' => [$old, $gone]], ['webhooks' => [$new]]);
		$before();
		$after();

		$this->assertSame([['webhooks' => [$old]], ['webhooks' => [$new]]], $this->saved);
	}

	public function testUnchangedSecretsAreNotWrittenAgain(): void {
		[, $before, $after] = $this->split(['public_token' => 't'], ['public_token' => 't']);

		$this->assertSame([null, null], [$before, $after]);
	}
}
