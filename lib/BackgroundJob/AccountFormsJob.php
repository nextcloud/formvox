<?php

declare(strict_types=1);

namespace OCA\FormVox\BackgroundJob;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\Orphans;
use OCA\FormVox\Store\StoredForm;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Removes the forms of a deleted account, as noted while its home still existed: each one only while still bound to
 * the file it had then, and only once that file is gone, so a form moved elsewhere meanwhile lives on.
 */
class AccountFormsJob extends QueuedJob {
	/** Forms per job: the argument is kept in a column of limited length. */
	public const PER_JOB = 200;

	public function __construct(
		ITimeFactory $time,
		private FormStore $forms,
		private Orphans $orphans,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param array{forms?: list<array{0: int, 1: int}>} $argument form id and file id
	 */
	protected function run($argument): void {
		$noted = [];
		foreach (is_array($argument['forms'] ?? null) ? $argument['forms'] : [] as $pair) {
			if (!is_array($pair) || !isset($pair[0], $pair[1])) {
				continue;
			}
			$form = $this->forms->byId((int)$pair[0]);
			if ($form !== null && $form->fileId === (int)$pair[1]) {
				$noted[] = $form;
			}
		}
		if ($noted === []) {
			return;
		}
		$removed = $this->orphans->removeGone($noted);
		if ($removed !== []) {
			$this->logger->info('FormVox: removed ' . count($removed) . ' forms of a deleted account: ' . implode(', ', array_map(fn (StoredForm $form): int => $form->id, $removed)), ['app' => Application::APP_ID]);
		}
	}
}
