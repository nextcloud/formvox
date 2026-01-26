<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\FormVox\DAV\HideFormFilesPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that registers the HideFormFilesPlugin with the Sabre DAV server.
 *
 * @template-implements IEventListener<SabrePluginAuthInitEvent>
 */
class RegisterDavPluginListener implements IEventListener
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof SabrePluginAuthInitEvent)) {
            return;
        }

        $server = $event->getServer();
        $server->addPlugin(new HideFormFilesPlugin($this->logger));
    }
}
