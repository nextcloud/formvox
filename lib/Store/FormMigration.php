<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormBrokenException;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormReader;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Finds the forms still in their file and moves them in (FormConverter), the largest first: they are the busiest, and a
 * respondent who touches one before its turn waits for the move. Also finishes what an earlier move left: tidying the
 * file, moving the files beside it. Found through the file cache by type, per storage, as the forms list does.
 */
class FormMigration {
	/** Files that hold no form, each with what it was when judged: passed over until it changes or an update looks again. */
	private const BROKEN = 'migrate_broken';
	private const STORAGES_PER_QUERY = 500;
	/** How many forms are looked up at a time: what a run finds is still as the list says when its turn comes. */
	private const BATCH = 100;

	public function __construct(
		private IDBConnection $db,
		private IMimeTypeLoader $mimeTypes,
		private IRootFolder $root,
		private FormStore $forms,
		private FormConverter $converter,
		private FormReader $reader,
		private FormFileLocator $locator,
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The form files still to move in, largest first: by type in each storage, named as a form (a version or a file in
	 * the trash is named otherwise), with no form in the database bound to them. Not what respondents uploaded under
	 * that name, beside a form or in app data.
	 *
	 * @return list<int> their file ids
	 */
	public function pending(int $limit): array {
		$type = $this->mimeTypes->getId('application/x-fvform');
		$broken = $this->broken();
		$seen = [];
		$found = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('numeric_id')->from('storages');
		$storages = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		foreach (array_chunk($storages, self::STORAGES_PER_QUERY) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('c.fileid', 'c.size', 'c.mtime', 'c.etag')
				->from('filecache', 'c')
				->leftJoin('c', FormStore::TABLE, 'f', $qb->expr()->andX(
					$qb->expr()->eq('f.file_id', 'c.fileid'),
					$qb->expr()->eq('f.state', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
				))
				->where($qb->expr()->in('c.storage', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('c.mimetype', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->iLike('c.name', $qb->createNamedParameter('%.' . Application::FILE_EXTENSION)))
				->andWhere($qb->expr()->notLike('c.path', $qb->createNamedParameter('files_versions/%')))
				->andWhere($qb->expr()->notLike('c.path', $qb->createNamedParameter('files_trashbin/%')))
				->andWhere($qb->expr()->notLike('c.path', $qb->createNamedParameter('.formvox-%')))
				->andWhere($qb->expr()->notLike('c.path', $qb->createNamedParameter('%/.formvox-%')))
				// Not escaped: SQLite's NOT LIKE knows no escape character, and '_' matching itself is harmless here.
				->andWhere($qb->expr()->notLike('c.path', $qb->createNamedParameter($this->root->getAppDataDirectoryName() . '/' . Application::APP_ID . '/%')))
				->andWhere($qb->expr()->isNull('f.id'));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$fileId = (int)$row['fileid'];
				$seen[$fileId] = self::stamp((int)$row['size'], (int)$row['mtime'], (string)$row['etag']);
				if (($broken[$fileId] ?? null) !== $seen[$fileId]) {
					$found[$fileId] = (int)$row['size'];
				}
			}
			$result->closeCursor();
		}
		// A file changed since it was judged, moved in or gone is judged again when it turns up.
		$kept = array_filter($broken, fn (string $stamp, int $fileId) => ($seen[$fileId] ?? null) === $stamp, ARRAY_FILTER_USE_BOTH);
		if ($kept !== $broken) {
			$this->config->setValueArray(Application::APP_ID, self::BROKEN, $kept);
		}
		arsort($found);
		return array_slice(array_keys($found), 0, $limit);
	}

	/**
	 * Moves forms in, and finishes earlier moves, until $seconds are spent or nothing is left this run has not tried.
	 * Each form is tried once in a run: one that cannot be finished now waits for the next run.
	 *
	 * @param (callable(int, string): void)|null $progress told each file id and what became of it
	 * @return array{moved: int, settled: int, skipped: int, left: bool} left: whether there is more to do
	 */
	public function run(float $seconds, ?callable $progress = null): array {
		$until = microtime(true) + $seconds;
		$done = ['moved' => 0, 'settled' => 0, 'skipped' => 0, 'left' => false];
		// Each form once: one that cannot be finished must not use up the run, and only what it did not get to is left.
		$tried = [];
		do {
			// As many more as were tried: those may still be listed, and what comes after them must be reached.
			$batch = array_filter($this->forms->unsettled(count($tried) + self::BATCH), fn (StoredForm $form) => !isset($tried[$form->id]));
			foreach ($batch as $form) {
				if (microtime(true) >= $until) {
					$done['left'] = true;
					return $done;
				}
				$tried[$form->id] = true;
				try {
					$this->converter->settle($this->locator->getFileByIdPublic($form->fileId), $form);
					$done['settled']++;
					$progress && $progress($form->fileId, 'settled');
				} catch (\Throwable $e) {
					$done['skipped']++;
					$this->logger->info('FormVox: what moving form ' . $form->fileId . ' in left is finished later: ' . $e->getMessage(), ['app' => Application::APP_ID]);
					$progress && $progress($form->fileId, 'skipped: ' . $e->getMessage());
				}
			}
		} while ($batch !== []);
		$tried = [];
		do {
			$batch = array_filter($this->pending(count($tried) + self::BATCH), fn (int $fileId) => !isset($tried[$fileId]));
			foreach ($batch as $fileId) {
				if (microtime(true) >= $until) {
					$done['left'] = true;
					return $done;
				}
				$tried[$fileId] = true;
				$what = $this->moveIn($fileId, $done);
				$progress && $progress($fileId, $what);
			}
		} while ($batch !== []);
		return $done;
	}

	/** One form now, as occ asks for it. */
	public function moveOne(int $fileId): string {
		$done = ['moved' => 0, 'settled' => 0, 'skipped' => 0, 'left' => false];
		$stored = $this->forms->byFileId($fileId);
		if ($stored !== null) {
			$this->converter->settle($this->locator->getFileByIdPublic($fileId), $stored);
			return 'in the database already';
		}
		return $this->moveIn($fileId, $done);
	}

	/**
	 * @return array{pending: int, unsettled: int, broken: list<int>}
	 */
	public function status(): array {
		return ['pending' => count($this->pending(PHP_INT_MAX)), 'unsettled' => count($this->forms->unsettled(PHP_INT_MAX)), 'broken' => array_keys($this->broken())];
	}

	/** An update looks at every file again: one mended since moves in then. */
	public function forgetBroken(): void {
		$this->config->deleteKey(Application::APP_ID, self::BROKEN);
	}

	/** @param array{moved: int, settled: int, skipped: int, left: bool} $done */
	private function moveIn(int $fileId, array &$done): string {
		try {
			$file = $this->locator->getFileByIdPublic($fileId);
			try {
				$form = $this->converter->convert($file, true);
			} catch (FormBrokenException) {
				$done['skipped']++;
				return $this->passOver($file, $done);
			}
			$done['moved']++;
			return 'moved in, ' . $form->count . ' responses';
		} catch (FormLockedException) {
			$done['skipped']++;
			// A writer holds it for seconds: the next run, not the next day.
			$done['left'] = true;
			return 'skipped: busy, tried again later';
		} catch (\Throwable $e) {
			$done['skipped']++;
			$this->logger->warning('FormVox: form ' . $fileId . ' could not be moved in now: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return 'skipped: ' . $e->getMessage();
		}
	}

	/**
	 * A file that would not read as a form, judged again as a reader does: one that reads whole now, or not at all, was
	 * busy; only bytes that are no form, steady while nobody saves, are remembered as holding none.
	 *
	 * @param array{moved: int, settled: int, skipped: int, left: bool} $done
	 */
	private function passOver(File $file, array &$done): string {
		$fileId = (int)$file->getId();
		try {
			$form = $this->reader->read($file);
			if (!array_is_list($form)) {
				$done['left'] = true;
				return 'skipped: busy, tried again later';
			}
		} catch (FormLockedException) {
			$done['left'] = true;
			return 'skipped: busy, tried again later';
		} catch (FormBrokenException $e) {
			if ($e->getPrevious() !== null) {
				$this->logger->warning('FormVox: form ' . $fileId . ' could not be read now: ' . $e->getPrevious()->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
				return 'skipped: could not be read';
			}
		}
		$this->config->setValueArray(Application::APP_ID, self::BROKEN, $this->broken() + [$fileId => self::stamp((int)$file->getSize(), (int)$file->getMTime(), (string)$file->getEtag())]);
		$this->logger->info('FormVox: file ' . $fileId . ' is named as a form but holds none; it is not moved in', ['app' => Application::APP_ID]);
		return 'skipped: holds no form';
	}

	/** @return array<int, string> by file id, what the file was when judged */
	private function broken(): array {
		$broken = [];
		foreach ($this->config->getValueArray(Application::APP_ID, self::BROKEN, []) as $fileId => $stamp) {
			if (is_string($stamp)) {
				$broken[(int)$fileId] = $stamp;
			}
		}
		return $broken;
	}

	private static function stamp(int $size, int $mtime, string $etag): string {
		return $size . ':' . $mtime . ':' . $etag;
	}
}
