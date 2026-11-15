<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Pdf;

use OCA\FormVox\Pdf\Brand;
use OCA\FormVox\Pdf\PdfRenderer;
use OCA\FormVox\Pdf\ResponseDocument;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\L10N\IFactory;
use OCP\Server;

/**
 * Inside Nextcloud: dompdf loads from lib/Vendor under its own names, and a response becomes a PDF in the
 * respondent's language.
 *
 * @group DB
 */
class PdfRendererIntegrationTest extends IntegrationTestCase {
	public function testAResponseBecomesAPdf(): void {
		$form = ['title' => 'Anmeldung', 'questions' => [['id' => 'n', 'type' => 'text', 'question' => 'Name']]];
		$response = ['id' => 'r-1', 'submitted_at' => date('c'), 'answers' => ['n' => 'Jörg']];
		$html = Server::get(ResponseDocument::class)->html($form, $response, Server::get(IFactory::class)->get('formvox', 'de'));

		$pdf = Server::get(PdfRenderer::class)->render($html);

		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertTrue(class_exists(\OCA\FormVox\Vendor\Dompdf\Dompdf::class, false));
		$this->assertFalse(class_exists('Dompdf\Dompdf', false), 'no unprefixed copy that another app could clash with');
	}

	/** Where this Nextcloud keeps its theming logo differs per version: the PDF finds it on each. */
	public function testTheInstancesOwnLogoHeadsThePdf(): void {
		$config = Server::get(IConfig::class);
		$images = Server::get(IAppDataFactory::class)->get('theming');
		try {
			$global = $images->getFolder('global');
		} catch (NotFoundException) {
			$global = $images->newFolder('global');
		}
		try {
			$folder = $global->getFolder('images');
		} catch (NotFoundException) {
			$folder = $global->newFolder('images');
		}
		$image = imagecreatetruecolor(40, 20);
		ob_start();
		imagepng($image);
		$png = (string)ob_get_clean();
		$form = ['title' => 'Anmeldung', 'questions' => []];
		$response = ['id' => 'r-1', 'answers' => []];
		$l = Server::get(IFactory::class)->get('formvox', 'de');

		$this->assertNull(Server::get(Brand::class)->header()['logo'], 'no own logo, none in the PDF');
		$folder->newFile('logo', $png);
		$config->setAppValue('theming', 'logoMime', 'image/png');
		try {
			$this->assertSame('data:image/png;base64,' . base64_encode($png), Server::get(Brand::class)->header()['logo']);
			$pdf = Server::get(PdfRenderer::class)->render(Server::get(ResponseDocument::class)->html($form, $response, $l));
			$this->assertStringContainsString('/Subtype /Image', $pdf);
		} finally {
			$config->deleteAppValue('theming', 'logoMime');
			$folder->getFile('logo')->delete();
		}
	}
}
