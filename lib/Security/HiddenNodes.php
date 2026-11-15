<?php

declare(strict_types=1);

namespace OCA\FormVox\Security;

use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use Throwable;

/**
 * Whether a file or folder lies in one of a form's own folders, as its owner sees it: a recipient may have renamed the
 * share it reached it through.
 */
class HiddenNodes {
	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	public function isHidden(FileInfo $node): bool {
		$path = $node->getPath();
		if (HiddenPath::isHidden(self::belowUser($path))) {
			return true;
		}

		$owner = $node->getOwner()?->getUID();
		$id = $node->getId();
		if ($owner === null || $id === null || str_starts_with($path, '/' . $owner . '/')) {
			return false;
		}
		try {
			$own = $this->rootFolder->getUserFolder($owner)->getFirstNodeById($id);
		} catch (Throwable) {
			return false;
		}
		return $own !== null && HiddenPath::isHidden(self::belowUser($own->getPath()));
	}

	/** A user id is a name the user picked, not one of a form's folders. */
	private static function belowUser(string $path): string {
		$segments = HiddenPath::segments($path);
		array_shift($segments);
		return implode('/', $segments);
	}
}
