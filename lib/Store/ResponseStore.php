<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\ResponseOutbox;
use OCA\FormVox\Service\ResponseRules;
use OCA\FormVox\Service\SubmissionIds;
use OCA\FormVox\Service\UploadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;

/**
 * A stored form's responses, one row each, with the keys and counters its rules need.
 *
 * Every change starts on the form's own row (FormStore), which the database keeps locked until the commit: changes to
 * one form go one after the other, so the form's rules are applied, as ever, to counts nobody else is changing.
 */
class ResponseStore {
	public const TABLE = 'formvox_resp';
	public const KEYS = 'formvox_keys';
	public const COUNTS = 'formvox_counts';
	public const OUTBOX = 'formvox_outbox';

	public const SUBMISSION = 's';
	public const PERSON = 'p';
	public const UPLOAD = 'u';

	/** Starts a page cursor: no response id does. */
	private const CURSOR = '~';
	private const RID = 64;
	private const KEY = 128;
	private const QID = 64;
	private const VAL = 255;
	/** A deadlock or lock timeout is tried again this often, from the start of the transaction. */
	private const TRIES = 5;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
		private IndexService $index,
		private ResponseRules $rules,
	) {
	}

	/**
	 * Stores a new response, held to the form's rules on what is stored now.
	 *
	 * @param array|null $outbox what it sets off (ResponseOutbox::add), null for nothing
	 * @param string|null $submissionId the fill as recognised: one stored under it before is returned instead
	 * @return array{0: array, 1: bool} the stored response, and whether it is new
	 * @throws \OCA\FormVox\Service\ResponseRefusedException|\OCA\FormVox\Service\InvalidAnswerException
	 */
	public function add(StoredForm $stored, array $form, array $response, ?array $outbox, ?string $submissionId, bool $applyRules = true): array {
		$rid = (string)$response['id'];
		try {
			return $this->transaction(fn (): array => $this->insert($stored, $form, $response, $outbox, $submissionId, $applyRules, $rid));
		} catch (StoredBefore $seen) {
			return [$seen->response, false];
		}
	}

	/** @return array{0: array, 1: bool} */
	private function insert(StoredForm $stored, array $form, array $response, ?array $outbox, ?string $submissionId, bool $applyRules, string $rid): array {
		$count = $this->lockForm($stored->id, 1);
		if ($submissionId !== null) {
			$seen = $this->byKey($stored->id, self::SUBMISSION, $submissionId);
			if ($seen !== null) {
				throw new StoredBefore($seen);
			}
		}
		if ($applyRules) {
			$form['_index'] = $this->index($stored->id, $form, $count - 1, $response['respondent'] ?? null);
			$this->rules->assertNew($form, $response);
		}
		$this->insertResponse($stored->id, $response);
		if ($submissionId !== null) {
			$this->addKey($stored->id, self::SUBMISSION, $submissionId, $rid);
		}
		$this->addResponseKeys($stored->id, $response);
		$this->count($stored->id, $this->index->answerCounts($form, $response), 1);
		if ($outbox !== null) {
			$this->addOutbox($stored->id, $outbox);
		}
		return [$response, true];
	}

	/**
	 * Changes one response, held to the form's rules; its own earlier choice does not count against it.
	 *
	 * @param callable(array): array $change gets the stored response, returns the new one
	 * @param callable(array): ?array $outbox what the change sets off
	 * @return array{0: array, 1: array} the response before and after
	 * @throws NotFoundException|\OCA\FormVox\Service\ResponseRefusedException|\OCA\FormVox\Service\InvalidAnswerException
	 */
	public function change(StoredForm $stored, array $form, string $responseId, callable $change, callable $outbox): array {
		return $this->transaction(function () use ($stored, $form, $responseId, $change, $outbox): array {
			$count = $this->lockForm($stored->id, 0);
			$old = $this->get($stored->id, $responseId) ?? throw new NotFoundException('Response not found');
			$new = $change($old);
			$form['_index'] = $this->index($stored->id, $form, $count);
			$this->rules->assertChange($form, $old, $new);
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('data', $qb->createNamedParameter($this->encode($new)))
				->set('updated', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($stored->id, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('rid', $qb->createNamedParameter(self::rid($responseId))))
				->executeStatement();
			$this->count($stored->id, $this->index->answerCounts($form, $old), -1);
			$this->count($stored->id, $this->index->answerCounts($form, $new), 1);
			$this->deleteKeys($stored->id, $responseId, self::UPLOAD);
			$this->addResponseKeys($stored->id, $new, true);
			$entry = $outbox($new);
			if ($entry !== null) {
				$this->addOutbox($stored->id, $entry);
			}
			return [$old, $new];
		});
	}

	/**
	 * Removes one response with its keys and counts, and what still waited to be sent for it (forgetOutbox).
	 *
	 * @param callable(array): ?array $outbox what the removal sets off
	 * @return array the removed response
	 * @throws NotFoundException
	 */
	public function remove(StoredForm $stored, array $form, string $responseId, callable $outbox): array {
		return $this->transaction(function () use ($stored, $form, $responseId, $outbox): array {
			$this->lockForm($stored->id, -1);
			$removed = $this->get($stored->id, $responseId) ?? throw new NotFoundException('Response not found');
			$this->deleteWhere(self::TABLE, $stored->id, $responseId);
			$this->deleteKeys($stored->id, $responseId);
			$this->forgetOutbox($stored->id, $responseId);
			$this->count($stored->id, $this->index->answerCounts($form, $removed), -1);
			$entry = $outbox($removed);
			if ($entry !== null) {
				$this->addOutbox($stored->id, $entry);
			}
			return $removed;
		});
	}

	/**
	 * Removes every response, its keys and counts, and what still waited to be sent (forgetOutbox).
	 *
	 * @param callable(array): ?array $outbox what each removal sets off, given the removed response's id only
	 * @return list<string> the removed responses' ids
	 */
	public function removeAll(StoredForm $stored, callable $outbox): array {
		return $this->transaction(function () use ($stored, $outbox): array {
			$this->lockForm($stored->id, null);
			$qb = $this->db->getQueryBuilder();
			$qb->select('rid')->from(self::TABLE)
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($stored->id, IQueryBuilder::PARAM_INT)))
				->orderBy('id');
			$ids = array_map('strval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
			foreach ([self::TABLE, self::KEYS, self::COUNTS] as $table) {
				$this->deleteWhere($table, $stored->id);
			}
			$this->forgetOutbox($stored->id, null);
			foreach ($ids as $id) {
				$entry = $outbox(['id' => $id]);
				if ($entry !== null) {
					$this->addOutbox($stored->id, $entry);
				}
			}
			return $ids;
		});
	}

	/**
	 * Makes a form's rows hold what a form file holds: its responses with the keys they bring, the counts, what waits to
	 * be sent and the submissions it recognises. By difference, so a run after one that copied ahead only adds, changes
	 * or removes what changed in the file since.
	 *
	 * @param array $document the form file's contents
	 * @param (callable(): void)|null $within run in the same transaction after the rows: the switch to the database
	 * @param bool $rows false when the file did not change since the rows were copied: only $within then
	 * @return bool false when the form is no draft any more: switched by another run, whose rows a slower copy of the
	 *              file must not write over
	 */
	public function mirror(int $formId, array $document, ?callable $within = null, bool $rows = true): bool {
		return $this->transaction(function () use ($formId, $document, $within, $rows): bool {
			$qb = $this->db->getQueryBuilder();
			$qb->update(FormStore::TABLE)
				->set('n', $qb->createFunction($qb->getColumnName('n')))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(FormStore::DRAFT, IQueryBuilder::PARAM_INT)));
			if ($qb->executeStatement() === 0) {
				return false;
			}
			if (!$rows) {
				if ($within !== null) {
					$within();
				}
				return true;
			}
			$responses = self::responsesOf($document);
			$this->mirrorResponses($formId, $responses);
			$this->mirrorKeys($formId, $document, $responses);
			$this->deleteWhere(self::COUNTS, $formId);
			$totals = [];
			foreach ($responses as $response) {
				foreach ($this->index->answerCounts($document, $response) as $qid => $values) {
					foreach ($values as $value => $n) {
						$totals[$qid][$value] = ($totals[$qid][$value] ?? 0) + $n;
					}
				}
			}
			$this->count($formId, $totals, 1);
			$this->mirrorOutbox($formId, $document);
			$qb = $this->db->getQueryBuilder();
			$qb->update(FormStore::TABLE)
				->set('n', $qb->createNamedParameter(count($responses), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			if ($within !== null) {
				$within();
			}
			return true;
		});
	}

	/** @return array<string, array> a form file's responses by their stored id, in its order; a repeated id counts once */
	private static function responsesOf(array $document): array {
		$responses = [];
		foreach (is_array($document['responses'] ?? null) ? $document['responses'] : [] as $response) {
			if (is_array($response) && is_scalar($response['id'] ?? null) && (string)$response['id'] !== '') {
				$responses[self::rid((string)$response['id'])] ??= $response;
			}
		}
		return $responses;
	}

	/** @param array<string, array> $responses */
	private function mirrorResponses(int $formId, array $responses): void {
		$have = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('rid', 'data')->from(self::TABLE)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$data = $row['data'];
			$have[(string)$row['rid']] = md5(is_resource($data) ? (string)stream_get_contents($data) : (string)$data);
		}
		$result->closeCursor();
		foreach ($responses as $rid => $response) {
			$json = $this->encode($response);
			if (!isset($have[$rid])) {
				$this->insertResponse($formId, $response);
			} elseif ($have[$rid] !== md5($json)) {
				$qb = $this->db->getQueryBuilder();
				$qb->update(self::TABLE)
					->set('data', $qb->createNamedParameter($json))
					->set('updated', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->eq('rid', $qb->createNamedParameter($rid)))
					->executeStatement();
			}
			unset($have[$rid]);
		}
		foreach (array_keys($have) as $rid) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('rid', $qb->createNamedParameter((string)$rid)))
				->executeStatement();
		}
	}

	/**
	 * The keys the responses bring, the first response keeping a person's, and the submissions the file recognises.
	 *
	 * @param array<string, array> $responses
	 */
	private function mirrorKeys(int $formId, array $document, array $responses): void {
		$wanted = [];
		foreach ($responses as $rid => $response) {
			$person = self::personKey(is_array($response['respondent'] ?? null) ? $response['respondent'] : []);
			if ($person !== null) {
				$wanted[self::PERSON . "\0" . StoreKey::fit($person, self::KEY)] ??= $rid;
			}
			foreach (self::uploadGroupsOf($response) as $group) {
				$wanted[self::UPLOAD . "\0" . self::uploadKey($group, $rid)] = $rid;
			}
		}
		foreach (is_array($document[SubmissionIds::KEY] ?? null) ? $document[SubmissionIds::KEY] : [] as $submission => $responseId) {
			$rid = is_scalar($responseId) ? self::rid((string)$responseId) : '';
			if (isset($responses[$rid])) {
				$wanted[self::SUBMISSION . "\0" . StoreKey::fit((string)$submission, self::KEY)] ??= $rid;
			}
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('kind', 'k', 'rid')->from(self::KEYS)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$gone = [];
		while ($row = $result->fetch()) {
			$key = $row['kind'] . "\0" . $row['k'];
			if (($wanted[$key] ?? null) === (string)$row['rid']) {
				unset($wanted[$key]);
			} else {
				$gone[] = [(string)$row['kind'], (string)$row['k']];
			}
		}
		$result->closeCursor();
		foreach ($gone as [$kind, $k]) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::KEYS)
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
				->andWhere($qb->expr()->eq('k', $qb->createNamedParameter($k)))
				->executeStatement();
		}
		foreach ($wanted as $key => $rid) {
			[$kind, $k] = explode("\0", $key, 2);
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::KEYS)->values([
				'form_id' => $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT),
				'kind' => $qb->createNamedParameter($kind),
				'k' => $qb->createNamedParameter($k),
				'rid' => $qb->createNamedParameter((string)$rid),
				'created' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
	}

	/** What waits to be sent, with what runs made of it so far: by entry, so an unchanged one is not written again. */
	private function mirrorOutbox(int $formId, array $document): void {
		$wanted = [];
		foreach (is_array($document[ResponseOutbox::KEY] ?? null) ? $document[ResponseOutbox::KEY] : [] as $entry) {
			if (is_array($entry) && is_string($entry['id'] ?? null)) {
				$wanted[$entry['id']] = $entry;
			}
		}
		$state = static fn (array $entry): string => json_encode([array_values((array)($entry['todo'] ?? [])), (int)($entry['attempts'] ?? 0),
			$entry['claimedAt'] ?? null, $entry['notBefore'] ?? null, $entry['language'] ?? null, !empty($entry['signedIn']), $entry['account'] ?? null]);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'rid', 'event', 'todo', 'attempts', 'claimed_at', 'not_before', 'lang', 'signed_in', 'account')->from(self::OUTBOX)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$gone = [];
		while ($row = $result->fetch()) {
			$id = $row['rid'] . ':' . $row['event'];
			$have = ['todo' => ($row['todo'] ?? '') === '' ? [] : explode(',', (string)$row['todo']), 'attempts' => (int)$row['attempts'],
				'claimedAt' => $row['claimed_at'] === null ? null : (int)$row['claimed_at'], 'notBefore' => $row['not_before'] === null ? null : (int)$row['not_before'],
				'language' => ($row['lang'] ?? '') === '' ? null : (string)$row['lang'], 'signedIn' => (int)$row['signed_in'] === 1,
				'account' => ($row['account'] ?? '') === '' ? null : (string)$row['account']];
			if (isset($wanted[$id]) && $state($wanted[$id]) === $state($have)) {
				unset($wanted[$id]);
			} else {
				$gone[] = (int)$row['id'];
			}
		}
		$result->closeCursor();
		foreach (array_chunk($gone, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::OUTBOX)->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		foreach ($wanted as $entry) {
			$this->addOutbox($formId, $entry);
		}
	}

	/**
	 * Runs $work while the form's row is held, so no response is stored, changed or removed meanwhile.
	 *
	 * @param callable(): void $work
	 */
	public function whileHeld(StoredForm $stored, callable $work): void {
		$this->transaction(function () use ($stored, $work): void {
			$this->lockForm($stored->id, 0);
			$work();
		});
	}

	/** Counts the responses again, as the form's questions are now: after a question changed its type, or a repair. */
	public function recount(StoredForm $stored, array $form): void {
		$this->transaction(function () use ($stored, $form): void {
			$this->lockForm($stored->id, 0);
			$this->deleteWhere(self::COUNTS, $stored->id);
			$total = [];
			$n = 0;
			foreach ($this->each($stored->id) as $response) {
				$n++;
				foreach ($this->index->answerCounts($form, $response) as $qid => $values) {
					foreach ($values as $value => $count) {
						$total[$qid][$value] = ($total[$qid][$value] ?? 0) + $count;
					}
				}
			}
			$this->count($stored->id, $total, 1);
			$qb = $this->db->getQueryBuilder();
			$qb->update(FormStore::TABLE)
				->set('n', $qb->createNamedParameter($n, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($stored->id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		});
	}

	public function get(int $formId, string $responseId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('data')->from(self::TABLE)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rid', $qb->createNamedParameter(self::rid($responseId))));
		$data = $qb->executeQuery()->fetchOne();
		return $data === false ? null : self::decode($data);
	}

	/**
	 * A page of responses, oldest first, and the cursor of the next. Paged by row, so a response removed since, or one
	 * that does not decode, does not end the paging early.
	 *
	 * @param string|null $after the cursor an earlier page gave (next), or the id of a response
	 * @return array{responses: list<array>, next: ?string} next is null on the last page, and without a limit
	 */
	public function page(int $formId, ?int $limit = null, ?string $after = null): array {
		$last = $after === null ? 0 : $this->rowAfter($formId, $after);
		if ($last === null) {
			return ['responses' => [], 'next' => null];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'data')->from(self::TABLE)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($last, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		$responses = [];
		foreach ($rows as $row) {
			$response = self::decode($row['data']);
			if ($response !== null) {
				$responses[] = $response;
			}
		}
		$next = $limit !== null && count($rows) === $limit ? self::CURSOR . (int)end($rows)['id'] : null;
		return ['responses' => $responses, 'next' => $next];
	}

	/** The row a page starts after; null when $after names no stored response. */
	private function rowAfter(int $formId, string $after): ?int {
		if (preg_match('/^' . preg_quote(self::CURSOR, '/') . '(\d+)$/', $after, $match) === 1) {
			return (int)$match[1];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rid', $qb->createNamedParameter(self::rid($after))));
		$id = $qb->executeQuery()->fetchOne();
		return $id === false ? null : (int)$id;
	}

	/** @return list<array> every response, oldest first */
	public function all(int $formId): array {
		return $this->page($formId)['responses'];
	}

	/**
	 * Every response, oldest first, a page at a time: who reads them all holds one page, not the whole form. Paged by
	 * row, so a response removed meanwhile, or one that does not decode, does not end it early.
	 *
	 * @return \Generator<int, array>
	 */
	public function each(int $formId, int $size = 500): \Generator {
		$last = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'data')->from(self::TABLE)
				->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($last, IQueryBuilder::PARAM_INT)))
				->orderBy('id')
				->setMaxResults($size);
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
			foreach ($rows as $row) {
				$last = (int)$row['id'];
				$response = self::decode($row['data']);
				if ($response !== null) {
					yield $response;
				}
			}
		} while (count($rows) === $size);
	}

	/**
	 * What the form's rules and its public page read of the index, from the counters: how many responses, how many chose
	 * each option, and whether this respondent answered before.
	 */
	public function index(int $formId, array $form, ?int $count = null, ?array $respondent = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('qid', 'val', 'n')->from(self::COUNTS)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			// PostgreSQL returns an updated row wherever it landed; the counts keep one order whatever the database.
			->orderBy('qid')->addOrderBy('val');
		$stored = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$stored[(string)$row['qid']][(string)$row['val']] = (int)$row['n'];
		}
		$result->closeCursor();
		// Keyed as the form names them: a long id or option value is stored as its hash.
		$names = [];
		foreach (is_array($form['questions'] ?? null) ? $form['questions'] : [] as $question) {
			$values = [];
			foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
				$value = $option['value'] ?? $option['id'] ?? null;
				if (is_scalar($value)) {
					$values[StoreKey::fit((string)$value, self::VAL)] = (string)$value;
				}
			}
			$names[StoreKey::fit((string)($question['id'] ?? ''), self::QID)] = [(string)($question['id'] ?? ''), $values];
		}
		$counts = [];
		foreach ($stored as $qid => $values) {
			[$name, $optionNames] = $names[$qid] ?? [$qid, []];
			foreach ($values as $value => $n) {
				if ($n > 0) {
					$counts[$name][$optionNames[$value] ?? $value] = $n;
				}
			}
		}
		$index = ['response_count' => $count ?? $this->countOf($formId), 'answer_counts' => $counts, 'fingerprints' => [], 'user_ids' => []];
		$person = self::personKey($respondent ?? []);
		if ($person !== null && $this->hasKey($formId, self::PERSON, $person)) {
			if (($respondent['type'] ?? null) === 'user') {
				$index['user_ids'][(string)$respondent['user_id']] = 0;
			} else {
				$index['fingerprints'][(string)$respondent['fingerprint']] = 0;
			}
		}
		return $index;
	}

	/** @return array<string, true> the upload folders the stored responses name */
	public function uploadGroups(int $formId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('k')->from(self::KEYS)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(self::UPLOAD)));
		$groups = [];
		foreach ($qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) as $k) {
			$groups[explode('/', (string)$k, 2)[0]] = true;
		}
		return $groups;
	}

	/**
	 * The stored responses that name any of these upload folders, but $exceptResponse: what a removal must leave alone.
	 *
	 * @param list<string> $groups
	 * @return list<array>
	 */
	public function namingUploads(int $formId, array $groups, ?string $exceptResponse = null): array {
		if ($groups === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('k.rid', 'r.data')->from(self::KEYS, 'k')
			->innerJoin('k', self::TABLE, 'r', $qb->expr()->andX($qb->expr()->eq('r.form_id', 'k.form_id'), $qb->expr()->eq('r.rid', 'k.rid')))
			->where($qb->expr()->eq('k.form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('k.kind', $qb->createNamedParameter(self::UPLOAD)))
			->andWhere($qb->expr()->orX(...array_map(fn (string $group) => $qb->expr()->like('k.k', $qb->createNamedParameter($this->db->escapeLikeParameter($group) . '/%')), $groups)));
		if ($exceptResponse !== null) {
			$qb->andWhere($qb->expr()->neq('k.rid', $qb->createNamedParameter(self::rid($exceptResponse))));
		}
		$responses = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$responses[(string)$row['rid']] ??= self::decode($row['data']);
		}
		$result->closeCursor();
		return array_values(array_filter($responses));
	}

	public function countOf(int $formId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('n')->from(FormStore::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Takes the form's row for this transaction and moves its count.
	 *
	 * @param int|null $by added to the count; null sets it to 0
	 * @return int the count after
	 */
	private function lockForm(int $formId, ?int $by): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(FormStore::TABLE)
			->set('n', $by === null ? $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT) : $qb->createFunction($qb->getColumnName('n') . ($by < 0 ? ' - ' . -$by : ' + ' . $by)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() === 0) {
			throw new NotFoundException('Form ' . $formId . ' has no row');
		}
		return $this->countOf($formId);
	}

	private function insertResponse(int $formId, array $response): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'form_id' => $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT),
			'rid' => $qb->createNamedParameter(self::rid((string)$response['id'])),
			'created' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
			'data' => $qb->createNamedParameter($this->encode($response)),
		])->executeStatement();
	}

	/** Who answered and which upload folders the response names; $uploadsOnly after a change. */
	private function addResponseKeys(int $formId, array $response, bool $uploadsOnly = false): void {
		$rid = (string)$response['id'];
		$person = self::personKey(is_array($response['respondent'] ?? null) ? $response['respondent'] : []);
		if (!$uploadsOnly && $person !== null) {
			// Also where several answers are allowed, so a form that stops allowing them knows who answered.
			$this->addKey($formId, self::PERSON, $person, $rid);
		}
		foreach (self::uploadGroupsOf($response) as $group) {
			$this->addKey($formId, self::UPLOAD, self::uploadKey($group, self::rid($rid)), $rid);
		}
	}

	/** A key per folder and response, so a folder two responses name is each one's; never hashed: at most 101 characters. */
	private static function uploadKey(string $group, string $rid): string {
		return $group . '/' . $rid;
	}

	private function addKey(int $formId, string $kind, string $key, string $rid): void {
		// The first response with a key keeps it: a second one with the same key is allowed only where the rules said so.
		$this->db->insertIgnoreConflict(self::KEYS, [
			'form_id' => $formId,
			'kind' => $kind,
			'k' => StoreKey::fit($key, self::KEY),
			'rid' => self::rid($rid),
			'created' => $this->time->getTime(),
		]);
	}

	private function hasKey(int $formId, string $kind, string $key): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('rid')->from(self::KEYS)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('k', $qb->createNamedParameter(StoreKey::fit($key, self::KEY))));
		return $qb->executeQuery()->fetchOne() !== false;
	}

	/** The response stored for this submission, or null when it is new (or that response was removed since). */
	public function bySubmission(StoredForm $stored, string $submissionId): ?array {
		return $this->byKey($stored->id, self::SUBMISSION, $submissionId);
	}

	private function byKey(int $formId, string $kind, string $key): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('rid')->from(self::KEYS)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('k', $qb->createNamedParameter(StoreKey::fit($key, self::KEY))));
		$rid = $qb->executeQuery()->fetchOne();
		return $rid === false ? null : $this->get($formId, (string)$rid);
	}

	/** @param string|null $kind null for every kind */
	private function deleteKeys(int $formId, string $responseId, ?string $kind = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::KEYS)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rid', $qb->createNamedParameter(self::rid($responseId))));
		if ($kind !== null) {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		$qb->executeStatement();
	}

	/** @param array<string, array<string, int>> $counts */
	private function count(int $formId, array $counts, int $sign): void {
		foreach ($counts as $qid => $values) {
			foreach ($values as $value => $n) {
				if ((string)$value === '') {
					// Oracle keeps an empty string as NULL, which the key may not be; an empty answer counts for nothing.
					continue;
				}
				$by = $sign * $n;
				$qb = $this->db->getQueryBuilder();
				$qb->update(self::COUNTS)
					->set('n', $qb->createFunction($qb->getColumnName('n') . ($by < 0 ? ' - ' . -$by : ' + ' . $by)))
					->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->eq('qid', $qb->createNamedParameter(StoreKey::fit((string)$qid, self::QID))))
					->andWhere($qb->expr()->eq('val', $qb->createNamedParameter(StoreKey::fit((string)$value, self::VAL))));
				if ($qb->executeStatement() === 0 && $by > 0) {
					$this->db->insertIgnoreConflict(self::COUNTS, [
						'form_id' => $formId,
						'qid' => StoreKey::fit((string)$qid, self::QID),
						'val' => StoreKey::fit((string)$value, self::VAL),
						'n' => $by,
					]);
				}
			}
		}
	}

	/** @param array $entry as ResponseOutbox::add made it */
	private function addOutbox(int $formId, array $entry): void {
		$rid = (string)($entry['response'] ?? '');
		$id = (string)($entry['id'] ?? '');
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::OUTBOX)->values([
			'form_id' => $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT),
			'rid' => $qb->createNamedParameter(self::rid($rid)),
			// The entry's id without the response's: the event, and for a change its own suffix.
			'event' => $qb->createNamedParameter(str_starts_with($id, $rid . ':') ? substr($id, strlen($rid) + 1) : (string)($entry['event'] ?? '')),
			'todo' => $qb->createNamedParameter(implode(',', array_map('strval', (array)($entry['todo'] ?? [])))),
			'lang' => $qb->createNamedParameter(is_string($entry['language'] ?? null) ? $entry['language'] : null),
			'signed_in' => $qb->createNamedParameter(empty($entry['signedIn']) ? 0 : 1, IQueryBuilder::PARAM_INT),
			// A signed-in respondent's account, whose address the confirmation goes to.
			'account' => $qb->createNamedParameter(is_string($entry['account'] ?? null) && $entry['account'] !== '' ? $entry['account'] : null),
			// What a run made of it before: carried over from a form file, so nothing is sent twice or too soon.
			'attempts' => $qb->createNamedParameter((int)($entry['attempts'] ?? 0), IQueryBuilder::PARAM_INT),
			'claimed_at' => $qb->createNamedParameter(isset($entry['claimedAt']) ? (int)$entry['claimedAt'] : null, isset($entry['claimedAt']) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_NULL),
			'not_before' => $qb->createNamedParameter(isset($entry['notBefore']) ? (int)$entry['notBefore'] : null, isset($entry['notBefore']) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_NULL),
			'created' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/**
	 * What still waited for these responses is not sent; a removal still to be told, and what a run is sending now,
	 * stay, as with a form file.
	 *
	 * @param string|null $responseId null for every response
	 */
	private function forgetOutbox(int $formId, ?string $responseId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::OUTBOX)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('claimed_at'))
			->andWhere($qb->expr()->neq('event', $qb->createNamedParameter(ResponseOutbox::DELETED)));
		if ($responseId !== null) {
			$qb->andWhere($qb->expr()->eq('rid', $qb->createNamedParameter(self::rid($responseId))));
		}
		$qb->executeStatement();
	}

	private function deleteWhere(string $table, int $formId, ?string $responseId = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		if ($responseId !== null) {
			$qb->andWhere($qb->expr()->eq('rid', $qb->createNamedParameter(self::rid($responseId))));
		}
		$qb->executeStatement();
	}

	/**
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	private function transaction(callable $work) {
		for ($try = 1; ; $try++) {
			$this->db->beginTransaction();
			try {
				$result = $work();
				$this->db->commit();
				return $result;
			} catch (DbException $e) {
				// MySQL ends the whole transaction on a deadlock: it starts again from the top.
				$this->db->rollBack();
				if ($try < self::TRIES && in_array($e->getReason(), [DbException::REASON_DEADLOCK, DbException::REASON_LOCK_WAIT_TIMEOUT], true)) {
					usleep(random_int(5_000, 50_000) * $try);
					continue;
				}
				throw $e;
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		}
	}

	public static function personKey(array $respondent): ?string {
		if (($respondent['type'] ?? null) === 'user' && is_string($respondent['user_id'] ?? null) && $respondent['user_id'] !== '') {
			return 'u:' . $respondent['user_id'];
		}
		if (($respondent['type'] ?? null) === 'anonymous' && is_string($respondent['fingerprint'] ?? null) && $respondent['fingerprint'] !== '') {
			return 'f:' . $respondent['fingerprint'];
		}
		return null;
	}

	private static function rid(string $responseId): string {
		return StoreKey::fit($responseId, self::RID);
	}

	private function encode(array $response): string {
		// Escaped where the database cannot hold four-byte characters: an emoji would cut the row short there.
		return json_encode($response, ($this->db->supports4ByteText() ? JSON_UNESCAPED_UNICODE : 0) | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** @return list<string> the upload folders a response names, through any answer */
	public static function uploadGroupsOf(array $response): array {
		$groups = [];
		foreach (is_array($response['answers'] ?? null) ? $response['answers'] : [] as $answer) {
			if (!is_array($answer)) {
				continue;
			}
			foreach (array_is_list($answer) ? $answer : [$answer] as $item) {
				$id = is_array($item) ? ($item['responseId'] ?? null) : null;
				if (is_string($id) && UploadService::isValidResponseId($id)) {
					$groups[$id] = true;
				}
			}
		}
		return array_keys($groups);
	}

	private static function decode(mixed $data): ?array {
		// Oracle hands a CLOB as a stream.
		if (is_resource($data)) {
			$data = stream_get_contents($data);
		}
		$response = is_string($data) ? json_decode($data, true) : null;
		return is_array($response) ? $response : null;
	}
}
