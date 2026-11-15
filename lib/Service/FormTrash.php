<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\Files_Trashbin\Trash\ITrashItem;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Store\FormStore;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * A form put back from the trash takes along the uploads, branding, templates and PDFs kept beside it, which went into
 * the trash with it (FormDeletedListener); before, the form came back without the respondents' files (qual#98, PO 5).
 * Deleted from the trash for good, it takes them along as well. Files in app data never went to the trash, so there is
 * nothing to put back or to clear for a form that was moved into it.
 */
class FormTrash {
	/**
	 * Where Nextcloud keeps deleted files, as the paths in a storage begin: a user's trash, the trash of the team
	 * folders made before Groupfolders 20, and a team folder's own. Only a team folder's storage has the last.
	 */
	public const PLACES = ['files_trashbin/', '__groupfolders/trash/', 'trash/'];

	private const FOLDERS = ['uploads', 'branding', 'templates', 'pdfs'];

	public function __construct(
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private ResponseOutbox $outbox,
		private LoggerInterface $logger,
		private FormStore $forms,
	) {
	}

	/**
	 * Nextcloud's own trash and the team folders' trash both send this when they put something back.
	 *
	 * @param array{filePath?: string} $params
	 */
	public static function onRestore(array $params): void {
		Server::get(self::class)->restored((string)($params['filePath'] ?? ''));
	}

	public function restored(string $path): void {
		$user = $this->userSession->getUser();
		if ($user === null || !interface_exists(ITrashManager::class)
			|| strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== Application::FILE_EXTENSION) {
			return;
		}
		try {
			$form = $this->rootFolder->getUserFolder($user->getUID())->get($path);
			if (!($form instanceof File)) {
				return;
			}
			// What it still had to send went into the trash with it, and its job did not wait (review of the 3a fixes).
			$this->outbox->schedule($form->getId());
			// In the trash its row was told where it lay.
			$this->forms->follow($form->getId(), $form->getId());
			$trash = Server::get(ITrashManager::class);
			$items = $trash->listTrashRoot($user);
			foreach (self::FOLDERS as $type) {
				$item = $this->latest($items, ".formvox-$type-" . $form->getId());
				if ($item !== null) {
					$trash->restoreItem($item);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: could not put back the folders of a form restored from the trash (' . $path . '): ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	/**
	 * Forms deleted from the trash for good, one by one: the folders that went into the trash with each stay there as
	 * items of their own, with the respondents' files in them, unless they go too. Only for a file that is really gone.
	 *
	 * @param list<int> $fileIds the ids those forms had
	 */
	public function removed(array $fileIds): void {
		$user = $this->userSession->getUser();
		if ($fileIds === [] || $user === null || !interface_exists(ITrashManager::class)) {
			return;
		}
		try {
			$gone = array_fill_keys($this->forms->gone($fileIds), true);
			if ($gone === []) {
				return;
			}
			$trash = Server::get(ITrashManager::class);
			foreach ($trash->listTrashRoot($user) as $item) {
				if (preg_match('/^\.formvox-(?:' . implode('|', self::FOLDERS) . ')-(\d+)$/', basename($item->getOriginalLocation()), $found) === 1 && isset($gone[(int)$found[1]])) {
					$trash->removeItem($item);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: could not clear the folders of a form deleted from the trash: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	/**
	 * @param list<ITrashItem> $items
	 */
	private function latest(array $items, string $name): ?ITrashItem {
		$found = null;
		foreach ($items as $item) {
			if (basename($item->getOriginalLocation()) === $name && ($found === null || $item->getDeletedTime() > $found->getDeletedTime())) {
				$found = $item;
			}
		}
		return $found;
	}
}
