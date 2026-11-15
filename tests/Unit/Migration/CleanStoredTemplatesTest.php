<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Migration;

use OCA\FormVox\Migration\CleanStoredTemplates;
use OCA\FormVox\Service\TemplateService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** TemplateServiceTest pins the cleaning itself; this pins that the upgrade runs it and says so. */
class CleanStoredTemplatesTest extends TestCase {
	public function testTheRepairStepCleansTheStoredTemplatesAndReportsHowMany(): void {
		$templates = $this->createMock(TemplateService::class);
		$templates->expects($this->once())->method('cleanStored')->willReturn(2);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Cleaned 2 FormVox template(s)');

		(new CleanStoredTemplates($templates))->run($output);
	}

	public function testTheRepairStepIsRegisteredForUpgrades(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));

		$this->assertContains(CleanStoredTemplates::class, $steps);
	}
}
