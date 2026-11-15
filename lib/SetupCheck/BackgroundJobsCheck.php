<?php

declare(strict_types=1);

namespace OCA\FormVox\SetupCheck;

use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * FormVox sends confirmation emails, webhooks and notifications from background jobs (7a, 3a-4); without the system
 * cron they wait for a visitor (AJAX) or an outside caller (Webcron), so the admin is told.
 */
class BackgroundJobsCheck implements ISetupCheck {
	public function __construct(
		private IAppConfig $appConfig,
		private IL10N $l,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		// TRANSLATORS: Name of a check in the admin overview about how FormVox sends emails, webhooks and notifications
		return $this->l->t('FormVox emails, webhooks and notifications');
	}

	public function run(): SetupResult {
		if ($this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax') === 'cron') {
			return SetupResult::success();
		}
		// TRANSLATORS: Warning in the admin overview; "Cron" is the name of the background jobs setting in Nextcloud
		return SetupResult::warning($this->l->t('FormVox sends confirmation emails, webhooks and notifications about new responses in the background. Without the system cron they can arrive late. Set background jobs to "Cron".'));
	}
}
