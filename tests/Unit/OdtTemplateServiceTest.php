<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\OdtTemplateService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for OdtTemplateService: the per-form ODT template file
 * in a hidden `.formvox-templates-{id}` folder. Pins folder naming, the
 * replace-on-store behaviour, and has/get/delete semantics.
 */
class OdtTemplateServiceTest extends TestCase
{
    private FormFileLocator $fileLocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileLocator = $this->createMock(FormFileLocator::class);
    }

    private function service(): OdtTemplateService
    {
        return new OdtTemplateService($this->fileLocator);
    }

    private function formFileWithParent(Folder $parent): File
    {
        $file = $this->createMock(File::class);
        $file->method('getParent')->willReturn($parent);
        return $file;
    }

    public function testTemplatesFolderNamedByFileId(): void
    {
        $folder = $this->createMock(Folder::class);
        $parent = $this->createMock(Folder::class);
        $parent->expects($this->once())->method('get')->with('.formvox-templates-9')->willReturn($folder);
        $this->fileLocator->method('getFileByIdPublic')->with(9, false)
            ->willReturn($this->formFileWithParent($parent));

        $this->assertSame($folder, $this->service()->getTemplatesFolder(9));
    }

    public function testStoreReplacesExistingTemplate(): void
    {
        $existing = $this->createMock(File::class);
        $existing->expects($this->once())->method('delete');   // replace-on-store

        $newFile = $this->createMock(File::class);

        $folder = $this->createMock(Folder::class);
        $folder->method('get')->with('template.odt')->willReturn($existing);
        $folder->expects($this->once())->method('newFile')->with('template.odt')->willReturn($newFile);

        $parent = $this->createMock(Folder::class);
        $parent->method('get')->with('.formvox-templates-9')->willReturn($folder);
        $this->fileLocator->method('getFileByIdPublic')->with(9, true)
            ->willReturn($this->formFileWithParent($parent));

        $tmp = tempnam(sys_get_temp_dir(), 'fvodt_');
        file_put_contents($tmp, 'odt-bytes');
        $this->service()->storeOdtTemplate(9, ['tmp_name' => $tmp]);
        unlink($tmp);
    }

    public function testHasOdtTemplateFalseWhenMissing(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->with('template.odt')->willThrowException(new NotFoundException());
        $parent = $this->createMock(Folder::class);
        $parent->method('get')->with('.formvox-templates-9')->willReturn($folder);
        $this->fileLocator->method('getFileByIdPublic')->willReturn($this->formFileWithParent($parent));

        $this->assertFalse($this->service()->hasOdtTemplate(9));
    }

    public function testHasOdtTemplateTrueWhenPresent(): void
    {
        $template = $this->createMock(File::class);
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->with('template.odt')->willReturn($template);
        $parent = $this->createMock(Folder::class);
        $parent->method('get')->with('.formvox-templates-9')->willReturn($folder);
        $this->fileLocator->method('getFileByIdPublic')->willReturn($this->formFileWithParent($parent));

        $this->assertTrue($this->service()->hasOdtTemplate(9));
    }

    public function testDeleteResolvesThroughWriteCapableAccount(): void
    {
        $template = $this->createMock(File::class);
        $template->expects($this->once())->method('delete');
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->with('template.odt')->willReturn($template);
        $parent = $this->createMock(Folder::class);
        $parent->method('get')->with('.formvox-templates-9')->willReturn($folder);
        // delete must resolve with requireWrite=true so the delete succeeds (#90).
        $this->fileLocator->expects($this->atLeastOnce())
            ->method('getFileByIdPublic')->with(9, true)
            ->willReturn($this->formFileWithParent($parent));

        $this->service()->deleteOdtTemplate(9);
    }
}
