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
			$result->method('fetch')->willReturnCallback(function () use (&$rows, &$cursor) {
				if ($cursor >= count($rows)) {
					return false;
				}
				return $rows[$cursor++];
			});
			$result->method('closeCursor')->willReturn(true);

			$qb = $this->createMock(IQueryBuilder::class);
			// Fluent chain: every builder call returns the builder itself.
			foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'orderBy'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			// setMaxResults is honoured rather than ignored: the LIMIT is
			// applied by the database, so a mock that drops it cannot show a
			// row cap excluding rows the code needs (#136 follow-up).
			$qb->method('setMaxResults')->willReturnCallback(
				function (?int $max) use ($qb, &$rows): IQueryBuilder {
					if ($max !== null) {
						$rows = array_slice($rows, 0, $max);
					}
					return $qb;
				}
			);
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

	/**
	 * #136 follow-up: a Team Folder with more than 20 applicable groups.
	 *
	 * The reporter delegates responsibilities through Advanced Permissions and
	 * has 27 groups on one folder. Everything beyond the twentieth was never
	 * consulted, so a form in that folder answered 404 again — the same symptom
	 * the 1.4.6 fix removed, from a different cause.
	 *
	 * The writer here sits at position 27 deliberately: it is the case a bound
	 * of any size still gets wrong.
	 */
	public function testGroupFolderBeyondTwentyGroupsStillResolves(): void {
		$fileId = 77;
		$groupRows = [];
		for ($i = 1; $i <= 27; $i++) {
			$groupRows[] = ['group_id' => sprintf('team%02d', $i), 'circle_id' => null, 'permissions' => 31];
		}

		$this->programDb([
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
			[['circle_id' => null]],
			$groupRows,
		]);

		// Only the 27th group holds the account that can open the file.
		$this->groupManager->method('get')->willReturnCallback(
			function (string $groupId): IGroup {
				$group = $this->createMock(IGroup::class);
				$group->method('searchUsers')->willReturn([$this->userWithUid('user-' . $groupId)]);
				return $group;
			}
		);

		$writable = $this->fileFor(true);
		$this->programUserFolders(['user-team27' => [$writable]], $fileId);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic($fileId, true));
	}

	/**
	 * Members are resolved one group at a time, stopping at the first account
	 * that can open the file.
	 *
	 * This is what makes removing the bound affordable. Resolving a group costs
	 * an LDAP round-trip, and groups are ordered so a write-capable one comes
	 * first, so the common case should cost one query — not one per group on
	 * the folder. Resolving all of them up front is what forced a cap in the
	 * first place.
	 */
	public function testStopsResolvingGroupsOnceAWriterIsFound(): void {
		$fileId = 78;
		$groupRows = [];
		for ($i = 1; $i <= 10; $i++) {
			$groupRows[] = ['group_id' => sprintf('team%02d', $i), 'circle_id' => null, 'permissions' => 31];
		}

		$this->programDb([
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
			[['circle_id' => null]],
			$groupRows,
		]);

		$resolved = [];
		$this->groupManager->method('get')->willReturnCallback(
			function (string $groupId) use (&$resolved): IGroup {
				$resolved[] = $groupId;
				$group = $this->createMock(IGroup::class);
				$group->method('searchUsers')->willReturn([$this->userWithUid('user-' . $groupId)]);
				return $group;
			}
		);

		// The very first group yields a usable account.
		$writable = $this->fileFor(true);
		$this->programUserFolders(['user-team01' => [$writable]], $fileId);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic($fileId, true));
		$this->assertSame(
			['team01'],
			$resolved,
			'only the first group should have been resolved; the other nine cost an LDAP query each'
		);
	}

	/**
	 * A read-only caller on a folder whose first groups cannot write still gets
	 * the file: every group is consulted when needed, and read-only candidates
	 * are not skipped (#90).
	 */
	public function testReadOnlyCallerWalksPastNonWritableGroups(): void {
		$fileId = 79;
		$groupRows = [];
		for ($i = 1; $i <= 25; $i++) {
			$groupRows[] = ['group_id' => sprintf('team%02d', $i), 'circle_id' => null, 'permissions' => 1];
		}

		$this->programDb([
			[['id' => 'local::/mnt/data/__groupfolders/12/', 'numeric_id' => 9]],
			[['circle_id' => null]],
			$groupRows,
		]);

		$this->groupManager->method('get')->willReturnCallback(
			function (string $groupId): IGroup {
				$group = $this->createMock(IGroup::class);
				$group->method('searchUsers')->willReturn([$this->userWithUid('user-' . $groupId)]);
				return $group;
			}
		);

		// Nobody before the 25th can even see the file.
		$readable = $this->fileFor(false);
		$this->programUserFolders(['user-team25' => [$readable]], $fileId);

		$this->assertSame($readable, $this->locator()->getFileByIdPublic($fileId, false));
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
