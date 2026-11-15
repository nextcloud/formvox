<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * A stored form's outbox: one row per event, in the shape ResponseOutbox gives an entry in a form file, so the job
 * that sends them works the same for both.
 */
class OutboxStore {
	/** The row an entry came from; never sent. */
	public const ROW = '_row';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return list<int> the forms something waits to be sent for */
	public function waitingForms(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('form_id')->from(ResponseStore::OUTBOX);
		$result = $qb->executeQuery();
		$forms = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $forms;
	}

	/** @return list<array> the waiting entries, oldest first */
	public function entries(int $formId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(ResponseStore::OUTBOX)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		$result = $qb->executeQuery();
		$entries = [];
		while ($row = $result->fetch()) {
			$rid = (string)$row['rid'];
			$event = (string)$row['event'];
			$entry = [
				self::ROW => (int)$row['id'],
				'id' => $rid . ':' . $event,
				'response' => $rid,
				// An update keeps its own suffix in the id: the event is what comes before it.
				'event' => explode(':', $event, 2)[0],
				'todo' => ($row['todo'] ?? '') === '' ? [] : explode(',', (string)$row['todo']),
				'attempts' => (int)$row['attempts'],
			];
			if ($row['lang'] !== null && $row['lang'] !== '') {
				$entry['language'] = (string)$row['lang'];
			}
			if ((int)$row['signed_in'] === 1) {
				$entry['signedIn'] = true;
			}
			if (($row['account'] ?? '') !== '' && $row['account'] !== null) {
				$entry['account'] = (string)$row['account'];
			}
			if ($row['claimed_at'] !== null) {
				$entry['claimedAt'] = (int)$row['claimed_at'];
			}
			if ($row['not_before'] !== null) {
				$entry['notBefore'] = (int)$row['not_before'];
			}
			$entries[] = $entry;
		}
		$result->closeCursor();
		return $entries;
	}

	/**
	 * Writes back what a run made of the entries it read: a missing one is done or dropped, a changed one is updated.
	 * Rows that arrived meanwhile are left alone.
	 *
	 * @param list<array> $before the entries as read
	 * @param list<mixed> $after the entries as they are to stay
	 */
	public function apply(array $before, array $after): void {
		$kept = [];
		foreach ($after as $entry) {
			if (is_array($entry) && isset($entry[self::ROW])) {
				$kept[(int)$entry[self::ROW]] = $entry;
			}
		}
		$this->db->beginTransaction();
		try {
			foreach ($before as $entry) {
				$row = (int)$entry[self::ROW];
				$now = $kept[$row] ?? null;
				$qb = $this->db->getQueryBuilder();
				if ($now === null) {
					$qb->delete(ResponseStore::OUTBOX)->where($qb->expr()->eq('id', $qb->createNamedParameter($row, IQueryBuilder::PARAM_INT)))->executeStatement();
					continue;
				}
				if (self::state($now) === self::state($entry)) {
					continue;
				}
				$qb->update(ResponseStore::OUTBOX)
					->set('todo', $qb->createNamedParameter(implode(',', array_map('strval', (array)($now['todo'] ?? [])))))
					->set('attempts', $qb->createNamedParameter((int)($now['attempts'] ?? 0), IQueryBuilder::PARAM_INT))
					->set('claimed_at', $qb->createNamedParameter(isset($now['claimedAt']) ? (int)$now['claimedAt'] : null, isset($now['claimedAt']) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_NULL))
					->set('not_before', $qb->createNamedParameter(isset($now['notBefore']) ? (int)$now['notBefore'] : null, isset($now['notBefore']) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_NULL))
					->where($qb->expr()->eq('id', $qb->createNamedParameter($row, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @param list<string> $responseIds
	 * @return array<string, array> the responses among these that are stored, by id
	 */
	public function responses(int $formId, array $responseIds): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($responseIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('rid', 'data')->from(ResponseStore::TABLE)
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('rid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$data = $row['data'];
				if (is_resource($data)) {
					$data = stream_get_contents($data);
				}
				$response = is_string($data) ? json_decode($data, true) : null;
				if (is_array($response)) {
					$found[(string)$row['rid']] = $response;
				}
			}
			$result->closeCursor();
		}
		return $found;
	}

	private static function state(array $entry): array {
		return [(array)($entry['todo'] ?? []), (int)($entry['attempts'] ?? 0), $entry['claimedAt'] ?? null, $entry['notBefore'] ?? null];
	}
}
