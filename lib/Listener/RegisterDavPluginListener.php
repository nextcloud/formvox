<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\FormVox\DAV\FormUploadPlugin;
use OCA\FormVox\DAV\HideFormFilesPlugin;
use OCA\FormVox\DAV\PublicDavScope;
use OCA\FormVox\DAV\PublicFormDataPlugin;
use OCA\FormVox\DAV\StripFormDataPlugin;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Security\HiddenNodes;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\SabrePluginEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hides forms from sync clients on remote.php/dav. On public links, a form's own folders do not exist. On every WebDAV
 * entry point, remote.php/dav and legacy webdav (SabrePluginAddEvent) and public links (BeforeSabrePubliclyLoadedEvent):
 * strips forms that are fetched, and keeps a form's data when one is put.
 *
 * @template-implements IEventListener<SabrePluginAuthInitEvent|SabrePluginAddEvent|BeforeSabrePubliclyLoadedEvent>
 */
class RegisterDavPluginListener implements IEventListener {
	public function __construct(
		private LoggerInterface $logger,
		private FormSanitizer $sanitizer,
		private ContainerInterface $container,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof SabrePluginAuthInitEvent) {
			$event->getServer()->addPlugin(new HideFormFilesPlugin($this->logger));
			return;
		}
		// Only public links dispatch this one; it is a SabrePluginEvent too, so the strip and upload plugins follow.
		if ($event instanceof BeforeSabrePubliclyLoadedEvent) {
			$server = $event->getServer();
			if ($server !== null && $server->getPlugin(PublicFormDataPlugin::NAME) === null) {
				$server->addPlugin(new PublicFormDataPlugin(
					$this->container->get(HiddenNodes::class),
					$this->container->get(PublicDavScope::class),
				));
			}
		}
		if ($event instanceof SabrePluginAddEvent || $event instanceof SabrePluginEvent) {
			$server = $event->getServer();
			if ($server === null || $server->getPlugin(StripFormDataPlugin::NAME) !== null) {
				return;
			}
			$server->addPlugin(new StripFormDataPlugin($this->sanitizer));
			$server->addPlugin(new FormUploadPlugin($this->container, $this->logger));
		}
	}
}
