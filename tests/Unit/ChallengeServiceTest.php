<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\ChallengeService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The password cookie proves the link password was given, for that password and that link only
 * (qual#110, secu#51, PO 6).
 */
class ChallengeServiceTest extends TestCase {
	private function service(): ChallengeService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		return new ChallengeService($config, $this->createMock(ICacheFactory::class), $this->createMock(ISecureRandom::class));
	}

	public function testTheCookieLetsInWithTheSamePasswordAndLink(): void {
		$settings = ['share_password_hash' => 'enc:v1:one', 'public_token' => 'TOKEN'];
		$service = $this->service();

		$this->assertTrue($service->verifyPasswordToken($service->issuePasswordToken(7, $settings), 7, $settings));
	}

	public function testANewPasswordOrANewLinkLetsNobodyInWithTheOldCookie(): void {
		$settings = ['share_password_hash' => 'enc:v1:one', 'public_token' => 'TOKEN'];
		$service = $this->service();
		$cookie = $service->issuePasswordToken(7, $settings);

		$this->assertFalse($service->verifyPasswordToken($cookie, 7, ['share_password_hash' => 'enc:v1:two'] + $settings));
		$this->assertFalse($service->verifyPasswordToken($cookie, 7, ['public_token' => 'NEW'] + $settings));
		$this->assertFalse($service->verifyPasswordToken($cookie, 8, $settings));
	}

	public function testAForgedOrExpiredCookieLetsNobodyIn(): void {
		$settings = ['share_password_hash' => 'enc:v1:one', 'public_token' => 'TOKEN'];
		$service = $this->service();

		$this->assertFalse($service->verifyPasswordToken((time() - 10) . '.' . str_repeat('a', 64), 7, $settings));
		$this->assertFalse($service->verifyPasswordToken('not a cookie', 7, $settings));
		$this->assertFalse($service->verifyPasswordToken(null, 7, $settings));
	}

	/** A pass holds for its purpose and its subject until it runs out; a retry pass is no upload pass (7a, 3a-5). */
	public function testAPassHoldsOnlyForWhatItWasMadeFor(): void {
		$service = $this->service();
		$pass = $service->issuePass('retry', '7:sub-1:ua', 120);

		$this->assertTrue($service->verifyPass($pass, 'retry', '7:sub-1:ua'));
		$this->assertFalse($service->verifyPass($pass, 'upload', '7:sub-1:ua'), 'another purpose');
		$this->assertFalse($service->verifyPass($pass, 'retry', '7:sub-2:ua'), 'another fill');
		$this->assertFalse($service->verifyPass($pass, 'retry', '8:sub-1:ua'), 'another form');
		$this->assertFalse($service->verifyPass(null, 'retry', '7:sub-1:ua'));
		$this->assertFalse($service->verifyPass('garbage', 'retry', '7:sub-1:ua'));
		$this->assertFalse($service->verifyPass($service->issuePass('retry', '7:sub-1:ua', -1), 'retry', '7:sub-1:ua'), 'ran out');
		[$expiry] = explode('.', $pass);
		$this->assertFalse($service->verifyPass(($expiry + 3600) . '.' . explode('.', $pass)[1], 'retry', '7:sub-1:ua'), 'a later expiry is not signed');
	}

	/**
	 * What a pass is for is only worked out for a pass that has not run out: no work on the answers for a missing, bad
	 * or old one (review of the 3a fixes).
	 */
	public function testThePassSubjectIsOnlyWorkedOutForALivePass(): void {
		$service = $this->service();
		$calls = 0;
		$subject = function () use (&$calls): string {
			$calls++;
			return '7:sub-1:ua';
		};

		foreach ([null, '', 'garbage', $service->issuePass('retry', '7:sub-1:ua', -1)] as $pass) {
			$this->assertFalse($service->verifyPass($pass, 'retry', $subject));
		}
		$this->assertSame(0, $calls);

		$this->assertTrue($service->verifyPass($service->issuePass('retry', '7:sub-1:ua', 120), 'retry', $subject));
		$this->assertSame(1, $calls);
	}
}
