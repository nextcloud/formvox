<?php

declare(strict_types=1);

namespace OCA\FormVox\BackgroundJob;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\UploadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Makes the PDFs a ZIP download could not make in its time, a run at a time, until the form has them all. It comes
 * back only while it gets somewhere: a PDF that cannot be made or kept is logged and left.
 */
class PdfPrepareJob extends QueuedJob {
	public const AGAIN_SECONDS = 60;

	public function __construct(
		ITimeFactory $time,
		private FormFileLocator $locator,
		private FormRepository $repository,
		private ResponsePdf $pdfs,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private float $runSeconds = 60.0,
	) {
		parent::__construct($time);
	}

	/**
	 * @param array{fileId?: int} $argument
	 */
	protected function run($argument): void {
		$fileId = (int)($argument['fileId'] ?? 0);
		try {
			$file = $this->locator->getFileByIdPublicForWrite($fileId);
			$form = $this->repository->readForm($file);
		} catch (NotFoundException) {
			return;
		} catch (FormLockedException) {
			$this->again($fileId);
			return;
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: the PDFs of form ' . $fileId . ' are not made: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return;
		}
		// Switched off since the ZIP asked for them: nothing left to make.
		if (!ResponsePdf::keptFor($form)) {
			return;
		}
		$started = null;
		$kept = 0;
		$seen = [];
		$made = [];
		foreach ($this->repository->eachResponse($fileId, $form) as $response) {
			$id = is_array($response) && is_scalar($response['id'] ?? null) ? (string)$response['id'] : '';
			// The store keeps no PDF for an id from a file made elsewhere, and a repeated id's would push out its twin's.
			if (!UploadService::isValidResponseId($id) || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			if ($this->pdfs->stored($file, $response) !== null) {
				continue;
			}
			// Its time counts from the first PDF it makes, not from finding the ones kept.
			$started ??= microtime(true);
			if (microtime(true) - $started >= $this->runSeconds) {
				$this->forgetGone($fileId, $file, $form, $made);
				if ($kept > 0) {
					$this->again($fileId);
				}
				return;
			}
			$made[] = $id;
			try {
				$this->pdfs->of($file, $form, $response);
			} catch (\Throwable $e) {
				$this->logger->warning('FormVox: the PDF of response ' . $response['id'] . ' of form ' . $fileId . ' could not be made: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
				continue;
			}
			// Made is not kept: one that could not be stored (full quota, a name the store refuses) would be made again for good.
			if ($this->pdfs->stored($file, $response) !== null) {
				$kept++;
			}
		}
		$this->forgetGone($fileId, $file, $form, $made);
	}

	/**
	 * A response deleted while this ran does not keep the PDF made for it.
	 *
	 * @param list<string> $made
	 */
	private function forgetGone(int $fileId, File $file, array $form, array $made): void {
		foreach ($made as $id) {
			if ($this->repository->response($fileId, $form, $id) === null) {
				$this->pdfs->forget($file, $id);
			}
		}
	}

	private function again(int $fileId): void {
		$this->jobList->scheduleAfter(self::class, time() + self::AGAIN_SECONDS, ['fileId' => $fileId]);
	}
}
