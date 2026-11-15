<?php

declare(strict_types=1);

namespace OCA\FormVox\Command;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\BackgroundJob\MigrateFormsJob;
use OCA\FormVox\Store\FormMigration;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Moves the forms still in their file into the database now, rather than waiting for the background job. */
class Migrate extends Command {
	public function __construct(
		private FormMigration $migration,
		private IAppConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('formvox:migrate')
			->setDescription('Move the forms still in their file into the database now')
			->addOption('status', null, InputOption::VALUE_NONE, 'Only show what is left to do')
			->addOption('file-id', null, InputOption::VALUE_REQUIRED, 'Move only this form, by the file id of its form file');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('status')) {
			$status = $this->migration->status();
			$output->writeln('Forms still in their file: ' . $status['pending']);
			$output->writeln('Forms moved in whose file or files are still to be finished: ' . $status['unsettled']);
			$output->writeln('Files named as a form that hold none: ' . ($status['broken'] === [] ? 'none' : implode(', ', $status['broken'])));
			return 0;
		}
		$fileId = $input->getOption('file-id');
		if ($fileId !== null) {
			$output->writeln('File ' . (int)$fileId . ': ' . $this->migration->moveOne((int)$fileId));
			return 0;
		}
		// Without a limit: each form has its turn once, and what was passed over waits for the background job.
		$done = $this->migration->run(INF, fn (int $id, string $what) => $output->writeln('File ' . $id . ': ' . $what));
		if ($done['left']) {
			// A form was busy: the job looks at its next run, not a day after it last found nothing.
			$this->config->deleteKey(Application::APP_ID, MigrateFormsJob::DONE);
		}
		$output->writeln('Moved in: ' . $done['moved'] . ', finished: ' . $done['settled'] . ', passed over: ' . $done['skipped']);
		return 0;
	}
}
