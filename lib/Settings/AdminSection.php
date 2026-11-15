<?php

declare(strict_types=1);

namespace OCA\FormVox\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'formvox';
	}

	public function getName(): string {
		// The product name, not translated.
		return 'FormVox';
	}

	public function getPriority(): int {
		return 90;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('formvox', 'app-dark.svg');
	}
}
