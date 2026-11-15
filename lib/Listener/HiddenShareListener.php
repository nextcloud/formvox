<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\Security\HiddenNodes;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;

/**
 * A form's own folders, and all in them, are not shared beyond signed-in people: no link, mail, other server,
 * Talk room or Team. Sharing with a colleague or a group still works.
 *
 * @template-implements IEventListener<BeforeShareCreatedEvent>
 */
class HiddenShareListener implements IEventListener {
	private const PUBLIC_TYPES = [
		IShare::TYPE_LINK,
		IShare::TYPE_EMAIL,
		IShare::TYPE_REMOTE,
		IShare::TYPE_REMOTE_GROUP,
		IShare::TYPE_SCIENCEMESH,
		IShare::TYPE_ROOM,
		IShare::TYPE_CIRCLE,
	];

	public function __construct(
		private HiddenNodes $hiddenNodes,
		private IL10N $l10n,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeShareCreatedEvent)) {
			return;
		}
		$share = $event->getShare();
		if (!in_array($share->getShareType(), self::PUBLIC_TYPES, true)) {
			return;
		}
		try {
			$node = $share->getNode();
		} catch (NotFoundException) {
			return;
		}
		if ($this->hiddenNodes->isHidden($node)) {
			$event->setError($this->l10n->t('Files and folders that belong to a form cannot be shared by link, email, Talk or Teams.'));
			$event->stopPropagation();
		}
	}
}
