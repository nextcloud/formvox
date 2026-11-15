<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormLock;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * Where a form in the database keeps its respondents' files, PDFs, branding images and ODT template: in FormVox's app
 * data, by the form's own id, out of every user's files. Forms not in the database keep their folders beside the file.
 */
class FormFolders {
	public const UPLOADS = 'uploads';
	public const BRANDING = 'branding';
	public const TEMPLATES = 'templates';
	public const PDFS = 'pdfs';

	/** How long a writer waits while the move of a form's files closes: that step is short, and decides where it writes. */
	private const CLOSING_WAIT_SECONDS = 3.0;

	public function __construct(
		private IRootFolder $root,
		private FormStore $forms,
		private ?FormLock $lock = null,
	) {
	}

	/** Whether the form keeps its files here; a form moved in keeps them beside its file until they are moved. */
	public function isStored(int $fileId): bool {
		return $this->forms->byFileId($fileId)?->filesMoved === true;
	}

	/**
	 * The folder a writer or reader goes to now. While the files are beside the file and someone holds the form, that
	 * may be the move's last step, which brings the folders over and stamps them moved: a writer that chose beside the
	 * file just before would write there after. So it waits for that step and looks again.
	 *
	 * @return Folder|null the folder of this kind; null for a form whose files are beside its file
	 * @throws NotFoundException when it does not exist and $create is false
	 */
	public function of(File $form, string $kind, bool $create = false): ?Folder {
		$fileId = (int)$form->getId();
		$stored = $this->forms->byFileId($fileId);
		if ($stored !== null && !$stored->filesMoved && $this->lock !== null) {
			$deadline = microtime(true) + self::CLOSING_WAIT_SECONDS;
			for ($attempt = 0; $this->lock->heldByAnother($fileId) && microtime(true) < $deadline; $attempt++) {
				FormLock::backoff($attempt, $deadline);
			}
			$stored = $this->forms->byFileId($fileId);
		}
		return $stored === null || !$stored->filesMoved ? null : $this->folder([(string)$stored->id, $kind], $create);
	}

	/**
	 * By the form's own id, for a form whose file is not bound to it yet.
	 *
	 * @throws NotFoundException when it does not exist and $create is false
	 */
	public function kind(int $formId, string $kind, bool $create = false): Folder {
		return $this->folder([(string)$formId, $kind], $create);
	}

	/**
	 * Everything a form keeps here, for when its rows are removed for good.
	 *
	 * @throws NotFoundException
	 */
	public function all(int $formId): Folder {
		return $this->folder([(string)$formId], false);
	}

	/**
	 * @param list<string> $path below forms/
	 * @throws NotFoundException
	 */
	private function folder(array $path, bool $create): Folder {
		$folder = $this->child($this->root, $this->root->getAppDataDirectoryName(), $create);
		foreach ([Application::APP_ID, 'forms', ...$path] as $name) {
			$folder = $this->child($folder, $name, $create);
		}
		return $folder;
	}

	/** @throws NotFoundException */
	private function child(Folder $parent, string $name, bool $create): Folder {
		try {
			$node = $parent->get($name);
		} catch (NotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			return $parent->newFolder($name);
		}
		if (!$node instanceof Folder) {
			throw new NotFoundException($name . ' is not a folder');
		}
		return $node;
	}
}
