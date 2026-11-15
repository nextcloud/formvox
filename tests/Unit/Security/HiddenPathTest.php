<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Security;

use OCA\FormVox\Security\HiddenPath;
use PHPUnit\Framework\TestCase;

class HiddenPathTest extends TestCase {
	/**
	 * @return array<string, array{string}>
	 */
	public static function hidden(): array {
		return [
			'a form\'s PDF folder' => ['.formvox-pdfs-12'],
			'a file inside it' => ['.formvox-pdfs-12/r1/3.pdf'],
			'deeper in a shared folder' => ['/Team/Survey/.formvox-uploads-7/r1/photo.jpg'],
			'any case' => ['Team/.FormVox-Branding-3/logo.png'],
			'a trashed folder' => ['.formvox-templates-4.d1700000000'],
			'a NUL byte Nextcloud drops' => [".formv\0ox-pdfs-1/a.pdf"],
			'a NUL byte before the dot' => ["\0.formvox-uploads-1/x"],
			'a backslash Nextcloud reads as a slash' => ['Team\\.formvox-uploads-1\\x'],
			'a current-directory segment' => ['./.formvox-pdfs-1/./a.pdf'],
		];
	}

	/**
	 * @dataProvider hidden
	 */
	public function testAFormsOwnFoldersAreHidden(string $path): void {
		$this->assertTrue(HiddenPath::isHidden($path));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function visible(): array {
		return [
			'the root' => ['/'],
			'empty' => [''],
			'the form itself' => ['Team/Survey.fvform'],
			'no leading dot' => ['formvox-pdfs-1/a.pdf'],
			'the name later in a segment' => ['notes.formvox-pdfs-1'],
			'another dot folder' => ['.attachments.12/a.png'],
			'a share token' => ['/dav/files/aBcD3fGh1JkLmN0/Survey.fvform'],
		];
	}

	/**
	 * @dataProvider visible
	 */
	public function testOtherPathsAreNot(string $path): void {
		$this->assertFalse(HiddenPath::isHidden($path));
	}

	public function testSegmentsAreTheOnesNextcloudOpens(): void {
		$this->assertSame(['Team', '.formvox-pdfs-1', 'a.pdf'], HiddenPath::segments("/Team//.\\.formv\0ox-pdfs-1/a.pdf/"));
	}
}
