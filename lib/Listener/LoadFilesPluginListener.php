<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FormVox\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesPluginListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}

		// Load the Files integration script
		Util::addScript(Application::APP_ID, 'formvox-files');

		// Load the filetype icons CSS
		Util::addStyle(Application::APP_ID, 'filetypes');
	}
}
