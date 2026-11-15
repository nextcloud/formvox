<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Pdf;

use OCA\FormVox\Pdf\PdfStore;
use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Server;

/**
 * A response's PDF is kept beside the form in .formvox-pdfs-{id}/, as its uploads are: it moves with the form and goes
 * with its response and with the form.
 *
 * @group DB
 */
class ResponsePdfIntegrationTest extends IntegrationTestCase {
	private const RESPONSE = '3f2a9c1e-77b0-4c1d-9e2a-0b1c2d3e4f50';

	private function formWithResponse(?Folder $folder = null): File {
		return $this->writeFormFile($folder, 'Pdf ' . uniqid(), [
			'settings' => ['keepResponsePdf' => true],
			'questions' => [['id' => 'n', 'type' => 'text', 'question' => 'Name']],
			'responses' => [['id' => self::RESPONSE, 'submitted_at' => date('c'), 'language' => 'de', 'answers' => ['n' => 'Jörg']]],
		]);
	}

	/** The one PDF kept for the response, in its own folder, named by its revision. */
	private function kept(File $form): ?File {
		try {
			$own = $form->getParent()->get(PdfStore::FOLDER . $form->getId() . '/' . self::RESPONSE);
		} catch (\OCP\Files\NotFoundException) {
			return null;
		}
		$files = $own instanceof Folder ? $own->getDirectoryListing() : [];
		return count($files) === 1 && $files[0] instanceof File ? $files[0] : null;
	}

	/** Two writers take the PDF's name under Nextcloud's own lock: the store must get it from the container. */
	public function testTheStoreTakesNextcloudsLock(): void {
		$this->assertInstanceOf(\OCP\Lock\ILockingProvider::class, (new \ReflectionProperty(PdfStore::class, 'locks'))->getValue(Server::get(PdfStore::class)));
	}

	public function testItIsKeptBesideTheFormAndGivenAgain(): void {
		$form = $this->formWithResponse();
		$stored = $this->readFormFromStorage($form);
		$pdfs = Server::get(ResponsePdf::class);

		$pdf = $pdfs->of($form, $stored, $stored['responses'][0]);

		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertSame($pdf, $this->kept($form)?->getContent());
		$this->assertSame($pdf, $pdfs->of($form, ['title' => 'changed since'] + $stored, $stored['responses'][0]), 'the same document again');
	}

	/**
	 * A write that stopped halfway leaves no PDF under its name: it is never found, and a later write clears it once its
	 * writer has surely stopped. Another writer's part that may still be written is left to it.
	 */
	public function testAHalfWrittenPdfIsNeverFound(): void {
		$form = $this->formWithResponse();
		$stored = $this->readFormFromStorage($form);
		$response = $stored['responses'][0];
		$revision = ResponsePdf::revision($response);
		$own = $form->getParent()->newFolder(PdfStore::FOLDER . $form->getId())->newFolder(self::RESPONSE);
		$own->newFile($revision . '.0000aaaa.tmp', '%PDF-1.7 cut off')->touch(time() - 7200);
		$own->newFile($revision . '.0000bbbb.tmp', '%PDF-1.7 still being written');

		$this->assertNull(Server::get(ResponsePdf::class)->stored($form, $response));
		$pdf = Server::get(ResponsePdf::class)->of($form, $stored, $response);

		$names = array_map(fn ($node) => $node->getName(), $own->getDirectoryListing());
		sort($names);
		$this->assertSame([$revision . '.0000bbbb.tmp', $revision . '.pdf'], $names);
		$this->assertSame($pdf, Server::get(ResponsePdf::class)->stored($form, $response)?->getContent());
	}

	/**
	 * Whoever still holds the response as it was (a ZIP export, the job, a download that had begun) keeps its older PDF
	 * late: the PDF of the response as it is now, the one a webhook named, stays.
	 */
	public function testAnOlderRevisionKeptLateLeavesTheCurrentOne(): void {
		$form = $this->formWithResponse();
		$stored = $this->readFormFromStorage($form);
		$old = $stored['responses'][0];
		$pdfs = Server::get(ResponsePdf::class);
		$pdfs->of($form, $stored, $old);
		// As a change through the API does: the PDF goes, the answers change.
		$pdfs->forget($form, self::RESPONSE);
		$new = ['answers' => ['n' => 'Jörg Müller'], 'updated_at' => date('c')] + $old;
		$current = $pdfs->of($form, $stored, $new);

		$pdfs->of($form, $stored, $old);

		$this->assertSame($current, $pdfs->stored($form, $new)?->getContent(), 'the PDF of the response as it is now stays');
	}

	public function testItMovesWithTheForm(): void {
		$form = $this->formWithResponse();
		$stored = $this->readFormFromStorage($form);
		Server::get(ResponsePdf::class)->of($form, $stored, $stored['responses'][0]);
		$target = $this->userFolder->newFolder('Pdf moved ' . uniqid());

		$this->reconnectNodeEvents();
		$moved = $form->move($target->getPath() . '/' . $form->getName());

		$this->assertNotNull($this->kept($moved));
	}

	/**
	 * Someone the form file alone is shared with deletes every response before its files are moved to app data: its
	 * PDFs and uploads go from beside the owner's form, which is not in their own folder.
	 */
	public function testDeletingAllAsSomeoneItIsSharedWithTakesTheFilesBesideTheForm(): void {
		$form = $this->formWithResponse();
		$stored = $this->readFormFromStorage($form);
		Server::get(ResponsePdf::class)->of($form, $stored, $stored['responses'][0]);
		$parent = $form->getParent();
		$parent->newFolder('.formvox-uploads-' . $form->getId())->newFolder('5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f')->newFile('cv.pdf', 'from a respondent');
		$this->assertNotNull($this->kept($form));
		$shares = Server::get(\OCP\Share\IManager::class);
		$this->ensureUser('formvox-int-other');
		$share = $shares->newShare();
		$share->setNode($form)
			->setShareType(\OCP\Share\IShare::TYPE_USER)
			->setSharedWith('formvox-int-other')
			->setSharedBy($this->ownerUid)
			->setShareOwner($this->ownerUid)
			->setPermissions(\OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_UPDATE);
		$shares->createShare($share);

		$this->switchToUser('formvox-int-other');
		$theirs = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('formvox-int-other')->getFirstNodeById($form->getId());
		$this->assertInstanceOf(File::class, $theirs);
		Server::get(ResponsePersistenceService::class)->deleteAll($theirs);
		$this->switchToUser($this->ownerUid);

		$this->assertFalse($parent->nodeExists(PdfStore::FOLDER . $form->getId()), 'the PDFs are gone');
		$this->assertFalse($parent->nodeExists('.formvox-uploads-' . $form->getId()), 'the uploads are gone');
	}

	public function testItGoesWithItsResponseAndWithTheForm(): void {
		$form = $this->formWithResponse();
		$stored = $this->readFormFromStorage($form);
		Server::get(ResponsePdf::class)->of($form, $stored, $stored['responses'][0]);

		Server::get(ResponsePersistenceService::class)->delete($form, self::RESPONSE);
		$this->assertNull($this->kept($form), 'gone with its response');

		Server::get(ResponsePdf::class)->of($form, $stored, $stored['responses'][0]);
		$parent = $form->getParent();
		$folder = PdfStore::FOLDER . $form->getId();
		$this->reconnectNodeEvents();
		$form->delete();
		$this->assertFalse($parent->nodeExists($folder), 'gone with the form');
	}
}
