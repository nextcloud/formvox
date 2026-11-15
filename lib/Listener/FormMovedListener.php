<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormBusyHint;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\OutsideWriteHold;
use OCA\FormVox\Service\ResponseOutbox;
use OCA\FormVox\Store\FormStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * A form moved to another folder takes the uploads, branding, templates and PDFs kept beside it along. Moved to another
 * storage (a team folder, external storage) it gets a new file id, and the folders are named for the id it had: so that
 * id is read before the move, while the form is still there, and the folders take the new one (7a, 3a-6). Moving the
 * folders is only for a form not yet moved into app data, whose files are found by the form and need no move; for
 * every form the database row follows the file (FormStore::follow) and its outbox job runs under the new id.
 *
 * @template-implements IEventListener<BeforeNodeRenamedEvent|NodeRenamedEvent>
 */
class FormMovedListener implements IEventListener {
	private const KINDS = ['uploads', 'templates', 'branding', 'pdfs'];

	/** @var array<string, int> each moving form's id before the move, by its path then */
	private array $before = [];
	/** @var array<string, array<string, int>> forms inside a moving folder: their ids by path below it, by the folder's path */
	private array $inside = [];
	/** @var array<string, list<int>> the forms held for a rename, by the source's path */
	private array $held = [];

	public function __construct(
		private ResponseOutbox $outbox,
		private IRootFolder $rootFolder,
		private OutsideWriteHold $hold,
		private IL10N $l,
		private LoggerInterface $logger,
		private ?FormStore $forms = null,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeNodeRenamedEvent) && !($event instanceof NodeRenamedEvent)) {
			return;
		}
		$source = $event->getSource();
		$target = $event->getTarget();
		if ($event instanceof BeforeNodeRenamedEvent) {
			$this->hold($source, $target);
		}
		// Never a reason to fail the user's own move: the form moved already.
		try {
			if ($source instanceof Folder || $target instanceof Folder) {
				$this->folderMoved($event, $source, $target);
			} elseif ($this->isForm($source->getName()) && $this->isForm($target->getName())) {
				// A form moved or renamed as a form; a file renamed over one is FormIntake's.
				$this->formMoved($event, $source, $target);
			}
		} catch (\Throwable $e) {
			$this->logger->error('FormVox: could not move the folders that belong to a moved form: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		} finally {
			if ($event instanceof NodeRenamedEvent) {
				foreach ($this->held[$source->getPath()] ?? [] as $fileId) {
					$this->hold->release($fileId);
				}
				unset($this->held[$source->getPath()]);
			}
		}
	}

	/**
	 * A form renamed or moved, whatever its new name, and a form it lands on: a save under way finishes first, and the
	 * rename waits for it briefly or is refused (3b-3, spike M2/A4). In one order, so crossing moves do not wait on
	 * each other. Released once it moved; at the end of the request when it did not.
	 *
	 * @throws FormBusyHint
	 */
	private function hold(Node $source, Node $target): void {
		$forms = [];
		foreach ([$source, $target] as $node) {
			try {
				if ($node instanceof File && $this->isForm($node->getName())) {
					$forms[(int)$node->getId()] = true;
				}
			} catch (NotFoundException) {
				// The target does not exist yet.
			}
		}
		ksort($forms);
		$taken = [];
		foreach (array_keys($forms) as $fileId) {
			try {
				$this->hold->take($fileId);
				$taken[] = $fileId;
			} catch (FormLockedException $e) {
				foreach ($taken as $held) {
					$this->hold->release($held);
				}
				// TRANSLATORS: Error when a form is moved or renamed in Files while FormVox saves it
				throw new FormBusyHint($this->l->t('The form is busy, please try again'), $e);
			} catch (\Throwable $e) {
				$this->logger->warning('FormVox: moved a form without its lock: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		$this->held[$source->getPath()] = $taken;
	}

	private function formMoved(Event $event, Node $source, Node $target): void {
		if ($event instanceof BeforeNodeRenamedEvent) {
			try {
				$id = $source->getId();
			} catch (NotFoundException) {
				return;
			}
			$this->before[$source->getPath()] = $id;
			return;
		}

		$newId = $target->getId();
		$oldId = $this->before[$source->getPath()] ?? $newId;
		unset($this->before[$source->getPath()]);
		$this->follow($oldId, $newId);
		$sourceParent = $source->getParent();
		$targetParent = $target->getParent();
		if ($sourceParent->getId() === $targetParent->getId() && $oldId === $newId) {
			return; // Just a rename within the same folder, nothing to move
		}

		$this->newId($oldId, $newId);
		$this->moveFolders($sourceParent, $targetParent, $oldId, $newId);
	}

	/**
	 * A folder with forms in it moved to another storage: every form in it gets a new id, and its folders, which moved
	 * along, take that id (review 3a-6). Within one storage, or a mount moved as a whole, the ids stay and nothing is
	 * looked up (review of the 3a fixes).
	 */
	private function folderMoved(Event $event, Node $source, Node $target): void {
		if ($event instanceof BeforeNodeRenamedEvent) {
			if ($source instanceof Folder && $this->changesStorage($source, $target)) {
				$this->inside[$source->getPath()] = $this->formsBelow($source);
			}
			return;
		}
		$forms = $this->inside[$source->getPath()] ?? [];
		unset($this->inside[$source->getPath()]);
		if (!($target instanceof Folder)) {
			return;
		}
		foreach ($forms as $below => $oldId) {
			// One form that cannot be handled does not hold back the others.
			try {
				$form = $target->get($below);
				$newId = $form->getId();
				$this->follow($oldId, $newId);
				if ($newId === $oldId) {
					continue;
				}
				$this->newId($oldId, $newId);
				$parent = $form->getParent();
				$this->moveFolders($parent, $parent, $oldId, $newId);
			} catch (NotFoundException) {
				continue;
			} catch (\Throwable $e) {
				$this->logger->error('FormVox: could not move the folders of form ' . $oldId . ' in a moved folder: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
	}

	/**
	 * Only a move to another storage gives new ids; a mount moved as a whole keeps them, as does a move within one. The
	 * target's storage comes from the mount table in memory: no file cache or storage call on every rename.
	 */
	private function changesStorage(Folder $source, Node $target): bool {
		if ($source->getInternalPath() === '') {
			return false;
		}
		try {
			$targetStorage = $this->rootFolder->getMount(dirname($target->getPath()))->getStorage();
			return $targetStorage === null || $source->getStorage()->getCache()->getNumericStorageId() !== $targetStorage->getCache()->getNumericStorageId();
		} catch (\Throwable) {
			// Cannot tell: look, rather than leave the folders under ids that are gone.
			return true;
		}
	}

	/**
	 * The forms that move with a folder: through Nextcloud's search, which knows team folders and shares, by name as
	 * FormVox knows a form (its file type may never have been registered), and only those on the folder's own mount, as a
	 * mount inside it keeps its ids.
	 *
	 * @return array<string, int> their ids, by path below the folder
	 */
	private function formsBelow(Folder $folder): array {
		$base = rtrim($folder->getPath(), '/') . '/';
		$mount = $folder->getMountPoint()->getMountPoint();
		$forms = [];
		foreach ($folder->search('.' . Application::FILE_EXTENSION) as $node) {
			if ($node instanceof File && $this->isForm($node->getName()) && $node->getMountPoint()->getMountPoint() === $mount && str_starts_with($node->getPath(), $base)) {
				$forms[substr($node->getPath(), strlen($base))] = $node->getId();
			}
		}
		return $forms;
	}

	/** The form's folders take its new place and id; one that cannot move does not hold back the others. */
	private function moveFolders(Folder $sourceParent, Folder $targetParent, int $oldId, int $newId): void {
		foreach (self::KINDS as $kind) {
			try {
				$this->moveAssociatedFolder($sourceParent, $targetParent, ".formvox-{$kind}-{$oldId}", ".formvox-{$kind}-{$newId}");
			} catch (\Throwable $e) {
				$this->logger->error("FormVox: could not move .formvox-{$kind}-{$oldId} of a moved form: " . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
	}

	/** A form in the database: its row follows the file, to its new id or storage. */
	private function follow(int $oldId, int $newId): void {
		try {
			$this->forms?->follow($oldId, $newId);
		} catch (\Throwable $e) {
			$this->logger->error('FormVox: could not follow moved form ' . $oldId . ' in the database: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	/** What the form still had to send waits under its old id; its job now runs under the new one. */
	private function newId(int $oldId, int $newId): void {
		if ($oldId === $newId) {
			return;
		}
		try {
			$this->outbox->schedule($newId);
		} catch (\Throwable $e) {
			// The folders still move; the next event on the form asks for the job again.
			$this->logger->error('FormVox: could not ask for the background job of moved form ' . $newId . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	private function isForm(string $name): bool {
		return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === Application::FILE_EXTENSION;
	}

	private function moveAssociatedFolder(Folder $sourceParent, Folder $targetParent, string $from, string $to): void {
		try {
			$folder = $sourceParent->get($from);
		} catch (NotFoundException) {
			return;
		}
		if (!($folder instanceof Folder)) {
			return;
		}
		if ($targetParent->nodeExists($to)) {
			// Never over another folder: that would delete it.
			$this->logger->warning("FormVox: did not move {$from} to {$to}, a folder by that name is there already", ['app' => Application::APP_ID]);
			return;
		}
		$folder->move($targetParent->getPath() . '/' . $to);
		$this->logger->info("FormVox: Moved {$from} to {$to}");
	}
}
