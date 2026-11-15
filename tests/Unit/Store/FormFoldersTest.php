<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Store;

use OCA\FormVox\Service\FormLock;
use OCA\FormVox\Store\FormFolders;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\StoredForm;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

/**
 * Where a writer of a form's files goes while they are being moved: the move's last step holds the form while it brings
 * the folders beside the file over and stamps them moved, so a writer that finds the form held waits for the outcome
 * rather than writing beside the file after the stamp.
 */
class FormFoldersTest extends TestCase {
	private Folder $uploads;

	private function root(): IRootFolder {
		$this->uploads = $this->createMock(Folder::class);
		$folder = $this->uploads;
		foreach (['uploads', '5', 'forms', 'formvox'] as $name) {
			$parent = $this->createMock(Folder::class);
			$parent->method('get')->with($name)->willReturn($folder);
			$folder = $parent;
		}
		$root = $this->createMock(IRootFolder::class);
		$root->method('getAppDataDirectoryName')->willReturn('appdata_x');
		$root->method('get')->with('appdata_x')->willReturn($folder);
		return $root;
	}

	private function file(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		return $file;
	}

	private function beside(): StoredForm {
		return new StoredForm(5, 'u', 7, 3, 0, '', null, false, false, true);
	}

	private function moved(): StoredForm {
		return new StoredForm(5, 'u', 7, 3, 0, '', null, false, true, true, 1700000000);
	}

	public function testAWriterWaitsWhileTheMoveClosesAndThenGoesWhereTheFilesAreNow(): void {
		$root = $this->root();
		$forms = $this->createMock(FormStore::class);
		$forms->method('byFileId')->with(7)->willReturnOnConsecutiveCalls($this->beside(), $this->moved());
		$lock = $this->createMock(FormLock::class);
		$lock->expects($this->exactly(3))->method('heldByAnother')->with(7)->willReturnOnConsecutiveCalls(true, true, false);

		$this->assertSame($this->uploads, (new FormFolders($root, $forms, $lock))->of($this->file(), FormFolders::UPLOADS));
	}

	/** Nobody holding the form: the writer goes beside the file at once, as the row says. */
	public function testWithNobodyHoldingTheFormAWriterDoesNotWait(): void {
		$forms = $this->createMock(FormStore::class);
		$forms->method('byFileId')->willReturn($this->beside());
		$lock = $this->createMock(FormLock::class);
		$lock->expects($this->once())->method('heldByAnother')->willReturn(false);

		$this->assertNull((new FormFolders($this->root(), $forms, $lock))->of($this->file(), FormFolders::UPLOADS));
	}

	public function testAFormWhoseFilesAreMovedNeverAsksTheLock(): void {
		$root = $this->root();
		$forms = $this->createMock(FormStore::class);
		$forms->method('byFileId')->willReturn($this->moved());
		$lock = $this->createMock(FormLock::class);
		$lock->expects($this->never())->method('heldByAnother');

		$this->assertSame($this->uploads, (new FormFolders($root, $forms, $lock))->of($this->file(), FormFolders::UPLOADS));
	}
}
