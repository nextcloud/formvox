<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\FormSecrets;
use OCA\FormVox\Tests\Unit\Support\ReversibleCrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FormSecretsTest extends TestCase {
	public function testSealedValuesOpenToTheOriginal(): void {
		$secrets = ReversibleCrypto::secrets();

		$sealed = $secrets->seal('whsec_123');

		$this->assertStringStartsWith('enc:v1:', $sealed);
		$this->assertStringNotContainsString('whsec_123', $sealed);
		$this->assertSame('whsec_123', $secrets->open($sealed));
	}

	public function testSealingTwiceDoesNotWrapTwice(): void {
		$secrets = ReversibleCrypto::secrets();
		$sealed = $secrets->seal('x');

		$this->assertSame($sealed, $secrets->seal($sealed));
	}

	public function testEmptyStaysEmpty(): void {
		$secrets = ReversibleCrypto::secrets();

		$this->assertSame('', $secrets->seal(''));
		$this->assertSame('', $secrets->open(''));
	}

	public function testValuesFromBeforeEncryptionAreReadAsTheyAre(): void {
		$this->assertSame('$2y$10$legacyhash', ReversibleCrypto::secrets()->open('$2y$10$legacyhash'));
	}

	public function testAValueThisServerCannotDecryptOpensToNothingAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$secrets = new FormSecrets(new ReversibleCrypto(), $logger);

		$this->assertSame('', $secrets->open('enc:v1:sealed-elsewhere'));
	}

	public function testSealSettingsSealsEverySecretAndNothingElse(): void {
		$sealed = ReversibleCrypto::secrets()->sealSettings([
			'share_password_hash' => '$2y$10$pw',
			'api_keys' => [['id' => 'k1', 'name' => 'CRM', 'hash' => '$2y$10$key']],
			'webhooks' => [['id' => 'w1', 'url' => 'https://example.com', 'secret' => 'whsec']],
			'public_token' => 'TOK',
		]);

		$this->assertStringStartsWith('enc:v1:', $sealed['share_password_hash']);
		$this->assertStringStartsWith('enc:v1:', $sealed['api_keys'][0]['hash']);
		$this->assertStringStartsWith('enc:v1:', $sealed['webhooks'][0]['secret']);
		$this->assertSame('CRM', $sealed['api_keys'][0]['name']);
		$this->assertSame('https://example.com', $sealed['webhooks'][0]['url']);
		$this->assertSame('TOK', $sealed['public_token']);
	}

	public function testSealSettingsToleratesMissingAndMalformedEntries(): void {
		$settings = ['share_password_hash' => null, 'api_keys' => 'nonsense', 'webhooks' => [['id' => 'w1']]];

		$this->assertSame($settings, ReversibleCrypto::secrets()->sealSettings($settings));
	}
}
