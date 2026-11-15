<?php

declare(strict_types=1);

namespace OCA\FormVox\Exception;

use OCP\AppFramework\Http\Response;

/** Carries the answer files_sharing gives for a missing item, for one that lies in a form's own folders. */
class HiddenShareItemException extends \Exception {
	public function __construct(
		public readonly Response $response,
	) {
		parent::__construct('Item in a form\'s own folders');
	}
}
