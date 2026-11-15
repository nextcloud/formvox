<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Listener;

use OCA\FormVox\Listener\FormRemovedListener;
use OCA\FormVox\Service\FormTrash;
use OCA\FormVox\Store\Orphans;
use OCA\FormVox\Store\StoredForm;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What counts as a form's file deleted for good through Nextcloud, and what a file Nextcloud merely forgot costs.
 */
class FormRemovedListenerTest extends TestCase {
	private const HOME = 7;
	private const TEAM = 9;

	/** @var list<list<array{0: int, 1: int, 2: string}>> what each request's end asked to remove */
	private array $asked = [];
	/** @var list<list<int>> the forms whose folders in the trash were cleared */
	private array $cleared = [];
	/** @var list<StoredForm> */
	private array $removes = [];
	private int $containerCalls = 0;
	private bool $trashApp = true;
	private string $skipHeader = '';
	private bool $inTransaction = false;
	private ?\Throwable $fails = null;
	private LoggerInterface $logger;

	private function listener(): FormRemovedListener {
		$orphans = $this->createMock(Orphans::class);
		$orphans->method('removeDeleted')->willReturnCallback(function (array $deleted): array {
			if ($this->fails !== null) {
				throw $this->fails;
			}
			$this->asked[] = $deleted;
			return $this->removes;
		});
		$trash = $this->createMock(FormTrash::class);
		$trash->method('removed')->willReturnCallback(function (array $fileIds): void {
			$this->cleared[] = $fileIds;
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(fn (): bool => $this->inTransaction);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $class) use ($orphans, $trash, $db) {
			$this->containerCalls++;
			return [Orphans::class => $orphans, FormTrash::class => $trash, IDBConnection::class => $db][$class];
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $app === 'files_trashbin' && $this->trashApp);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $name === 'X-NC-Skip-Trashbin' ? $this->skipHeader : '');
		$this->logger = $this->createMock(LoggerInterface::class);
		return new FormRemovedListener($apps, $request, $this->logger, $container);
	}

	private function forgotten(string $path, int $fileId, int $storageId = self::HOME, string $storage = 'home::alice'): CacheEntryRemovedEvent {
		$backend = $this->createMock(IStorage::class);
		$backend->method('getId')->willReturn($storage);
		return new CacheEntryRemovedEvent($backend, $path, $fileId, $storageId);
	}

	private function deleting(string $class, string $name, ?int $id): BeforeNodeDeletedEvent {
		/** @var Node&\PHPUnit\Framework\MockObject\MockObject $node */
		$node = $this->createMock($class);
		$node->method('getName')->willReturn($name);
		if ($id === null) {
			$node->method('getId')->willThrowException(new NotFoundException());
		} else {
			$node->method('getId')->willReturn($id);
		}
		return new BeforeNodeDeletedEvent($node);
	}

	/** @param list<Event> $events */
	private function request(array $events): void {
		$listener = $this->listener();
		foreach ($events as $event) {
			$listener->handle($event);
		}
		$listener->flush();
		// The request ended once: a second end has nothing left.
		$calls = $this->containerCalls;
		$listener->flush();
		$this->assertSame($calls, $this->containerCalls);
	}

	public function testAFileThatIsNoFormCostsALookAtItsPathAndNothingElse(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->expects($this->never())->method('getId');

		$this->request([
			new CacheEntryRemovedEvent($storage, 'appdata_oc123/preview/a/b/c/4096-4096-max.png', 11, 1),
			new CacheEntryRemovedEvent($storage, 'files_versions/Form.fvform.v1700000000', 12, self::HOME),
			new CacheEntryRemovedEvent($storage, 'files_trashbin/files/notes.txt.d1700000000', 13, self::HOME),
			new CacheEntryRemovedEvent($storage, 'trash/report.pdf.d1700000000', 14, self::TEAM),
			$this->deleting(File::class, 'notes.txt', 15),
		]);

		$this->assertSame(0, $this->containerCalls, 'nothing was asked of the database');
	}

	public function testAFormRemovedFromAUsersTrashWasDeletedForGood(): void {
		$this->request([
			$this->forgotten('files_trashbin/files/Survey.fvform.d1700000000', 21),
			$this->forgotten('files_trashbin/files/Folder.d1700000000/deep/Poll.FVFORM', 22),
		]);

		$this->assertSame([[
			[21, self::HOME, 'files_trashbin/files/Survey.fvform.d1700000000'],
			[22, self::HOME, 'files_trashbin/files/Folder.d1700000000/deep/Poll.FVFORM'],
		]], $this->asked);
	}

	/** @return array<string, array{string, string, bool}> */
	public static function trashes(): array {
		return [
			'a team folder\'s own trash' => ['trash/Survey.fvform.d1700000000', 'local::/var/www/data/__groupfolders/5/', true],
			'a team folder in object storage' => ['trash/Dir.d1700000000/Survey.fvform', 'object::groupfolder:5.amazon::bucket', true],
			'the trash of team folders made before Groupfolders 20' => ['__groupfolders/trash/5/Survey.fvform.d1700000000', 'local::/var/www/data/', true],
			'a folder named trash in a home' => ['trash/Survey.fvform.d1700000000', 'home::alice', false],
			'a folder named trash on external storage' => ['trash/Survey.fvform', 'smb::user@host//share//', false],
			'a team folder\'s files' => ['files/Survey.fvform', 'local::/var/www/data/__groupfolders/5/', false],
			'a user\'s files' => ['files/Survey.fvform', 'home::alice', false],
		];
	}

	/** @dataProvider trashes */
	public function testOnlyNextcloudsTrashesCountAsATrash(string $path, string $storage, bool $deleted): void {
		$this->request([$this->forgotten($path, 31, self::TEAM, $storage)]);

		$this->assertSame($deleted ? [[[31, self::TEAM, $path]]] : [], $this->asked);
	}

	public function testAFileNextcloudForgotWithNoOneDeletingItKeepsItsForm(): void {
		$this->trashApp = false;

		$this->request([
			$this->forgotten('files/Dir/Survey.fvform', 41),
			$this->forgotten('files/Dir', 40),
			$this->forgotten('files/Poll.fvform', 42),
		]);

		$this->assertSame([], $this->asked, 'a scan found them gone');
	}

	public function testAFormDeletedWhereNoTrashIsAtWorkWasDeletedForGood(): void {
		$this->trashApp = false;

		$this->request([
			$this->deleting(File::class, 'Poll.fvform', 42),
			$this->forgotten('files/Poll.fvform', 42),
			$this->deleting(Folder::class, 'Dir', 40),
			$this->forgotten('files/Dir/Survey.fvform', 41),
			$this->forgotten('files/Dir', 40),
			// Found gone by a scan in the same request, beside the folder and on another storage.
			$this->forgotten('files/Directory/Other.fvform', 43),
			$this->forgotten('files/Dir/Elsewhere.fvform', 44, self::TEAM),
		]);

		$this->assertSame([[
			[42, self::HOME, 'files/Poll.fvform'],
			[41, self::HOME, 'files/Dir/Survey.fvform'],
		]], $this->asked);
	}

	public function testAFormDeletedPastTheTrashOnRequestWasDeletedForGood(): void {
		$this->skipHeader = 'true';

		$this->request([$this->deleting(File::class, 'Poll.fvform', 42), $this->forgotten('files/Poll.fvform', 42)]);

		$this->assertSame([[[42, self::HOME, 'files/Poll.fvform']]], $this->asked);
	}

	/** It may lie in a trash under a new id: the row decides at the sweep. */
	public function testAFormForgottenWhileATrashIsAtWorkIsNotKnownToBeGone(): void {
		$this->request([$this->deleting(File::class, 'Poll.fvform', 42), $this->forgotten('files/Poll.fvform', 42)]);

		$this->assertSame([[]], $this->asked, 'only the forms in a trash are told where they lie');
	}

	public function testSomethingDeletedIntoATrashTellsTheFormsInItWhereTheyLie(): void {
		$this->request([$this->deleting(Folder::class, 'Dir', 40)]);
		$this->request([$this->deleting(File::class, 'Poll.fvform', 42)]);

		$this->assertSame([[], []], $this->asked);
		$this->assertSame([[], []], $this->cleared);
	}

	public function testAFormDeletedFromATrashOnItsOwnTakesTheFoldersThatWentWithIt(): void {
		$this->removes = [
			new StoredForm(1, 'uuid-1', 51, self::HOME, filesMoved: true),
			new StoredForm(2, 'uuid-2', 52, self::HOME, filesMoved: false),
		];

		$this->request([
			$this->forgotten('files_trashbin/files/In app data.fvform.d1700000000', 51),
			$this->forgotten('files_trashbin/files/Beside its file.fvform.d1700000000', 52),
			$this->forgotten('files_trashbin/files/Not in the database.fvform.d1700000000', 53),
			$this->forgotten('files_trashbin/files/Dir.d1700000000/In a folder.fvform', 54),
		]);

		$this->assertSame([[52, 53]], $this->cleared);
	}

	/** What it deleted may be rolled back with the rest. */
	public function testARequestThatBrokeOffInATransactionRemovesNothing(): void {
		$this->inTransaction = true;

		$this->request([$this->forgotten('files_trashbin/files/Survey.fvform.d1700000000', 21)]);

		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->cleared);
	}

	public function testANodeThatIsGoneAlreadyStopsNoOnesDelete(): void {
		$this->request([$this->deleting(Folder::class, 'Dir', null)]);

		$this->assertSame([], $this->asked);
	}

	public function testAFailureAtTheEndIsLoggedAndLeftToTheSweep(): void {
		$this->fails = new \RuntimeException('the database is away');
		$listener = $this->listener();
		$this->logger->expects($this->once())->method('error');
		$listener->handle($this->forgotten('files_trashbin/files/Survey.fvform.d1700000000', 21));

		$listener->flush();

		$this->assertSame([], $this->cleared);
	}
}
