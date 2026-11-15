<?php

declare(strict_types=1);

namespace OCA\FormVox\Command;

use OCA\FormVox\Service\FormLock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Frees a form whose writer was killed while holding it, before the lock runs out by itself (two minutes).
 */
class Unlock extends Command {
	public function __construct(
		private FormLock $lock,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('formvox:unlock')
			->setDescription('Free a form whose writer was killed while saving it')
			->addArgument('fileId', InputArgument::REQUIRED, 'The form\'s file id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$fileId = (int)$input->getArgument('fileId');
		$freed = false;
		$held = false;
		foreach (['form', 'effects'] as $purpose) {
			$freed = $this->lock->unlock($fileId, $purpose) || $freed;
			$held = $this->lock->isHeld($fileId, $purpose) || $held;
		}
		// Said only once it holds: a release that failed is logged, and a new writer may have taken it since.
		if ($held) {
			$output->writeln("<error>Form $fileId is still locked: it could not be freed (see the log), or a new writer has it.</error>");
			return 1;
		}
		$output->writeln($freed ? "Form $fileId is free again." : "Form $fileId was not locked.");
		return 0;
	}
}
