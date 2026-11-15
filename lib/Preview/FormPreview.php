<?php

declare(strict_types=1);

namespace OCA\FormVox\Preview;

use OCA\FormVox\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;

/**
 * Gives a form the FormVox icon wherever Nextcloud shows a preview: the Files list and grid, search, activity, shares.
 * Nextcloud takes file type icons only from its own signed core folder (#128), so the preview is how a form gets one.
 */
class FormPreview implements IProviderV2 {
	/** Rendered from img/filetypes/application-x-fvform.svg by scripts/render-filetype-icon.sh. */
	public const ICON = __DIR__ . '/../../img/filetypes/application-x-fvform.png';
	public const ICON_SIZE = 512;

	public function getMimeType(): string {
		return Application::MIME_TYPE;
	}

	// By type, not name: a form in the trash is "x.fvform.d1729600000", an older version "x.fvform.v1729600000".
	public function isAvailable(FileInfo $file): bool {
		return $file->getMimetype() === Application::MIME_TYPE;
	}

	/**
	 * The icon, not the form: nothing is read, and the PNG's transparency keeps the dark theme dark.
	 */
	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$data = @file_get_contents(self::ICON);
		if ($data === false) {
			return null;
		}
		$image = new Image();
		if (!$image->loadFromData($data)) {
			return null;
		}
		// Nextcloud derives every size from this one; an admin may have set a smaller maximum.
		$size = min(self::ICON_SIZE, $maxX, $maxY);
		if ($size < self::ICON_SIZE) {
			$image->resize($size);
		}
		return $image->valid() ? $image : null;
	}
}
