<?php

declare(strict_types=1);

namespace OCA\FormVox\Security;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\Security\Events\GenerateSecurePasswordEvent;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Security\ISecureRandom;
use OCP\Security\PasswordContext;
use OCP\Share\IManager as IShareManager;

/**
 * The response-link password follows Nextcloud's rules for share passwords (M39).
 */
class LinkPasswordPolicy {
	public function __construct(
		private IShareManager $shareManager,
		private IEventDispatcher $eventDispatcher,
		private ISecureRandom $secureRandom,
	) {
	}

	/** Whether the admin requires a password on public links, for the current user's groups. */
	public function isEnforced(): bool {
		return (bool)$this->shareManager->shareApiLinkEnforcePassword();
	}

	/**
	 * @return string|null why Nextcloud refuses the password, or null when it is accepted
	 */
	public function rejectionFor(string $password): ?string {
		try {
			$this->eventDispatcher->dispatchTyped(new ValidatePasswordPolicyEvent($password, PasswordContext::SHARING));
		} catch (HintException $e) {
			return $e->getHint();
		}
		return null;
	}

	/** A password from Nextcloud's password policy app, as its own share dialog gets one. */
	public function generate(): string {
		$event = new GenerateSecurePasswordEvent(PasswordContext::SHARING);
		$this->eventDispatcher->dispatchTyped($event);
		return $event->getPassword() ?? $this->secureRandom->generate(20, ISecureRandom::CHAR_HUMAN_READABLE);
	}
}
