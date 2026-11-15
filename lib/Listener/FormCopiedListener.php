<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\Service\FormIntake;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;

/**
 * A copied file or folder: FormIntake cleans every form in it.
 *
 * @template-implements IEventListener<NodeCopiedEvent>
 */
class FormCopiedListener implements IEventListener {
	public function __construct(
		private FormIntake $intake,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof NodeCopiedEvent) {
			$this->intake->copied($event->getTarget());
		}
	}
}
