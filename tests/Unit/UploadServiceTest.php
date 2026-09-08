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
 * Characterization tests for UploadService.
 *
 * Pins the hidden-folder naming (by file ID so a rename doesn't break uploads),
 * the #90 read-only-vs-write-capable folder behaviour, and the upload-filename
 * sanitizing (path-traversal stripping). Mocked against ocp stubs.
 */
class UploadServiceTest extends TestCase
{
    private FormFileLocator $fileLocator;
    private FormFactory $formFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileLocator = $this->createMock(FormFileLocator::class);
        // Real FormFactory (only needs IL10N) so getUniqueFilename is exercised.
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $t): string => $t);
        $this->formFactory = new FormFactory($l);
    }

    private function service(): UploadService
    {
        return new UploadService($this->fileLocator, $this->formFactory);
    }

    /** A form file whose parent folder is $parent. */
    private function formFileWithParent(Folder $parent): File
    {
        $file = $this->createMock(File::class);
        $file->method('getParent')->willReturn($parent);
        return $file;
    }

    public function testUploadsFolderNamedByFileIdAndReturnedWhenExists(): void
    {
        $existing = $this->createMock(Folder::class);
        $parent = $this->createMock(Folder::class);
        $parent->expects($this->once())->method('get')->with('.formvox-uploads-42')->willReturn($existing);

        $this->fileLocator->method('getFileByIdPublic')->with(42, false)
            ->willReturn($this->formFileWithParent($parent));

        $this->assertSame($existing, $this->service()->getUploadsFolder(42));
    }

    public function testReadOnlyCallerDoesNotCreateFolder(): void
    {
        $parent = $this->createMock(Folder::class);
        $parent->method('get')->willThrowException(new NotFoundException());
        $parent->expects($this->never())->method('newFolder');

        $this->fileLocator->method('getFileByIdPublic')->with(42, false)
            ->willReturn($this->formFileWithParent($parent));

        // Read-only: absent folder must surface as NotFound, not be created.
        $this->expectException(NotFoundException::class);
        $this->service()->getUploadsFolder(42, false);
    }

    public function testWriteCallerCreatesFolderWhenMissing(): void
    {
        $created = $this->createMock(Folder::class);
        $parent = $this->createMock(Folder::class);
        $parent->method('get')->willThrowException(new NotFoundException());
        $parent->expects($this->once())->method('newFolder')->with('.formvox-uploads-42')->willReturn($created);

        // requireWrite=true is passed through to the locator too (#90).
        $this->fileLocator->method('getFileByIdPublic')->with(42, true)
            ->willReturn($this->formFileWithParent($parent));

        $this->assertSame($created, $this->service()->getUploadsFolder(42, true));
    }

    public function testBrandingFolderNamedByFileId(): void
    {
        $existing = $this->createMock(Folder::class);
        $parent = $this->createMock(Folder::class);
        $parent->expects($this->once())->method('get')->with('.formvox-branding-7')->willReturn($existing);

        $this->fileLocator->method('getFileByIdPublic')->with(7, false)
            ->willReturn($this->formFileWithParent($parent));

        $this->assertSame($existing, $this->service()->getBrandingFolder(7));
    }

    public function testStoreUploadSanitizesFilenameAndStripsPathTraversal(): void
    {
        // A response subfolder that reports the sanitized name is free.
        $responseFolder = $this->createMock(Folder::class);
        $responseFolder->method('nodeExists')->willReturn(false);
        $captured = null;
        $newFile = $this->createMock(File::class);
        $newFile->method('getId')->willReturn(123);
        $responseFolder->method('newFile')->willReturnCallback(function (string $name) use (&$captured, $newFile) {
            $captured = $name;
            return $newFile;
        });

        $uploadsFolder = $this->createMock(Folder::class);
        $uploadsFolder->method('get')->with('resp-1')->willReturn($responseFolder);

        $parent = $this->createMock(Folder::class);
        $parent->method('get')->with('.formvox-uploads-42')->willReturn($uploadsFolder);
        $this->fileLocator->method('getFileByIdPublic')->with(42, true)
            ->willReturn($this->formFileWithParent($parent));

        // Write a real temp file so file_get_contents in storeUpload works.
        $tmp = tempnam(sys_get_temp_dir(), 'fvtest_');
        file_put_contents($tmp, 'data');

        $meta = $this->service()->storeUpload(42, 'resp-1', [
            'name' => '../../etc/pas<swd>.PDF',
            'tmp_name' => $tmp,
            'size' => 4,
            'type' => 'application/pdf',
        ]);
        unlink($tmp);

        // pathinfo() strips the directory, so filename is 'pas<swd>'; unsafe
        // chars ('<','>') become '_' and collapse, extension preserved =>
        // 'pas_swd.PDF'. (Path traversal in the leading dirs never reaches the
        // stored name.)
        $this->assertSame('pas_swd.PDF', $captured);
        $this->assertSame('pas_swd.PDF', $meta['filename']);
        $this->assertSame('../../etc/pas<swd>.PDF', $meta['originalName']);
        $this->assertSame(123, $meta['fileId']);
        $this->assertSame('resp-1', $meta['responseId']);
        $this->assertStringNotContainsString('..', $captured);
        $this->assertStringNotContainsString('/', $captured);
        $this->assertStringNotContainsString('<', $captured);
    }
}
