<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Security\FormPermission;

/**
 * Whether each API key and webhook of a form still works, and why not, for the share dialog: they work only
 * while their creator may use the form (H2, M29); a webhook sends responses out, so its creator must be allowed
 * to download them, as WebhookService checks.
 */
class IntegrationStatus {
	private const NEEDS = ['api_keys' => FormPermission::READ, 'webhooks' => FormPermission::DOWNLOAD];

	public function __construct(
		private AccountAccess $access,
	) {
	}

	/**
	 * Adds creator_status and creator_name to every API key and webhook in an outgoing form.
	 */
	public function annotate(int $fileId, array $form): array {
		$byCreator = [];
		foreach (self::NEEDS as $list => $permission) {
			if (!is_array($form['settings'][$list] ?? null)) {
				continue;
			}
			foreach ($form['settings'][$list] as $i => $item) {
				$creator = is_array($item) && is_string($item['created_by'] ?? null) ? $item['created_by'] : '';
				$byCreator[$permission][$creator] ??= $this->access->status($creator, $fileId, $permission);
				$form['settings'][$list][$i]['creator_status'] = $byCreator[$permission][$creator]['status'];
				$form['settings'][$list][$i]['creator_name'] = $byCreator[$permission][$creator]['displayName'];
			}
		}
		return $form;
	}
}
