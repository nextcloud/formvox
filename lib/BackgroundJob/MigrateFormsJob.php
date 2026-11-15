<?php

declare(strict_types=1);

namespace OCA\FormVox\BackgroundJob;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Store\FormMigration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Moves the forms still in their file into the database, a while at each cron run; once none is left it looks once a
 * day, for a form put back from the trash or a backup.
 */
class MigrateFormsJob extends TimedJob {
	/** How long one run may move forms in: the next run goes on. */
	public const SECONDS = 120.0;
	/** Shorter than a run that leaves work takes: the cron run after it is always due, however late in its own it began. */
	private const INTERVAL = 60;
	/** When a run found nothing left to do. */
	public const DONE = 'migrate_done_at';

	public function __construct(
		ITimeFactory $time,
		private FormMigration $migration,
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	protected function run(mixed $argument): void {
		$now = $this->time->getTime();
		if ($this->config->getValueInt(Application::APP_ID, self::DONE) > $now - 86400) {
			return;
		}
		$done = $this->migration->run(self::SECONDS);
		if ($done['moved'] + $done['settled'] > 0) {
			$this->logger->info('FormVox: moved ' . $done['moved'] . ' forms into the database, finished ' . $done['settled'] . ', passed over ' . $done['skipped'], ['app' => Application::APP_ID]);
		}
		if (!$done['left']) {
			$this->config->setValueInt(Application::APP_ID, self::DONE, $now);
		}
	}
}
