<?php

declare(strict_types=1);

namespace OCA\FormVox\SetupCheck;

use OCA\FormVox\Pdf\PdfMailPolicy;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * The PDF for anonymous respondents is limited per address and per form; those limits need a count every process
 * shares, so without Redis or Memcached it cannot be switched on. Told as Nextcloud tells about its memory cache.
 */
class PdfConfirmationCheck implements ISetupCheck {
	public function __construct(
		private PdfMailPolicy $policy,
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		// TRANSLATORS: Name of a check in the admin overview about sending the PDF of a response to anonymous respondents
		return $this->l->t('FormVox PDF for anonymous respondents');
	}

	public function run(): SetupResult {
		if ($this->policy->sharedCache()) {
			return SetupResult::success();
		}
		return SetupResult::info(
			// TRANSLATORS: Note in the admin overview; Redis and Memcached are names of shared memory caches for Nextcloud
			$this->l->t('Sending the PDF to anonymous respondents needs a shared memory cache (Redis or Memcached) to keep its limits, so it cannot be switched on.'),
			$this->urlGenerator->linkToDocs('admin-performance'),
		);
	}
}
