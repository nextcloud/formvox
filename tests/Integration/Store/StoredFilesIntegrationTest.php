<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Service\BrandingService;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\OdtTemplateService;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Server;

/**
 * Against a real Nextcloud: a form in the database keeps its respondents' files, PDFs, branding images and ODT template
 * in FormVox's app data, by its own id, and nothing beside the form; a form file keeps them beside it.
 *
 * @group DB
 */
class StoredFilesIntegrationTest extends IntegrationTestCase {
	private const GROUP = '6c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f';
	private const BLOCK = 'logo1';

	private int $fileId = 0;

	protected function setUp(): void {
		parent::setUp();
		$repository = Server::get(FormRepository::class);
		$this->fileId = (int)$repository->create('Stored files ' . uniqid())['fileId'];
		$repository->update($this->fileId, [
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name'], ['id' => 'cv', 'type' => 'file', 'question' => 'CV']],
			'settings' => ['allow_multiple' => true, 'anonymous' => false, 'keepResponsePdf' => true],
		]);
	}

	private function formFile(): File {
		return Server::get(FormFileLocator::class)->getFileById($this->fileId);
	}

	/** Where in app data this form's files of a kind are kept. */
	private function home(string $kind): string {
		$formId = Server::get(FormStore::class)->byFileId($this->fileId)->id;
		return '/' . Server::get(IRootFolder::class)->getAppDataDirectoryName() . '/formvox/forms/' . $formId . '/' . $kind . '/';
	}

	private function assertNothingBesideTheForm(File $form): void {
		foreach ($form->getParent()->getDirectoryListing() as $node) {
			$this->assertStringNotContainsString('.formvox-', $node->getName());
		}
	}

	public function testUploadsAreKeptInAppDataAndServedAndRemovedFromThere(): void {
		$uploads = Server::get(UploadService::class);
		$stored = $uploads->storeUploadPublic($this->fileId, self::GROUP, $this->makeUploadedFile('cv.pdf', 'my cv', 'application/pdf'));
		$uploads->storeUploadPublic($this->fileId, self::GROUP, $this->makeUploadedFile('cv.pdf', 'my other cv', 'application/pdf'));
		$form = $this->formFile();

		$file = $uploads->getUpload($form, self::GROUP, $stored['filename']);
		$this->assertStringStartsWith($this->home('uploads') . self::GROUP . '/', $file->getPath());
		$this->assertSame('my cv', $file->getContent());
		$this->assertSame(2, $uploads->countUploadsPublic($this->fileId, self::GROUP));
		$this->assertNothingBesideTheForm($form);

		$uploads->deleteUpload($form, self::GROUP, $stored['filename']);
		$this->assertSame(1, $uploads->countUploadsPublic($this->fileId, self::GROUP));
		$uploads->deleteResponseUploads($form, self::GROUP);
		$this->assertSame(0, $uploads->countUploadsPublic($this->fileId, self::GROUP));

		$uploads->storeUploadPublic($this->fileId, self::GROUP, $this->makeUploadedFile('cv.pdf', 'again', 'application/pdf'));
		$uploads->deleteAllUploads($form);
		$this->assertSame(0, $uploads->countUploadsPublic($this->fileId, self::GROUP));
	}

	public function testBrandingAndTheOdtTemplateAreKeptInAppData(): void {
		$form = $this->formFile();
		$branding = Server::get(BrandingService::class);
		$image = $this->makeUploadedFile('logo.png', "\x89PNG\r\n\x1a\nlogo", 'image/png');
		$branding->saveFormBlockImage($form, self::BLOCK, $image['tmp_name'], 'image/png');
		$this->assertSame("\x89PNG\r\n\x1a\nlogo", $branding->getFormBlockImage($this->fileId, self::BLOCK)['content'] ?? null, 'a respondent sees it');

		$templates = Server::get(OdtTemplateService::class);
		$templates->storeOdtTemplate($form, $this->makeUploadedFile('template.odt', 'odt bytes'));
		$this->assertStringStartsWith($this->home('templates'), $templates->getOdtTemplate($form)->getPath());
		$this->assertNothingBesideTheForm($form);

		$branding->deleteFormBlockImage($form, self::BLOCK);
		$templates->deleteOdtTemplate($form);
		$this->assertNull($branding->getFormBlockImage($this->fileId, self::BLOCK));
		$this->assertFalse($templates->hasOdtTemplate($form));
	}

	public function testAResponsesPdfIsKeptInAppDataUntilItIsForgotten(): void {
		$repository = Server::get(FormRepository::class);
		$response = Server::get(ResponseService::class)->submitAuthenticated($this->fileId, $repository->loadPublic($this->fileId), ['q1' => 'Bob'], 'formvox-int-respondent', 'Bob');
		$form = $this->formFile();
		$pdfs = Server::get(ResponsePdf::class);
		$definition = $repository->loadForm($this->fileId);
		$response = $repository->response($this->fileId, $definition, $response['id']);

		$this->assertStringStartsWith('%PDF-', $pdfs->of($form, $definition, $response));
		$kept = $pdfs->stored($form, $response);
		$this->assertNotNull($kept);
		$this->assertStringStartsWith($this->home('pdfs'), $kept->getPath());
		$this->assertNothingBesideTheForm($form);

		$pdfs->forget($form, $response['id']);
		$this->assertNull($pdfs->stored($form, $response));
	}

	public function testAFormFileKeepsItsFilesBesideIt(): void {
		$form = $this->writeFormFile(null, 'File form ' . uniqid(), ['questions' => [['id' => 'cv', 'type' => 'file', 'question' => 'CV']]]);
		Server::get(UploadService::class)->storeUploadPublic($form->getId(), self::GROUP, $this->makeUploadedFile('cv.pdf', 'beside', 'application/pdf'));

		$this->assertTrue($form->getParent()->nodeExists('.formvox-uploads-' . $form->getId() . '/' . self::GROUP));
	}
}
