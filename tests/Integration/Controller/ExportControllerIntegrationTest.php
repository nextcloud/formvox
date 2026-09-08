<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Controller;

use OCA\FormVox\Controller\ExportController;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;

/**
 * Integration coverage for the DataDownloadResponse endpoints of
 * ExportController. These run against a real Nextcloud container + DB +
 * filesystem via the app container's auto-wired ExportController — the unit
 * harness could not instantiate the controller because DataDownloadResponse /
 * ResponseService need real infrastructure.
 *
 * The logged-in $this->owner owns every fixture form file, so
 * PermissionService::getRoleFromFile() returns ROLE_OWNER and
 * canViewResponses() is true — the export permission gate always passes here.
 *
 * @group DB
 */
class ExportControllerIntegrationTest extends IntegrationTestCase {
	/**
	 * Two concrete responses whose answer shape (keyed by question id) matches
	 * exactly what ResponseService::buildExportData() reads, so the CSV/JSON/
	 * XLSX exporters all produce non-empty, meaningful output.
	 *
	 * The default form skeleton from FormFactory has no questions, so the export
	 * table would be header-only (the fixed Response ID / Submitted At /
	 * Respondent columns). We therefore also override the questions so answers
	 * land in real answer columns.
	 */
	private function makeFormWithResponses(string $title = 'Export IT Form'): File {
		$questions = [
			[
				'id' => 'q_name',
				'type' => 'text',
				'question' => 'Your name',
				'required' => true,
			],
			[
				'id' => 'q_color',
				'type' => 'choice',
				'question' => 'Favourite colour',
				'required' => false,
				'options' => [
					['id' => 'o1', 'label' => 'Red', 'value' => 'red'],
					['id' => 'o2', 'label' => 'Blue', 'value' => 'blue'],
				],
			],
		];

		$responses = [
			[
				'id' => 'resp-0001',
				'submitted_at' => '2026-01-01T10:00:00+00:00',
				'respondent' => [
					'type' => 'anonymous',
					'fingerprint' => 'sha256:aaaa',
				],
				'answers' => [
					'q_name' => 'Alice',
					'q_color' => 'red',
				],
			],
			[
				'id' => 'resp-0002',
				'submitted_at' => '2026-01-02T11:30:00+00:00',
				'respondent' => [
					'type' => 'user',
					'user_id' => $this->ownerUid,
					'display_name' => 'Owner',
				],
				'answers' => [
					'q_name' => 'Bob',
					'q_color' => 'blue',
				],
			],
		];

		return $this->writeFormFile($this->userFolder, $title, [
			'questions' => $questions,
			'responses' => $responses,
		]);
	}

	/** Read the Content-Type header the controller set on the download response. */
	private function contentTypeOf(DataDownloadResponse $response): string {
		$headers = $response->getHeaders();
		return (string)($headers['Content-Type'] ?? '');
	}

	public function testExportCsvReturnsDownload(): void {
		$file = $this->makeFormWithResponses('CSV Export Form');
		/** @var ExportController $controller */
		$controller = $this->getService(ExportController::class);

		$response = $controller->exportCsv($file->getId());

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('text/csv', $this->contentTypeOf($response));

		$body = $response->render();
		$this->assertNotSame('', $body, 'CSV body must be non-empty for a form with responses');
		// UTF-8 BOM prepended by ResponseService::exportCsv().
		$this->assertStringStartsWith("\xEF\xBB\xBF", $body);
		// Header column and both respondent answers land in the CSV.
		$this->assertStringContainsString('Response ID', $body);
		$this->assertStringContainsString('Alice', $body);
		$this->assertStringContainsString('Bob', $body);
		// choice option value mapped to its label.
		$this->assertStringContainsString('Red', $body);
	}

	public function testExportJsonReturnsDownload(): void {
		$file = $this->makeFormWithResponses('JSON Export Form');
		/** @var ExportController $controller */
		$controller = $this->getService(ExportController::class);

		$response = $controller->exportJson($file->getId());

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('application/json', $this->contentTypeOf($response));

		$body = $response->render();
		$this->assertNotSame('', $body);

		$decoded = json_decode($body, true);
		$this->assertIsArray($decoded, 'JSON export body must decode to an array');
		$this->assertSame(JSON_ERROR_NONE, json_last_error());
		$this->assertSame('JSON Export Form', $decoded['title']);
		$this->assertArrayHasKey('exportedAt', $decoded);
		$this->assertArrayHasKey('questions', $decoded);
		$this->assertArrayHasKey('responses', $decoded);
		$this->assertCount(2, $decoded['responses']);
		$this->assertSame('Alice', $decoded['responses'][0]['answers']['q_name']);
	}

	public function testExportExcelReturnsXlsx(): void {
		// ResponseService::exportXlsx() builds the workbook with the bundled
		// \ZipArchive extension — no external Composer/library dependency.
		if (!class_exists(\ZipArchive::class)) {
			$this->markTestSkipped('ext-zip (\ZipArchive) is not available; xlsx export cannot be built');
		}

		$file = $this->makeFormWithResponses('Excel Export Form');
		/** @var ExportController $controller */
		$controller = $this->getService(ExportController::class);

		$response = $controller->exportExcel($file->getId());

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString(
			'spreadsheetml.sheet',
			$this->contentTypeOf($response)
		);

		$body = $response->render();
		$this->assertNotSame('', $body, 'XLSX body must be non-empty for a form with responses');
		// A .xlsx is a ZIP container — the local file header magic is "PK\x03\x04".
		$this->assertStringStartsWith("PK\x03\x04", $body);
	}

	public function testDownloadOdtTemplateStatus(): void {
		// Fresh form, no ODT template uploaded → hasTemplate must be false.
		$file = $this->makeFormWithResponses('ODT Status Form');
		/** @var ExportController $controller */
		$controller = $this->getService(ExportController::class);

		$response = $controller->hasOdtTemplate($file->getId());

		$this->assertInstanceOf(DataResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('hasTemplate', $data);
		$this->assertFalse($data['hasTemplate']);
	}

	/**
	 * Guard: the fixture responses match the shape ResponseService actually
	 * reads. If ResponseService's export API drifts, this catches it before the
	 * download-body assertions above give a confusing empty result.
	 */
	public function testResponseServiceExportProducesNonEmptyForFixture(): void {
		$file = $this->makeFormWithResponses('Sanity Export Form');
		/** @var ResponseService $service */
		$service = $this->getService(ResponseService::class);

		$csv = $service->exportCsv($file->getId());
		$this->assertNotSame('', $csv);
		$this->assertStringContainsString('Alice', $csv);
	}
}
