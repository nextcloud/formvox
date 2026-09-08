<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Upload;

use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Integration coverage for UploadService — the storage side: real hidden
 * sibling folders under the user's home, real file bytes, and a real
 * ZipArchive round-trip. Runs against a booted Nextcloud + DB + filesystem.
 *
 * @group DB
 */
class UploadServiceIntegrationTest extends IntegrationTestCase {
	private UploadService $service;

	protected function setUp(): void {
		parent::setUp();
		/** @var UploadService $service */
		$service = $this->getService(UploadService::class);
		$this->service = $service;
	}

	public function testStoreUploadWritesRealFile(): void {
		$formFile = $this->writeFormFile($this->userFolder, 'Upload Store Form');
		$fileId = $formFile->getId();
		// storeUpload → getUploadsFolder → getFileByIdPublic (public raw lookup).
		$this->requirePublicResolvable($fileId);

		$meta = $this->service->storeUpload(
			$fileId,
			'resp-1',
			$this->makeUploadedFile('photo.jpg', 'BYTES', 'image/jpeg')
		);

		// 'photo.jpg' is already safe — sanitized name is unchanged.
		$this->assertSame('photo.jpg', $meta['filename']);
		$this->assertSame('photo.jpg', $meta['originalName']);
		$this->assertSame('resp-1', $meta['responseId']);
		$this->assertSame('image/jpeg', $meta['mimeType']);
		$this->assertSame(strlen('BYTES'), $meta['size']);
		$this->assertIsInt($meta['fileId']);

		// The uploads folder is a hidden sibling of the .fvform file.
		$parent = $formFile->getParent();
		$uploadsFolder = $parent->get(".formvox-uploads-{$fileId}");
		$this->assertInstanceOf(Folder::class, $uploadsFolder);

		$responseFolder = $uploadsFolder->get('resp-1');
		$this->assertInstanceOf(Folder::class, $responseFolder);

		$stored = $responseFolder->get('photo.jpg');
		$this->assertInstanceOf(File::class, $stored);

		// Read the bytes straight from storage (bypassing any cache).
		$bytes = $stored->getStorage()->file_get_contents($stored->getInternalPath());
		$this->assertSame('BYTES', $bytes);
	}

	public function testStoreUploadSanitizesUnsafeFilename(): void {
		$formFile = $this->writeFormFile($this->userFolder, 'Upload Sanitize Form');
		$fileId = $formFile->getId();
		$this->requirePublicResolvable($fileId);

		// Path traversal + illegal chars + collapsed whitespace must be scrubbed.
		$meta = $this->service->storeUpload(
			$fileId,
			'resp-x',
			$this->makeUploadedFile('../my  bad:name?.PNG', 'X', 'image/png')
		);

		// '..' stripped, '/' removed, ':' and '?' -> '_', runs collapsed to a
		// single '_', then trailing '._' trimmed off the base name.
		$this->assertSame('my_bad_name.PNG', $meta['filename']);
		$this->assertSame('../my  bad:name?.PNG', $meta['originalName']);

		$parent = $formFile->getParent();
		$responseFolder = $parent->get(".formvox-uploads-{$fileId}")->get('resp-x');
		$this->assertInstanceOf(Folder::class, $responseFolder);
		$stored = $responseFolder->get('my_bad_name.PNG');
		$this->assertInstanceOf(File::class, $stored);
	}

	public function testGetUploadReadsBack(): void {
		$formFile = $this->writeFormFile($this->userFolder, 'Upload Read Form');
		$fileId = $formFile->getId();
		$this->requirePublicResolvable($fileId);

		$meta = $this->service->storeUpload(
			$fileId,
			'resp-1',
			$this->makeUploadedFile('doc.txt', 'HELLO-WORLD', 'text/plain')
		);

		$file = $this->service->getUpload($fileId, 'resp-1', $meta['filename']);
		$this->assertInstanceOf(File::class, $file);

		$bytes = $file->getStorage()->file_get_contents($file->getInternalPath());
		$this->assertSame('HELLO-WORLD', $bytes);
	}

	public function testCreateUploadsZipProducesRealZip(): void {
		$formFile = $this->writeFormFile($this->userFolder, 'Upload Zip Form');
		$fileId = $formFile->getId();
		$this->requirePublicResolvable($fileId);

		$this->service->storeUpload(
			$fileId,
			'resp-1',
			$this->makeUploadedFile('one.txt', 'CONTENT-ONE', 'text/plain')
		);
		$this->service->storeUpload(
			$fileId,
			'resp-2',
			$this->makeUploadedFile('two.txt', 'CONTENT-TWO', 'text/plain')
		);

		$zipBytes = $this->service->createUploadsZip($fileId);
		$this->assertIsString($zipBytes);
		$this->assertGreaterThan(0, strlen($zipBytes));

		$tempFile = tempnam(sys_get_temp_dir(), 'fvit_zip_');
		file_put_contents($tempFile, $zipBytes);

		try {
			$zip = new \ZipArchive();
			$this->assertTrue($zip->open($tempFile) === true);

			// Entries are nested under their response folder (recursive add).
			$this->assertNotFalse($zip->locateName('resp-1/one.txt'));
			$this->assertNotFalse($zip->locateName('resp-2/two.txt'));

			$this->assertSame('CONTENT-ONE', $zip->getFromName('resp-1/one.txt'));
			$this->assertSame('CONTENT-TWO', $zip->getFromName('resp-2/two.txt'));

			$zip->close();
		} finally {
			unlink($tempFile);
		}
	}

	public function testDeleteAllUploadsRemovesFolder(): void {
		$formFile = $this->writeFormFile($this->userFolder, 'Upload Delete Form');
		$fileId = $formFile->getId();
		$this->requirePublicResolvable($fileId);

		$this->service->storeUpload(
			$fileId,
			'resp-1',
			$this->makeUploadedFile('gone.txt', 'DELETE-ME', 'text/plain')
		);

		$parent = $formFile->getParent();
		// Precondition: hidden folder exists.
		$this->assertTrue($parent->nodeExists(".formvox-uploads-{$fileId}"));

		$this->service->deleteAllUploads($fileId);

		$this->assertFalse($parent->nodeExists(".formvox-uploads-{$fileId}"));
	}

	public function testGetUploadsFolderReadOnly404(): void {
		// A form with no uploads: read-only resolution must 404 rather than
		// creating the folder.
		$formFile = $this->writeFormFile($this->userFolder, 'Upload NoUploads Form');
		$fileId = $formFile->getId();
		$this->requirePublicResolvable($fileId);

		$this->expectException(NotFoundException::class);
		$this->service->getUploadsFolder($fileId, false);
	}
}
