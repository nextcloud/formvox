<?php

declare(strict_types=1);

namespace OCA\FormVox\Security;

use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Encrypts the secrets kept in a .fvform with the server's own key, so whoever can
 * read the file (WebDAV, sync, versions, a copy) cannot use them.
 *
 * Values written before encryption existed are read as they are and sealed on the next save.
 */
class FormSecrets {
	private const PREFIX = 'enc:v1:';

	public function __construct(
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	public function seal(string $value): string {
		if ($value === '' || str_starts_with($value, self::PREFIX)) {
			return $value;
		}
		return self::PREFIX . $this->crypto->encrypt($value);
	}

	/**
	 * '' when the value cannot be decrypted (e.g. the file came from another server).
	 */
	public function open(string $value): string {
		if (!str_starts_with($value, self::PREFIX)) {
			return $value;
		}
		try {
			return $this->crypto->decrypt(substr($value, strlen(self::PREFIX)));
		} catch (\Exception $e) {
			$this->logger->warning('FormVox: a form secret could not be decrypted', ['exception' => $e]);
			return '';
		}
	}

	/**
	 * Seals the link-password hash, API-key hashes and webhook secrets in $settings.
	 */
	public function sealSettings(array $settings): array {
		if (is_string($settings['share_password_hash'] ?? null)) {
			$settings['share_password_hash'] = $this->seal($settings['share_password_hash']);
		}
		foreach (['api_keys' => 'hash', 'webhooks' => 'secret'] as $list => $field) {
			if (!is_array($settings[$list] ?? null)) {
				continue;
			}
			foreach ($settings[$list] as $i => $item) {
				if (is_string($item[$field] ?? null)) {
					$settings[$list][$i][$field] = $this->seal($item[$field]);
				}
			}
		}
		return $settings;
	}
}
