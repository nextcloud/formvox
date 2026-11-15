<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

use OCP\IConfig;
use OCP\ITempManager;

/** A folder this process owns alone, kept between renders: dompdf's font metrics, and the header's logo scaled once. */
class PdfCache {
	public function __construct(
		private ITempManager $temp,
		private IConfig $config,
	) {
	}

	/** dompdf writes through what it finds in its cache, so only a folder this process owns alone is used. */
	public function dir(): string {
		$cache = $this->temp->getTempBaseDir() . '/formvox-pdf-' . preg_replace('/[^A-Za-z0-9]/', '', $this->config->getSystemValueString('instanceid', 'default'));
		if (!is_dir($cache)) {
			@mkdir($cache, 0700);
		}
		clearstatcache(true, $cache);
		$owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
		if (is_dir($cache) && !is_link($cache) && ($owner === null || fileowner($cache) === $owner) && (fileperms($cache) & 0022) === 0) {
			return $cache;
		}
		$fresh = $this->temp->getTemporaryFolder();
		if ($fresh === false) {
			throw new \RuntimeException('No temporary folder to make a PDF in');
		}
		return rtrim($fresh, '/');
	}
}
