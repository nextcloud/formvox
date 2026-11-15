<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Deletes the hidden folders kept beside a .fvform file when it is deleted, for a form not yet moved into app data.
 * The rows and app data of a form in the database go once its file is gone for good (FormRemovedListener), or with the
 * orphan sweep when the file only disappeared (Orphans).
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class FormDeletedListener implements IEventListener {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeDeletedEvent)) {
			return;
		}

		$node = $event->getNode();

		// Check if this is a .fvform file
		if ($node->getMimeType() !== 'application/x-fvform') {
			// Also check by extension as mimetype might not be registered
			$extension = pathinfo($node->getName(), PATHINFO_EXTENSION);
			if ($extension !== 'fvform') {
				return;
			}
		}

		$this->deleteAssociatedFolder($node, 'uploads');
		$this->deleteAssociatedFolder($node, 'templates');
		$this->deleteAssociatedFolder($node, 'branding');
		$this->deleteAssociatedFolder($node, 'pdfs');
	}

	/**
	 * Delete an associated folder (uploads, templates, branding or pdfs) kept beside a form file
	 */
	private function deleteAssociatedFolder($formFile, string $type): void {
		try {
			$parent = $formFile->getParent();
			$fileId = $formFile->getId();
			$folderName = ".formvox-{$type}-{$fileId}";

			try {
				$folder = $parent->get($folderName);
				if ($folder instanceof Folder) {
					$folder->delete();
					$this->logger->info("FormVox: Deleted {$type} folder for form ID {$fileId}");
				}
			} catch (NotFoundException $e) {
				// No folder to delete - this is fine
			}
		} catch (\Exception $e) {
			$this->logger->error("FormVox: Failed to delete {$type} folder: " . $e->getMessage());
		}
	}
}
