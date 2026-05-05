<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\IConfig;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;

/**
 * ALTCHA-compatible proof-of-work challenge issuer/verifier.
 *
 * The browser solves SHA-256(salt + N) for some N in [0, maxnumber] such that
 * the digest matches the expected `challenge`. Difficulty = expected work,
 * tuned by maxnumber. Server-side verification recomputes the digest, checks
 * the HMAC signature (so the client can't forge a challenge), and enforces
 * single-use via cache to prevent replay.
 *
 * Replaces per-IP rate limiting on public form submission — cost is per
 * browser, NAT-friendly. See issue #76.
 */
class ChallengeService
{
    /** Single-use challenge expiry, in seconds. */
    private const EXPIRY_SECONDS = 600;

    /** Difficulty rungs (max number to search). Higher = more work. */
    private const DIFFICULTY_LOW = 50_000;
    private const DIFFICULTY_MID = 250_000;
    private const DIFFICULTY_HIGH = 1_000_000;

    /** Submit-rate thresholds (per hour, per form) that bump difficulty. */
    private const RATE_THRESHOLD_MID = 1_500;
    private const RATE_THRESHOLD_HIGH = 10_000;

    public function __construct(
        private IConfig $config,
        private ICacheFactory $cacheFactory,
        private ISecureRandom $secureRandom,
    ) {
    }

    /**
     * Cache for replay-protection and rate tracking.
     *
     * Prefers a distributed backend (Redis/Memcached) for multi-server
     * setups; falls back to the local backend (APCu) which works fine for
     * the ~95% of Nextcloud installs that run on a single server. If
     * neither is available we return null and the caller treats that as
     * "challenge accepted, no replay tracking" — better than refusing all
     * submissions on a misconfigured instance.
     */
    private function cache(): ?\OCP\ICache
    {
        if ($this->cacheFactory->isAvailable()) {
            return $this->cacheFactory->createDistributed('formvox_altcha_');
        }
        if ($this->cacheFactory->isLocalCacheAvailable()) {
            return $this->cacheFactory->createLocal('formvox_altcha_');
        }
        return null;
    }

    /**
     * Issue a new challenge for a given form.
     *
     * Difficulty adapts to the per-form submit rate so a popular form does
     * not penalise other forms on the same instance.
     *
     * @return array{algorithm:string,challenge:string,salt:string,signature:string,maxnumber:int}
     */
    public function issue(int $fileId): array
    {
        $maxnumber = $this->currentDifficulty($fileId);
        $secretNumber = random_int(0, $maxnumber);
        $salt = bin2hex(random_bytes(12));
        $challenge = hash('sha256', $salt . $secretNumber);
        // Bind the signature to the fileId so a challenge issued for a
        // low-difficulty form can't be reused on a high-difficulty form
        // (which would defeat adaptive difficulty escalation).
        $signature = hash_hmac('sha256', $fileId . ':' . $challenge, $this->hmacKey());

        return [
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'salt' => $salt,
            'signature' => $signature,
            'maxnumber' => $maxnumber,
        ];
    }

    /**
     * Verify a solved challenge payload from the client.
     *
     * Expected payload shape (ALTCHA standard):
     *   { algorithm, challenge, salt, signature, number }
     */
    public function verify(?array $payload, int $fileId): bool
    {
        if (!\is_array($payload)) {
            return false;
        }
        foreach (['algorithm', 'challenge', 'salt', 'signature', 'number'] as $k) {
            if (!isset($payload[$k])) {
                return false;
            }
        }
        if ($payload['algorithm'] !== 'SHA-256') {
            return false;
        }

        // Signature is bound to fileId — see issue(). A challenge issued for
        // form A will not validate when submitted to form B.
        $expectedSig = hash_hmac('sha256', $fileId . ':' . (string)$payload['challenge'], $this->hmacKey());
        if (!hash_equals($expectedSig, (string)$payload['signature'])) {
            return false;
        }

        $recomputed = hash('sha256', $payload['salt'] . $payload['number']);
        if (!hash_equals($recomputed, (string)$payload['challenge'])) {
            return false;
        }

        // Single-use: reject if we've seen this challenge before.
        // If no cache backend is available we accept the challenge (HMAC +
        // SHA-256 still verified above) but lose replay protection — better
        // than blocking all submissions on a misconfigured instance.
        $cache = $this->cache();
        if ($cache !== null) {
            $key = "used_{$fileId}_" . substr($payload['challenge'], 0, 32);
            if ($cache->get($key)) {
                return false;
            }
            $cache->set($key, 1, self::EXPIRY_SECONDS);
        }

        // Track per-form submit rate so difficulty adapts on the next issue().
        $this->bumpSubmitRate($fileId);

        return true;
    }

    private function currentDifficulty(int $fileId): int
    {
        $rate = $this->currentSubmitRate($fileId);
        if ($rate >= self::RATE_THRESHOLD_HIGH) {
            return self::DIFFICULTY_HIGH;
        }
        if ($rate >= self::RATE_THRESHOLD_MID) {
            return self::DIFFICULTY_MID;
        }
        return self::DIFFICULTY_LOW;
    }

    private function currentSubmitRate(int $fileId): int
    {
        $cache = $this->cache();
        if ($cache === null) {
            return 0;
        }
        return (int)($cache->get($this->rateKey($fileId)) ?? 0);
    }

    private function bumpSubmitRate(int $fileId): void
    {
        $cache = $this->cache();
        if ($cache === null) {
            return;
        }
        $cache->set($this->rateKey($fileId), $this->currentSubmitRate($fileId) + 1, 3600);
    }

    private function rateKey(int $fileId): string
    {
        return "submit_rate_{$fileId}_" . $this->currentHourBucket();
    }

    private function currentHourBucket(): string
    {
        return (string)((int)floor(time() / 3600));
    }

    private function hmacKey(): string
    {
        $secret = $this->config->getSystemValueString('secret', '');
        if ($secret === '') {
            $secret = $this->config->getAppValue('formvox', 'altcha_hmac_key', '');
            if ($secret === '') {
                $secret = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
                $this->config->setAppValue('formvox', 'altcha_hmac_key', $secret);
            }
        }
        return 'formvox_altcha:' . $secret;
    }
}
