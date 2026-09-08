<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Security\ISecureRandom;

/**
 * The rules governing a form's public share token (#135).
 *
 * The share token is server-owned: once a link exists its URL must stay valid
 * until someone deliberately revokes it. A generic form save must never rotate
 * it, however stale the client's copy of `settings` is. This mirrors Nextcloud
 * core, which mints a token only when there is none and never touches it on
 * update. Rotating is a separate, deliberate act.
 *
 * This service holds those rules so they live in one tested place instead of
 * inline in a controller. The controller keeps the HTTP concerns (permission
 * checks, responses); the token decisions live here.
 */
class ShareTokenService
{
    private ISecureRandom $secureRandom;

    public function __construct(ISecureRandom $secureRandom)
    {
        $this->secureRandom = $secureRandom;
    }

    /**
     * Mint a share token.
     *
     * CHAR_HUMAN_READABLE matches what Nextcloud core uses for share tokens: it
     * drops the characters people misread when a link is dictated or copied off
     * a screen. 32 chars of that alphabet still leaves far more entropy than a
     * guessing attack can cover.
     */
    public function generateToken(): string
    {
        return $this->secureRandom->generate(32, ISecureRandom::CHAR_HUMAN_READABLE);
    }

    /**
     * Decide what the `settings.public_token` should be on a form update, and
     * return the settings array to persist.
     *
     * FormService::update() replaces `settings` wholesale, so a save that merely
     * omits public_token would drop the link just as effectively as one sending
     * a stale value — hence this runs whenever settings are written, not only
     * when the client includes the key. The four cases:
     *
     *  - $readOk === false → fail closed: we couldn't read the current form, so
     *    we can't tell whether a link exists; the caller must skip the settings
     *    write entirely. Signalled by returning null.
     *  - explicit revoke (client sent public_token as null/'') → set null.
     *  - a link already exists → keep it, whatever the client sent or omitted.
     *  - no link yet and the client asked for one → mint a fresh token.
     *  - no link and no request → leave the key absent.
     *
     * @param array $settings the incoming settings the client wants to save
     * @param bool  $readOk   whether the current form could be read
     * @param string|null $existing the token currently stored (null if none)
     * @return array|null the settings to persist, or null to skip the settings
     *                    write entirely (fail-closed)
     */
    public function resolveTokenForUpdate(array $settings, bool $readOk, ?string $existing): ?array
    {
        if (!$readOk) {
            // Fail closed: caller must not write settings this time.
            return null;
        }

        $hasExisting = is_string($existing) && $existing !== '';

        $sentKey = array_key_exists('public_token', $settings);
        $incoming = $sentKey ? $settings['public_token'] : null;
        $isRevoke = $sentKey && ($incoming === null || $incoming === '');

        if ($isRevoke) {
            // Revoking the link: explicit and deliberate.
            $settings['public_token'] = null;
        } elseif ($hasExisting) {
            // A link exists — keep it, whatever the client sent or omitted.
            // This is the case that used to silently rotate the URL and break
            // every link already handed out.
            $settings['public_token'] = $existing;
        } elseif ($sentKey) {
            // No link yet and the client asked for one. Its value is only an
            // intent marker; the token is always minted here.
            $settings['public_token'] = $this->generateToken();
        }
        // No link and no request for one: leave it absent.

        return $settings;
    }

    /**
     * Produce the settings for a deliberate link rotation.
     *
     * Rotating invalidates the URL everyone already has, so it is only valid
     * when a link exists; password, expiry and access restrictions are left
     * untouched — only the token changes.
     *
     * @param array $currentSettings the form's current settings
     * @return array the settings to persist (with a fresh token)
     * @throws \DomainException if there is no existing link to replace
     */
    public function rotate(array $currentSettings): array
    {
        $existing = $currentSettings['public_token'] ?? null;
        if (!is_string($existing) || $existing === '') {
            throw new \DomainException('This form has no share link to replace');
        }

        $currentSettings['public_token'] = $this->generateToken();
        return $currentSettings;
    }
}
