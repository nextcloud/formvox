<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\Pdf\PdfExport;
use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\FileNames;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\OdtTemplateService;
use OCA\FormVox\Service\ResponseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\StrictCookiesRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\ZipResponse;
use OCP\IL10N;
use OCP\IRequest;

class ExportController extends Controller {
	public function __construct(
		IRequest $request,
		private FormRepository $formRepository,
		private FormAccessService $access,
		private ResponseService $responseService,
		private OdtTemplateService $odtTemplateService,
		private PdfExport $pdfExport,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireFormPermission(FormPermission::DOWNLOAD)]
	public function exportCsv(int $fileId): DataDownloadResponse {
		return new DataDownloadResponse(
			$this->responseService->exportCsv($fileId),
			$this->filename($fileId, 'csv'),
			'text/csv; charset=utf-8'
		);
	}

	/**
	 * A real spreadsheet, so umlauts and columns are right in every locale (#114).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireFormPermission(FormPermission::DOWNLOAD)]
	public function exportExcel(int $fileId): DataDownloadResponse {
		return new DataDownloadResponse(
			$this->responseService->exportXlsx($fileId),
			$this->filename($fileId, 'xlsx'),
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireFormPermission(FormPermission::DOWNLOAD)]
	public function exportJson(int $fileId): DataDownloadResponse {
		return new DataDownloadResponse(
			$this->responseService->exportJson($fileId),
			$this->filename($fileId, 'json'),
			'application/json'
		);
	}

	/** Every response's PDF in one ZIP, with a note on those still being made or that could not be made, and manifest.csv. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	// Heavy to make: only from Nextcloud's own pages, not from a link on another site.
	#[StrictCookiesRequired]
	#[RequireFormPermission(FormPermission::DOWNLOAD)]
	public function exportPdfs(int $fileId): ZipResponse {
		$form = $this->formRepository->loadForm($fileId);
		if (!ResponsePdf::keptFor($form)) {
			throw RequestException::pdfOff();
		}
		$zip = new ZipResponse($this->request, substr(self::nameFor($form, 'zip'), 0, -4) . '-pdf');
		$export = $this->pdfExport->entries($this->access->getFile($fileId), $form, $this->formRepository->eachResponse($fileId, $form));
		foreach ($export['entries'] as $entry) {
			$zip->addResource($entry['stream'], $entry['name'], $entry['size'], $entry['time']);
		}
		$note = '';
		if ($export['pending'] > 0) {
			$note .= $this->l->t('PDFs still being made: %s. Download the ZIP again in a few minutes.', [(string)$export['pending']]) . "\n";
		}
		if ($export['failed'] > 0) {
			$note .= $this->l->t('PDFs that could not be made: %s. Your administrator finds the reason in the Nextcloud log.', [(string)$export['failed']]) . "\n";
		}
		if ($note !== '') {
			$stream = fopen('php://temp', 'w+b');
			if ($stream !== false) {
				fwrite($stream, $note);
				rewind($stream);
				// TRANSLATORS: Name, without extension, of the note in a ZIP of PDFs that says which are missing
				$zip->addResource($stream, $this->l->t('README') . '.txt', strlen($note), time());
			}
		}
		// Last: it is written as the ZIP reads the PDFs before it.
		$manifest = $export['manifest'];
		$zip->addResource($manifest['stream'], $manifest['name'], $manifest['size'], $manifest['time']);
		return $zip;
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function uploadOdtTemplate(int $fileId): DataResponse {
		$uploadedFile = $this->request->getUploadedFile('template');
		if (!$uploadedFile || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$this->odtTemplateService->storeOdtTemplate($this->access->getFile($fileId), $uploadedFile);
		return new DataResponse(['success' => true]);
	}

	/**
	 * The client fills this template with responses, so it counts as an export.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireFormPermission(FormPermission::DOWNLOAD)]
	public function downloadOdtTemplate(int $fileId): DataDownloadResponse {
		return new DataDownloadResponse(
			$this->odtTemplateService->getOdtTemplate($this->access->getFile($fileId))->getContent(),
			'template.odt',
			'application/vnd.oasis.opendocument.text'
		);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function deleteOdtTemplate(int $fileId): DataResponse {
		$this->odtTemplateService->deleteOdtTemplate($this->access->getFile($fileId));
		return new DataResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequireFormPermission(FormPermission::READ)]
	public function hasOdtTemplate(int $fileId): DataResponse {
		return new DataResponse(['hasTemplate' => $this->odtTemplateService->hasOdtTemplate($this->access->getFile($fileId))]);
	}

	private function filename(int $fileId, string $extension): string {
		return self::nameFor($this->formRepository->loadForm($fileId), $extension);
	}

	private static function nameFor(array $form, string $extension): string {
		return FileNames::export(is_scalar($form['title'] ?? null) ? (string)$form['title'] : '', $extension);
	}
}
