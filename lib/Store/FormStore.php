<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

use OCA\FormVox\Service\FormTrash;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The forms whose responses live in the database, and the file each one is bound to.
 *
 * The database decides which rows belong to which form: a uuid in a file is only a hint, so a copied or planted file
 * never reaches another form's responses.
 */
class FormStore {
	public const TABLE = 'formvox_forms';

	private const ACTIVE = 0;
	private const ORPHAN = 1;
	/** Copied ahead from a form file and not switched to yet (FormConverter). */
	public const DRAFT = 2;

	/** Every table that holds rows of a form, by `form_id`. */
	private const OWNED = ['formvox_resp', 'formvox_keys', 'formvox_counts', 'formvox_outbox', 'formvox_secrets'];

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @throws \OCP\DB\Exception a unique violation when the uuid is taken already
	 */
	public function create(string $uuid, int $fileId, int $storageId, string $pathHash = ''): StoredForm {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'uuid' => $qb->createNamedParameter($uuid),
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'storage_id' => $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT),
			'path_hash' => $qb->createNamedParameter($pathHash),
			'state' => $qb->createNamedParameter(self::ACTIVE, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
			// Born in the database: nothing beside its file to move, nothing in it to tidy.
			'files_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
			'tidied_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return new StoredForm($qb->getLastInsertId(), $uuid, $fileId, $storageId, 0, $pathHash);
	}

	/**
	 * A form file's row while its responses are copied ahead, bound to the file like any.
	 *
	 * @throws \OCP\DB\Exception a unique violation when the uuid is taken already
	 */
	public function draft(string $uuid, int $fileId): StoredForm {
		[$storageId, $pathHash] = $this->place($fileId) ?? [0, ''];
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'uuid' => $qb->createNamedParameter($uuid),
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'storage_id' => $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT),
			'path_hash' => $qb->createNamedParameter($pathHash),
			'state' => $qb->createNamedParameter(self::DRAFT, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return new StoredForm($qb->getLastInsertId(), $uuid, $fileId, $storageId, 0, $pathHash, null, true, false, false);
	}

	public function draftOf(int $fileId): ?StoredForm {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::DRAFT, IQueryBuilder::PARAM_INT)))
			->orderBy('id')
			->setMaxResults(1);
		return $this->one($qb);
	}

	/** The draft is the form from now on: in the same transaction as its last rows (ResponseStore::mirror). */
	public function activate(int $formId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter(self::ACTIVE, IQueryBuilder::PARAM_INT))
			->set('migrated_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::DRAFT, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** Its files are in app data from now on: readers and writers go there. */
	public function markFilesMoved(int $formId): void {
		$this->stamp($formId, 'files_at');
	}

	/** Its file holds the form only now. */
	public function markTidied(int $formId): void {
		$this->stamp($formId, 'tidied_at');
	}

	private function stamp(int $formId, string $column): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set($column, $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Forms moved in whose files or file still wait, oldest first; after them, forms stamped as done that still have a
	 * folder beside their file (a crash after the stamp, or a writer that chose its folder before it), so those never
	 * hold back the others.
	 *
	 * @return list<StoredForm>
	 */
	public function unsettled(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter(self::ACTIVE, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX($qb->expr()->isNull('files_at'), $qb->expr()->isNull('tidied_at')))
			->orderBy('id')
			->setMaxResults($limit);
		$forms = $this->all($qb);
		if (count($forms) < $limit) {
			$forms = [...$forms, ...$this->withFoldersBeside($limit - count($forms))];
		}
		return $forms;
	}

	/**
	 * Stamped forms with a `.formvox-<kind>-<file id>` folder next to their file, through the file cache: the folders
	 * are named after the file and lie in its parent, so one join by parent finds them, whatever the storage.
	 *
	 * @return list<StoredForm>
	 */
	private function withFoldersBeside(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$names = [];
		foreach (['uploads', 'branding', 'templates', 'pdfs'] as $kind) {
			$names[] = $qb->func()->concat($qb->createNamedParameter('.formvox-' . $kind . '-'), 'f.file_id');
		}
		$beside = $this->db->getQueryBuilder();
		$beside->select('c.fileid')
			->from('filecache', 'c')
			->innerJoin('c', 'filecache', 'p', $beside->expr()->eq('c.parent', 'p.parent'))
			->where($beside->expr()->eq('p.fileid', 'f.file_id'))
			->andWhere($beside->expr()->in('c.name', $names));
		$qb->select('f.*')->from(self::TABLE, 'f')
			->where($qb->expr()->eq('f.state', $qb->createNamedParameter(self::ACTIVE, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('f.files_at'))
			->andWhere($qb->expr()->isNotNull('f.tidied_at'))
			->andWhere($qb->createFunction('EXISTS (' . $beside->getSQL() . ')'))
			->orderBy('f.id')
			->setMaxResults($limit);
		return $this->all($qb);
	}

	/** A draft follows its file to where it is now, still a draft. */
	public function moveDraft(int $formId, int $fileId): void {
		[$storageId, $pathHash] = $this->place($fileId) ?? [0, ''];
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('storage_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT))
			->set('path_hash', $qb->createNamedParameter($pathHash))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::DRAFT, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * A new form or a copy, bound to its file where Nextcloud has it.
	 *
	 * @throws \OCP\DB\Exception a unique violation when the uuid is taken already
	 */
	public function bindNew(string $uuid, int $fileId): StoredForm {
		[$storageId, $pathHash] = $this->place($fileId) ?? [0, ''];
		return $this->create($uuid, $fileId, $storageId, $pathHash);
	}

	/**
	 * The form at $oldId is at $newId now: moved, keeping its id, or another file renamed over it with a new one. Its
	 * row follows. Renamed onto a file another form is bound to, it was written over: an orphan.
	 */
	public function follow(int $oldId, int $newId): void {
		$form = $this->byFileId($oldId);
		$place = $form === null ? null : $this->place($newId);
		if ($form === null || $place === null) {
			return;
		}
		if ($oldId !== $newId && $this->byFileId($newId) !== null) {
			$this->markOrphan($form->id);
			return;
		}
		if ([$form->fileId, $form->storageId, $form->pathHash] !== [$newId, $place[0], $place[1]]) {
			$this->rebind($form->id, $newId, $place[0], $place[1]);
		}
	}

	/** The file Nextcloud has at this place now, if any. */
	public function fileAt(int $storageId, string $pathHash): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid')->from('filecache')
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter($pathHash)));
		$fileId = $qb->executeQuery()->fetchOne();
		return $fileId === false ? null : (int)$fileId;
	}

	/**
	 * Where Nextcloud has a file, whoever looks: the storage it is on and the hash of its path there.
	 *
	 * @return array{0: int, 1: string}|null null when it is gone
	 */
	public function place(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('storage', 'path_hash')->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return is_array($row) ? [(int)$row['storage'], (string)$row['path_hash']] : null;
	}

	public function byFileId(int $fileId): ?StoredForm {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::ACTIVE, IQueryBuilder::PARAM_INT)))
			->orderBy('id')
			->setMaxResults(1);
		return $this->one($qb);
	}

	/**
	 * The form a file holds, also when its row lost the file's id with nothing to tell: moved outside Nextcloud and
	 * scanned, or files put back without the database. Bound again only where it was, on the same storage under the
	 * same path; elsewhere, or while the file it is bound to is still there (a copy), the file is a form of its own.
	 */
	public function resolve(int $fileId, ?string $uuid): ?StoredForm {
		$form = $this->byFileId($fileId);
		if ($form !== null || $uuid === null || $uuid === '') {
			return $form;
		}
		$known = $this->byUuid($uuid);
		$place = $known === null || $known->draft ? null : $this->place($fileId);
		if ($known === null || $place === null) {
			// A draft is the converter's until it switches.
			return null;
		}
		$same = $known->fileId === $fileId;
		if (!$same && ([$known->storageId, $known->pathHash] !== $place || $this->place($known->fileId) !== null)) {
			return null;
		}
		$this->rebind($known->id, $fileId, $place[0], $place[1]);
		return $this->byFileId($fileId);
	}

	/** @return list<StoredForm> the forms whose file is gone, longest gone first */
	public function orphans(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter(self::ORPHAN, IQueryBuilder::PARAM_INT)))
			->orderBy('orphaned_at')->addOrderBy('id');
		$result = $qb->executeQuery();
		$orphans = [];
		while ($row = $result->fetch()) {
			$orphans[] = StoredForm::fromRow($row);
		}
		$result->closeCursor();
		return $orphans;
	}

	public function byId(int $formId): ?StoredForm {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		return $this->one($qb);
	}

	/**
	 * Each form with where its file is now, from one look at the file cache.
	 *
	 * @return list<array{0: StoredForm, 1: array{0: int, 1: string}|null}> the place is null when the file is gone
	 */
	public function withPlaces(): array {
		return $this->places($this->db->getQueryBuilder());
	}

	/**
	 * The forms whose file is gone and those whose file lies in a trash, from one look at the file cache.
	 *
	 * @return list<array{0: StoredForm, 1: array{0: int, 1: string}|null}> the place is null when the file is gone
	 */
	public function goneOrTrashed(): array {
		$qb = $this->db->getQueryBuilder();
		$where = [$qb->expr()->isNull('c.fileid')];
		foreach (FormTrash::PLACES as $prefix) {
			$where[] = $qb->expr()->like('c.path', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%'));
		}
		return $this->places($qb->where($qb->expr()->orX(...$where)));
	}

	/**
	 * The forms bound to one of these storages or whose file lies in one now, drafts too.
	 *
	 * @param list<int> $storageIds
	 * @return list<StoredForm>
	 */
	public function inStorages(array $storageIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->where($qb->expr()->orX(
			$qb->expr()->in('f.storage_id', $qb->createNamedParameter($storageIds, IQueryBuilder::PARAM_INT_ARRAY)),
			$qb->expr()->in('c.storage', $qb->createNamedParameter($storageIds, IQueryBuilder::PARAM_INT_ARRAY)),
		));
		return array_column($this->places($qb), 0);
	}

	/**
	 * The numbers of an account's home storage, on disk or in an object store, for as long as Nextcloud knows them.
	 *
	 * @return list<int>
	 */
	public function homeStorages(string $uid): array {
		// Nextcloud keeps an id longer than 64 characters as its md5 (Cache\Storage::adjustStorageId).
		$ids = array_map(fn (string $id): string => strlen($id) > 64 ? md5($id) : $id, ['home::' . $uid, 'object::user:' . $uid]);
		$qb = $this->db->getQueryBuilder();
		$qb->select('numeric_id')->from('storages')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$numbers = [];
		while (($number = $result->fetchOne()) !== false) {
			$numbers[] = (int)$number;
		}
		$result->closeCursor();
		return $numbers;
	}

	/**
	 * @return list<array{0: StoredForm, 1: array{0: int, 1: string}|null}>
	 */
	private function places(IQueryBuilder $qb): array {
		$qb->select('f.*')
			->selectAlias('c.storage', 'now_storage')
			->selectAlias('c.path_hash', 'now_hash')
			->from(self::TABLE, 'f')
			->leftJoin('f', 'filecache', 'c', $qb->expr()->eq('c.fileid', 'f.file_id'))
			->orderBy('f.id');
		$result = $qb->executeQuery();
		$forms = [];
		while ($row = $result->fetch()) {
			$forms[] = [StoredForm::fromRow($row), $row['now_storage'] === null ? null : [(int)$row['now_storage'], (string)$row['now_hash']]];
		}
		$result->closeCursor();
		return $forms;
	}

	/**
	 * @param list<int> $fileIds
	 * @return list<int> those Nextcloud has no file for
	 */
	public function gone(array $fileIds): array {
		$there = [];
		foreach (array_chunk($fileIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid')->from('filecache')
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($fileId = $result->fetchOne()) !== false) {
				$there[] = (int)$fileId;
			}
			$result->closeCursor();
		}
		return array_values(array_diff($fileIds, $there));
	}

	/** Also an orphan: a form found again by its uuid is bound again rather than started anew. */
	public function byUuid(string $uuid): ?StoredForm {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
		return $this->one($qb);
	}

	/** The form now lives in another file: moved where Nextcloud gave it a new id, or found again. */
	public function rebind(int $formId, int $fileId, int $storageId, string $pathHash): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('storage_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT))
			->set('path_hash', $qb->createNamedParameter($pathHash))
			->set('state', $qb->createNamedParameter(self::ACTIVE, IQueryBuilder::PARAM_INT))
			->set('orphaned_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** Where its file lies now, for a form that was not told: in a trash. Its state stays as it is. */
	public function seenAt(int $formId, int $fileId, int $storageId, string $pathHash): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('storage_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT))
			->set('path_hash', $qb->createNamedParameter($pathHash))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return array<int, int> how many responses each form in the database holds, by its file */
	public function counts(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'n')->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter(self::ACTIVE, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['file_id']] = (int)$row['n'];
		}
		$result->closeCursor();
		return $counts;
	}

	/** Its file is gone; the rows wait, in case the form turns up again. */
	public function markOrphan(int $formId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter(self::ORPHAN, IQueryBuilder::PARAM_INT))
			->set('orphaned_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(self::ACTIVE, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** The form and every row it owns, in one transaction. */
	public function delete(int $formId): void {
		$this->db->beginTransaction();
		try {
			foreach (self::OWNED as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * A draft and every row it owns, only while it is still a draft: switched to meanwhile, it is the form.
	 *
	 * @return bool whether it was removed
	 */
	public function deleteDraft(int $formId): bool {
		return $this->deleteWhile($formId, 'state', self::DRAFT);
	}

	/**
	 * An orphan and every row it owns, only while it is still an orphan: found again meanwhile, it is the form.
	 *
	 * @return bool whether it was removed
	 */
	public function deleteOrphan(int $formId): bool {
		return $this->deleteWhile($formId, 'state', self::ORPHAN);
	}

	/**
	 * A form and every row it owns, whatever its state, only while it is still bound to this file: bound to another
	 * meanwhile, it lives on there.
	 *
	 * @return bool whether it was removed
	 */
	public function deleteBoundTo(int $formId, int $fileId): bool {
		return $this->deleteWhile($formId, 'file_id', $fileId);
	}

	/** The row goes first, as it was when its removal was decided; the same row lock a switch or a rebind waits on. */
	private function deleteWhile(int $formId, string $column, int $value): bool {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$gone = $qb->delete(self::TABLE)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			if ($gone === 0) {
				$this->db->rollBack();
				return false;
			}
			foreach (self::OWNED as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return true;
	}

	private function one(IQueryBuilder $qb): ?StoredForm {
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return is_array($row) ? StoredForm::fromRow($row) : null;
	}

	/** @return list<StoredForm> */
	private function all(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$forms = [];
		while ($row = $result->fetch()) {
			$forms[] = StoredForm::fromRow($row);
		}
		$result->closeCursor();
		return $forms;
	}
}
