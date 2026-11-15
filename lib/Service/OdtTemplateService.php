<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Store\FormFolders;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * The per-form ODT export template, in a hidden folder next to the .fvform, or in app data for a form in the
 * database (FormFolders).
 *
 * Callers pass the form File opened for the current user.
 */
class OdtTemplateService {
	private const NAME = 'template.odt';

	public function __construct(
		private ?FormFolders $folders = null,
	) {
	}

	/**
	 * @throws NotFoundException when it does not exist and $create is false
	 */
	public function getTemplatesFolder(File $formFile, bool $create = false): Folder {
		$stored = $this->folders?->of($formFile, FormFolders::TEMPLATES, $create);
		if ($stored !== null) {
			return $stored;
		}
		$parent = $formFile->getParent();
		$name = '.formvox-templates-' . $formFile->getId();
		try {
			$folder = $parent->get($name);
		} catch (NotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			return $parent->newFolder($name);
		}
		if (!($folder instanceof Folder)) {
			throw new NotFoundException('Templates path is not a folder');
		}
		return $folder;
	}

	public function storeOdtTemplate(File $formFile, array $uploadedFile): void {
		$folder = $this->getTemplatesFolder($formFile, true);
		try {
			$folder->get(self::NAME)->delete();
		} catch (NotFoundException $e) {
			// No existing template
		}
		$folder->newFile(self::NAME)->putContent(file_get_contents($uploadedFile['tmp_name']));
	}

	/**
	 * @throws NotFoundException
	 */
	public function getOdtTemplate(File $formFile): File {
		$file = $this->getTemplatesFolder($formFile)->get(self::NAME);
		if (!($file instanceof File)) {
			throw new NotFoundException('Template not found');
		}
		return $file;
	}

	public function hasOdtTemplate(File $formFile): bool {
		try {
			$this->getOdtTemplate($formFile);
			return true;
		} catch (NotFoundException $e) {
			return false;
		}
	}

	public function deleteOdtTemplate(File $formFile): void {
		try {
			$this->getOdtTemplate($formFile)->delete();
		} catch (NotFoundException $e) {
			// No template to delete
		}
	}
}
