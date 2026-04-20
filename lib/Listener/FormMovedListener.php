<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Listener that moves associated folders when a .fvform file is moved.
 *
 * @template-implements IEventListener<NodeRenamedEvent>
 */
class FormMovedListener implements IEventListener
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof NodeRenamedEvent)) {
            return;
        }

        $source = $event->getSource();
        $target = $event->getTarget();

        // Check if this is a .fvform file
        $extension = pathinfo($target->getName(), PATHINFO_EXTENSION);
        if ($extension !== 'fvform') {
            return;
        }

        // Only act if the parent folder changed (actual move, not just rename)
        $sourceParent = $source->getParent();
        $targetParent = $target->getParent();

        if ($sourceParent->getId() === $targetParent->getId()) {
            return; // Just a rename within the same folder, nothing to move
        }

        $fileId = $target->getId();
        $this->moveAssociatedFolder($sourceParent, $targetParent, ".formvox-uploads-{$fileId}", $fileId);
        $this->moveAssociatedFolder($sourceParent, $targetParent, ".formvox-templates-{$fileId}", $fileId);
        $this->moveAssociatedFolder($sourceParent, $targetParent, ".formvox-branding-{$fileId}", $fileId);
    }

    private function moveAssociatedFolder(Folder $sourceParent, Folder $targetParent, string $folderName, int $fileId): void
    {
        try {
            $folder = $sourceParent->get($folderName);
            if ($folder instanceof Folder) {
                $folder->move($targetParent->getPath() . '/' . $folderName);
                $this->logger->info("FormVox: Moved {$folderName} for form ID {$fileId}");
            }
        } catch (NotFoundException $e) {
            // No folder to move — fine
        } catch (\Exception $e) {
            $this->logger->error("FormVox: Failed to move {$folderName}: " . $e->getMessage());
        }
    }
}
