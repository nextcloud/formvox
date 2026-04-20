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
 * Listener that deletes the uploads folder when a .fvform file is deleted.
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class FormDeletedListener implements IEventListener
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Event $event): void
    {
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
    }

    /**
     * Delete an associated folder (uploads or templates) for a form file
     */
    private function deleteAssociatedFolder($formFile, string $type): void
    {
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
