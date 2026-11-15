<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormFolders;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Each response's PDF where its form keeps its uploads; one file per revision, so an outdated PDF is never served. Kept as
 * long as the response, so it stays the document the respondent was sent.
 */
class PdfStore {
	public const FOLDER = '.formvox-pdfs-';
	/** A part this old is left by a writer that stopped; a younger one may still be written. */
	private const PART_SECONDS = 3600;

	public function __construct(
		private ?FormFolders $folders = null,
		private ?ILockingProvider $locks = null,
	) {
	}

	/** @return string|null the stored PDF of this revision, null when there is none or it is not whole */
	public function get(File $form, string $responseId, string $revision): ?string {
		try {
			$file = $this->folder($form)->get(self::name($responseId) . '/' . self::revisionName($revision));
		} catch (NotFoundException|\InvalidArgumentException) {
			return null;
		}
		if (!$file instanceof File) {
			return null;
		}
		$pdf = $file->getContent();
		return self::whole($pdf) ? $pdf : null;
	}

	/**
	 * The stored PDF of this revision from the file cache alone, without opening it: put() writes under another name
	 * and renames, so a PDF under its own name is always whole.
	 */
	public function find(File $form, string $responseId, string $revision): ?File {
		try {
			$file = $this->folder($form)->get(self::name($responseId) . '/' . self::revisionName($revision));
		} catch (NotFoundException|\InvalidArgumentException) {
			return null;
		}
		return $file instanceof File && $file->getSize() >= 16 ? $file : null;
	}

	/**
	 * Keeps this revision's PDF; one kept first stays, so every way out gives the same bytes. Other revisions are left
	 * as they are: whoever calls may hold the response as it was, and forget() clears them when the response changes.
	 *
	 * @throws \InvalidArgumentException a response id or revision that is no file name
	 */
	public function put(File $form, string $responseId, string $revision, string $pdf): void {
		$name = self::revisionName($revision);
		try {
			$folder = $this->folder($form);
		} catch (NotFoundException) {
			$folder = $this->folders?->of($form, FormFolders::PDFS, true) ?? $form->getParent()->newFolder(self::FOLDER . $form->getId());
		}
		$responseFolder = self::name($responseId);
		try {
			$own = $folder->get($responseFolder);
		} catch (NotFoundException) {
			$own = $folder->newFolder($responseFolder);
		}
		if (!$own instanceof Folder) {
			throw new \InvalidArgumentException('Not a folder');
		}
		foreach ($own->getDirectoryListing() as $old) {
			// A part whose writer has surely stopped, of any revision.
			if (str_ends_with($old->getName(), '.tmp') && $old->getMTime() < time() - self::PART_SECONDS) {
				UploadService::withoutTrash(fn () => $old->delete());
			}
		}
		if (self::stands($own, $name)) {
			return;
		}
		// Written whole under a name of its own first: a write that stops halfway never stands under the PDF's name.
		$part = $own->newFile(self::partName($revision), $pdf);
		try {
			$this->take($own, $part, $name);
		} catch (\Throwable $e) {
			UploadService::withoutTrash(fn () => $part->delete());
			throw $e;
		}
	}

	/** Checked and taken under one lock, so of two writers the second never goes over the first's PDF. */
	private function take(Folder $own, File $part, string $name): void {
		$key = 'formvox/pdf/' . $own->getId() . '/' . $name;
		try {
			$this->locks?->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			// Another writer is taking it now: its PDF stays.
			UploadService::withoutTrash(fn () => $part->delete());
			return;
		}
		try {
			if (self::stands($own, $name)) {
				UploadService::withoutTrash(fn () => $part->delete());
				return;
			}
			$part->move($own->getPath() . '/' . $name);
		} finally {
			$this->locks?->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Whether a whole PDF stands under the name; anything else there would never be served, and goes. */
	private static function stands(Folder $own, string $name): bool {
		try {
			$file = $own->get($name);
		} catch (NotFoundException) {
			return false;
		}
		if ($file instanceof File && self::whole($file->getContent())) {
			return true;
		}
		UploadService::withoutTrash(fn () => $file->delete());
		return false;
	}

	/** A write that stopped halfway leaves no whole PDF. */
	private static function whole(mixed $pdf): bool {
		return is_string($pdf) && str_starts_with($pdf, '%PDF-') && str_contains(substr($pdf, -1024), '%%EOF');
	}

	/** Without the trash, as the response itself. */
	public function delete(File $form, string $responseId): void {
		try {
			$own = $this->folder($form)->get(self::name($responseId));
			UploadService::withoutTrash(fn () => $own->delete());
		} catch (NotFoundException|\InvalidArgumentException) {
		}
	}

	public function deleteAll(File $form): void {
		try {
			$folder = $this->folder($form);
			UploadService::withoutTrash(fn () => $folder->delete());
		} catch (NotFoundException) {
		}
	}

	/** @throws NotFoundException */
	private function folder(File $form): Folder {
		$folder = $this->folders?->of($form, FormFolders::PDFS) ?? $form->getParent()->get(self::FOLDER . $form->getId());
		if (!$folder instanceof Folder) {
			throw new NotFoundException('Not a folder');
		}
		return $folder;
	}

	/** Nothing path-like. */
	private static function name(string $responseId): string {
		if (!UploadService::isValidResponseId($responseId)) {
			throw new \InvalidArgumentException('Not a response id');
		}
		return $responseId;
	}

	/** Each writer its own: another's part of the same revision is never taken for this one's. */
	private static function partName(string $revision): string {
		return substr(self::revisionName($revision), 0, -4) . '.' . bin2hex(random_bytes(4)) . '.tmp';
	}

	private static function revisionName(string $revision): string {
		if (preg_match('/^[0-9a-f]{16}$/', $revision) !== 1) {
			throw new \InvalidArgumentException('Not a revision');
		}
		return $revision . '.pdf';
	}
}
