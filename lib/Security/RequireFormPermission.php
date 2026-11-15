<?php

declare(strict_types=1);

namespace OCA\FormVox\Security;

use Attribute;

/**
 * Permission a controller method needs on the form in its `fileId`; enforced by FormAccessMiddleware.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RequireFormPermission {
	public function __construct(
		public readonly string $permission,
	) {
		if (!in_array($permission, FormPermission::ALL, true)) {
			throw new \InvalidArgumentException('Unknown form permission: ' . $permission);
		}
	}
}
