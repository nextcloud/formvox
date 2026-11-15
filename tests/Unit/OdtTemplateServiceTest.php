<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\OdtTemplateService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * The per-form ODT template in a hidden `.formvox-templates-{id}` folder next to the form.
 */
class OdtTemplateServiceTest extends TestCase {
	private function formFile(Folder $parent): File {
		$file = $this->createMock(File::class);
		$file->method('getParent')->willReturn($parent);
		$file->method('getId')->willReturn(9);
		return $file;
	}

	private function parentWith(Folder $templates): Folder {
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with('.formvox-templates-9')->willReturn($templates);
		return $parent;
	}

	public function testTemplatesFolderIsNamedByFileId(): void {
		$folder = $this->createMock(Folder::class);
		$parent = $this->createMock(Folder::class);
		$parent->expects($this->once())->method('get')->with('.formvox-templates-9')->willReturn($folder);

		$this->assertSame($folder, (new OdtTemplateService())->getTemplatesFolder($this->formFile($parent)));
	}

	public function testStoreReplacesTheExistingTemplate(): void {
		$existing = $this->createMock(File::class);
		$existing->expects($this->once())->method('delete');
		$newFile = $this->createMock(File::class);
		$newFile->expects($this->once())->method('putContent')->with('odt-bytes');
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->with('template.odt')->willReturn($existing);
		$folder->expects($this->once())->method('newFile')->with('template.odt')->willReturn($newFile);

		$tmp = tempnam(sys_get_temp_dir(), 'fvodt_');
		file_put_contents($tmp, 'odt-bytes');
		(new OdtTemplateService())->storeOdtTemplate($this->formFile($this->parentWith($folder)), ['tmp_name' => $tmp]);
		unlink($tmp);
	}

	public function testHasOdtTemplateReflectsWhetherTheFileExists(): void {
		$missing = $this->createMock(Folder::class);
		$missing->method('get')->willThrowException(new NotFoundException());
		$present = $this->createMock(Folder::class);
		$present->method('get')->willReturn($this->createMock(File::class));

		$this->assertFalse((new OdtTemplateService())->hasOdtTemplate($this->formFile($this->parentWith($missing))));
		$this->assertTrue((new OdtTemplateService())->hasOdtTemplate($this->formFile($this->parentWith($present))));
	}

	public function testDeleteRemovesTheTemplateAndIgnoresAMissingOne(): void {
		$template = $this->createMock(File::class);
		$template->expects($this->once())->method('delete');
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->with('template.odt')->willReturn($template);
		(new OdtTemplateService())->deleteOdtTemplate($this->formFile($this->parentWith($folder)));

		$empty = $this->createMock(Folder::class);
		$empty->method('get')->willThrowException(new NotFoundException());
		(new OdtTemplateService())->deleteOdtTemplate($this->formFile($this->parentWith($empty)));
	}
}
