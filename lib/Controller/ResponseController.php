<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\StrictCookiesRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ResponseController extends Controller {
	/** The most responses one page holds. */
	/** The largest page the results page asks for: Nextcloud 35 holds any `limit` to 500 unless the method says otherwise. */
	public const MAX_PAGE = 2000;

	public function __construct(
		IRequest $request,
		private ResponseService $responseService,
		private FormAccessService $access,
		private FormRepository $formRepository,
		private ResponsePdf $pdfs,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param int<1, 2000>|null $limit as MAX_PAGE
	 */
	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::VIEW_CONTENT)]
	public function getResponses(int $fileId, ?string $date = null, ?int $limit = null, ?string $after = null): DataResponse {
		if ($limit === null || $date !== null) {
			$data = ['responses' => $this->responseService->getResponses($fileId, $date)];
		} else {
			$data = $this->responseService->getResponsePage($fileId, max(1, min(self::MAX_PAGE, $limit)), $after);
		}
		// A later page needs no summary: the first one brought it.
		if ($after === null) {
			$data['summary'] = $this->responseService->getSummary($fileId);
		}
		return new DataResponse($data);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function deleteAllResponses(int $fileId): DataResponse {
		$this->responseService->deleteAllResponses($fileId, $this->access->getFile($fileId));
		return new DataResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function deleteResponse(int $fileId, string $responseId): DataResponse {
		$this->responseService->deleteResponse($fileId, $this->access->getFile($fileId), $responseId);
		return new DataResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	// Heavy to make: only from Nextcloud's own pages, not from a link on another site.
	#[StrictCookiesRequired]
	#[RequireFormPermission(FormPermission::DOWNLOAD)]
	public function getResponsePdf(int $fileId, string $responseId): DataDownloadResponse {
		$form = $this->formRepository->loadForm($fileId);
		if (!ResponsePdf::keptFor($form)) {
			throw RequestException::pdfOff();
		}
		$download = $this->pdfs->download($this->access->getFile($fileId), $form, fn () => $this->formRepository->response($fileId, $form, $responseId));
		if ($download === null) {
			// TRANSLATORS: Error when the response whose PDF was asked for does not exist
			throw RequestException::notFound(Translatable::t('Response not found'));
		}
		return new DataDownloadResponse($download[0], $download[1], 'application/pdf');
	}
}
