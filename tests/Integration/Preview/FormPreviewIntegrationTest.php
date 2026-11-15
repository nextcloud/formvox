<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Preview;

use OCA\FormVox\Migration\RegisterMimeType;
use OCA\FormVox\Preview\FormPreviewReset;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\Server;

/**
 * Against a REAL Nextcloud: a form in Files shows the FormVox icon at the sizes the Files list and grid ask for, see-
 * through around the clipboard for the dark theme, and the thumbnails stored before the icon changed give way to it.
 *
 * @group DB
 */
class FormPreviewIntegrationTest extends IntegrationTestCase {
	private const PURPLE = [0xb6, 0x46, 0x9d];

	public function testAFormShowsTheFormVoxIconInTheFilesListAndGrid(): void {
		$form = $this->writeFormFile(null, 'Cooking workshop');
		$previews = Server::get(IPreview::class);

		$this->assertTrue($previews->isAvailable($form));
		// The Files list asks 32, the grid 128; Nextcloud serves 64 and 256.
		foreach ([32 => 64, 128 => 256] as $asked => $served) {
			$image = imagecreatefromstring($previews->getPreview($form, $asked, $asked, true)->getContent());
			$this->assertSame([$served, $served], [imagesx($image), imagesy($image)]);
			$this->assertSame(127, $this->rgba($image, 0, 0)[3], 'the corner is see-through');
			// The clipboard's body, left of the text field (x 6, y 20 on the icon's 32 grid).
			$body = $this->rgba($image, intdiv(6 * $served, 32), intdiv(20 * $served, 32));
			$this->assertEqualsWithDelta(self::PURPLE, array_slice($body, 0, 3), 3);
			$this->assertSame(0, $body[3]);
		}
	}

	public function testEveryFormGetsTheSameIconWhateverItHolds(): void {
		$previews = Server::get(IPreview::class);
		$one = $this->writeFormFile(null, 'Short');
		$other = $this->writeFormFile(null, 'A much longer title with many questions', ['description' => str_repeat('Text ', 200)]);

		$this->assertSame(
			$previews->getPreview($one, 64, 64, true)->getContent(),
			$previews->getPreview($other, 64, 64, true)->getContent(),
		);
	}

	public function testAFormInTheTrashKeepsItsIcon(): void {
		if (!Server::get(IAppManager::class)->isEnabledForUser('files_trashbin')) {
			$this->markTestSkipped('files_trashbin is not enabled');
		}
		$this->reconnectNodeEvents();
		$form = $this->writeFormFile(null, 'Trashed ' . uniqid());
		$name = $form->getName();
		$form->delete();
		// What the trash's preview gets: the node itself, named "<name>.d<timestamp>".
		$trashed = null;
		try {
			foreach ($this->rootFolder->get('/' . $this->ownerUid . '/files_trashbin/files')->getDirectoryListing() as $node) {
				if (str_starts_with($node->getName(), $name . '.d')) {
					$trashed = $node;
				}
			}
		} catch (NotFoundException) {
		}
		if (!$trashed instanceof File) {
			$this->markTestSkipped('this test filesystem deletes without the trash');
		}

		$previews = Server::get(IPreview::class);
		$this->assertTrue($previews->isAvailable($trashed));
		$image = imagecreatefromstring($previews->getPreview($trashed, 32, 32, true)->getContent());
		$this->assertEqualsWithDelta(self::PURPLE, array_slice($this->rgba($image, 12, 40), 0, 3), 3);
	}

	public function testTheOldThumbnailOfAFormGivesWayAndOtherFilesKeepTheirs(): void {
		$previews = Server::get(IPreview::class);
		$form = $this->writeFormFile(null, 'Stored thumbnail');
		$photo = $this->userFolder->newFile('photo-' . uniqid() . '.png', $this->png());
		$previews->getPreview($form, 64, 64, true);
		$previews->getPreview($photo, 64, 64, true);
		$this->assertTrue($this->hasStoredPreview($form));
		$this->assertTrue($this->hasStoredPreview($photo));

		Server::get(FormPreviewReset::class)->resetAll();

		$this->assertFalse($this->hasStoredPreview($form));
		$this->assertTrue($this->hasStoredPreview($photo));
		// The next request draws the icon again. Nextcloud 32's generator keeps the folder it knew for the rest of this
		// process (the reset runs in its own cron process), so only 33+ can show it here.
		if (Server::get(IDBConnection::class)->tableExists('previews')) {
			$this->assertNotSame('', $previews->getPreview($form, 64, 64, true)->getContent());
		}
	}

	public function testAThumbnailInNextcloud32sFoldersGoesTooUntilNextcloudHasMovedThem(): void {
		$form = $this->writeFormFile(null, 'Folder thumbnail');
		$appConfig = Server::get(IAppConfig::class);
		$moved = $appConfig->getValueBool('core', 'previewMovedDone');
		$id = (string)$form->getId();
		// Where Nextcloud 32 keeps a file's previews, and Nextcloud 33+ finds them until it has moved them.
		$path = implode('/', str_split(substr(md5($id), 0, 7))) . '/' . $id;
		$folders = Server::get(IAppDataFactory::class)->get('preview');
		$folders->newFolder($path)->newFile('64-64-crop.png', $this->png());
		$appConfig->setValueBool('core', 'previewMovedDone', false);
		try {
			Server::get(FormPreviewReset::class)->resetAll();
		} finally {
			$appConfig->setValueBool('core', 'previewMovedDone', $moved);
		}

		$this->assertFalse($this->previewFolderExists($path));
	}

	public function testWhereNoPreviewCanBeShownAFormFallsBackToThePlainFileIcon(): void {
		$this->withAliasFiles(function (string $config): void {
			// A new install: the admin's other aliases stay.
			file_put_contents($config, json_encode(['application/x-foo' => 'text']));
			$this->runRegisterMimeType();
			$this->assertSame(['application/x-foo' => 'text', 'application/x-fvform' => 'file'], $this->readJson($config));

			// What earlier versions set: an icon core does not have.
			file_put_contents($config, json_encode(['application/x-fvform' => 'formvox']));
			$this->runRegisterMimeType();
			$this->assertSame('file', $this->readJson($config)['application/x-fvform']);

			// An admin's own choice stays.
			file_put_contents($config, json_encode(['application/x-fvform' => 'x-office-form']));
			$this->runRegisterMimeType();
			$this->assertSame('x-office-form', $this->readJson($config)['application/x-fvform']);
		});
	}

	public function testAnAliasCoresListAlreadyHoldsStaysSoTheIntegrityCheckPasses(): void {
		$this->withAliasFiles(function (string $config, string $coreList): void {
			// What `occ maintenance:mimetype:update-js` wrote while the alias was 'formvox'.
			file_put_contents($coreList, str_replace('aliases: {', "aliases: {\n    \"application/x-fvform\": \"formvox\",", file_get_contents($coreList)));

			file_put_contents($config, json_encode(['application/x-fvform' => 'formvox']));
			$this->runRegisterMimeType();
			$this->assertSame('formvox', $this->readJson($config)['application/x-fvform']);

			file_put_contents($config, json_encode(['application/x-foo' => 'text']));
			$this->runRegisterMimeType();
			$this->assertSame('formvox', $this->readJson($config)['application/x-fvform']);
		});
	}

	public function testAnAliasFileThatDoesNotReadIsLeftAlone(): void {
		$this->withAliasFiles(function (string $config): void {
			$broken = '{"application/x-foo": "text",}';
			file_put_contents($config, $broken);
			$this->runRegisterMimeType();
			$this->assertSame($broken, file_get_contents($config));
		});
	}

	/**
	 * Runs $test with config/mimetypealiases.json and core's mimetypelist.js put back afterwards.
	 */
	private function withAliasFiles(callable $test): void {
		$config = \OC::$configDir . 'mimetypealiases.json';
		$coreList = \OC::$SERVERROOT . '/core/js/mimetypelist.js';
		$configBefore = file_exists($config) ? file_get_contents($config) : null;
		$coreListBefore = file_get_contents($coreList);
		try {
			$test($config, $coreList);
		} finally {
			$configBefore === null ? @unlink($config) : file_put_contents($config, $configBefore);
			file_put_contents($coreList, $coreListBefore);
		}
	}

	private function readJson(string $file): array {
		return json_decode(file_get_contents($file), true);
	}

	private function runRegisterMimeType(): void {
		Server::get(RegisterMimeType::class)->run(new \OC\Migration\NullOutput());
	}

	private function hasStoredPreview(File $file): bool {
		$db = Server::get(IDBConnection::class);
		// Nextcloud 33+ lists previews in a table; 32 keeps a folder per file.
		if ($db->tableExists('previews')) {
			$qb = $db->getQueryBuilder();
			$result = $qb->select('id')->from('previews')
				->where($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
				->setMaxResults(1)
				->executeQuery();
			$found = $result->fetchOne() !== false;
			$result->closeCursor();
			return $found;
		}
		$id = (string)$file->getId();
		return $this->previewFolderExists(implode('/', str_split(substr(md5($id), 0, 7))) . '/' . $id);
	}

	/** Looked up on disk: IAppData keeps the folders it once found for the rest of the process. */
	private function previewFolderExists(string $path): bool {
		$instanceId = Server::get(IConfig::class)->getSystemValueString('instanceid');
		return $this->rootFolder->nodeExists('appdata_' . $instanceId . '/preview/' . $path);
	}

	/**
	 * @return array{int, int, int, int} red, green, blue, alpha (127 = see-through)
	 */
	private function rgba(\GdImage $image, int $x, int $y): array {
		$c = imagecolorsforindex($image, imagecolorat($image, $x, $y));
		return [$c['red'], $c['green'], $c['blue'], $c['alpha']];
	}

	private function png(): string {
		$image = imagecreatetruecolor(80, 80);
		imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 60));
		ob_start();
		imagepng($image);
		return (string)ob_get_clean();
	}
}
