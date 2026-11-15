<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Permissions;

use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * qual#9, test#4: a team folder made before Groupfolders 20 has no storage of its own. It lives in the instance's
 * storage under __groupfolders/{id}/, beside every other such folder, so the storage says nothing about who may
 * write. A public form there is opened through the folder's own members, not guessed among the storage's mounts:
 * more readers than that guess ever tried sort before the one writer here.
 *
 * @group DB
 */
class LegacyTeamFolderIntegrationTest extends IntegrationTestCase {
	private const FOLDER = 'FormVox Legacy IT';
	private const OTHER_FOLDER = 'FormVox Legacy IT B';
	private const WRITERS = 'fvlegacy-writers';
	private const READERS = 'fvlegacy-readers';
	private const WRITER = 'fvlegacy-writer';
	private const READER_COUNT = 51;

	protected function setUp(): void {
		parent::setUp();
		$apps = Server::get(IAppManager::class);
		if (!$apps->isEnabledForAnyone('groupfolders')) {
			$message = 'The Groupfolders app is not enabled, so a team folder from before Groupfolders 20 cannot be made.';
			if (getenv('FORMVOX_REQUIRE_GROUPFOLDERS')) {
				$this->fail($message);
			}
			$this->markTestSkipped($message);
		}
		$apps->loadApp('groupfolders');
	}

	public static function tearDownAfterClass(): void {
		try {
			self::removeFolders();
		} catch (\Throwable $e) {
			// best effort; the next run removes leftovers before it starts
		}
		$users = Server::get(IUserManager::class);
		foreach ([self::WRITER, ...self::readers()] as $uid) {
			$users->get($uid)?->delete();
		}
		$groups = Server::get(IGroupManager::class);
		foreach ([self::WRITERS, self::READERS] as $gid) {
			$groups->get($gid)?->delete();
		}
		parent::tearDownAfterClass();
	}

	/** @return list<string> sorted before the writer, as the storage's mounts are read */
	private static function readers(): array {
		return array_map(static fn (int $i): string => sprintf('fvlegacy-a%02d', $i), range(1, self::READER_COUNT));
	}

	public function testAPublicFormInATeamFolderFromBeforeGroupfolders20IsOpenedThroughItsMembers(): void {
		self::removeFolders();
		$folderId = $this->legacyFolder();
		$storage = $this->folderStorage($folderId);

		$groups = Server::get(IGroupManager::class);
		$folders = Server::get(\OCA\GroupFolders\Folder\FolderManager::class);
		foreach ([self::WRITERS => Constants::PERMISSION_ALL, self::READERS => Constants::PERMISSION_READ] as $gid => $permissions) {
			$groups->get($gid) ?? $groups->createGroup($gid);
			$folders->addApplicableGroup($folderId, $gid);
			$folders->setGroupPermissions($folderId, $gid, $permissions);
		}
		$groups->get(self::WRITERS)->addUser($this->ensureUser(self::WRITER));
		$setup = Server::get(\OC\Files\SetupManager::class);
		foreach (self::readers() as $uid) {
			$reader = $this->ensureUser($uid);
			$groups->get(self::READERS)->addUser($reader);
			$setup->setupForUser($reader); // records the reader's mounts, as a login does
		}
		$setup->tearDown();

		$this->loginAs(self::WRITER);
		$fileId = (int)Server::get(FormRepository::class)->create('Legacy form', self::FOLDER)['fileId'];
		$this->assertSame(
			['storage' => $storage, 'folder' => '__groupfolders/' . $folderId . '/'],
			$this->whereStored($fileId),
			'The form is not in a team folder without a storage of its own',
		);
		$this->assertGreaterThan(50, $this->mountsOn($storage), 'Fewer readers have the folder than the old lookup tried');

		$this->logout();
		(new \ReflectionProperty(FormFileLocator::class, 'groupFolderFileCache'))->setValue(Server::get(FormFileLocator::class), []);

		$file = Server::get(FormFileLocator::class)->getFileByIdPublic($fileId, true);
		$this->assertTrue($file->isUpdateable());
		Server::get(ResponsePersistenceService::class)->appendResponsePublic($fileId, [
			'id' => bin2hex(random_bytes(16)), 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => [],
		]);
		$this->assertSame(1, Server::get(FormRepository::class)->loadPublic($fileId)['_index']['response_count']);
	}

	/**
	 * A submission whose request found the form before it moved to another such folder, at the same path inside it, is
	 * written where the form is now: both folders share the instance's storage, and Nextcloud remembers the old place
	 * for the rest of the request (review of 3b-4d).
	 */
	public function testASubmissionToAFormMovedToAnotherOlderTeamFolderLandsWhereItIsNow(): void {
		self::removeFolders();
		$from = $this->legacyFolder();
		$to = $this->legacyFolder(self::OTHER_FOLDER);
		$groups = Server::get(IGroupManager::class);
		$folders = Server::get(\OCA\GroupFolders\Folder\FolderManager::class);
		$groups->get(self::WRITERS) ?? $groups->createGroup(self::WRITERS);
		foreach ([$from, $to] as $folderId) {
			$folders->addApplicableGroup($folderId, self::WRITERS);
			$folders->setGroupPermissions($folderId, self::WRITERS, Constants::PERMISSION_ALL);
		}
		$groups->get(self::WRITERS)->addUser($this->ensureUser(self::WRITER));

		$this->loginAs(self::WRITER);
		$fileId = (int)Server::get(FormRepository::class)->create('Moving legacy form', self::FOLDER)['fileId'];
		Server::get(FormFileLocator::class)->getFileByIdPublic($fileId, true);
		$form = $this->rootFolder->getUserFolder(self::WRITER)->getFirstNodeById($fileId);
		$this->assertNotNull($form);
		$form->move($this->rootFolder->getUserFolder(self::WRITER)->getPath() . '/' . self::OTHER_FOLDER . '/' . $form->getName());
		$this->assertSame('__groupfolders/' . $to . '/', $this->whereStored($fileId)['folder'], 'moved into the other folder on the same storage');

		// The node found before reads the old place first, where PHP warns that the file is gone.
		$previous = set_error_handler(function (int $no, string $message, string $file = '', int $line = 0) use (&$previous): bool {
			return str_contains($message, 'Failed to open stream') || ($previous !== null && (bool)$previous($no, $message, $file, $line));
		}, E_WARNING);
		try {
			Server::get(ResponsePersistenceService::class)->appendResponsePublic($fileId, [
				'id' => bin2hex(random_bytes(16)), 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => [],
			]);
		} finally {
			restore_error_handler();
		}
		$this->assertSame(1, Server::get(FormRepository::class)->loadPublic($fileId)['_index']['response_count']);
	}

	/** A team folder as Groupfolders made them before version 20: no options, in the instance's own storage. */
	private function legacyFolder(string $mountPoint = self::FOLDER): int {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->insert('group_folders')->values([
			'mount_point' => $qb->createNamedParameter($mountPoint),
			'quota' => $qb->createNamedParameter(\OCA\GroupFolders\Folder\FolderManager::SPACE_DEFAULT),
		]);
		$qb->executeStatement();
		$folderId = $qb->getLastInsertId();

		['storage_id' => $storageId, 'root_id' => $rootId] = Server::get(\OCA\GroupFolders\Mount\FolderStorageManager::class)
			->initRootAndStorageForFolder($folderId, false, []);
		$qb = $db->getQueryBuilder();
		$qb->update('group_folders')
			->set('storage_id', $qb->createNamedParameter($storageId))
			->set('root_id', $qb->createNamedParameter($rootId))
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
		$qb->executeStatement();
		return $folderId;
	}

	private function folderStorage(int $folderId): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('storage_id')->from('group_folders')->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return array{storage: int, folder: string} */
	private function whereStored(int $fileId): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('storage', 'path')->from('filecache')->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));
		$row = $qb->executeQuery()->fetch();
		preg_match('#^__groupfolders/\d+/#', (string)($row['path'] ?? ''), $folder);
		return ['storage' => (int)($row['storage'] ?? 0), 'folder' => $folder[0] ?? (string)($row['path'] ?? '')];
	}

	private function mountsOn(int $storage): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('mounts')->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($storage)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private static function removeFolders(): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('folder_id')->from('group_folders')->where($qb->expr()->in('mount_point', $qb->createNamedParameter([self::FOLDER, self::OTHER_FOLDER], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		$ids = $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
		foreach ($ids as $id) {
			$command = Server::get(\OCA\GroupFolders\Command\Delete::class);
			$command->setHelperSet(new HelperSet([new QuestionHelper()]));
			if ($command->run(new ArrayInput(['folder_id' => (string)$id, '--force' => true]), new BufferedOutput()) !== 0) {
				Server::get(\OCA\GroupFolders\Folder\FolderManager::class)->removeFolder((int)$id);
			}
		}
	}
}
