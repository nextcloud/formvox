<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Listener;

use OCA\FormVox\Listener\FormMovedListener;
use OCA\FormVox\Service\ResponseOutbox;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A moved form takes its uploads, branding and templates along; moved to another storage it gets a new id, and the
 * folders take it (7a, 3a-6).
 */
class FormMovedListenerTest extends TestCase {
	/** @var list<array{string, string}> folder moves: [from name, to path] */
	private array $moves = [];
	private ?ResponseOutbox $outbox = null;
	private ?IRootFolder $rootFolder = null;
	private ?\OCA\FormVox\Service\OutsideWriteHold $hold = null;

	/**
	 * @param list<string> $children names of the hidden folders it holds
	 * @param list<string> $taken names of folders already there, never moved over
	 */
	private function folder(int $id, string $path, array $children = [], array $taken = []): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn($path);
		$folder->method('nodeExists')->willReturnCallback(fn (string $name) => in_array($name, $taken, true));
		$folder->method('get')->willReturnCallback(function (string $name) use ($children) {
			if (!in_array($name, $children, true)) {
				throw new NotFoundException($name);
			}
			$child = $this->createMock(Folder::class);
			$child->method('move')->willReturnCallback(function (string $to) use ($name, $child) {
				$this->moves[] = [$name, $to];
				return $child;
			});
			return $child;
		});
		return $folder;
	}

	private function file(string $name, string $path, ?int $id, Folder $parent): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn($path);
		$file->method('getParent')->willReturn($parent);
		if ($id === null) {
			// After the move the source is a path that no longer exists.
			$file->method('getId')->willThrowException(new NotFoundException());
		} else {
			$file->method('getId')->willReturn($id);
		}
		return $file;
	}

	/** Before the move nothing is at the target yet, as in Nextcloud, unless $there is a file already there. */
	private function move(File $before, File $after, File $target, ?File $there = null): void {
		$listener = new FormMovedListener($this->outbox ?? $this->createMock(ResponseOutbox::class), $this->rootFolder ?? $this->createMock(IRootFolder::class), $this->hold ?? new \OCA\FormVox\Service\OutsideWriteHold($this->createMock(\OCA\FormVox\Service\FormLock::class)), new \OCA\FormVox\Tests\Unit\Support\EnglishL10N(), $this->createMock(LoggerInterface::class));
		$listener->handle(new BeforeNodeRenamedEvent($before, $there ?? $this->file($target->getName(), $target->getPath(), null, $target->getParent())));
		$listener->handle(new NodeRenamedEvent($after, $target));
	}

	private const HIDDEN = ['.formvox-uploads-5', '.formvox-branding-5', '.formvox-templates-5'];

	public function testAFormMovedToAnotherFolderTakesItsFoldersAlong(): void {
		$from = $this->folder(1, '/anna/files/A', self::HIDDEN);
		$to = $this->folder(2, '/anna/files/B');

		$this->move($this->file('S.fvform', '/anna/files/A/S.fvform', 5, $from), $this->file('S.fvform', '/anna/files/A/S.fvform', null, $from), $this->file('S.fvform', '/anna/files/B/S.fvform', 5, $to));

		$this->assertEqualsCanonicalizing([
			['.formvox-uploads-5', '/anna/files/B/.formvox-uploads-5'],
			['.formvox-branding-5', '/anna/files/B/.formvox-branding-5'],
			['.formvox-templates-5', '/anna/files/B/.formvox-templates-5'],
		], $this->moves);
	}

	/** Into a team folder: another storage, so a new id; the folders are found by the old one and take the new one. */
	public function testMovedToAnotherStorageTheFoldersTakeTheNewId(): void {
		$from = $this->folder(1, '/anna/files', self::HIDDEN);
		$to = $this->folder(3, '/anna/files/Team');

		$this->move($this->file('S.fvform', '/anna/files/S.fvform', 5, $from), $this->file('S.fvform', '/anna/files/S.fvform', null, $from), $this->file('S.fvform', '/anna/files/Team/S.fvform', 9, $to));

		$this->assertContains(['.formvox-uploads-5', '/anna/files/Team/.formvox-uploads-9'], $this->moves);
		$this->assertCount(3, $this->moves);
	}

	public function testARenameInTheSameFolderMovesNothing(): void {
		$folder = $this->folder(1, '/anna/files', self::HIDDEN);

		$this->move($this->file('Old.fvform', '/anna/files/Old.fvform', 5, $folder), $this->file('Old.fvform', '/anna/files/Old.fvform', null, $folder), $this->file('New.fvform', '/anna/files/New.fvform', 5, $folder));

		$this->assertSame([], $this->moves);
	}

	/** Another file renamed over a form is FormIntake's; its folders stay where they are. */
	public function testAFileRenamedOverAFormMovesNothing(): void {
		$from = $this->folder(1, '/anna/files', self::HIDDEN);
		$to = $this->folder(2, '/anna/files/B');

		$this->move($this->file('notes.txt', '/anna/files/notes.txt', 4, $from), $this->file('notes.txt', '/anna/files/notes.txt', null, $from), $this->file('S.fvform', '/anna/files/B/S.fvform', 4, $to));

		$this->assertSame([], $this->moves);
	}

	public function testAFolderAlreadyAtTheNewNameIsNeverMovedOver(): void {
		$from = $this->folder(1, '/anna/files', self::HIDDEN);
		$to = $this->folder(3, '/anna/files/Team', [], ['.formvox-uploads-9']);

		$this->move($this->file('S.fvform', '/anna/files/S.fvform', 5, $from), $this->file('S.fvform', '/anna/files/S.fvform', null, $from), $this->file('S.fvform', '/anna/files/Team/S.fvform', 9, $to));

		$this->assertNotContains(['.formvox-uploads-5', '/anna/files/Team/.formvox-uploads-9'], $this->moves);
		$this->assertCount(2, $this->moves);
	}

	/** Moving the folders never fails the user's own move: the form moved already (review 3a-6). */
	public function testAFailureToMoveTheFoldersDoesNotFailTheMove(): void {
		$from = $this->createMock(Folder::class);
		$from->method('getId')->willReturn(1);
		$from->method('get')->willThrowException(new \RuntimeException('storage gone'));
		$to = $this->folder(3, '/anna/files/Team');

		$this->move($this->file('S.fvform', '/anna/files/S.fvform', 5, $from), $this->file('S.fvform', '/anna/files/S.fvform', null, $from), $this->file('S.fvform', '/anna/files/Team/S.fvform', 9, $to));

		$this->addToAssertionCount(1);
	}

	/** What the form still had to send now runs under its new id. */
	public function testANewIdBringsItsOutboxAlong(): void {
		$this->outbox = $this->createMock(ResponseOutbox::class);
		$this->outbox->expects($this->once())->method('schedule')->with(9);
		$from = $this->folder(1, '/anna/files', self::HIDDEN);

		$this->move($this->file('S.fvform', '/anna/files/S.fvform', 5, $from), $this->file('S.fvform', '/anna/files/S.fvform', null, $from), $this->file('S.fvform', '/anna/files/Team/S.fvform', 9, $this->folder(3, '/anna/files/Team')));
	}

	private function storage(int $numericId): IStorage {
		$cache = $this->createMock(\OCP\Files\Cache\ICache::class);
		$cache->method('getNumericStorageId')->willReturn($numericId);
		$storage = $this->createMock(IStorage::class);
		$storage->method('getCache')->willReturn($cache);
		return $storage;
	}

	private function mount(string $at): IMountPoint {
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getMountPoint')->willReturn($at);
		return $mount;
	}

	/** @var int how many times the moving folder was searched for forms */
	private int $searches = 0;

	/**
	 * Moves the folder /anna/files/Project, holding a form at Sub/S.fvform (id 5), from one storage to another; the
	 * form has id 9 after the move.
	 *
	 * @param list<array{string, int, string}> $found what a search by name finds: [path, id, mount point]
	 */
	private function moveProject(int $fromStorage, int $toStorage, string $internalPath = 'files/Project', ?array $found = null, ?Folder $sub = null): void {
		$found ??= [['/anna/files/Project/Sub/S.fvform', 5, '/anna/'], ['/anna/files/Project/Sub/S.fvform.txt', 6, '/anna/']];
		$source = $this->createMock(Folder::class);
		$source->method('getPath')->willReturn('/anna/files/Project');
		$source->method('getInternalPath')->willReturn($internalPath);
		$source->method('getStorage')->willReturn($this->storage($fromStorage));
		$source->method('getMountPoint')->willReturn($this->mount('/anna/'));
		$source->method('search')->willReturnCallback(function (string $query) use ($found) {
			$this->searches++;
			$this->assertSame('.fvform', $query);
			return array_map(function (array $hit) {
				$node = $this->createMock(File::class);
				$node->method('getName')->willReturn(basename($hit[0]));
				$node->method('getPath')->willReturn($hit[0]);
				$node->method('getId')->willReturn($hit[1]);
				$node->method('getMountPoint')->willReturn($this->mount($hit[2]));
				return $node;
			}, $found);
		});
		$sub ??= $this->folder(30, '/anna/files/Team/Project/Sub', ['.formvox-uploads-5', '.formvox-branding-5']);
		$movedForm = $this->file('S.fvform', '/anna/files/Team/Project/Sub/S.fvform', 9, $sub);
		$targetMount = $this->createMock(IMountPoint::class);
		$targetMount->method('getStorage')->willReturn($this->storage($toStorage));
		$this->rootFolder = $this->createMock(IRootFolder::class);
		// Found in the mount table: no node of the target is looked up before the move.
		$this->rootFolder->method('getMount')->with('/anna/files/Team')->willReturn($targetMount);
		$target = $this->createMock(Folder::class);
		$target->method('getPath')->willReturn('/anna/files/Team/Project');
		$target->expects($this->never())->method('getParent');
		$target->method('get')->willReturnCallback(fn (string $below) => strtolower($below) === 'sub/s.fvform' ? $movedForm : throw new NotFoundException($below));

		$listener = new FormMovedListener($this->outbox ?? $this->createMock(ResponseOutbox::class), $this->rootFolder, $this->hold ?? new \OCA\FormVox\Service\OutsideWriteHold($this->createMock(\OCA\FormVox\Service\FormLock::class)), new \OCA\FormVox\Tests\Unit\Support\EnglishL10N(), $this->createMock(LoggerInterface::class));
		$listener->handle(new BeforeNodeRenamedEvent($source, $target));
		$listener->handle(new NodeRenamedEvent($source, $target));
	}

	/**
	 * A folder with forms in it moved to another storage: each form inside gets a new id, and its folders, which moved
	 * along, take it (review 3a-6).
	 */
	public function testAFolderWithFormsMovedToAnotherStorageRenamesTheirFolders(): void {
		$this->outbox = $this->createMock(ResponseOutbox::class);
		$this->outbox->expects($this->once())->method('schedule')->with(9);

		$this->moveProject(1, 2);

		$this->assertEqualsCanonicalizing([
			['.formvox-uploads-5', '/anna/files/Team/Project/Sub/.formvox-uploads-9'],
			['.formvox-branding-5', '/anna/files/Team/Project/Sub/.formvox-branding-9'],
		], $this->moves);
	}

	/**
	 * Within one storage, or a share or mount moved as a whole, the ids stay: nothing is looked up (review of the 3a
	 * fixes: every folder rename searched, and a mount root searched the owner's whole storage).
	 *
	 * @return array<string, array{int, int, string}>
	 */
	public static function movesThatKeepIds(): array {
		return [
			'a rename or move within one storage' => [1, 1, 'files/Project'],
			'a share or mount moved as a whole' => [1, 2, ''],
		];
	}

	/**
	 * @dataProvider movesThatKeepIds
	 */
	public function testAMoveThatKeepsIdsLooksNothingUp(int $from, int $to, string $internalPath): void {
		$this->moveProject($from, $to, $internalPath);

		$this->assertSame([0, []], [$this->searches, $this->moves]);
	}

	/** A share or team folder mounted inside the moving folder keeps its ids: its forms are left alone. */
	public function testFormsOnAMountInsideAreLeftAlone(): void {
		$this->moveProject(1, 2, 'files/Project', [['/anna/files/Project/Sub/S.fvform', 7, '/anna/files/Project/Sub/']]);

		$this->assertSame([1, []], [$this->searches, $this->moves]);
	}

	/** One folder that cannot move does not hold back the others, nor the outbox under the new id. */
	public function testOneFolderThatCannotMoveHoldsNothingBack(): void {
		$this->outbox = $this->createMock(ResponseOutbox::class);
		$this->outbox->expects($this->once())->method('schedule')->with(9);
		$sub = $this->createMock(Folder::class);
		$sub->method('getPath')->willReturn('/anna/files/Team/Project/Sub');
		$sub->method('nodeExists')->willReturn(false);
		$sub->method('get')->willReturnCallback(function (string $name) {
			if ($name === '.formvox-uploads-5') {
				throw new \OCP\Lock\LockedException($name);
			}
			$child = $this->createMock(Folder::class);
			$child->method('move')->willReturnCallback(function (string $to) use ($name, $child) {
				$this->moves[] = [$name, $to];
				return $child;
			});
			return $child;
		});

		$this->moveProject(1, 2, 'files/Project', null, $sub);

		$this->assertEqualsCanonicalizing([
			['.formvox-templates-5', '/anna/files/Team/Project/Sub/.formvox-templates-9'],
			['.formvox-branding-5', '/anna/files/Team/Project/Sub/.formvox-branding-9'],
			['.formvox-pdfs-5', '/anna/files/Team/Project/Sub/.formvox-pdfs-9'],
		], $this->moves);
	}

	/** A form whose file type was never registered is still found by its name, in any case (review of the 3a fixes). */
	public function testAFormIsFoundByItsNameWhateverItsFileType(): void {
		$this->moveProject(1, 2, 'files/Project', [['/anna/files/Project/Sub/S.FVFORM', 5, '/anna/']]);

		$this->assertNotSame([], $this->moves);
	}

	/** A job that cannot be asked for does not keep the folders from moving: the next event asks again. */
	public function testTheFoldersMoveWhenTheJobCannotBeAskedFor(): void {
		$this->outbox = $this->createMock(ResponseOutbox::class);
		$this->outbox->method('schedule')->willThrowException(new \RuntimeException('deadlock on oc_jobs'));

		$this->moveProject(1, 2);

		$this->assertCount(2, $this->moves);
	}

	/** A form a FormVox save holds is not moved from under it: refused with a hint, after a moment (3b-3). */
	public function testAFormASaveHoldsIsNotMovedFromUnderIt(): void {
		$lock = $this->createMock(\OCA\FormVox\Service\FormLock::class);
		$lock->method('acquire')->willThrowException(new \OCA\FormVox\Service\FormLockedException('busy'));
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($lock);
		$from = $this->folder(1, '/anna/files', self::HIDDEN);

		$this->expectException(\OCA\FormVox\Service\FormBusyHint::class);
		$this->move($this->file('S.fvform', '/anna/files/S.fvform', 5, $from), $this->file('S.fvform', '/anna/files/S.fvform', null, $from), $this->file('S.fvform', '/anna/files/Team/S.fvform', 9, $this->folder(3, '/anna/files/Team')));
	}

	/** Held while it moves, and let go once it moved. */
	public function testAMovedFormIsHeldWhileItMoves(): void {
		$lock = $this->createMock(\OCA\FormVox\Service\FormLock::class);
		$lock->expects($this->once())->method('acquire')->with(5)->willReturn('token');
		$lock->expects($this->once())->method('release')->with(5, 'token');
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($lock);
		$from = $this->folder(1, '/anna/files/A', self::HIDDEN);

		$this->move($this->file('S.fvform', '/anna/files/A/S.fvform', 5, $from), $this->file('S.fvform', '/anna/files/A/S.fvform', null, $from), $this->file('S.fvform', '/anna/files/B/S.fvform', 5, $this->folder(2, '/anna/files/B')));
	}

	/** @return \OCA\FormVox\Service\FormLock&\PHPUnit\Framework\MockObject\MockObject one that records what it took and let go */
	private function recordingLock(array &$taken, array &$released, ?int $busy = null): \OCA\FormVox\Service\FormLock {
		$lock = $this->createMock(\OCA\FormVox\Service\FormLock::class);
		$lock->method('acquire')->willReturnCallback(function (int $fileId) use (&$taken, $busy): string {
			if ($fileId === $busy) {
				throw new \OCA\FormVox\Service\FormLockedException('busy');
			}
			$taken[] = $fileId;
			return 't' . $fileId;
		});
		$lock->method('release')->willReturnCallback(function (int $fileId) use (&$released): void {
			$released[] = $fileId;
		});
		return $lock;
	}

	/** Moved onto another form: both are held, the lower id first, so crossing moves never wait on each other. */
	public function testAFormMovedOntoAnotherHoldsBothInOrder(): void {
		$taken = $released = [];
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($this->recordingLock($taken, $released));
		$from = $this->folder(1, '/anna/files/A', self::HIDDEN);
		$to = $this->folder(2, '/anna/files/B');

		$this->move($this->file('S.fvform', '/anna/files/A/S.fvform', 8, $from), $this->file('S.fvform', '/anna/files/A/S.fvform', null, $from), $this->file('S.fvform', '/anna/files/B/S.fvform', 8, $to), $this->file('S.fvform', '/anna/files/B/S.fvform', 3, $to));

		$this->assertSame([3, 8], $taken);
		$this->assertEqualsCanonicalizing([3, 8], $released);
	}

	/** The second form busy: the first is let go before the move is refused. */
	public function testWhenTheSecondFormIsBusyTheFirstIsLetGo(): void {
		$taken = $released = [];
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($this->recordingLock($taken, $released, 8));
		$from = $this->folder(1, '/anna/files/A', self::HIDDEN);
		$to = $this->folder(2, '/anna/files/B');

		try {
			$this->move($this->file('S.fvform', '/anna/files/A/S.fvform', 8, $from), $this->file('S.fvform', '/anna/files/A/S.fvform', null, $from), $this->file('S.fvform', '/anna/files/B/S.fvform', 8, $to), $this->file('S.fvform', '/anna/files/B/S.fvform', 3, $to));
			$this->fail('A move onto a busy form went ahead');
		} catch (\OCA\FormVox\Service\FormBusyHint) {
		}

		$this->assertSame([3], $taken);
		$this->assertSame([3], $released);
	}

	/** Renamed to another extension, the form is still held while it moves. */
	public function testAFormRenamedToAnotherExtensionIsHeld(): void {
		$taken = $released = [];
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($this->recordingLock($taken, $released));
		$folder = $this->folder(1, '/anna/files', self::HIDDEN);

		$this->move($this->file('S.fvform', '/anna/files/S.fvform', 5, $folder), $this->file('S.fvform', '/anna/files/S.fvform', null, $folder), $this->file('S.txt', '/anna/files/S.txt', 5, $folder));

		$this->assertSame([5], $taken);
		$this->assertSame([5], $released);
	}

	/** Another file renamed over a form holds that form while it is written over. */
	public function testAFileRenamedOverAFormHoldsThatForm(): void {
		$taken = $released = [];
		$this->hold = new \OCA\FormVox\Service\OutsideWriteHold($this->recordingLock($taken, $released));
		$folder = $this->folder(1, '/anna/files', self::HIDDEN);

		$this->move($this->file('x.txt', '/anna/files/x.txt', 4, $folder), $this->file('x.txt', '/anna/files/x.txt', null, $folder), $this->file('S.fvform', '/anna/files/S.fvform', 4, $folder), $this->file('S.fvform', '/anna/files/S.fvform', 6, $folder));

		$this->assertSame([6], $taken);
		$this->assertSame([6], $released);
	}
}
