<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Listener;

use OCA\FormVox\Listener\FormCopiedListener;
use OCA\FormVox\Service\FormIntake;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

/**
 * Every copy, of a file or a folder, goes to FormIntake (FormIntakeTest pins what it does).
 */
class FormCopiedListenerTest extends TestCase {
	public function testACopiedFileOrFolderGoesToTheIntake(): void {
		$file = $this->createMock(File::class);
		$folder = $this->createMock(Folder::class);
		$intake = $this->createMock(FormIntake::class);
		$seen = [];
		$intake->method('copied')->willReturnCallback(function ($node) use (&$seen) {
			$seen[] = $node;
		});

		$listener = new FormCopiedListener($intake);
		$listener->handle(new NodeCopiedEvent($this->createMock(File::class), $file));
		$listener->handle(new NodeCopiedEvent($this->createMock(Folder::class), $folder));
		$listener->handle(new Event());

		$this->assertSame([$file, $folder], $seen);
	}
}
