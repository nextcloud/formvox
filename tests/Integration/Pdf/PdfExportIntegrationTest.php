<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Pdf;

use OCA\FormVox\BackgroundJob\PdfPrepareJob;
use OCA\FormVox\Controller\ExportController;
use OCA\FormVox\Pdf\PdfExport;
use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\OdtTemplateService;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Http\ZipResponse;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\IRequest;
use OCP\L10N\IFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * "Export PDFs (ZIP)" as Nextcloud sends it: kept PDFs and those made in time are in the ZIP whole; what had no time is
 * made by PdfPrepareJob, and a note in the ZIP says so.
 *
 * @group DB
 */
class PdfExportIntegrationTest extends IntegrationTestCase {
	private const NAME = 'Anmeldung-fuer-das-Schulfest_2026-10-27_';
	private const RESPONSES = ['3f2a9c1e-77b0-4c1d-9e2a-0b1c2d3e4f51', '3f2a9c1e-77b0-4c1d-9e2a-0b1c2d3e4f52', '3f2a9c1e-77b0-4c1d-9e2a-0b1c2d3e4f53'];

	private function form(): File {
		return $this->writeFormFile(null, 'Pdf export ' . uniqid(), [
			'title' => 'Anmeldung für das Schulfest',
			'settings' => ['keepResponsePdf' => true],
			'questions' => [['id' => 'n', 'type' => 'text', 'question' => 'Name']],
			'responses' => array_map(fn (string $id) => ['id' => $id, 'submitted_at' => '2026-10-27T09:42:00+00:00', 'answers' => ['n' => 'Jörg ' . $id]], self::RESPONSES),
		]);
	}

	private function controller(float $budget): ExportController {
		$export = new PdfExport(Server::get(ResponsePdf::class), Server::get(IJobList::class), Server::get(LoggerInterface::class), $budget);
		return new ExportController(Server::get(IRequest::class), Server::get(FormRepository::class), Server::get(FormAccessService::class),
			Server::get(ResponseService::class), Server::get(OdtTemplateService::class), $export, Server::get(IFactory::class)->get('formvox', 'en'));
	}

	/**
	 * What the ZIP is given, by name. Its streamer closes every output buffer, so the files are read where it would read
	 * them; each must hold exactly the size it was given, or a tar for macOS browsers is broken.
	 *
	 * @return array<string, string>
	 */
	private function unzip(ZipResponse $response): array {
		$out = [];
		foreach ((new \ReflectionProperty(ZipResponse::class, 'resources'))->getValue($response) as $file) {
			$content = (string)stream_get_contents($file['resource']);
			$this->assertSame($file['size'], strlen($content), $file['internalName']);
			$out[$file['internalName']] = $content;
		}
		return $out;
	}

	public function testKeptAndNewlyMadePdfsAreAllInTheZipWhole(): void {
		$form = $this->form();
		$stored = $this->readFormFromStorage($form);
		Server::get(ResponsePdf::class)->of($form, $stored, $stored['responses'][0]);

		$files = $this->unzip($this->controller(20.0)->exportPdfs($form->getId()));

		$this->assertCount(4, $files);
		$manifest = array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($files['manifest.csv'] ?? '')));
		$this->assertSame(['response_id', 'file', 'status', 'sha256', 'size'], $manifest[0], 'manifest.csv comes last');
		$this->assertSame('manifest.csv', array_key_last($files));
		foreach (self::RESPONSES as $n => $id) {
			$pdf = $files[self::NAME . $id . '.pdf'] ?? '';
			$this->assertStringStartsWith('%PDF-', $pdf, $id);
			$this->assertStringContainsString('%%EOF', substr($pdf, -1024), $id);
			$this->assertSame([$id, self::NAME . $id . '.pdf', 'included', hash('sha256', $pdf), (string)strlen($pdf)], $manifest[$n + 1], 'the checksum of what is in the ZIP');
		}
	}

	/**
	 * Someone the form file itself is shared with gets the PDFs kept beside the owner's form, not a ZIP that waits for
	 * PDFs nobody will make.
	 */
	public function testSomeoneItIsSharedWithGetsTheKeptPdfs(): void {
		$form = $this->form();
		$stored = $this->readFormFromStorage($form);
		foreach ($stored['responses'] as $response) {
			Server::get(ResponsePdf::class)->of($form, $stored, $response);
		}
		$shares = Server::get(\OCP\Share\IManager::class);
		$this->ensureUser('formvox-int-other');
		$share = $shares->newShare();
		$share->setNode($form)
			->setShareType(\OCP\Share\IShare::TYPE_USER)
			->setSharedWith('formvox-int-other')
			->setSharedBy($this->ownerUid)
			->setShareOwner($this->ownerUid)
			->setPermissions(\OCP\Constants::PERMISSION_READ);
		$shares->createShare($share);

		$this->switchToUser('formvox-int-other');
		$files = $this->unzip($this->controller(0.0)->exportPdfs($form->getId()));

		$this->assertSame([...array_map(fn (string $id) => self::NAME . $id . '.pdf', self::RESPONSES), 'manifest.csv'], array_keys($files), 'all kept ones, no note');
	}

	/** Out of time: the kept PDF goes in, a note says the rest is coming, and the background job makes it. */
	public function testWhatHadNoTimeIsMadeInTheBackgroundAndANoteSaysSo(): void {
		$form = $this->form();
		$stored = $this->readFormFromStorage($form);
		Server::get(ResponsePdf::class)->of($form, $stored, $stored['responses'][0]);
		$jobs = Server::get(IJobList::class);
		$argument = ['fileId' => $form->getId()];

		try {
			$files = $this->unzip($this->controller(0.0)->exportPdfs($form->getId()));

			$this->assertSame([self::NAME . self::RESPONSES[0] . '.pdf', 'README.txt', 'manifest.csv'], array_keys($files));
			$this->assertStringContainsString("\n" . self::RESPONSES[1] . ",,pending,,\n", $files['manifest.csv']);
			$this->assertSame("PDFs still being made: 2. Download the ZIP again in a few minutes.\n", $files['README.txt']);
			$this->assertTrue($jobs->has(PdfPrepareJob::class, $argument), 'the rest is made in the background');

			$job = Server::get(PdfPrepareJob::class);
			(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);

			$this->assertSame([...array_map(fn (string $id) => self::NAME . $id . '.pdf', self::RESPONSES), 'manifest.csv'], array_keys($this->unzip($this->controller(0.0)->exportPdfs($form->getId()))), 'all there, none made in the request, no note');
		} finally {
			$jobs->remove(PdfPrepareJob::class, $argument);
		}
	}
}
