<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\Files_Versions\Events\CreateVersionEvent;
use OCA\Files_Versions\Versions\INeedSyncVersionBackend;
use OCA\Files_Versions\Versions\IVersionManager;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\OwnWrites;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * A response written into a form leaves no version: each one used to keep a full copy of the form with every
 * answer (qual#12, qual#30). A change to the form itself is kept as a version, as for any file (PO 5).
 *
 * @template-implements IEventListener<CreateVersionEvent>
 */
class FormVersionListener implements IEventListener {
	public function __construct(
		private OwnWrites $ownWrites,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof CreateVersionEvent) || !$this->ownWrites->withoutVersion()) {
			return;
		}
		$event->disableVersions();
		$this->keepRecordOfCurrentVersion($event->getNode());
	}

	/**
	 * Without a version, Nextcloud moves its record of the current one along with the write, and logs an error when
	 * there is none: after a write it did not see, such as a cleaned copy or a change outside Nextcloud.
	 */
	private function keepRecordOfCurrentVersion(Node $node): void {
		try {
			$versions = Server::get(IVersionManager::class);
			// The event's node can carry the time from before an earlier write in this second; look it up again.
			$node = Server::get(IRootFolder::class)->get($node->getPath());
			if (!($node instanceof File) || !($versions instanceof INeedSyncVersionBackend) || !method_exists($versions, 'getRevision')) {
				return;
			}
			try {
				// With nothing to change this only looks the record up.
				$versions->updateVersionEntity($node, $versions->getRevision($node), []);
			} catch (DoesNotExistException) {
				$versions->createVersionEntity($node);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('FormVox: could not check the version record of a form: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}
}
