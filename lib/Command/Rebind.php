<?php

declare(strict_types=1);

namespace OCA\FormVox\Command;

use OCA\FormVox\Exception\OrphanException;
use OCA\FormVox\Store\Orphans;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Binds a form's responses to a file by hand: for a form whose file got a new id elsewhere than where it was, which
 * FormVox does not do on its own.
 */
class Rebind extends Command {
	public function __construct(
		private Orphans $orphans,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('formvox:rebind')
			->setDescription('Bind a form, with its responses, to a file (see formvox:orphans)')
			->addArgument('file-id', InputArgument::REQUIRED, 'The file id of the form file')
			->addArgument('form-id', InputArgument::REQUIRED, 'The form, as formvox:orphans shows it');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$fileId = (int)$input->getArgument('file-id');
		$formId = (int)$input->getArgument('form-id');
		try {
			$this->orphans->rebind($formId, $fileId);
		} catch (OrphanException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Form ' . $formId . ' is bound to file ' . $fileId . '; its responses show in that file.');
		return 0;
	}
}
