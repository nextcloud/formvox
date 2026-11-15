<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormTrash;
use OCA\FormVox\Store\Orphans;
use OCA\FormVox\Store\StoredForm;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A form whose file is deleted for good through Nextcloud loses its responses and files at the end of that request:
 * its trash emptied, deleted from a trash, or deleted where no trash takes it. A file that only disappeared (found gone
 * by a scan, its storage away) was deleted by no one, and keeps the grace the daily sweep gives it (Orphans).
 *
 * Nextcloud sends CacheEntryRemovedEvent for every file it forgets, previews and versions too, and also for a file moved
 * to another storage. So an event costs a look at its path; what is true of the forms is asked once, when the request
 * ends and a moved form has been followed.
 *
 * @template-implements IEventListener<CacheEntryRemovedEvent|BeforeNodeDeletedEvent>
 */
class FormRemovedListener implements IEventListener {
	/** A form, also as a trash names the item it holds: with the time it was deleted. */
	private const FORM = '/\.' . Application::FILE_EXTENSION . '(\.d\d+)?$/i';
	private const TEAM_FOLDER_STORAGE = '#^object::groupfolder:|/__groupfolders/\d+/?$#';

	/** @var list<array{0: int, 1: int, 2: string, 3: bool}> forgotten files named like a form: id, storage, path, whether in a trash */
	private array $forgotten = [];
	/** @var array<int, true> the folders and forms someone deletes in this request, by id */
	private array $deleting = [];
	/** @var list<array{0: int, 1: string}> where those were forgotten rather than moved to a trash: storage, path */
	private array $outright = [];
	private bool $waiting = false;

	public function __construct(
		private IAppManager $apps,
		private IRequest $request,
		private LoggerInterface $logger,
		// Asked for when there is something to do: this listener is built for every file Nextcloud forgets.
		private ContainerInterface $container,
	) {
	}

	public function handle(Event $event): void {
		// Never a reason to fail what the user is doing.
		try {
			if ($event instanceof CacheEntryRemovedEvent) {
				$this->forgotten($event);
			} elseif ($event instanceof BeforeNodeDeletedEvent) {
				$node = $event->getNode();
				if ($node instanceof Folder || preg_match(self::FORM, $node->getName()) === 1) {
					$this->deleting[(int)$node->getId()] = true;
					$this->atTheEnd();
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('FormVox: could not note a deleted file: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	private function forgotten(CacheEntryRemovedEvent $event): void {
		$path = $event->getPath();
		$fileId = $event->getFileId();
		if (isset($this->deleting[$fileId])) {
			$this->outright[] = [$event->getStorageId(), $path];
		}
		if (preg_match(self::FORM, $path) !== 1) {
			return;
		}
		$this->forgotten[] = [$fileId, $event->getStorageId(), $path, $this->inTrash($event, $path)];
		$this->atTheEnd();
	}

	/** Only Nextcloud's trash removes from a trash; any storage may hold a folder named as a team folder's is. */
	private function inTrash(CacheEntryRemovedEvent $event, string $path): bool {
		foreach (FormTrash::PLACES as $place) {
			if (str_starts_with($path, $place)) {
				return $place !== 'trash/' || preg_match(self::TEAM_FOLDER_STORAGE, $event->getStorage()->getId()) === 1;
			}
		}
		return false;
	}

	private function atTheEnd(): void {
		if (!$this->waiting) {
			$this->waiting = true;
			register_shutdown_function([$this, 'flush']);
		}
	}

	/**
	 * Removes the forms whose file was deleted for good in this request. Also when only something was deleted into a
	 * trash: the forms in it are told where they lie now (Orphans::removeDeleted).
	 */
	public function flush(): void {
		[$forgotten, $outright, $deleting] = [$this->forgotten, $this->outright, $this->deleting];
		[$this->forgotten, $this->outright, $this->deleting] = [[], [], []];
		try {
			$deleted = [];
			$single = [];
			$noTrash = $outright !== [] && $this->noTrashTookIt();
			foreach ($forgotten as [$fileId, $storageId, $path, $inTrash]) {
				if ($inTrash || ($noTrash && $this->below($outright, $storageId, $path))) {
					$deleted[] = [$fileId, $storageId, $path];
				}
				if ($inTrash && preg_match('/\.d\d+$/', $path) === 1) {
					$single[$fileId] = true;
				}
			}
			if ($deleted === [] && $deleting === []) {
				return;
			}
			if ($this->container->get(IDBConnection::class)->inTransaction()) {
				// The request broke off half-way: what it deleted may be undone yet, so the sweep decides.
				return;
			}
			$removed = $this->container->get(Orphans::class)->removeDeleted($deleted);
			foreach ($removed as $form) {
				if ($form->filesMoved) {
					// Its files were in app data, and went with it.
					unset($single[$form->fileId]);
				}
			}
			$this->container->get(FormTrash::class)->removed(array_keys($single));
			if ($removed !== []) {
				$this->logger->info('FormVox: removed ' . count($removed) . ' forms whose file was deleted for good: ' . implode(', ', array_map(fn (StoredForm $form): int => $form->id, $removed)), ['app' => Application::APP_ID]);
			}
		} catch (\Throwable $e) {
			// The daily sweep finds what is left, and gives it its grace.
			$this->logger->error('FormVox: could not remove the forms whose file was deleted: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	/**
	 * @param list<array{0: int, 1: string}> $outright
	 */
	private function below(array $outright, int $storageId, string $path): bool {
		foreach ($outright as [$storage, $deleted]) {
			if ($storage === $storageId && ($path === $deleted || str_starts_with($path, $deleted . '/'))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A file deleted while a trash is at work may lie in it under a new id (an encrypted team folder), so only where
	 * none is, or the request asked to pass it by, does a deleted file count as gone.
	 */
	private function noTrashTookIt(): bool {
		return !$this->apps->isEnabledForUser('files_trashbin') || $this->request->getHeader('X-NC-Skip-Trashbin') === 'true';
	}
}
