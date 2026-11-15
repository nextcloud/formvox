<?php

declare(strict_types=1);

namespace OCA\FormVox\Migration;

use OCA\FormVox\Service\TemplateService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Admin templates saved before 1.6 still hold webhook secrets, API key hashes, links and people in appconfig.
 */
class CleanStoredTemplates implements IRepairStep {
	public function __construct(
		private TemplateService $templates,
	) {
	}

	public function getName(): string {
		return 'Remove secrets, links and people from stored FormVox templates';
	}

	public function run(IOutput $output): void {
		$cleaned = $this->templates->cleanStored();
		if ($cleaned > 0) {
			$output->info("Cleaned $cleaned FormVox template(s)");
		}
	}
}
