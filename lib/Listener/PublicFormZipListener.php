<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\DAV\PublicDavScope;
use OCA\FormVox\Security\HiddenPath;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;

/**
 * A zip cannot leave out single files, so a zip over a public link that holds a form, or one of a form's own folders,
 * is refused rather than handing out its responses.
 *
 * @template-implements IEventListener<BeforeZipCreatedEvent>
 */
class PublicFormZipListener implements IEventListener {
	public function __construct(
		private PublicDavScope $scope,
		private IL10N $l10n,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeZipCreatedEvent) || !$this->scope->isPublic()) {
			return;
		}
		$folder = $event->getFolder();
		if ($folder === null) {
			return;
		}

		$nodes = [];
		$names = $event->getFiles();
		if ($names === []) {
			$nodes[] = $folder;
		}
		foreach ($names as $name) {
			if (HiddenPath::isHidden($name)) {
				$this->refuse($event);
				return;
			}
			try {
				$nodes[] = $folder->get($name);
			} catch (NotFoundException) {
			}
		}

		foreach ($nodes as $node) {
			if ($this->holdsFormData($node)) {
				$this->refuse($event);
				return;
			}
		}
	}

	private function refuse(BeforeZipCreatedEvent $event): void {
		$event->setErrorMessage($this->l10n->t('Folders with forms cannot be downloaded through a public link.'));
		$event->setSuccessful(false);
	}

	private function holdsFormData(Node $node): bool {
		if (HiddenPath::isHidden($node->getName())) {
			return true;
		}
		if ($node instanceof File) {
			return self::isForm($node);
		}
		if (!($node instanceof Folder)) {
			return false;
		}
		foreach (['.' . Application::FILE_EXTENSION, '.formvox-'] as $part) {
			foreach ($node->search($part) as $found) {
				if (self::isForm($found) || HiddenPath::isHidden($found->getName())) {
					return true;
				}
			}
		}
		return $node->searchByMime(Application::MIME_TYPE) !== [];
	}

	/** By its name or by its type, whatever it is called. */
	private static function isForm(Node $node): bool {
		return strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION)) === Application::FILE_EXTENSION
			|| str_starts_with($node->getMimetype(), Application::MIME_TYPE);
	}
}
