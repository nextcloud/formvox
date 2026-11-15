<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

/**
 * Who created a form (`permissions.owner`) and whether Nextcloud still lets them open it (#146).
 *
 * Nextcloud has no "created by", and in a team folder getOwner() is whoever is looking.
 */
class FormCreatorService {
	public const STATUS_OK = AccountAccess::STATUS_OK;
	/** No creator recorded (hand-made or foreign file). */
	public const STATUS_NONE = AccountAccess::STATUS_NONE;
	public const STATUS_DELETED = AccountAccess::STATUS_DELETED;
	public const STATUS_DISABLED = AccountAccess::STATUS_DISABLED;
	public const STATUS_NO_ACCESS = AccountAccess::STATUS_NO_ACCESS;

	public function __construct(
		private AccountAccess $access,
	) {
	}

	public static function getCreatorId(array $form): ?string {
		$id = $form['permissions']['owner'] ?? null;
		return is_string($id) && $id !== '' ? $id : null;
	}

	public static function withCreator(array $form, ?string $userId): array {
		$permissions = is_array($form['permissions'] ?? null) ? $form['permissions'] : [];
		if ($userId === null || $userId === '') {
			unset($permissions['owner']);
		} else {
			$permissions['owner'] = $userId;
		}
		$form['permissions'] = $permissions;
		return $form;
	}

	/**
	 * @return array{id: ?string, displayName: ?string, status: string}
	 */
	public function getCreator(int $fileId, array $form): array {
		$id = self::getCreatorId($form);
		$status = $this->access->status($id, $fileId);
		return ['id' => $id, 'displayName' => $status['displayName'], 'status' => $status['status']];
	}
}
