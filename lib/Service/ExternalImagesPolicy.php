<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;

/**
 * Opt-in for images from other websites in form descriptions (Markdown).
 * Loosens the CSP of FormVox's own pages only, never Nextcloud's default policy.
 */
class ExternalImagesPolicy {
	private const CONFIG_KEY = 'allow_external_images';

	public function __construct(
		private IConfig $config,
	) {
	}

	public function isEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, 'false') === 'true';
	}

	public function setEnabled(bool $enabled): void {
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_KEY, $enabled ? 'true' : 'false');
	}

	public function applyTo(TemplateResponse $response): void {
		if ($this->isEnabled()) {
			$response->getContentSecurityPolicy()->addAllowedImageDomain('https:');
		}
	}
}
