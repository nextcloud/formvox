<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

use OCA\FormVox\Security\FormSecrets;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * A form's link token, link password, API keys and webhooks, kept in the database instead of the form file.
 *
 * In and out they have the shape of the form's settings, so whoever reads a form sees them where they always were.
 * Sealed values stay sealed; the token is sealed here.
 */
class SecretStore {
	public const TABLE = 'formvox_secrets';
	/** The settings that live here, never in the form file. */
	public const KEYS = ['public_token', 'share_password_hash', 'api_keys', 'webhooks'];

	private const TOKEN = 'token';
	private const PASSWORD = 'password';
	private const LISTS = ['api_keys' => 'apikey', 'webhooks' => 'webhook'];
	/** The kid of a secret a form has one of; '' would be NULL on Oracle. */
	private const SINGLE = '-';

	public function __construct(
		private IDBConnection $db,
		private FormSecrets $secrets,
	) {
	}

	/** @return array<string, mixed> the stored settings, only those the form has */
	public function load(int $formId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('kind', 'value', 'meta')->from(self::TABLE)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		$result = $qb->executeQuery();
		$settings = [];
		$lists = array_flip(self::LISTS);
		while ($row = $result->fetch()) {
			$kind = (string)$row['kind'];
			if ($kind === self::TOKEN) {
				$settings['public_token'] = $this->secrets->open((string)$row['value']);
			} elseif ($kind === self::PASSWORD) {
				$settings['share_password_hash'] = (string)$row['value'];
			} elseif (isset($lists[$kind])) {
				$item = json_decode((string)$row['meta'], true);
				if (is_array($item)) {
					$settings[$lists[$kind]][] = $item;
				}
			}
		}
		$result->closeCursor();
		return $settings;
	}

	/**
	 * Replaces what the form keeps here by $settings; a key that is missing or empty is no longer kept.
	 *
	 * @param array<string, mixed> $settings the form's settings, secrets sealed already (FormSecrets::sealSettings)
	 */
	public function save(int $formId, array $settings): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))->executeStatement();
			$token = $settings['public_token'] ?? null;
			if (is_string($token) && $token !== '') {
				$this->insert($formId, self::TOKEN, self::SINGLE, $this->secrets->seal($token));
			}
			$password = $settings['share_password_hash'] ?? null;
			if (is_string($password) && $password !== '') {
				$this->insert($formId, self::PASSWORD, self::SINGLE, $password);
			}
			foreach (self::LISTS as $key => $kind) {
				$list = $settings[$key] ?? null;
				if (!is_array($list)) {
					continue;
				}
				$i = 0;
				foreach ($list as $item) {
					// The position as its kid: an id inside may be missing or repeated.
					$this->insert($formId, $kind, (string)$i++, null, json_encode($item, ($this->db->supports4ByteText() ? JSON_UNESCAPED_UNICODE : 0) | JSON_UNESCAPED_SLASHES));
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function insert(int $formId, string $kind, string $kid, ?string $value, ?string $meta = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'form_id' => $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT),
			'kind' => $qb->createNamedParameter($kind),
			'kid' => $qb->createNamedParameter($kid),
			'value' => $qb->createNamedParameter($value),
			'meta' => $qb->createNamedParameter($meta),
		])->executeStatement();
	}
}
