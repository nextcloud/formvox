<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Listener;

use OCA\FormVox\Listener\HiddenShareListener;
use OCA\FormVox\Security\HiddenNodes;
use OCP\Files\Folder;
use OCP\IL10N;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

class HiddenShareListenerTest extends TestCase {
	private function share(int $type, bool $hidden): BeforeShareCreatedEvent {
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn($type);
		$share->method('getNode')->willReturn($this->createMock(Folder::class));
		$hiddenNodes = $this->createMock(HiddenNodes::class);
		$hiddenNodes->method('isHidden')->willReturn($hidden);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$event = new BeforeShareCreatedEvent($share);
		(new HiddenShareListener($hiddenNodes, $l10n))->handle($event);
		return $event;
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function publicTypes(): array {
		return [
			'link' => [IShare::TYPE_LINK],
			'mail' => [IShare::TYPE_EMAIL],
			'another server' => [IShare::TYPE_REMOTE],
			'a group on another server' => [IShare::TYPE_REMOTE_GROUP],
			'ScienceMesh' => [IShare::TYPE_SCIENCEMESH],
			'a Talk room' => [IShare::TYPE_ROOM],
			'a Team' => [IShare::TYPE_CIRCLE],
		];
	}

	/**
	 * @dataProvider publicTypes
	 */
	public function testAFormsFoldersAreNotSharedPublicly(int $type): void {
		$event = $this->share($type, true);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('Files and folders that belong to a form cannot be shared by link, email, Talk or Teams.', $event->getError());
	}

	/**
	 * @dataProvider publicTypes
	 */
	public function testOtherItemsAreSharedAsBefore(int $type): void {
		$event = $this->share($type, false);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertNull($event->getError());
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function signedInTypes(): array {
		return [
			'a colleague' => [IShare::TYPE_USER],
			'a group' => [IShare::TYPE_GROUP],
			'a Deck board' => [IShare::TYPE_DECK],
		];
	}

	/**
	 * @dataProvider signedInTypes
	 */
	public function testSigningInPeopleStillWorks(int $type): void {
		$this->assertFalse($this->share($type, true)->isPropagationStopped());
	}
}
