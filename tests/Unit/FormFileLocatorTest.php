<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormFileLocator;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for FormFileLocator — the #90/#101/#136 crown jewel.
 *
 * Pins the behaviour that MUST NOT regress when this code moves out of
 * FormService and later when its consumers repoint to it:
 *  - #101: requireWrite skips candidates that can see but not update the file.
 *  - #90:  the member candidate list is deterministic (sorted) + deduped + bounded.
 *  - #136: group membership resolves through the group BACKEND (IGroupManager),
 *          not the local oc_group_user table.
 *  - not-found vs no-writer distinction (misconfigured share ≠ missing form).
 *
 * All mocked against ocp interface stubs; no running server.
 */
class FormFileLocatorTest extends TestCase {
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private IDBConnection $db;
	private IGroupManager $groupManager;
	private IServerContainer $serverContainer;

	/** @var array<int, array<int, array<string,mixed>|false>> queued fetch() rows per query, FIFO */
	private array $fetchQueues = [];
	private int $queryIndex = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->serverContainer = $this->createMock(IServerContainer::class);
	}

	private function locator(): FormFileLocator {
		return new FormFileLocator(
			$this->rootFolder,
			$this->userSession,
			$this->db,
			$this->groupManager,
			$this->serverContainer
		);
	}

	/**
	 * Program the DB mock so each successive getQueryBuilder() call returns a
	 * builder whose executeQuery()->fetch() drains the next queued row-set.
	 * $queues is a list of row-lists; each row-list is drained by one query
	 * (fetch() returns each row then false).
	 *
	 * @param array<int, list<array<string,mixed>>> $queues
	 */
	private function programDb(array $queues): void {
		$this->fetchQueues = $queues;
		$this->queryIndex = 0;

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('neq')->willReturn('neq');

		$this->db->method('getQueryBuilder')->willReturnCallback(function () use ($expr): IQueryBuilder {
			$rows = $this->fetchQueues[$this->queryIndex] ?? [];
			$this->queryIndex++;
			$cursor = 0;

			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(function () use ($rows, &$cursor) {
				if ($cursor >= count($rows)) {
					return false;
				}
				return $rows[$cursor++];
			});
			$result->method('closeCursor')->willReturn(true);

			$qb = $this->createMock(IQueryBuilder::class);
			// Fluent chain: every builder call returns the builder itself.
			foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnArgument(0);
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
	}

	/** A File mock that resolves for a user, with a given updateable flag. */
	private function fileFor(bool $updateable): File {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn($updateable);
		return $file;
	}

	/**
	 * Make rootFolder->getUserFolder($uid)->getById($fileId) return the given
	 * node list for each uid.
	 *
	 * @param array<string, list<mixed>> $byUser uid => nodes returned by getById
	 */
	private function programUserFolders(array $byUser, int $fileId): void {
		$this->rootFolder->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($byUser, $fileId): Folder {
				$folder = $this->createMock(Folder::class);
				$nodes = $byUser[$uid] ?? [];
				$folder->method('getById')->with($fileId)->willReturn($nodes);
				return $folder;
			}
		);
	}

	// ---- Case 1: personal folder (home::uid) -------------------------------

	public function testPersonalFolderReturnsFileForOwner(): void {
		$fileId = 42;
		$this->programDb([[['id' => 'home::alice', 'numeric_id' => 7]]]);
		$file = $this->fileFor(true);
		$this->programUserFolders(['alice' => [$file]], $fileId);

		$this->assertSame($file, $this->locator()->getFileByIdPublic($fileId));
	}

	public function testUnknownFileIdThrowsNotFound(): void {
		$this->programDb([[]]); // filecache lookup returns no row
		$this->expectException(NotFoundException::class);
		$this->locator()->getFileByIdPublic(999);
	}

	// ---- #101: requireWrite skips read-only candidates ---------------------

	public function testRequireWriteThrowsWhenOwnerCannotWrite(): void {
		$fileId = 42;
		$this->programDb([[['id' => 'home::alice', 'numeric_id' => 7]]]);
		$this->programUserFolders(['alice' => [$this->fileFor(false)]], $fileId);

		// requireWrite => a see-but-not-write file is NOT acceptable; and for a
		// personal mount there is no other candidate => no-writer RuntimeException.
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/write access/');
		$this->locator()->getFileByIdPublic($fileId, true);
	}

	public function testReadOnlyCallerAcceptsNonWritableFile(): void {
		$fileId = 42;
		$this->programDb([[['id' => 'home::alice', 'numeric_id' => 7]]]);
		$ro = $this->fileFor(false);
		$this->programUserFolders(['alice' => [$ro]], $fileId);

		// requireWrite=false => a readable file is fine even if not writable.
		$this->assertSame($ro, $this->locator()->getFileByIdPublic($fileId, false));
	}

	// ---- Case 2: group folder — #136 backend lookup + #90 order/skip -------

	public function testGroupFolderResolvesThroughGroupBackendNotDbTable(): void {
		$fileId = 55;
		// Query 1: filecache -> a groupfolder storage id.
		// Query 2: circle_id probe (groupFolderGroupsHasCircleId) -> succeeds (any row).
		// Query 3: group_folders_groups -> one group row, no circle.
		$this->programDb([
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
			[['circle_id' => null]],                              // probe: column exists
			[['group_id' => 'staff', 'circle_id' => null, 'permissions' => 31]],
		]);

		// #136: membership comes from IGroupManager (backend), NOT from a DB
		// table. The group returns members via searchUsers().
		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())
			->method('searchUsers')
			->with('', 50)
			->willReturn([
				$this->userWithUid('carol'),
				$this->userWithUid('bob'),
			]);
		$this->groupManager->method('get')->with('staff')->willReturn($group);

		// bob is the writable account; carol is read-only. Because members are
		// SORTED (#90: bob < carol), bob is tried first and returned.
		$writable = $this->fileFor(true);
		$this->programUserFolders([
			'bob' => [$writable],
			'carol' => [$this->fileFor(false)],
		], $fileId);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic($fileId, true));
	}

	public function testGroupFolderMissingGroupInBackendYieldsNoWriter(): void {
		$fileId = 55;
		$this->programDb([
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
			[['circle_id' => null]],
			[['group_id' => 'ghost', 'circle_id' => null, 'permissions' => 31]],
		]);
		// #136: group not resolvable in the backend => no members => no writer.
		$this->groupManager->method('get')->with('ghost')->willReturn(null);
		$this->programUserFolders([], $fileId);

		$this->expectException(\RuntimeException::class);
		$this->locator()->getFileByIdPublic($fileId, true);
	}

	/**
	 * Owner-group-first + lazy early-exit: when the most-permissive group's
	 * first member can write, the walk must stop before ever resolving a
	 * lower-permission group. Programming only ONE group_folders_groups query
	 * row-set and asserting the second group is never fetched pins that the
	 * candidate stream is lazy (the performance win, previously untested).
	 */
	public function testGroupFolderStopsAtFirstWritableWithoutTouchingLaterGroups(): void {
		$fileId = 55;
		$this->programDb([
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
			[['circle_id' => null]],
			// Two rows, owner group (perms 31) first, a read group second.
			[
				['group_id' => 'owners', 'circle_id' => null, 'permissions' => 31],
				['group_id' => 'readers', 'circle_id' => null, 'permissions' => 1],
			],
		]);

		$owners = $this->createMock(IGroup::class);
		$owners->method('searchUsers')->with('', 50)->willReturn([$this->userWithUid('alice')]);

		// The 'readers' group must NEVER be resolved: alice (owners) writes the
		// file, so the generator must not descend into the second group.
		$readers = $this->createMock(IGroup::class);
		$readers->expects($this->never())->method('searchUsers');

		$this->groupManager->method('get')->willReturnCallback(
			function (string $gid) use ($owners, $readers): ?IGroup {
				return match ($gid) {
					'owners' => $owners,
					'readers' => $readers,
					default => null,
				};
			}
		);

		$writable = $this->fileFor(true);
		$this->programUserFolders(['alice' => [$writable]], $fileId);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic($fileId, true));
	}

	/**
	 * #90 fall-through still holds under the lazy walk: when advanced ACL denies
	 * the owner group's only member on THIS path, the generator must descend to
	 * the next group and find the writable member there — not stop at group one.
	 */
	public function testGroupFolderDescendsPastAclBlockedOwnerGroup(): void {
		$fileId = 55;
		$this->programDb([
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
			[['circle_id' => null]],
			[
				['group_id' => 'owners', 'circle_id' => null, 'permissions' => 31],
				['group_id' => 'editors', 'circle_id' => null, 'permissions' => 3],
			],
		]);

		$owners = $this->createMock(IGroup::class);
		$owners->method('searchUsers')->with('', 50)->willReturn([$this->userWithUid('alice')]);
		$editors = $this->createMock(IGroup::class);
		$editors->method('searchUsers')->with('', 50)->willReturn([$this->userWithUid('frank')]);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => match ($gid) {
				'owners' => $owners, 'editors' => $editors, default => null,
			}
		);

		// alice (owner group) is ACL-denied write on this file; frank (editors)
		// can write it. The walk must reach frank rather than give up at alice.
		$writable = $this->fileFor(true);
		$this->programUserFolders([
			'alice' => [$this->fileFor(false)],
			'frank' => [$writable],
		], $fileId);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic($fileId, true));
	}

	/**
	 * Per-request memo: two getFileByIdPublic() calls for the same file in one
	 * request (e.g. render then capacity check) must resolve the group backend
	 * only ONCE. The cheap filecache lookup still runs per call (it is not the
	 * expensive part), so query 1 is programmed for BOTH calls; the circle-probe
	 * and group_folders_groups queries plus searchUsers() are programmed once —
	 * a second resolution would need row-sets that are not queued. searchUsers()
	 * asserted exactly once pins that the resolved File is reused.
	 */
	public function testGroupFolderResolutionIsMemoisedWithinRequest(): void {
		$fileId = 55;
		$this->programDb([
			// Call 1: filecache + circle-probe + group rows.
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
			[['circle_id' => null]],
			[['group_id' => 'staff', 'circle_id' => null, 'permissions' => 31]],
			// Call 2: only the filecache lookup runs again; the group resolution
			// is served from the memo, so no further group queries are needed.
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
		]);

		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())   // resolved once, reused on the second call
			->method('searchUsers')
			->willReturn([$this->userWithUid('bob')]);
		$this->groupManager->method('get')->with('staff')->willReturn($group);

		$writable = $this->fileFor(true);
		$this->programUserFolders(['bob' => [$writable]], $fileId);

		$locator = $this->locator();
		$this->assertSame($writable, $locator->getFileByIdPublic($fileId, true));
		$this->assertSame($writable, $locator->getFileByIdPublic($fileId, true));
	}

	/**
	 * #136 follow-up: a folder that delegates through many groups (GaelBe: 27)
	 * must still resolve when the only account that can write THIS file lives in
	 * a group past the old 20-group cap. An advanced-permission ACL can grant
	 * write on a path to a member of a group that sorts well down the list, so
	 * capping the number of groups would drop that account and 404 the form.
	 * With the cap removed, the walk reaches the 25th group and finds the writer.
	 */
	public function testGroupFolderResolvesWriterBeyondTheOldTwentyGroupCap(): void {
		$fileId = 55;

		// 25 groups on the folder, ordered by permissions DESC. Only the last one
		// ('team-24') has a member who can write this specific file — every
		// higher-listed group is read-only on this path (ACL).
		$rows = [];
		for ($i = 0; $i < 25; $i++) {
			$rows[] = ['group_id' => 'team-' . $i, 'circle_id' => null, 'permissions' => 31];
		}
		$this->programDb([
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
			[['circle_id' => null]],
			$rows,
		]);

		$this->groupManager->method('get')->willReturnCallback(
			function (string $gid): IGroup {
				$g = $this->createMock(IGroup::class);
				// Each group has one member named after the group.
				$g->method('searchUsers')->willReturn([$this->userWithUid('u-' . $gid)]);
				return $g;
			}
		);

		// Only u-team-24 can write; all earlier candidates are read-only.
		$writable = $this->fileFor(true);
		$byUser = [];
		for ($i = 0; $i < 25; $i++) {
			$byUser['u-team-' . $i] = [$i === 24 ? $writable : $this->fileFor(false)];
		}
		$this->programUserFolders($byUser, $fileId);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic($fileId, true));
	}

	// ---- Case 3: external storage ------------------------------------------

	public function testExternalStorageResolvesFirstWritableCandidate(): void {
		$fileId = 77;
		$this->programDb([
			[['id' => 'smb::share', 'numeric_id' => 21]],        // not home::, not groupfolder
			[['user_id' => 'dave'], ['user_id' => 'erin']],      // mounts query
		]);
		$writable = $this->fileFor(true);
		$this->programUserFolders([
			'dave' => [$this->fileFor(false)], // read-only => skipped under requireWrite
			'erin' => [$writable],
		], $fileId);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic($fileId, true));
	}

	private function userWithUid(string $uid): IUser {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		return $u;
	}
}
