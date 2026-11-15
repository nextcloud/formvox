<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\FormVox\Store\FormFolders;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Server;

/**
 * Response uploads and branding images, kept in hidden folders next to the .fvform, or in app data for a form in the
 * database (FormFolders).
 *
 * Callers pass the form File opened for them; only the *Public methods resolve it
 * themselves, through a borrowed account, for respondents without one.
 */
class UploadService {
	public function __construct(
		private FormFileLocator $fileLocator,
		private FormFactory $formFactory,
		private ?FormFolders $folders = null,
	) {
	}

	/**
	 * Store an upload from a public respondent. They have no account, so the
	 * form is opened through an account that may write where the files go (#90).
	 *
	 * @return array File metadata
	 */
	public function storeUploadPublic(int $fileId, string $responseId, array $uploadedFile): array {
		$formFile = $this->fileLocator->getFileByIdPublicForWrite($fileId);
		$responseFolder = $this->childFolder($this->getUploadsFolder($formFile, true), $responseId, true);

		$originalName = $uploadedFile['name'];
		$safeName = $this->formFactory->getUniqueFilename($responseFolder, self::sanitizeUploadFilename($originalName));

		$newFile = $responseFolder->newFile($safeName);
		$newFile->putContent(file_get_contents($uploadedFile['tmp_name']));

		return [
			'fileId' => $newFile->getId(),
			'filename' => $safeName,
			'originalName' => $originalName,
			'size' => $uploadedFile['size'],
			'mimeType' => $uploadedFile['type'],
			'responseId' => $responseId,
		];
	}

	/** How many files one response's upload folder holds so far. */
	public function countUploadsPublic(int $fileId, string $responseId): int {
		try {
			$folder = $this->childFolder($this->getUploadsFolder($this->fileLocator->getFileByIdPublicForWrite($fileId)), $responseId);
		} catch (NotFoundException|\InvalidArgumentException) {
			return 0;
		}
		return count($folder->getDirectoryListing());
	}

	/**
	 * An upload folder name: 32 hex characters (server) or a UUID (the browser's crypto.randomUUID) (H5, L18).
	 */
	public static function isValidResponseId(string $responseId): bool {
		return preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/', $responseId) === 1;
	}

	/**
	 * @throws NotFoundException
	 */
	public function getUpload(File $formFile, string $responseId, string $filename): File {
		try {
			$file = $this->childFolder($this->getUploadsFolder($formFile), $responseId)->get($filename);
		} catch (NotFoundException|\InvalidArgumentException $e) {
			throw new NotFoundException('Upload not found');
		}
		if (!($file instanceof File)) {
			throw new NotFoundException('Upload not found');
		}
		return $file;
	}

	public function deleteResponseUploads(File $formFile, string $responseId): void {
		try {
			$folder = $this->childFolder($this->getUploadsFolder($formFile), $responseId);
			self::withoutTrash(fn () => $folder->delete());
		} catch (NotFoundException|\InvalidArgumentException $e) {
			// Nothing stored for this response, or an id that is not a folder name.
		}
	}

	/**
	 * One file of a response, when a changed response no longer names it.
	 */
	public function deleteUpload(File $formFile, string $responseId, string $filename): void {
		try {
			$upload = $this->getUpload($formFile, $responseId, $filename);
			self::withoutTrash(fn () => $upload->delete());
		} catch (NotFoundException $e) {
			// Already gone.
		}
	}

	public function deleteAllUploads(File $formFile): void {
		try {
			$folder = $this->getUploadsFolder($formFile);
			self::withoutTrash(fn () => $folder->delete());
		} catch (NotFoundException $e) {
			// No uploads folder
		}
	}

	/**
	 * For good: whoever deletes a response deletes its files, rather than leaving them in the trash of the
	 * account the form lives with, where nobody looks (qual#4, secu#47, PO 5).
	 */
	public static function withoutTrash(callable $delete): void {
		try {
			$trash = interface_exists(ITrashManager::class) ? Server::get(ITrashManager::class) : null;
		} catch (\Throwable) {
			$trash = null;
		}
		$trash?->pauseTrash();
		try {
			$delete();
		} finally {
			$trash?->resumeTrash();
		}
	}

	/**
	 * @return string The ZIP file content
	 * @throws NotFoundException If no uploads exist
	 */
	public function createUploadsZip(File $formFile): string {
		$uploadsFolder = $this->getUploadsFolder($formFile);

		$tempFile = tempnam(sys_get_temp_dir(), 'formvox_uploads_');
		$zip = new \ZipArchive();
		if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Failed to create ZIP file');
		}
		$this->addFolderToZip($zip, $uploadsFolder, '');
		$zip->close();

		$content = file_get_contents($tempFile);
		unlink($tempFile);

		if ($content === false || strlen($content) === 0) {
			throw new NotFoundException('No uploads found');
		}
		return $content;
	}

	/**
	 * Hidden sibling folder named by file id, so a rename or move of the form keeps it.
	 *
	 * @throws NotFoundException when it does not exist and $create is false
	 */
	public function getUploadsFolder(File $formFile, bool $create = false): Folder {
		return $this->folders?->of($formFile, FormFolders::UPLOADS, $create)
			?? $this->siblingFolder($formFile, '.formvox-uploads-' . $formFile->getId(), $create);
	}

	/**
	 * @throws NotFoundException when it does not exist and $create is false
	 */
	public function getBrandingFolder(File $formFile, bool $create = false): Folder {
		return $this->folders?->of($formFile, FormFolders::BRANDING, $create)
			?? $this->siblingFolder($formFile, '.formvox-branding-' . $formFile->getId(), $create);
	}

	/**
	 * Branding images for public rendering, opened through any account that can read the form.
	 *
	 * @throws NotFoundException
	 */
	public function getBrandingFolderPublic(int $fileId): Folder {
		return $this->getBrandingFolder($this->fileLocator->getFileByIdPublic($fileId));
	}

	private function siblingFolder(File $formFile, string $name, bool $create): Folder {
		$parent = $formFile->getParent();
		try {
			$folder = $parent->get($name);
		} catch (NotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			return $parent->newFolder($name);
		}
		if (!($folder instanceof Folder)) {
			throw new NotFoundException($name . ' is not a folder');
		}
		return $folder;
	}

	/**
	 * A response's subfolder. An empty or path-like id would address the uploads folder
	 * itself, and deleting it would wipe every response's files.
	 *
	 * @throws NotFoundException|\InvalidArgumentException
	 */
	private function childFolder(Folder $uploadsFolder, string $responseId, bool $create = false): Folder {
		if (!self::isValidResponseId($responseId)) {
			throw new \InvalidArgumentException('Invalid response id');
		}
		try {
			$folder = $uploadsFolder->get($responseId);
		} catch (NotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			return $uploadsFolder->newFolder($responseId);
		}
		if (!($folder instanceof Folder)) {
			throw new NotFoundException('Response folder not found');
		}
		return $folder;
	}

	/**
	 * Recursively add a folder's contents to a ZIP archive
	 *
	 * @param \ZipArchive $zip The ZIP archive
	 * @param Folder $folder The folder to add
	 * @param string $basePath The base path in the ZIP
	 */
	private function addFolderToZip(\ZipArchive $zip, Folder $folder, string $basePath): void {
		foreach ($folder->getDirectoryListing() as $node) {
			$nodePath = $basePath === '' ? $node->getName() : $basePath . '/' . $node->getName();

			if ($node instanceof File) {
				$zip->addFromString($nodePath, $node->getContent());
			} elseif ($node instanceof Folder) {
				$this->addFolderToZip($zip, $node, $nodePath);
			}
		}
	}

	/**
	 * Whether $claimed is the name a respondent's file was stored as $stored under (getUniqueFilename may add -N).
	 */
	public static function isStoredNameOf(string $stored, string $claimed): bool {
		if ($claimed === '' || mb_strlen($claimed) > 255 || basename(str_replace('\\', '/', $claimed)) !== $claimed) {
			return false;
		}
		$safe = self::sanitizeUploadFilename($claimed);
		if ($stored === $safe) {
			return true;
		}
		$base = pathinfo($safe, PATHINFO_FILENAME);
		$extension = pathinfo($safe, PATHINFO_EXTENSION);
		return preg_match('/^' . preg_quote($base, '/') . '-\d+\.' . preg_quote($extension, '/') . '$/', $stored) === 1;
	}

	/**
	 * Sanitize upload filename
	 * Keeps extension, removes unsafe characters
	 */
	private static function sanitizeUploadFilename(string $filename): string {
		$info = pathinfo($filename);
		$extension = $info['extension'] ?? 'bin';

		// Clean the base name - allow more characters than form filenames
		$name = $info['filename'];
		// Remove path traversal characters and null bytes
		$name = str_replace(['..', "\0", '/', '\\'], '', $name);
		// Replace problematic characters with underscores
		$name = preg_replace('/[<>:"|?*]/u', '_', $name) ?? '';
		// Collapse multiple underscores/spaces
		$name = preg_replace('/[_\s]+/u', '_', $name) ?? '';
		// Trim
		$name = trim($name, '._');
		// At most 100 bytes, cut between characters: Nextcloud refuses names over 250 bytes, and half a character is none.
		$name = mb_strcut($name, 0, 100, 'UTF-8');
		// Default if empty
		if (empty($name)) {
			$name = 'upload';
		}

		// Clean extension
		$extension = substr(preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?? '', 0, 16);
		if (empty($extension)) {
			$extension = 'bin';
		}

		return $name . '.' . $extension;
	}
}
