<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormFactory;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\UploadService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Hidden per-form folders (named by file id), filename sanitising, and that only
 * the *Public methods resolve the form themselves.
 */
class UploadServiceTest extends TestCase {
	private FormFileLocator $fileLocator;
	private FormFactory $formFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->fileLocator = $this->createMock(FormFileLocator::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $t): string => $t);
		$this->formFactory = new FormFactory($l);
	}

	private function service(): UploadService {
		return new UploadService($this->fileLocator, $this->formFactory);
	}

	private function formFile(Folder $parent, int $id = 42): File {
		$file = $this->createMock(File::class);
		$file->method('getParent')->willReturn($parent);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	public function testUploadsFolderIsNamedByFileId(): void {
		$existing = $this->createMock(Folder::class);
		$parent = $this->createMock(Folder::class);
		$parent->expects($this->once())->method('get')->with('.formvox-uploads-42')->willReturn($existing);

		$this->assertSame($existing, $this->service()->getUploadsFolder($this->formFile($parent)));
	}

	public function testReadingNeverCreatesTheFolder(): void {
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->willThrowException(new NotFoundException());
		$parent->expects($this->never())->method('newFolder');

		$this->expectException(NotFoundException::class);
		$this->service()->getUploadsFolder($this->formFile($parent));
	}

	public function testCreateMakesTheFolderWhenMissing(): void {
		$created = $this->createMock(Folder::class);
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->willThrowException(new NotFoundException());
		$parent->expects($this->once())->method('newFolder')->with('.formvox-uploads-42')->willReturn($created);

		$this->assertSame($created, $this->service()->getUploadsFolder($this->formFile($parent), true));
	}

	public function testBrandingFolderIsNamedByFileId(): void {
		$existing = $this->createMock(Folder::class);
		$parent = $this->createMock(Folder::class);
		$parent->expects($this->once())->method('get')->with('.formvox-branding-7')->willReturn($existing);

		$this->assertSame($existing, $this->service()->getBrandingFolder($this->formFile($parent, 7)));
	}

	public function testSessionMethodsNeverBorrowAnAccount(): void {
		$this->fileLocator->expects($this->never())->method('getFileByIdPublic');
		$this->fileLocator->expects($this->never())->method('getFileByIdPublicForWrite');
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->willThrowException(new NotFoundException());

		$this->service()->deleteAllUploads($this->formFile($parent));
		$this->service()->deleteResponseUploads($this->formFile($parent), '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f');
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafeResponseIds(): array {
		return ['empty' => [''], 'dot' => ['.'], 'parent' => ['..'], 'slash' => ['a/b'], 'backslash' => ['a\\b']];
	}

	/**
	 * An id that addresses the uploads folder itself must not wipe every response's files.
	 *
	 * @dataProvider unsafeResponseIds
	 */
	public function testDeletingWithAnUnsafeResponseIdDeletesNothing(string $responseId): void {
		$uploads = $this->createMock(Folder::class);
		$uploads->expects($this->never())->method('delete');
		$uploads->expects($this->never())->method('get');
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with('.formvox-uploads-42')->willReturn($uploads);

		$this->service()->deleteResponseUploads($this->formFile($parent), $responseId);
	}

	public function testDeleteResponseUploadsRemovesThatResponsesFolder(): void {
		$responseFolder = $this->createMock(Folder::class);
		$responseFolder->expects($this->once())->method('delete');
		$uploads = $this->createMock(Folder::class);
		$uploads->method('get')->with('5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f')->willReturn($responseFolder);
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with('.formvox-uploads-42')->willReturn($uploads);

		$this->service()->deleteResponseUploads($this->formFile($parent), '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f');
	}

	/** @return array{0: ?string, 1: array} the name it was stored under, and the metadata returned */
	private function storeNamed(string $name): array {
		$responseFolder = $this->createMock(Folder::class);
		$responseFolder->method('nodeExists')->willReturn(false);
		$captured = null;
		$newFile = $this->createMock(File::class);
		$newFile->method('getId')->willReturn(123);
		$responseFolder->method('newFile')->willReturnCallback(function (string $name) use (&$captured, $newFile) {
			$captured = $name;
			return $newFile;
		});
		$uploads = $this->createMock(Folder::class);
		$uploads->method('get')->with('5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f')->willReturn($responseFolder);
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with('.formvox-uploads-42')->willReturn($uploads);
		$this->fileLocator->expects($this->once())->method('getFileByIdPublicForWrite')->with(42)
			->willReturn($this->formFile($parent));

		$tmp = tempnam(sys_get_temp_dir(), 'fvtest_');
		file_put_contents($tmp, 'data');
		$meta = $this->service()->storeUploadPublic(42, '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', [
			'name' => $name,
			'tmp_name' => $tmp,
			'size' => 4,
			'type' => 'application/pdf',
		]);
		unlink($tmp);
		return [$captured, $meta];
	}

	public function testStoreUploadPublicUsesAWriteCapableAccountAndSanitizesTheName(): void {
		[$captured, $meta] = $this->storeNamed('../../etc/pas<swd>.PDF');

		// pathinfo() drops the directories; '<' and '>' collapse to '_'.
		$this->assertSame('pas_swd.PDF', $captured);
		$this->assertSame('pas_swd.PDF', $meta['filename']);
		$this->assertSame('../../etc/pas<swd>.PDF', $meta['originalName']);
		$this->assertSame(123, $meta['fileId']);
		$this->assertSame('5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f', $meta['responseId']);
	}

	/** @return array<string, array{string}> */
	public static function longNames(): array {
		return ['Cyrillic' => [str_repeat('Заявление ', 20)], 'Chinese' => [str_repeat('申请表', 30)], 'emoji' => [str_repeat('📄', 70)]];
	}

	/**
	 * A long name in another script is cut between characters and within Nextcloud's byte limit, so it is a valid file name.
	 *
	 * @dataProvider longNames
	 */
	public function testALongNameIsCutWholeAndShortEnough(string $base): void {
		[$captured] = $this->storeNamed($base . '.pdf');

		$this->assertTrue(mb_check_encoding((string)$captured, 'UTF-8'));
		$this->assertLessThanOrEqual(104, strlen((string)$captured));
		$this->assertStringEndsWith('.pdf', (string)$captured);
		$this->assertTrue(\OCA\FormVox\Service\UploadService::isStoredNameOf((string)$captured, $base . '.pdf'));
	}

	/** An extension of any length, where a question takes every type: at most 16 characters, so the name stays valid. */
	public function testALongExtensionIsCut(): void {
		[$captured] = $this->storeNamed('scan.' . str_repeat('a', 300));

		$this->assertSame(str_repeat('a', 16), pathinfo((string)$captured, PATHINFO_EXTENSION));
		$this->assertLessThanOrEqual(100 + 1 + 16, strlen((string)$captured));
	}
}
