<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\ShareTokenService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for ShareTokenService — the #135 share-token rules.
 *
 * Pins: the mint uses CHAR_HUMAN_READABLE at length 32; a generic update never
 * rotates an existing link; fail-closed on unreadable form; explicit revoke;
 * mint-on-first-request; and the rotate "no link to replace" guard.
 */
class ShareTokenServiceTest extends TestCase
{
    private ISecureRandom $secureRandom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->secureRandom->method('generate')->willReturn('MINTED_TOKEN_VALUE');
    }

    private function service(): ShareTokenService
    {
        return new ShareTokenService($this->secureRandom);
    }

    public function testGenerateUsesHumanReadableAlphabetAt32Chars(): void
    {
        $sr = $this->createMock(ISecureRandom::class);
        $sr->expects($this->once())
            ->method('generate')
            ->with(32, ISecureRandom::CHAR_HUMAN_READABLE)
            ->willReturn('abc');
        $this->assertSame('abc', (new ShareTokenService($sr))->generateToken());
    }

    // ---- resolveTokenForUpdate: the four cases (#135) -----------------------

    public function testUpdateFailsClosedWhenFormUnreadable(): void
    {
        // readOk=false => null => caller skips the settings write entirely.
        $this->assertNull(
            $this->service()->resolveTokenForUpdate(['public_token' => 'whatever'], false, null)
        );
    }

    public function testUpdateKeepsExistingTokenWhenLinkExists(): void
    {
        // A stale client value must NOT overwrite the live token — the #135 bug.
        $out = $this->service()->resolveTokenForUpdate(
            ['public_token' => 'STALE_CLIENT_VALUE', 'other' => 1],
            true,
            'LIVE_TOKEN'
        );
        $this->assertSame('LIVE_TOKEN', $out['public_token']);
        $this->assertSame(1, $out['other']);
    }

    public function testUpdateKeepsExistingTokenEvenWhenClientOmitsIt(): void
    {
        // update() replaces settings wholesale, so an omitted key would drop the
        // link — the service re-injects the live token.
        $out = $this->service()->resolveTokenForUpdate(['other' => 1], true, 'LIVE_TOKEN');
        $this->assertSame('LIVE_TOKEN', $out['public_token']);
    }

    public function testUpdateRevokesWhenClientSendsNull(): void
    {
        $out = $this->service()->resolveTokenForUpdate(['public_token' => null], true, 'LIVE_TOKEN');
        $this->assertNull($out['public_token']);
    }

    public function testUpdateRevokesWhenClientSendsEmptyString(): void
    {
        $out = $this->service()->resolveTokenForUpdate(['public_token' => ''], true, 'LIVE_TOKEN');
        $this->assertNull($out['public_token']);
    }

    public function testUpdateMintsWhenNoLinkAndClientRequests(): void
    {
        // No existing link, client sent the key (intent marker) => mint.
        $out = $this->service()->resolveTokenForUpdate(['public_token' => 'new'], true, null);
        $this->assertSame('MINTED_TOKEN_VALUE', $out['public_token']);
    }

    public function testUpdateLeavesTokenAbsentWhenNoLinkAndNoRequest(): void
    {
        $out = $this->service()->resolveTokenForUpdate(['other' => 1], true, null);
        $this->assertArrayNotHasKey('public_token', $out);
        $this->assertSame(1, $out['other']);
    }

    // ---- rotate -------------------------------------------------------------

    public function testRotateMintsFreshTokenWhenLinkExists(): void
    {
        $out = $this->service()->rotate(['public_token' => 'OLD', 'share_password_hash' => 'h']);
        $this->assertSame('MINTED_TOKEN_VALUE', $out['public_token']);
        // Other settings untouched.
        $this->assertSame('h', $out['share_password_hash']);
    }

    public function testRotateThrowsWhenNoLinkToReplace(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/no share link to replace/');
        $this->service()->rotate(['other' => 1]);
    }

    public function testRotateThrowsOnEmptyToken(): void
    {
        $this->expectException(\DomainException::class);
        $this->service()->rotate(['public_token' => '']);
    }
}
