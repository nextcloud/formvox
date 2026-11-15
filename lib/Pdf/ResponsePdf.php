<?php

declare(strict_types=1);

namespace OCA\FormVox\Pdf;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Store\FormFolders;
use OCP\Files\File;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/** A response's PDF, made once in the respondent's language and kept, so every way out gives the same document. */
class ResponsePdf {
	public function __construct(
		private ResponseDocument $document,
		private PdfRenderer $renderer,
		private PdfStore $store,
		private FormFileLocator $locator,
		private IFactory $l10n,
		private LoggerInterface $logger,
		private ?FormFolders $folders = null,
	) {
	}

	/** Whether the form keeps a PDF of every response; off, none is made anywhere: not for the API, a webhook or the email. */
	public static function keptFor(array $form): bool {
		return !empty($form['settings']['keepResponsePdf']);
	}

	/** Whether the confirmation email carries the PDF: only of a form that keeps one. */
	public static function attachedFor(array $form): bool {
		return self::keptFor($form) && !empty($form['settings']['confirmationEmailPdf']);
	}

	/** Changes with every answer that would change the document. */
	public static function revision(array $response): string {
		$what = [$response['answers'] ?? null, $response['submitted_at'] ?? null, $response['updated_at'] ?? null, $response['score'] ?? null,
			$response['timezone'] ?? null, $response['language'] ?? null];
		return substr(hash('sha256', (string)json_encode($what)), 0, 16);
	}

	/** The kept PDF of the response as it is now, without reading it; null when it is still to be made. */
	public function stored(File $formFile, array $response): ?File {
		return $this->store->find($this->home($formFile), (string)($response['id'] ?? ''), self::revision($response));
	}

	/**
	 * The form where its PDFs are kept: a user it is shared with sees it in their own folder, where they are not.
	 * Resolve once and pass it on when asking for many responses.
	 */
	public function home(File $formFile): File {
		// In app data they are kept by the form's id, whoever looks.
		if ($this->folders?->isStored((int)$formFile->getId()) === true) {
			return $formFile;
		}
		try {
			return $this->locator->getFileByIdPublic((int)$formFile->getId(), true);
		} catch (\Throwable) {
			return $formFile;
		}
	}

	/**
	 * @param string|null $language the respondent's, where the response does not say
	 * @return string the PDF
	 * @throws RequestException when the form keeps no PDFs: whoever asks for one here gets the same answer as the API
	 */
	public function of(File $formFile, array $form, array $response, ?string $language = null): string {
		if (!self::keptFor($form)) {
			throw RequestException::pdfOff();
		}
		$responseId = (string)($response['id'] ?? '');
		$revision = self::revision($response);
		$stored = $this->kept($this->home($formFile), $responseId, $revision);
		if ($stored !== null) {
			return $stored;
		}
		$language = is_string($response['language'] ?? null) && $response['language'] !== '' ? $response['language'] : $language;
		$l = $this->l10n->get(Application::APP_ID, $language);
		$pdf = $this->renderer->render($this->document->html($form, $response, $l), ...$this->document->footer($response, $l));
		try {
			// The form may have moved or been trashed while this was rendered.
			$now = $this->locator->getFileByIdPublicForWrite((int)$formFile->getId(), true);
			if (!str_starts_with($now->getInternalPath(), 'files_trashbin/')) {
				$this->store->put($now, $responseId, $revision, $pdf);
				// Another one may have been kept first: every way out gives the kept bytes.
				return $this->kept($now, $responseId, $revision) ?? $pdf;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: could not keep the PDF of response ' . $responseId . ' of form ' . $formFile->getId() . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
		return $pdf;
	}

	/** As of(), but null when the PDF could not be kept: what a webhook names must be what the API gives later. */
	public function keptOf(File $formFile, array $form, array $response, ?string $language = null): ?string {
		$pdf = $this->of($formFile, $form, $response, $language);
		return $this->kept($this->home($formFile), (string)($response['id'] ?? ''), self::revision($response)) === $pdf ? $pdf : null;
	}

	/** A kept PDF that cannot be read now is made again rather than failing whoever asked for it. */
	private function kept(File $formFile, string $responseId, string $revision): ?string {
		try {
			return $this->store->get($formFile, $responseId, $revision);
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: the kept PDF of response ' . $responseId . ' of form ' . $formFile->getId() . ' could not be read: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return null;
		}
	}

	/**
	 * One response's PDF to hand out, as it is stored now: a download, the API. A response deleted while its PDF was
	 * made does not get it back.
	 *
	 * @param \Closure(): ?array $current the response as stored now, null when there is none
	 * @return array{0: string, 1: string}|null the PDF and its file name; null when there is no such response
	 */
	public function download(File $formFile, array $form, \Closure $current): ?array {
		$response = $current();
		if ($response === null) {
			return null;
		}
		$pdf = $this->of($formFile, $form, $response);
		if ($current() === null) {
			$this->forget($formFile, (string)($response['id'] ?? ''));
			return null;
		}
		return [$pdf, $this->fileName($form, $response)];
	}

	/** The one name the PDF goes by: a download, the API, the ZIP, the email and the webhook. */
	public function fileName(array $form, array $response): string {
		return $this->document->fileName($form, $response);
	}

	public function forget(File $formFile, string $responseId): void {
		try {
			try {
				$formFile = $this->locator->getFileByIdPublicForWrite((int)$formFile->getId(), true);
			} catch (\Throwable) {
			}
			$this->store->delete($formFile, $responseId);
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: could not remove the PDF of response ' . $responseId . ' of form ' . $formFile->getId() . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	public function forgetAll(File $formFile): void {
		try {
			$this->store->deleteAll($formFile);
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: could not remove the PDFs of form ' . $formFile->getId() . ': ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}
}
