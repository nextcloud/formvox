<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Controller;

use OCA\FormVox\Controller\ExportController;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\OdtTemplateService;
use OCA\FormVox\Service\ResponseService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * ExportController once access is granted; FormAccessMiddleware owns authorization and error mapping.
 */
class ExportControllerTest extends TestCase {
	private FormRepository $formRepository;
	private FormAccessService $access;
	private ResponseService $responseService;
	private OdtTemplateService $odtTemplateService;
	private IRequest $request;
	private File $formFile;

	protected function setUp(): void {
		parent::setUp();
		$this->formRepository = $this->createMock(FormRepository::class);
		$this->access = $this->createMock(FormAccessService::class);
		$this->responseService = $this->createMock(ResponseService::class);
		$this->odtTemplateService = $this->createMock(OdtTemplateService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->formFile = $this->createMock(File::class);
		$this->access->method('getFile')->with(1)->willReturn($this->formFile);
	}

	private function controller(?\OCA\FormVox\Pdf\PdfExport $pdfExport = null): ExportController {
		return new ExportController(
			$this->request,
			$this->formRepository,
			$this->access,
			$this->responseService,
			$this->odtTemplateService,
			$pdfExport ?? $this->createMock(\OCA\FormVox\Pdf\PdfExport::class),
			new \OCA\FormVox\Tests\Unit\Support\EnglishL10N(),
		);
	}

	/**
	 * The PDF ZIP as the controller fills it, by name: what the export gave, then the note, then manifest.csv.
	 *
	 * @return array<string, string>
	 */
	private function pdfZip(int $pending, int $failed): array {
		$stream = static function (string $content) {
			$stream = fopen('php://memory', 'w+b');
			fwrite($stream, $content);
			rewind($stream);
			return $stream;
		};
		$export = $this->createMock(\OCA\FormVox\Pdf\PdfExport::class);
		$export->method('entries')->willReturn([
			'entries' => [['name' => 'a.pdf', 'stream' => $stream('%PDF-a'), 'size' => 6, 'time' => 1]],
			'pending' => $pending,
			'failed' => $failed,
			'manifest' => ['name' => 'manifest.csv', 'stream' => $stream('m'), 'size' => 1, 'time' => 1],
		]);
		$this->formRepository->method('loadForm')->willReturn(['title' => 'T', 'settings' => ['keepResponsePdf' => true]]);
		$this->formRepository->method('eachResponse')->willReturn([]);
		$zip = $this->controller($export)->exportPdfs(1);
		$files = [];
		foreach ((new \ReflectionProperty(\OCP\AppFramework\Http\ZipResponse::class, 'resources'))->getValue($zip) as $file) {
			$files[$file['internalName']] = (string)stream_get_contents($file['resource']);
		}
		return $files;
	}

	/** The note says what is missing and why: one that could not be made is not promised as still coming. */
	public function testThePdfZipSaysWhatIsMissing(): void {
		$this->assertSame(['a.pdf' => '%PDF-a', 'manifest.csv' => 'm'], $this->pdfZip(0, 0), 'nothing missing, no note');
		$this->assertSame("PDFs that could not be made: 2. Your administrator finds the reason in the Nextcloud log.\n", $this->pdfZip(0, 2)['README.txt']);
		$this->assertSame("PDFs still being made: 1. Download the ZIP again in a few minutes.\nPDFs that could not be made: 1. Your administrator finds the reason in the Nextcloud log.\n", $this->pdfZip(1, 1)['README.txt']);
		$this->assertSame(['a.pdf', 'README.txt', 'manifest.csv'], array_keys($this->pdfZip(1, 0)), 'manifest.csv last: it is written as the PDFs before it are read');
	}

	/** A form that keeps no PDFs has no ZIP of them: 409 before anything is made. */
	public function testThePdfZipIsRefusedWhereTheFormKeepsNone(): void {
		$export = $this->createMock(\OCA\FormVox\Pdf\PdfExport::class);
		$export->expects($this->never())->method('entries');
		$this->formRepository->method('loadForm')->willReturn(['title' => 'T']);

		try {
			$this->controller($export)->exportPdfs(1);
			$this->fail('a ZIP was made');
		} catch (\OCA\FormVox\Exception\RequestException $e) {
			$this->assertSame(Http::STATUS_CONFLICT, $e->getStatus());
			$this->assertSame('PDF generation is disabled for this form', $e->getMessage());
		}
	}

	/**
	 * Invoke a download endpoint whose only observable output is a
	 * DataDownloadResponse. That class cannot be instantiated in the Unit env:
	 * OCP\AppFramework\Http\DownloadResponse pulls in Symfony HttpFoundation /
	 * String classes that are not on the unit autoloader, so the controller
	 * reaches the response constructor and throws a missing-Symfony-class Error
	 * *after* all the mocked service work is done. Swallow only that specific
	 * class of Error (any missing Symfony\Component\… class) — every other
	 * assertion (the ->expects() matchers) still runs at tearDown. Which exact
	 * Symfony class is missing depends on what other dev-deps happen to be
	 * installed, so match the namespace, not one class name.
	 */
	private function buildDownload(callable $fn): void {
		try {
			$fn();
		} catch (\Error $e) {
			if (!str_contains($e->getMessage(), 'Symfony\\Component\\')) {
				throw $e;
			}
		}
		$this->addToAssertionCount(1);
	}

	// ---- exportCsv() -------------------------------------------------------

	public function testExportCsvBuildsViaResponseService(): void {
		$this->formRepository->expects($this->once())->method('loadForm')->with(1)->willReturn(['title' => 'My Form']);
		$this->responseService->expects($this->once())->method('exportCsv')->with(1)->willReturn('a,b,c');

		$this->buildDownload(fn () => $this->controller()->exportCsv(1));
	}

	// ---- exportExcel() -----------------------------------------------------

	public function testExportExcelBuildsViaResponseService(): void {
		$this->formRepository->expects($this->once())->method('loadForm')->with(1)->willReturn(['title' => 'My Form']);
		$this->responseService->expects($this->once())->method('exportXlsx')->with(1)->willReturn('XLSXBYTES');

		$this->buildDownload(fn () => $this->controller()->exportExcel(1));
	}

	// ---- exportJson() ------------------------------------------------------

	public function testExportJsonBuildsViaResponseService(): void {
		$this->formRepository->expects($this->once())->method('loadForm')->with(1)->willReturn(['title' => 'My Form']);
		$this->responseService->expects($this->once())->method('exportJson')->with(1)->willReturn('{"ok":true}');

		$this->buildDownload(fn () => $this->controller()->exportJson(1));
	}

	// ---- uploadOdtTemplate() -----------------------------------------------

	public function testUploadOdtTemplateBadRequestWhenNoFile(): void {
		$this->request->method('getUploadedFile')->willReturn(null);
		$this->odtTemplateService->expects($this->never())->method('storeOdtTemplate');

		$resp = $this->controller()->uploadOdtTemplate(1);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testUploadOdtTemplateStoresOnFormFile(): void {
		$upload = [
			'error' => UPLOAD_ERR_OK,
			'tmp_name' => '/tmp/x',
			'name' => 't.odt',
		];
		$this->request->method('getUploadedFile')->with('template')->willReturn($upload);
		$this->odtTemplateService->expects($this->once())->method('storeOdtTemplate')->with($this->formFile, $upload);

		$resp = $this->controller()->uploadOdtTemplate(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testUploadOdtTemplatePropagatesStoreFailure(): void {
		$this->request->method('getUploadedFile')->willReturn(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/x']);
		$this->odtTemplateService->method('storeOdtTemplate')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->controller()->uploadOdtTemplate(1);
	}

	// ---- downloadOdtTemplate() ---------------------------------------------

	public function testDownloadOdtTemplateFetchesViaOdtService(): void {
		$template = $this->createMock(File::class);
		$template->expects($this->once())->method('getContent')->willReturn('ODTBYTES');
		$this->odtTemplateService->expects($this->once())->method('getOdtTemplate')->with($this->formFile)->willReturn($template);

		$this->buildDownload(fn () => $this->controller()->downloadOdtTemplate(1));
	}

	// ---- deleteOdtTemplate() -----------------------------------------------

	public function testDeleteOdtTemplateDeletesOnFormFile(): void {
		$this->odtTemplateService->expects($this->once())->method('deleteOdtTemplate')->with($this->formFile);

		$resp = $this->controller()->deleteOdtTemplate(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['success' => true], $resp->getData());
	}

	public function testDeleteOdtTemplatePropagatesFailure(): void {
		$this->odtTemplateService->method('deleteOdtTemplate')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->controller()->deleteOdtTemplate(1);
	}

	// ---- hasOdtTemplate() --------------------------------------------------

	public function testHasOdtTemplateReturnsFlag(): void {
		$this->odtTemplateService->expects($this->once())->method('hasOdtTemplate')->with($this->formFile)->willReturn(true);

		$resp = $this->controller()->hasOdtTemplate(1);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$this->assertSame(['hasTemplate' => true], $resp->getData());
	}

	public function testHasOdtTemplatePropagatesFailure(): void {
		$this->odtTemplateService->method('hasOdtTemplate')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		$this->controller()->hasOdtTemplate(1);
	}
}
