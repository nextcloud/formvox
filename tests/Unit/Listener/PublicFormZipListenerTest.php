<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Listener;

use OCA\FormVox\DAV\PublicDavScope;
use OCA\FormVox\Listener\PublicFormZipListener;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class PublicFormZipListenerTest extends TestCase {
	private function file(string $name, string $mimetype = 'application/octet-stream'): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getMimetype')->willReturn($mimetype);
		return $file;
	}

	/**
	 * @param array<string, File|Folder> $children
	 * @param array<string, list<File|Folder>> $found what search() finds per query
	 * @param list<File> $byMime what searchByMime() finds
	 */
	private function folder(string $name = 'Shared', array $children = [], array $found = [], array $byMime = []): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn($name);
		$folder->method('getMimetype')->willReturn('httpd/unix-directory');
		$folder->method('get')->willReturnCallback(fn (string $child) => $children[$child]);
		$folder->method('search')->willReturnCallback(fn (string $query) => $found[$query] ?? []);
		$folder->method('searchByMime')->willReturn($byMime);
		return $folder;
	}

	private function zip(Folder $folder, array $files = [], bool $public = true): BeforeZipCreatedEvent {
		$scope = new PublicDavScope();
		if ($public) {
			$scope->enter();
		}
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$event = new BeforeZipCreatedEvent($folder, $files);
		(new PublicFormZipListener($scope, $l10n))->handle($event);
		return $event;
	}

	public function testAPublicZipOfAFolderWithAFormIsRefused(): void {
		$event = $this->zip($this->folder(found: ['.fvform' => [$this->file('Survey.fvform')]]));

		$this->assertFalse($event->isSuccessful());
		$this->assertSame('Folders with forms cannot be downloaded through a public link.', $event->getErrorMessage());
	}

	public function testAFormIsFoundByItsTypeWhateverItsName(): void {
		$this->assertFalse($this->zip($this->folder(byMime: [$this->file('Survey', 'application/x-fvform')]))->isSuccessful());

		$folder = $this->folder(children: ['Survey' => $this->file('Survey', 'application/x-fvform')]);
		$this->assertFalse($this->zip($folder, ['Survey'])->isSuccessful());
	}

	public function testAPublicZipWithAFormsFolderIsRefused(): void {
		$leftover = $this->folder(found: ['.formvox-' => [$this->folder('.formvox-uploads-7')]]);
		$this->assertFalse($this->zip($leftover)->isSuccessful(), 'a folder left behind by a deleted form');

		$this->assertFalse($this->zip($this->folder(), ['.formvox-pdfs-7'])->isSuccessful(), 'asked for by name');
		$this->assertFalse($this->zip($this->folder(), ["\0.formvox-pdfs-7"])->isSuccessful(), 'with a byte Nextcloud drops');
	}

	public function testAPublicZipOfSelectedFilesWithAFormIsRefused(): void {
		$folder = $this->folder(children: ['a.pdf' => $this->file('a.pdf'), 'Survey.fvform' => $this->file('Survey.fvform')]);

		$this->assertFalse($this->zip($folder, ['a.pdf', 'Survey.fvform'])->isSuccessful());
	}

	public function testAPublicZipWithoutFormsIsLeftAlone(): void {
		$folder = $this->folder(children: ['a.pdf' => $this->file('a.pdf')], found: ['.fvform' => [$this->file('notes.fvform.txt')]]);

		$this->assertTrue($this->zip($folder, ['a.pdf'])->isSuccessful());
		$this->assertTrue($this->zip($folder)->isSuccessful());
	}

	public function testZipsOutsidePublicWebDavAreLeftAlone(): void {
		$folder = $this->folder(found: ['.fvform' => [$this->file('Survey.fvform')], '.formvox-' => [$this->folder('.formvox-pdfs-7')]]);

		$this->assertTrue($this->zip($folder, public: false)->isSuccessful());
	}
}
