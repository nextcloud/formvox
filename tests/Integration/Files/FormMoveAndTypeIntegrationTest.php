<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Files;

use OCA\FormVox\Listener\FormMovedListener;
use OCA\FormVox\Migration\RegisterMimeType;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Against a REAL Nextcloud (7a, 3a-6): a form moved to another folder takes its uploads along, and a folder named like
 * a form stays a folder, also one an earlier version marked as a form.
 *
 * @group DB
 */
class FormMoveAndTypeIntegrationTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->reconnectNodeEvents();
	}

	public function testAMovedFormTakesItsUploadsAlong(): void {
		$from = $this->userFolder->newFolder('Move From ' . uniqid());
		$to = $this->userFolder->newFolder('Move To ' . uniqid());
		$form = $this->writeFormFile($from, 'Moving');
		$from->newFolder('.formvox-uploads-' . $form->getId())->newFile('cv.pdf', 'a respondent\'s file');

		$moved = $form->move($to->getPath() . '/' . $form->getName());

		$this->assertSame('a respondent\'s file', $to->get('.formvox-uploads-' . $moved->getId() . '/cv.pdf')->getContent());
		$this->assertFalse($from->nodeExists('.formvox-uploads-' . $form->getId()));
	}

	/** A folder moved within one storage keeps its forms' ids, so their folders stay theirs (review of the 3a fixes). */
	public function testAFolderMovedWithinOneStorageKeepsItsForms(): void {
		$project = $this->userFolder->newFolder('Project ' . uniqid());
		$form = $this->writeFormFile($project->newFolder('Sub'), 'Inside');
		$project->get('Sub')->newFolder('.formvox-uploads-' . $form->getId())->newFile('cv.pdf', 'a respondent\'s file');

		$moved = $project->move($this->userFolder->getPath() . '/Moved ' . uniqid());

		$this->assertSame($form->getId(), $moved->get('Sub/' . $form->getName())->getId());
		$this->assertSame('a respondent\'s file', $moved->get('Sub/.formvox-uploads-' . $form->getId() . '/cv.pdf')->getContent());
	}

	/** Nextcloud's own search finds the forms that move with a folder, by their path below it. */
	public function testTheFormsBelowAFolderAreFound(): void {
		$project = $this->userFolder->newFolder('Project ' . uniqid());
		$form = $this->writeFormFile($project->newFolder('Sub'), 'Inside');
		$listener = Server::get(FormMovedListener::class);

		$found = (new \ReflectionMethod($listener, 'formsBelow'))->invoke($listener, $project);

		$this->assertSame(['Sub/' . $form->getName() => $form->getId()], $found);
	}

	public function testAFolderNamedLikeAFormStaysAFolder(): void {
		$loader = Server::get(IMimeTypeLoader::class);
		$folder = $this->userFolder->newFolder('Archive ' . uniqid() . '.fvform');
		// What an earlier version did to such a folder.
		$this->setMimeType($folder->getId(), $loader->getId('application/x-fvform'));

		// The repair runs once per instance; this test makes it run again.
		Server::get(\OCP\IAppConfig::class)->deleteKey('formvox', 'folders_named_like_forms_repaired');
		Server::get(RegisterMimeType::class)->run(new \OC\Migration\NullOutput());

		$this->assertSame($loader->getId('httpd/unix-directory'), $this->mimeTypeOf($folder->getId()));
	}

	private function setMimeType(int $fileId, int $mimeType): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('filecache')->set('mimetype', $qb->createNamedParameter($mimeType, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function mimeTypeOf(int $fileId): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$result = $qb->select('mimetype')->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$type = (int)$result->fetchOne();
		$result->closeCursor();
		return $type;
	}
}
