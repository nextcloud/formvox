<?php

declare(strict_types=1);

namespace OCA\FormVox\BackgroundJob;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\FormBrokenException;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormConverter;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\ResponseStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * A day after the first file of a fill was uploaded: when no stored response names those files, nobody submitted them,
 * and they go, so files sent without a response cannot fill the owner's storage (review 3a-5). Checked and removed while
 * the form's row is held, so a response stored at that moment keeps its files.
 */
class UploadCleanupJob extends QueuedJob {
	public const AFTER_SECONDS = 86400;

	public function __construct(
		ITimeFactory $time,
		private FormFileLocator $locator,
		private UploadService $uploads,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private FormStore $forms,
		private ResponseStore $stored,
		private FormConverter $converter,
	) {
		parent::__construct($time);
	}

	/**
	 * @param array{fileId?: int, group?: string} $argument
	 */
	protected function run($argument): void {
		$fileId = (int)($argument['fileId'] ?? 0);
		$group = (string)($argument['group'] ?? '');
		if (!UploadService::isValidResponseId($group)) {
			return;
		}
		try {
			$file = $this->locator->getFileByIdPublicForWrite($fileId);
		} catch (NotFoundException) {
			return;
		}
		try {
			// A form file moves into the database first, its uploads with it.
			$stored = $this->forms->byFileId($fileId) ?? $this->converter->convert($file);
			$this->stored->whileHeld($stored, function () use ($stored, $file, $group): void {
				if (!isset($this->stored->uploadGroups($stored->id)[$group])) {
					$this->uploads->deleteResponseUploads($file, $group);
				}
			});
		} catch (FormLockedException) {
			$this->jobList->scheduleAfter(self::class, time() + 600, $argument);
		} catch (FormBrokenException $e) {
			// Waiting does not mend it; the files stay rather than go unchecked.
			$this->logger->warning('FormVox: the files of an unsubmitted fill of form ' . $fileId . ' stay: the form file holds no form', ['app' => Application::APP_ID, 'exception' => $e]);
		} catch (\Throwable $e) {
			// The job is gone once it runs: without this the fill's files would stay for good (review of the 3a fixes).
			$this->logger->warning('FormVox: the files of an unsubmitted fill of form ' . $fileId . ' are checked again in an hour: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			$this->jobList->scheduleAfter(self::class, time() + 3600, $argument);
		}
	}
}
