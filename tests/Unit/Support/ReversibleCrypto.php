<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Support;

use OCA\FormVox\Security\FormSecrets;
use OCP\Security\ICrypto;
use Psr\Log\NullLogger;

/**
 * Stand-in for Nextcloud's ICrypto: reversible, recognisable, and rejects foreign ciphertext like the real one.
 */
class ReversibleCrypto implements ICrypto {
	public function calculateHMAC(string $message, string $password = ''): string {
		return hash_hmac('sha256', $message, $password);
	}

	public function encrypt(string $plaintext, string $password = ''): string {
		return 'crypt(' . base64_encode($plaintext) . ')';
	}

	public function decrypt(string $authenticatedCiphertext, string $password = ''): string {
		if (!preg_match('/^crypt\((.*)\)$/', $authenticatedCiphertext, $m)) {
			throw new \Exception('HMAC does not match.');
		}
		return base64_decode($m[1]);
	}

	public static function secrets(): FormSecrets {
		return new FormSecrets(new self(), new NullLogger());
	}
}
