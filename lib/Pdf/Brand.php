<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

use OCP\Defaults;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IConfig;

/** The instance's theming for the PDF header: name, colours and its own logo, if it has one. */
class Brand {
	private const DEFAULT_COLOR = '#00679e';
	private const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml'];
	private const LOGO_MAX_BYTES = 2 * 1024 * 1024;
	/** The header shows the logo 11 mm high: more pixels than this would only weigh down every PDF. */
	private const LOGO_WIDTH = 600;
	private const LOGO_HEIGHT = 260;

	public function __construct(
		private Defaults $defaults,
		private IConfig $config,
		private IAppDataFactory $appData,
		private PdfCache $cache,
	) {
	}

	/** @return array{name: string, color: string, textColor: string, logo: ?string} logo as a data URI */
	public function header(): array {
		return [
			'name' => $this->defaults->getName(),
			'color' => self::color($this->defaults->getColorPrimary(), self::DEFAULT_COLOR),
			'textColor' => self::color($this->defaults->getTextColorPrimary(), '#ffffff'),
			'logo' => $this->logo(),
		];
	}

	private function logo(): ?string {
		// Without an own logo the default Nextcloud logo would stand on someone else's document: name only.
		$mime = $this->config->getAppValue('theming', 'logoMime', '');
		if ($mime === '') {
			return null;
		}
		// Scaled once per logo the administrator set: theming's cachebuster changes with it.
		$kept = $this->cache->dir() . '/logo-' . preg_replace('/[^A-Za-z0-9]/', '', $this->config->getAppValue('theming', 'cachebuster', '0'));
		foreach (['png' => 'image/png', 'jpg' => 'image/jpeg'] as $extension => $type) {
			$bytes = is_file($kept . '.' . $extension) ? @file_get_contents($kept . '.' . $extension) : false;
			if (is_string($bytes) && $bytes !== '') {
				return 'data:' . $type . ';base64,' . base64_encode($bytes);
			}
		}
		try {
			if (method_exists($this->defaults, 'getLogoImage')) {
				/** @var array{content: string, mimeType: string}|null $image */
				$image = $this->defaults->getLogoImage();
				[$content, $mime] = $image === null ? [null, ''] : [$image['content'], $image['mimeType']];
			} else {
				$content = $this->appData->get('theming')->getFolder('global')->getFolder('images')->getFile('logo')->getContent();
			}
		} catch (\Throwable) {
			return null;
		}
		if (!is_string($content) || $content === '' || strlen($content) > self::LOGO_MAX_BYTES || !in_array($mime, self::LOGO_TYPES, true)) {
			return null;
		}
		if ($mime !== 'image/svg+xml') {
			[$content, $mime] = self::scaled($content, $mime);
			self::keep($kept . ($mime === 'image/jpeg' ? '.jpg' : '.png'), $content);
		}
		return 'data:' . $mime . ';base64,' . base64_encode($content);
	}

	/**
	 * The logo within LOGO_WIDTH by LOGO_HEIGHT pixels; one that is, or that GD cannot read, as it is.
	 *
	 * @return array{0: string, 1: string} the image and its type
	 */
	private static function scaled(string $content, string $mime): array {
		if (!function_exists('imagecreatefromstring')) {
			return [$content, $mime];
		}
		$image = @imagecreatefromstring($content);
		if ($image === false) {
			return [$content, $mime];
		}
		$width = imagesx($image);
		$height = imagesy($image);
		$scale = min(1.0, self::LOGO_WIDTH / max(1, $width), self::LOGO_HEIGHT / max(1, $height));
		if ($scale >= 1.0) {
			return [$content, $mime];
		}
		$small = imagecreatetruecolor(max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale)));
		if ($small === false) {
			return [$content, $mime];
		}
		imagealphablending($small, false);
		imagesavealpha($small, true);
		imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $width, $height);
		ob_start();
		$mime === 'image/jpeg' ? imagejpeg($small, null, 85) : imagepng($small, null, 9);
		$bytes = (string)ob_get_clean();
		return $bytes === '' ? [$content, $mime] : [$bytes, $mime === 'image/jpeg' ? 'image/jpeg' : 'image/png'];
	}

	/** Whole under its name, the logos of earlier theming gone; a cache that cannot be written only costs the scaling again. */
	private static function keep(string $path, string $bytes): void {
		foreach (glob(dirname($path) . '/logo-*') ?: [] as $old) {
			if ($old !== $path) {
				@unlink($old);
			}
		}
		$part = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (@file_put_contents($part, $bytes) === strlen($bytes)) {
			@rename($part, $path);
		} else {
			@unlink($part);
		}
	}

	private static function color(string $value, string $fallback): string {
		return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $fallback;
	}
}
