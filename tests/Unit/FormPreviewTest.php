<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\BackgroundJob\FormPreviewResetJob;
use OCA\FormVox\Migration\ScheduleFormPreviewReset;
use OCA\FormVox\Preview\FormPreview;
use OCA\FormVox\Preview\FormPreviewReset;
use OCP\BackgroundJob\IJobList;
use OCP\Files\FileInfo;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * A form shows the FormVox icon, also in the dark theme, and forms whose old thumbnail is stored get the new icon once.
 * Drawing the preview itself needs a real Nextcloud (FormPreviewIntegrationTest).
 */
class FormPreviewTest extends TestCase {
	public function testEveryFormHasThePreviewAlsoInTheTrashAndAsAnOlderVersion(): void {
		$preview = new FormPreview();

		$this->assertTrue($preview->isAvailable($this->file('Survey.FVFORM', 'application/x-fvform')));
		$this->assertTrue($preview->isAvailable($this->file('survey.fvform.d1729600000', 'application/x-fvform')));
		$this->assertTrue($preview->isAvailable($this->file('survey.fvform.v1729600000', 'application/x-fvform')));
		$this->assertFalse($preview->isAvailable($this->file('survey.json', 'application/json')));
		$this->assertSame('application/x-fvform', $preview->getMimeType());
	}

	public function testTheIconIsASquareTransparentPngLargeEnoughForTheGridAndSidebar(): void {
		[$width, $height, $type] = getimagesize(FormPreview::ICON);
		$this->assertSame([FormPreview::ICON_SIZE, FormPreview::ICON_SIZE, IMAGETYPE_PNG], [$width, $height, $type]);

		$image = imagecreatefrompng(FormPreview::ICON);
		// A corner is see-through, so the dark theme shows around the clipboard; its body is the FormVox purple.
		$this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha']);
		$body = imagecolorsforindex($image, imagecolorat($image, 6 * 16, 20 * 16));
		$this->assertSame(['red' => 0xb6, 'green' => 0x46, 'blue' => 0x9d, 'alpha' => 0], $body);
	}

	public function testAnUpdateThatChangedTheIconRemovesStoredPreviewsOnce(): void {
		$stored = 0;
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(function () use (&$stored) {
			return $stored;
		});
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value) use (&$stored) {
			$this->assertSame(['formvox', ScheduleFormPreviewReset::CONFIG_KEY], [$app, $key]);
			$stored = $value;
			return true;
		});
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('add')->with(FormPreviewResetJob::class);
		$step = new ScheduleFormPreviewReset($appConfig, $jobList);

		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(FormPreviewReset::ICON_VERSION, $stored);
	}

	private function file(string $name, string $mimeType): FileInfo {
		$file = $this->createMock(FileInfo::class);
		$file->method('getName')->willReturn($name);
		$file->method('getExtension')->willReturn(pathinfo($name, PATHINFO_EXTENSION));
		$file->method('getMimetype')->willReturn($mimeType);
		return $file;
	}
}
