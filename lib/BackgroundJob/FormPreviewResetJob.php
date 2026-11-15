<?php

declare(strict_types=1);

namespace OCA\FormVox\BackgroundJob;

use OCA\FormVox\Preview\FormPreviewReset;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Once after an update that changed the form icon: forms show the new icon instead of the thumbnail stored earlier.
 */
class FormPreviewResetJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private FormPreviewReset $reset,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$this->reset->resetAll();
	}
}
