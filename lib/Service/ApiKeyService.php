<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Security\ISecureRandom;

class ApiKeyService
{
    private ISecureRandom $secureRandom;

    public function __construct(ISecureRandom $secureRandom)
    {
        $this->secureRandom = $secureRandom;
    }

    /**
     * Generate a new API key
     * Returns both the plain key (to show once) and the hash (to store)
     */
    public function generateKey(): array
    {
        // Generate a secure random key: fvx_ + 32 random chars
        $randomPart = $this->secureRandom->generate(
            32,
            ISecureRandom::CHAR_ALPHANUMERIC
        );
        $plainKey = 'fvx_' . $randomPart;

        // Hash for storage
        $hash = password_hash($plainKey, PASSWORD_BCRYPT);

        // Generate a short ID for the key
        $keyId = 'ak_' . $this->secureRandom->generate(8, ISecureRandom::CHAR_ALPHANUMERIC);

        return [
            'id' => $keyId,
            'key' => $plainKey,      // Show this once to user
            'hash' => $hash,          // Store this in .fvform
        ];
    }

    /**
     * Verify an API key against a stored hash
     */
    public function verifyKey(string $providedKey, string $storedHash): bool
    {
        return password_verify($providedKey, $storedHash);
    }

    /**
     * Find and verify an API key in a form's api_keys array
     * Returns the matching key config if valid, null otherwise
     */
    public function findValidKey(string $providedKey, array $apiKeys): ?array
    {
        foreach ($apiKeys as $keyConfig) {
            if (!isset($keyConfig['hash'])) {
                continue;
            }

            if ($this->verifyKey($providedKey, $keyConfig['hash'])) {
                return $keyConfig;
            }
        }

        return null;
    }

    /**
     * Check if key has required permission
     */
    public function hasPermission(array $keyConfig, string $permission): bool
    {
        $permissions = $keyConfig['permissions'] ?? [];
        return in_array($permission, $permissions, true);
    }

    /**
     * Get masked version of a key for display (first 8 chars + last 4)
     */
    public function maskKey(string $key): string
    {
        if (strlen($key) <= 12) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 8) . '...' . substr($key, -4);
    }
}
