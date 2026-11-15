<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormIntake;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Container\ContainerInterface;

/**
 * A form file written outside FormVox, by any route: FormIntake keeps the server's data in it. A file renamed into
 * a form is taken in as a new one, or over an existing form as a write over it.
 *
 * @template-implements IEventListener<BeforeNodeWrittenEvent|NodeWrittenEvent|BeforeNodeRenamedEvent|NodeRenamedEvent>
 */
class FormWrittenListener implements IEventListener {
	/**
	 * The container, because every file write in Nextcloud passes here and only forms need FormIntake.
	 */
	public function __construct(
		private ContainerInterface $container,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof BeforeNodeRenamedEvent || $event instanceof NodeRenamedEvent) {
			if (strtolower(pathinfo($event->getTarget()->getName(), PATHINFO_EXTENSION)) !== Application::FILE_EXTENSION) {
				return;
			}
			$intake = $this->container->get(FormIntake::class);
			if ($event instanceof BeforeNodeRenamedEvent) {
				$intake->beforeRename($event->getSource(), $event->getTarget());
			} else {
				$intake->renamed($event->getSource(), $event->getTarget());
			}
			return;
		}
		if (!($event instanceof BeforeNodeWrittenEvent || $event instanceof NodeWrittenEvent)) {
			return;
		}
		$node = $event->getNode();
		if (strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION)) !== Application::FILE_EXTENSION) {
			return;
		}
		$intake = $this->container->get(FormIntake::class);
		if ($event instanceof BeforeNodeWrittenEvent) {
			$intake->beforeWrite($node);
		} else {
			$intake->written($node);
		}
	}
}
