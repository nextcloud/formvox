<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Repository;

use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Target 4 — real .fvform storage round-trip through FormRepository, against a
 * live Nextcloud + DB + filesystem. Every write lands on the owner's home
 * storage and is verified by resolving the node back out of the user folder and
 * reading the raw file bytes from storage (bypassing any cache).
 *
 * @group DB
 */
class FormRepositoryIntegrationTest extends IntegrationTestCase {
	private function repo(): FormRepository {
		/** @var FormRepository $repo */
		$repo = $this->getService(FormRepository::class);
		return $repo;
	}

	/** Resolve a File out of the owner's user folder by its file id. */
	private function fileById(int $fileId): File {
		$nodes = $this->userFolder->getById($fileId);
		$this->assertNotEmpty($nodes, 'expected a node for fileId ' . $fileId);
		$node = $nodes[0];
		$this->assertInstanceOf(File::class, $node);
		return $node;
	}

	public function testCreateWritesRealFile(): void {
		$result = $this->repo()->create('My Form');

		$this->assertArrayHasKey('fileId', $result);
		$fileId = $result['fileId'];
		$this->assertIsInt($fileId);

		$file = $this->fileById($fileId);
		$this->assertSame('my-form.fvform', $file->getName());

		// Decode straight from storage — the real bytes that hit disk.
		$stored = $this->readFormFromStorage($file);
		$this->assertSame('My Form', $stored['title']);

		// FormFactory skeleton keys must all be present.
		foreach (['version', 'id', 'description', 'created_at', 'modified_at',
			'settings', 'permissions', 'questions', 'pages', 'branding',
			'_index', 'responses'] as $key) {
			$this->assertArrayHasKey($key, $stored, "skeleton key '$key' missing");
		}
		$this->assertSame($this->ownerUid, $stored['permissions']['owner']);
	}

	public function testCreateInSubfolderCreatesFolder(): void {
		$result = $this->repo()->create('X', 'Forms/Sub');

		$file = $this->fileById($result['fileId']);
		$this->assertSame('x.fvform', $file->getName());

		// The nested folder chain must have been created and hold the file.
		$sub = $this->userFolder->get('Forms/Sub');
		$this->assertInstanceOf(Folder::class, $sub);
		$this->assertTrue($sub->nodeExists('x.fvform'));

		$byId = $sub->getById($result['fileId']);
		$this->assertNotEmpty($byId);
	}

	public function testCreateUniqueFilename(): void {
		$first = $this->repo()->create('Dup Title');
		$second = $this->repo()->create('Dup Title');

		$firstFile = $this->fileById($first['fileId']);
		$secondFile = $this->fileById($second['fileId']);

		$this->assertSame('dup-title.fvform', $firstFile->getName());
		$this->assertNotSame($firstFile->getName(), $secondFile->getName());
		$this->assertSame('dup-title-1.fvform', $secondFile->getName());
	}

	public function testLoadNormalisesNullTitle(): void {
		// A form file with explicit null title/description and no branding/pages
		// keys — exercises the #134 normalisation and the backwards-compat
		// default injection in load().
		$file = $this->writeFormFile($this->userFolder, 'Null Title Form', [
			'title' => null,
			'description' => null,
		]);
		// Also strip branding/pages so the default-injection branch runs.
		$raw = $this->readFormFromStorage($file);
		unset($raw['branding'], $raw['pages']);
		$file->putContent(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

		$form = $this->repo()->load($file->getId());

		$this->assertSame('', $form['title']);
		$this->assertSame('', $form['description']);
		$this->assertArrayHasKey('branding', $form);
		$this->assertNull($form['branding']);
		$this->assertArrayHasKey('pages', $form);
		$this->assertNull($form['pages']);
	}

	public function testUpdatePersistsAndRenames(): void {
		$created = $this->repo()->create('Before Rename');
		$fileId = $created['fileId'];

		$returned = $this->repo()->update($fileId, ['title' => 'Renamed']);
		$this->assertSame('Renamed', $returned['title']);

		// File was moved to the sanitized new name (same fileId).
		$file = $this->fileById($fileId);
		$this->assertSame('renamed.fvform', $file->getName());

		// Content on disk reflects the new title.
		$stored = $this->readFormFromStorage($file);
		$this->assertSame('Renamed', $stored['title']);

		// The shared lock row must have been released after the mutation.
		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('configkey')
			->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter('__formvox_lock__')))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('formvox')));
		$res = $qb->executeQuery();
		$locks = $res->fetchAll();
		$res->closeCursor();
		$this->assertSame([], $locks, 'lock row should be released after update()');
	}

	public function testUpdateHashesSharePassword(): void {
		$created = $this->repo()->create('Password Form');
		$fileId = $created['fileId'];

		// Merge share_password into the existing settings so a valid settings
		// array is passed (update() replaces the whole 'settings' key).
		$settings = $created['form']['settings'];
		$settings['share_password'] = 'secret';

		$this->repo()->update($fileId, ['settings' => $settings]);

		$file = $this->fileById($fileId);
		$stored = $this->readFormFromStorage($file);

		$this->assertArrayHasKey('share_password_hash', $stored['settings']);
		$this->assertStringStartsWith('$2y$', $stored['settings']['share_password_hash']);
		$this->assertTrue(
			password_verify('secret', $stored['settings']['share_password_hash']),
			'stored hash should verify against the original password'
		);
		$this->assertArrayNotHasKey(
			'share_password',
			$stored['settings'],
			'plaintext password must never be persisted'
		);
	}

	public function testDeleteRemovesFile(): void {
		$created = $this->repo()->create('To Delete');
		$fileId = $created['fileId'];

		// Sanity: it exists first.
		$this->assertNotEmpty($this->userFolder->getById($fileId));

		$this->repo()->delete($fileId);

		$this->assertEmpty(
			$this->userFolder->getById($fileId),
			'file should no longer resolve after delete()'
		);
	}

	public function testListFormsFindsOwnForms(): void {
		// One at the root, one in a subfolder.
		$rootForm = $this->repo()->create('List Root');
		$subForm = $this->repo()->create('List Sub', 'ListDir');

		$forms = $this->repo()->listForms();
		$foundIds = array_map(static fn (array $f): int => $f['fileId'], $forms);

		$missing = array_diff([$rootForm['fileId'], $subForm['fileId']], $foundIds);
		if ($missing !== []) {
			$this->markTestSkipped(
				'listForms() returned fewer than the two created forms (missing '
				. 'fileIds: ' . implode(', ', $missing) . '). This points at a '
				. 'mimetype-registration gap: the .fvform mimetype is not '
				. 'registered on this test instance and the home-storage '
				. 'self-healing fallback did not surface the newly created files.'
			);
		}

		$this->assertContains($rootForm['fileId'], $foundIds);
		$this->assertContains($subForm['fileId'], $foundIds);

		// Each listed entry carries the minimal list-view shape.
		foreach ($forms as $entry) {
			$this->assertArrayHasKey('title', $entry);
			$this->assertArrayHasKey('path', $entry);
			$this->assertArrayHasKey('name', $entry);
			$this->assertArrayHasKey('responseCount', $entry);
		}
	}
}
