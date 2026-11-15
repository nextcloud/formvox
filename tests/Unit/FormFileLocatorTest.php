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
	private function formFile(string $name = 'Survey.fvform'): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	private function fileFor(bool $updateable): File {
		$file = $this->formFile();
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

	/** qual#9: with S3 as primary storage a home is object::user:{uid}; the owner opens it, nobody is guessed. */
	public function testAHomeOnS3IsOpenedByItsOwner(): void {
		$fileId = 43;
		$this->programDb([
			[['id' => 'object::user:alice', 'numeric_id' => 7, 'path' => 'files/Survey.fvform']],
			[['user_id' => 'mallory']], // what a lookup among the storage's mounts would find first
		]);
		$file = $this->fileFor(true);
		$this->programUserFolders(['alice' => [$file], 'mallory' => [$this->fileFor(true)]], $fileId);

		$this->assertSame($file, $this->locator()->getFileByIdPublic($fileId, true));
		$this->assertSame(1, $this->queryIndex);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function sharedStorages(): array {
		return [
			'on disk' => ['local::/var/www/data/'],
			'on S3' => ['object::store:amazon::formvox-bucket'],
		];
	}

	/**
	 * qual#9, test#4: a team folder made before Groupfolders 20 has no storage of its own; only its path,
	 * __groupfolders/{id}/, says which folder it is. Its members open it, as in any team folder.
	 *
	 * @dataProvider sharedStorages
	 */
	public function testATeamFolderFromBeforeGroupfolders20IsFoundByItsPath(string $storageId): void {
		$fileId = 56;
		$this->programDb([
			[['id' => $storageId, 'numeric_id' => 1, 'path' => '__groupfolders/12/Forms/Survey.fvform']],
			[['circle_id' => null]],
			[['group_id' => 'staff', 'circle_id' => null, 'permissions' => 31]],
		]);
		$group = $this->createMock(IGroup::class);
		$group->method('searchUsers')->willReturn([$this->userWithUid('bob')]);
		$this->groupManager->expects($this->atLeastOnce())->method('get')->with('staff')->willReturn($group);
		$writable = $this->fileFor(true);
		$this->programUserFolders(['bob' => [$writable]], $fileId);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic($fileId, true));
	}

	public function testOnlyAFolderNumberMakesAPathATeamFolder(): void {
		$fileId = 57;
		$this->programDb([
			[['id' => 'local::/var/www/data/', 'numeric_id' => 1, 'path' => '__groupfolders/trash/12/Survey.fvform.d1']],
			[['user_id' => 'dave']],
		]);
		$this->groupManager->expects($this->never())->method('get');
		$file = $this->fileFor(true);
		$this->programUserFolders(['dave' => [$file]], $fileId);

		$this->assertSame($file, $this->locator()->getFileByIdPublic($fileId, true));
	}

	/**
	 * Nextcloud keeps a storage id over 64 characters as its md5, so a long data directory hides
	 * __groupfolders/{id}/; the folder is then found by its storage, and its members open the form,
	 * not whoever the mounts list first.
	 */
	public function testATeamFolderWhoseStorageIdNextcloudHashedIsFoundByItsStorage(): void {
		$fileId = 58;
		$this->programDb([
			[['id' => md5('local::/workspace/voxcloud-apps/formvox/server/data/__groupfolders/12/'), 'numeric_id' => 9, 'path' => 'Forms/Survey.fvform']],
			[['folder_id' => 12]],
			[['circle_id' => null]],
			[['group_id' => 'staff', 'circle_id' => null, 'permissions' => 31]],
		]);
		$group = $this->createMock(IGroup::class);
		$group->method('searchUsers')->willReturn([$this->userWithUid('bob')]);
		$this->groupManager->expects($this->atLeastOnce())->method('get')->with('staff')->willReturn($group);
		$writable = $this->fileFor(true);
		$this->programUserFolders(['bob' => [$writable], 'mallory' => [$this->fileFor(true)]], $fileId);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic($fileId, true));
	}

	public function testAHashedStorageIdThatIsNoTeamFolderStaysExternalStorage(): void {
		$fileId = 59;
		$this->programDb([
			[['id' => md5('local::/a/very/long/path/to/an/external/storage/mount/point/somewhere/'), 'numeric_id' => 9, 'path' => 'Survey.fvform']],
			[],
			[['user_id' => 'dave']],
		]);
		$this->groupManager->expects($this->never())->method('get');
		$file = $this->fileFor(true);
		$this->programUserFolders(['dave' => [$file]], $fileId);

		$this->assertSame($file, $this->locator()->getFileByIdPublic($fileId, true));
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

	// ---- one rule for a form reachable along several paths -----------------

	private function nodeWith(int $permissions, bool $updateable = true): File {
		$file = $this->formFile();
		$file->method('getPermissions')->willReturn($permissions);
		$file->method('isReadable')->willReturn(true);
		$file->method('isUpdateable')->willReturn($updateable);
		return $file;
	}

	/** A team folder and a share, two shares: the path with the most rights, whatever the mount order. */
	public function testTheMostPermissivePathWinsWhateverTheOrder(): void {
		$readOnly = $this->nodeWith(\OCP\Constants::PERMISSION_READ, false);
		$full = $this->nodeWith(\OCP\Constants::PERMISSION_ALL);
		$folder = $this->createMock(Folder::class);

		$this->assertSame($full, FormFileLocator::mostPermissive([$readOnly, $folder, $full]));
		$this->assertSame($full, FormFileLocator::mostPermissive([$full, $readOnly]));
		$this->assertNull(FormFileLocator::mostPermissive([$folder]));
		$this->assertNull(FormFileLocator::mostPermissive([]));
	}

	/** The creator and API keys are checked on the same path a session would use (T33). */
	public function testAnAccountsOwnViewTakesThePathWithMostRights(): void {
		$readOnly = $this->nodeWith(\OCP\Constants::PERMISSION_READ, false);
		$writable = $this->nodeWith(\OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_UPDATE);
		$this->programUserFolders(['anna' => [$readOnly, $writable]], 5);

		$this->assertSame($writable, $this->locator()->getFileForUser('anna', 5));
	}

	public function testAPublicWriteFindsTheWritablePathOfTheSameAccount(): void {
		$this->programDb([[['id' => 'home::anna', 'numeric_id' => 7]]]);
		$readOnly = $this->nodeWith(\OCP\Constants::PERMISSION_READ, false);
		$writable = $this->nodeWith(\OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_UPDATE);
		$this->programUserFolders(['anna' => [$readOnly, $writable]], 5);

		$this->assertSame($writable, $this->locator()->getFileByIdPublic(5, true));
	}

	// ---- getFileForUser: one account's own view ----------------------------

	public function testGetFileForUserReturnsTheFileTheAccountCanRead(): void {
		$file = $this->formFile();
		$file->method('isReadable')->willReturn(true);
		$this->programUserFolders(['anna' => [$file]], 5);

		$this->assertSame($file, $this->locator()->getFileForUser('anna', 5));
	}

	public function testGetFileForUserIsNullWhenTheFileIsNotInTheAccountsView(): void {
		// e.g. removed from the team folder's groups, or a share revoked.
		$this->programUserFolders(['anna' => []], 5);

		$this->assertNull($this->locator()->getFileForUser('anna', 5));
	}

	public function testGetFileForUserIsNullWhenNextcloudDeniesRead(): void {
		// e.g. an advanced-permission (ACL) rule that takes away read.
		$file = $this->formFile();
		$file->method('isReadable')->willReturn(false);
		$this->programUserFolders(['anna' => [$file]], 5);

		$this->assertNull($this->locator()->getFileForUser('anna', 5));
	}

	public function testGetFileForUserIsNullWhenTheAccountsFolderCannotBeOpened(): void {
		$this->rootFolder->method('getUserFolder')->willThrowException(new \RuntimeException('no such user'));

		$this->assertNull($this->locator()->getFileForUser('ghost', 5));
	}

	private function userWithUid(string $uid): IUser {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		return $u;
	}

	/** secu#9: a JSON file dropped through an upload link, with a token of its own, is never opened as a form. */
	public function testOnlyAFormIsEverOpened(): void {
		$json = $this->formFile('planted.json');
		$json->method('getMimetype')->willReturn('application/json');
		$byMimetype = $this->formFile('Survey');
		$byMimetype->method('getMimetype')->willReturn('application/x-fvform');

		$this->assertNull(FormFileLocator::mostPermissive([$json]));
		$this->assertSame($byMimetype, FormFileLocator::mostPermissive([$json, $byMimetype]));

		$this->programDb([[['id' => 'home::anna', 'numeric_id' => 7]]]);
		$this->programUserFolders(['anna' => [$json]], 5);
		$this->expectException(\OCP\Files\NotFoundException::class);
		$this->locator()->getFileByIdPublic(5);
	}
}
