<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\FormVox\DAV\HideFormFilesPlugin;
use OCA\FormVox\DAV\StripFormDataPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that registers DAV plugins with the Sabre DAV server.
 * - HideFormFilesPlugin: Hides .fvform files from sync clients
 * - StripFormDataPlugin: Strips responses and sensitive data on download
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
        $server->addPlugin(new StripFormDataPlugin());
    }
}
