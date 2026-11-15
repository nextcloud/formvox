<?php

declare(strict_types=1);

namespace OCA\FormVox\Migration;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\BackgroundJob\FormPreviewResetJob;
use OCA\FormVox\Preview\FormPreviewReset;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * After an update that changed the form icon, the stored previews of forms are removed once, in the background.
 */
class ScheduleFormPreviewReset implements IRepairStep {
	public const CONFIG_KEY = 'preview_icon_version';

	public function __construct(
		private IAppConfig $appConfig,
		private IJobList $jobList,
	) {
	}

	public function getName(): string {
		return 'Show the current FormVox icon for existing forms';
	}

	public function run(IOutput $output): void {
		if ($this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_KEY) >= FormPreviewReset::ICON_VERSION) {
			return;
		}
		$this->jobList->add(FormPreviewResetJob::class);
		$this->appConfig->setValueInt(Application::APP_ID, self::CONFIG_KEY, FormPreviewReset::ICON_VERSION);
		$output->info('Scheduled a background job that replaces the old preview of each form with the FormVox icon');
	}
}
