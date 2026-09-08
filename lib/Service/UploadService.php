<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Response file uploads and the hidden sibling folders that hold them.
 *
 * Each form keeps its uploads and branding images in hidden folders named by
 * the form's file ID (`.formvox-uploads-{id}`, `.formvox-branding-{id}`) as
 * siblings of the .fvform file, so they survive a rename/move of the form and
 * are reachable from public rendering. Resolution goes through FormFileLocator
 * so the #90 read-only-vs-write-capable account selection is honoured.
 *
 * Extracted verbatim from FormService.
 */
class UploadService {
	private FormFileLocator $fileLocator;
	private FormFactory $formFactory;

	public function __construct(FormFileLocator $fileLocator, FormFactory $formFactory) {
		$this->fileLocator = $fileLocator;
		$this->formFactory = $formFactory;
	}

	/**
	 * Get the uploads folder for a form
	 * Creates it if it doesn't exist
	 * Uses file ID in the folder name so renaming the form doesn't break uploads
	 */
	public function getUploadsFolder(int $fileId, bool $requireWrite = false): Folder {
		// Only writing callers need a write-capable account; a read-only caller
		// (e.g. downloading an upload) must keep working on shares where the
		// reachable account can read but not write (#90).
		$formFile = $this->fileLocator->getFileByIdPublic($fileId, $requireWrite);
		$formFolder = $formFile->getParent();

		// Hidden folder with file ID - never changes even if form is renamed
		$uploadsFolderName = ".formvox-uploads-{$fileId}";

		try {
			$uploadsFolder = $formFolder->get($uploadsFolderName);
			if (!($uploadsFolder instanceof Folder)) {
				throw new \RuntimeException('Uploads path is not a folder');
			}
			return $uploadsFolder;
		} catch (NotFoundException $e) {
			// Read-only callers must not try to create the folder — signal
			// "nothing here" so they can 404 cleanly instead of erroring.
			if (!$requireWrite) {
				throw $e;
			}
			return $formFolder->newFolder($uploadsFolderName);
		}
	}

	/**
	 * Hidden per-form folder for branding images (logo/header pictures).
	 * Sibling of the .fvform file, so it travels along on rename/move and
	 * is reachable from public form rendering as well as the editor.
	 */
	public function getBrandingFolder(int $fileId, bool $requireWrite = false): Folder {
		// Read-only by default so public form rendering can still show branding
		// images on shares where the reachable account cannot write (#90).
		$formFile = $this->fileLocator->getFileByIdPublic($fileId, $requireWrite);
		$formFolder = $formFile->getParent();
		$brandingFolderName = ".formvox-branding-{$fileId}";

		try {
			$brandingFolder = $formFolder->get($brandingFolderName);
			if (!($brandingFolder instanceof Folder)) {
				throw new \RuntimeException('Branding path is not a folder');
			}
			return $brandingFolder;
		} catch (NotFoundException $e) {
			if (!$requireWrite) {
				throw $e;
			}
			return $formFolder->newFolder($brandingFolderName);
		}
	}

	/**
	 * Store an uploaded file for a form response
	 *
	 * @param int $fileId The form file ID
	 * @param string $responseId The response ID (temporary or final)
	 * @param array $uploadedFile The uploaded file from $_FILES
	 * @return array File metadata
	 */
	public function storeUpload(int $fileId, string $responseId, array $uploadedFile): array {
		$uploadsFolder = $this->getUploadsFolder($fileId, true);

		// Create response subfolder
		try {
			$responseFolder = $uploadsFolder->get($responseId);
			if (!($responseFolder instanceof Folder)) {
				throw new \RuntimeException('Response path is not a folder');
			}
		} catch (NotFoundException $e) {
			$responseFolder = $uploadsFolder->newFolder($responseId);
		}

		// Sanitize filename but keep original for display
		$originalName = $uploadedFile['name'];
		$safeName = $this->sanitizeUploadFilename($originalName);

		// Ensure unique filename in folder
		$safeName = $this->formFactory->getUniqueFilename($responseFolder, $safeName);

		// Create the file
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

	/**
	 * Get an uploaded file
	 *
	 * @param int $formFileId The form file ID
	 * @param string $responseId The response ID
	 * @param string $filename The filename
	 * @return File The file
	 */
	public function getUpload(int $formFileId, string $responseId, string $filename): File {
		$uploadsFolder = $this->getUploadsFolder($formFileId);

		try {
			$responseFolder = $uploadsFolder->get($responseId);
			if (!($responseFolder instanceof Folder)) {
				throw new NotFoundException('Response folder not found');
			}

			$file = $responseFolder->get($filename);
			if (!($file instanceof File)) {
				throw new NotFoundException('File not found');
			}

			return $file;
		} catch (NotFoundException $e) {
			throw new NotFoundException('Upload not found');
		}
	}

	/**
	 * Delete all uploads for a response
	 *
	 * @param int $formFileId The form file ID
	 * @param string $responseId The response ID
	 */
	public function deleteResponseUploads(int $formFileId, string $responseId): void {
		try {
			$uploadsFolder = $this->getUploadsFolder($formFileId, true);
			$responseFolder = $uploadsFolder->get($responseId);
			if ($responseFolder instanceof Folder) {
				$responseFolder->delete();
			}
		} catch (NotFoundException $e) {
			// No uploads to delete
		}
	}

	/**
	 * Delete the entire uploads folder for a form
	 *
	 * @param int $fileId The form file ID
	 */
	public function deleteAllUploads(int $fileId): void {
		try {
			$formFile = $this->fileLocator->getFileByIdPublic($fileId, true);
			$formFolder = $formFile->getParent();

			// Use file ID based folder name
			$uploadsFolderName = ".formvox-uploads-{$fileId}";

			try {
				$uploadsFolder = $formFolder->get($uploadsFolderName);
				if ($uploadsFolder instanceof Folder) {
					$uploadsFolder->delete();
				}
			} catch (NotFoundException $e) {
				// No uploads folder
			}
		} catch (\Exception $e) {
			// Form file not found or other error
		}
	}

	/**
	 * Create a ZIP file containing all uploads for a form
	 *
	 * @param int $fileId The form file ID
	 * @return string The ZIP file content
	 * @throws NotFoundException If no uploads exist
	 */
	public function createUploadsZip(int $fileId): string {
		$formFile = $this->fileLocator->getFileByIdPublic($fileId);
		$formFolder = $formFile->getParent();

		// Get uploads folder
		$uploadsFolderName = ".formvox-uploads-{$fileId}";
		try {
			$uploadsFolder = $formFolder->get($uploadsFolderName);
			if (!($uploadsFolder instanceof Folder)) {
				throw new NotFoundException('No uploads found');
			}
		} catch (NotFoundException $e) {
			throw new NotFoundException('No uploads found');
		}

		// Create temporary ZIP file
		$tempFile = tempnam(sys_get_temp_dir(), 'formvox_uploads_');
		$zip = new \ZipArchive();

		if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Failed to create ZIP file');
		}

		// Add all files from uploads folder
		$this->addFolderToZip($zip, $uploadsFolder, '');

		$zip->close();

		// Read ZIP content
		$content = file_get_contents($tempFile);

		// Clean up temp file
		unlink($tempFile);

		if ($content === false || strlen($content) === 0) {
			throw new NotFoundException('No uploads found');
		}

		return $content;
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
	 * Sanitize upload filename
	 * Keeps extension, removes unsafe characters
	 */
	private function sanitizeUploadFilename(string $filename): string {
		$info = pathinfo($filename);
		$extension = $info['extension'] ?? 'bin';

		// Clean the base name - allow more characters than form filenames
		$name = $info['filename'];
		// Remove path traversal characters and null bytes
		$name = str_replace(['..', "\0", '/', '\\'], '', $name);
		// Replace problematic characters with underscores
		$name = preg_replace('/[<>:"|?*]/', '_', $name);
		// Collapse multiple underscores/spaces
		$name = preg_replace('/[_\s]+/', '_', $name);
		// Trim
		$name = trim($name, '._');
		// Limit length
		$name = substr($name, 0, 100);
		// Default if empty
		if (empty($name)) {
			$name = 'upload';
		}

		// Clean extension
		$extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);
		if (empty($extension)) {
			$extension = 'bin';
		}

		return $name . '.' . $extension;
	}
}
