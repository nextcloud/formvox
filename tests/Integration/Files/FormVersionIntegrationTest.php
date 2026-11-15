<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Files;

use OCA\Files_Versions\Versions\IVersionManager;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Server;

/**
 * A response written into a form leaves no version behind: each one used to keep a full copy of the form with
 * every answer, as version cleanup had been off since 1.4.6 (qual#12, qual#30). A change to the form itself is
 * kept as a version, as for any file (PO 5).
 *
 * @group DB
 */
class FormVersionIntegrationTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		if (!Server::get(IAppManager::class)->isEnabledForUser('files_versions')) {
			$this->markTestSkipped('files_versions is not enabled');
		}
		$this->reconnectNodeEvents();
	}

	private function versions(File $file): int {
		return count(Server::get(IVersionManager::class)->getVersionsForFile($this->owner, $this->userFolder->getFirstNodeById($file->getId())));
	}

	private function form(): File {
		return $this->writeFormFile($this->userFolder, 'Versions ' . uniqid(), [
			'settings' => ['allow_multiple' => true, 'anonymous' => true],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
		]);
	}

	/** A version is named after the file's time; two writes in one second would share one. */
	private function age(File $file): void {
		$this->userFolder->getFirstNodeById($file->getId())->touch(time() - 60);
	}

	/** Where every submission writes, without the public lookup the test filesystem cannot always do. */
	private function respond(File $file, string $name): void {
		Server::get(ResponsePersistenceService::class)->append($this->userFolder->getFirstNodeById($file->getId()), [
			'id' => bin2hex(random_bytes(16)), 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => ['q1' => $name],
		]);
	}

	public function testAResponseLeavesNoVersion(): void {
		$file = $this->form();
		$this->age($file);
		$before = $this->versions($file);

		$this->respond($file, 'Anna');
		$this->respond($file, 'Bob');

		$this->assertSame($before, $this->versions($file));
		$this->assertCount(2, Server::get(FormRepository::class)->load($file->getId())['responses']);
	}

	public function testAChangeToTheFormIsKeptAsAVersion(): void {
		$file = $this->form();
		$this->age($file);
		$before = $this->versions($file);

		Server::get(FormRepository::class)->update($file->getId(), ['description' => 'A change to the form']);

		$this->assertSame($before + 1, $this->versions($file));
	}

	public function testMarkingItAFavouriteIsNoChange(): void {
		$file = $this->form();
		$this->age($file);
		$before = $this->versions($file);

		Server::get(FormRepository::class)->update($file->getId(), ['favorite' => true]);

		$this->assertSame($before, $this->versions($file));
	}

	/** The versions a copy takes along are the original's, with its responses. */
	public function testACopyStartsWithoutTheOriginalsHistory(): void {
		$fresh = $this->versions($this->form());
		$file = $this->form();
		$this->age($file);
		Server::get(FormRepository::class)->update($file->getId(), ['description' => 'so it has a version']);
		$this->assertGreaterThan($fresh, $this->versions($file));

		/** @var File $copy */
		$copy = $this->userFolder->get($file->getName())->copy($this->userFolder->getPath() . '/Copy ' . uniqid() . '.fvform');

		$this->assertSame($fresh, $this->versions($copy));
	}

	/**
	 * After a write Nextcloud did not see (a cleaned copy, a change outside it), it has no record of the current
	 * version; a response written then must not make it log an error, and leaves the record in place.
	 */
	public function testAResponseAfterAWriteNextcloudDidNotSeeKeepsTheVersionRecord(): void {
		$file = $this->form();
		$node = $this->userFolder->getFirstNodeById($file->getId());
		$storage = $node->getStorage();
		$form = json_decode((string)$storage->file_get_contents($node->getInternalPath()), true);
		$form['description'] = 'changed outside Nextcloud';
		$storage->file_put_contents($node->getInternalPath(), (string)json_encode($form));
		$storage->touch($node->getInternalPath(), time() - 30);
		$storage->getUpdater()->update($node->getInternalPath());

		$this->respond($file, 'Anna');

		$mtime = $this->userFolder->getFirstNodeById($file->getId())->getMTime();
		$qb = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$rows = $qb->select('timestamp')->from('files_versions')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
			->executeQuery()->fetchAll();
		$this->assertContains($mtime, array_map('intval', array_column($rows, 'timestamp')));
	}
}
