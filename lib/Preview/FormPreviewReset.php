<?php

declare(strict_types=1);

namespace OCA\FormVox\Preview;

use OCA\FormVox\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Removes the stored previews of every form, so the next request draws the current icon. Nextcloud only drops a
 * file's previews when the file is written, so a form nobody saves would keep its old thumbnail forever.
 */
class FormPreviewReset {
	/** Raise when the icon changes; a repair step then schedules a reset once. */
	public const ICON_VERSION = 2;

	// Nextcloud 33 and later keep previews in a table behind this (private) service.
	private const PREVIEW_SERVICE = 'OC\Preview\PreviewService';

	public function __construct(
		private IDBConnection $db,
		private IMimeTypeLoader $mimeTypes,
		private IAppDataFactory $appDataFactory,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return int the previews (Nextcloud 33+) and preview folders (Nextcloud 32, or not moved yet) removed
	 */
	public function resetAll(): int {
		$removed = 0;
		// Nextcloud 33+ moves Nextcloud 32's preview folders into its table on first use; until it is done, they count.
		// Folders first: a preview made or moved meanwhile lands in the table, which the second pass empties.
		if (!class_exists(self::PREVIEW_SERVICE) || !$this->appConfig->getValueBool('core', 'previewMovedDone')) {
			$removed += $this->deletePreviewFolders();
		}
		if (class_exists(self::PREVIEW_SERVICE)) {
			$removed += $this->deleteStoredPreviews();
		}
		return $removed;
	}

	private function deleteStoredPreviews(): int {
		$removed = 0;
		try {
			$service = Server::get(self::PREVIEW_SERVICE);
			// Deleting while reading, as core's preview:reset-rendered-texts does.
			foreach ($service->getPreviewsForMimeTypes([Application::MIME_TYPE]) as $preview) {
				try {
					$service->deletePreview($preview);
					$removed++;
				} catch (\Throwable $e) {
					$this->logger->warning('FormVox: could not remove an old preview of a form: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: could not remove the old previews of forms; `occ preview:cleanup` removes all previews: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
		return $removed;
	}

	private function deletePreviewFolders(): int {
		$previews = $this->appDataFactory->get('preview');
		$removed = 0;
		try {
			$fileIds = iterator_to_array($this->formFileIds(), false);
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: could not list forms to remove their old previews; `occ preview:cleanup` removes all previews: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return 0;
		}
		foreach ($fileIds as $fileId) {
			$id = (string)$fileId;
			// Where OC\Preview\Storage\Root puts them: hashed, flat (older), and the multibucket fallback.
			$hashed = implode('/', str_split(substr(md5($id), 0, 7))) . '/' . $id;
			foreach ([$hashed, $id, 'old-multibucket/' . $hashed] as $path) {
				try {
					$previews->getFolder($path)->delete();
					$removed++;
				} catch (NotFoundException) {
				} catch (\Throwable $e) {
					$this->logger->warning('FormVox: could not remove the old previews of form ' . $id . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
				}
			}
		}
		return $removed;
	}

	/**
	 * @return \Generator<int>
	 */
	private function formFileIds(): \Generator {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid')
			->from('filecache')
			->where($qb->expr()->eq('mimetype', $qb->createNamedParameter($this->mimeTypes->getId(Application::MIME_TYPE))))
			// No storage to narrow it to: forms are everywhere.
			->runAcrossAllShards();
		$result = $qb->executeQuery();
		try {
			while (($fileId = $result->fetchOne()) !== false) {
				yield (int)$fileId;
			}
		} finally {
			$result->closeCursor();
		}
	}
}
