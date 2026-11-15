<?php

declare(strict_types=1);

namespace OCA\FormVox\Migration;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\BackgroundJob\MigrateFormsJob;
use OCA\FormVox\Store\FormMigration;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * After an update: every form still in its file is moved into the database by the background job (MigrateFormsJob).
 * Runs after RegisterMimeType, which gives every file named as a form the form type, so none is missed.
 */
class QueueFormMigration implements IRepairStep {
	public function __construct(
		private IJobList $jobs,
		private IAppConfig $config,
		private FormMigration $migration,
	) {
	}

	public function getName(): string {
		return 'Move FormVox forms into the database';
	}

	public function run(IOutput $output): void {
		$this->config->deleteKey(Application::APP_ID, MigrateFormsJob::DONE);
		$this->migration->forgetBroken();
		if (!$this->jobs->has(MigrateFormsJob::class, null)) {
			$this->jobs->add(MigrateFormsJob::class);
		}
		$output->info('FormVox moves its forms into the database in the background; see occ formvox:migrate --status');
	}
}
