<?php

declare(strict_types=1);

namespace OCA\FormVox\BackgroundJob;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Store\Orphans;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Daily: forms whose file is gone become orphans, and orphans of more than 30 days are removed (Orphans). */
class OrphanSweepJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Orphans $orphans,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		$done = $this->orphans->sweep();
		if ($done['orphaned'] + $done['found'] + $done['removed'] > 0) {
			$this->logger->info('FormVox: ' . $done['orphaned'] . ' forms lost their file, ' . $done['found'] . ' found it again, ' . $done['removed'] . ' orphans removed', ['app' => Application::APP_ID]);
		}
	}
}
