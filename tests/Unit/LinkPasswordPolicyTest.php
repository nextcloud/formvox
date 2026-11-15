<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Security\LinkPasswordPolicy;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\Security\Events\GenerateSecurePasswordEvent;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Security\ISecureRandom;
use OCP\Security\PasswordContext;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;

class LinkPasswordPolicyTest extends TestCase {
	private IShareManager $shareManager;
	private IEventDispatcher $dispatcher;
	private ISecureRandom $random;

	protected function setUp(): void {
		parent::setUp();
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->random = $this->createMock(ISecureRandom::class);
	}

	private function policy(): LinkPasswordPolicy {
		return new LinkPasswordPolicy($this->shareManager, $this->dispatcher, $this->random);
	}

	public function testEnforcementIsNextcloudsLinkSetting(): void {
		$this->shareManager->method('shareApiLinkEnforcePassword')->willReturn(true);

		$this->assertTrue($this->policy()->isEnforced());
	}

	public function testAPasswordIsCheckedAsASharePassword(): void {
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->with($this->callback(
			fn ($event) => $event instanceof ValidatePasswordPolicyEvent
				&& $event->getPassword() === 'correct horse'
				&& $event->getContext() === PasswordContext::SHARING
		));

		$this->assertNull($this->policy()->rejectionFor('correct horse'));
	}

	public function testARefusalCarriesNextcloudsReason(): void {
		$this->dispatcher->method('dispatchTyped')->willThrowException(new HintException('too short', 'Password needs to be at least 10 characters long.'));

		$this->assertSame('Password needs to be at least 10 characters long.', $this->policy()->rejectionFor('short'));
	}

	public function testAGeneratedPasswordComesFromThePasswordPolicyApp(): void {
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(function ($event): void {
			$this->assertInstanceOf(GenerateSecurePasswordEvent::class, $event);
			$this->assertSame(PasswordContext::SHARING, $event->getContext());
			$event->setPassword('From-Policy-App-1');
		});
		$this->random->expects($this->never())->method('generate');

		$this->assertSame('From-Policy-App-1', $this->policy()->generate());
	}

	public function testWithoutThePasswordPolicyAppARandomPasswordIsUsed(): void {
		$this->random->method('generate')->with(20, ISecureRandom::CHAR_HUMAN_READABLE)->willReturn('random-20-characters');

		$this->assertSame('random-20-characters', $this->policy()->generate());
	}
}
