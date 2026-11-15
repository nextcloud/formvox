<?php

declare(strict_types=1);

namespace OCA\FormVox\Command;

use OCA\FormVox\Exception\OrphanException;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\Orphans as OrphanForms;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Shows the forms whose file is gone, which the daily job removes after 30 days, or removes one now. */
class Orphans extends Command {
	public function __construct(
		private FormStore $forms,
		private OrphanForms $orphans,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('formvox:orphans')
			->setDescription('Show the forms whose file is gone; they are removed 30 days later')
			->addOption('remove', null, InputOption::VALUE_REQUIRED, 'Remove this orphan now, with its responses and files');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$remove = $input->getOption('remove');
		if ($remove !== null) {
			try {
				$this->orphans->remove((int)$remove);
			} catch (OrphanException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
			$output->writeln('Removed form ' . (int)$remove . ' with its responses and files.');
			return 0;
		}
		$orphans = $this->forms->orphans();
		if ($orphans === []) {
			$output->writeln('No forms have lost their file.');
			return 0;
		}
		$table = new Table($output);
		$table->setHeaders(['Form', 'Uuid', 'Last file', 'Gone since', 'Removed on', 'Responses']);
		foreach ($orphans as $form) {
			$table->addRow([$form->id, $form->uuid, $form->fileId, date('Y-m-d', (int)$form->orphanedAt),
				date('Y-m-d', (int)$form->orphanedAt + OrphanForms::GRACE_SECONDS), $form->count]);
		}
		$table->render();
		return 0;
	}
}
