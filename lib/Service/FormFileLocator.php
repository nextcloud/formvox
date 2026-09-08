<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserSession;

/**
 * Access-resolution core: turn a fileId into a readable (or writable) File,
 * for both session and public/system contexts.
 *
 * A public submission has no session of its own, so a form file on a shared
 * mount must be opened through *some* account that can see it — and, when the
 * caller intends to write, one that can actually update it. This class owns the
 * candidate-account search over group/team folders (incl. Circles) and external
 * storage, the deterministic bounded member lookup (#90/#136), and the
 * per-request memo that keeps that lookup cheap across the several
 * getFileByIdPublic() calls a single public request makes.
 *
 * Extracted verbatim from FormService so the #90/#101/#136 logic can be
 * unit-tested in isolation against mocked IDBConnection/IGroupManager, with no
 * running server.
 */
class FormFileLocator
{
    /**
     * How many groups / accounts to consider when looking for an account that
     * can open a form on a shared mount. Each candidate costs a filesystem
     * setup, so the search is bounded; candidates are ordered deterministically
     * and the caller stops at the first that can actually write. A groupfolders
     * ACL can grant write on a path to a single member of an otherwise low-
     * permission group, so the per-group cap is generous rather than tight —
     * otherwise the only writable account could sit just outside it (#90).
     */
    private const ACCESS_CANDIDATE_GROUPS = 20;
    private const ACCESS_CANDIDATE_USERS_PER_GROUP = 50;

    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private IDBConnection $db;
    private IGroupManager $groupManager;
    private IServerContainer $serverContainer;
    private ?bool $hasCircleIdColumn = null;
    /**
     * Per-request memo of resolved group-folder member candidates, keyed by
     * folder id. Resolving members hits the group backend (an LDAP round-trip
     * per group), and getFileByIdPublic() is called several times over a single
     * public request (render, capacity check, branding, uploads), so caching
     * the result for the request avoids repeating that work. Not persisted —
     * it lives only for the lifetime of this service instance.
     * @var array<int, list<string>>
     */
    private array $groupFolderMembersCache = [];

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        IDBConnection $db,
        IGroupManager $groupManager,
        IServerContainer $serverContainer
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->serverContainer = $serverContainer;
    }

    /**
     * Get the current session user's root folder.
     *
     * Shared helper: any layer that needs the caller's folder goes through here
     * rather than re-deriving it from IUserSession + IRootFolder.
     */
    public function getUserFolder(): Folder
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('No user logged in');
        }
        return $this->rootFolder->getUserFolder($user->getUID());
    }

    /**
     * Get file by ID with permission check
     */
    public function getFileById(int $fileId): File
    {
        $userFolder = $this->getUserFolder();
        $nodes = $userFolder->getById($fileId);

        if (empty($nodes)) {
            throw new NotFoundException('Form not found');
        }

        $file = $nodes[0];
        if (!($file instanceof File)) {
            throw new \RuntimeException('Not a file');
        }

        return $file;
    }

    /**
     * Get file by ID without user context (for public/system access)
     * Uses database lookup to find the owner and then accesses via their folder
     * Supports personal folders, group folders, and external storage
     *
     * A public submission has no session of its own, so the file has to be
     * opened through *some* account that can see it. For shared mounts (group/
     * team folders, external storage) several accounts qualify, and they do not
     * all have the same rights: a read-only member, or one blocked by a
     * groupfolders ACL rule, yields a File whose writes are silently refused —
     * both the group-folder PermissionsMask and the ACLStorageWrapper return
     * false from file_put_contents() rather than throwing. That lost responses
     * while the respondent saw "Thank you!" (#90, #101).
     *
     * Callers that intend to write must therefore pass $requireWrite, which
     * skips candidates that cannot update the file instead of picking the first
     * one that can merely see it. Read-only callers leave it false so that
     * viewing a form keeps working even where nobody can write.
     */
    public function getFileByIdPublic(int $fileId, bool $requireWrite = false): File
    {
        // Look up the file in the database to find the storage
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.id', 's.numeric_id')
            ->from('filecache', 'fc')
            ->innerJoin('fc', 'storages', 's', 'fc.storage = s.numeric_id')
            ->where($qb->expr()->eq('fc.fileid', $qb->createNamedParameter($fileId, \PDO::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new NotFoundException('Form not found');
        }

        $storageId = $row['id'];

        // Case 1: Personal folder (home::username)
        // Exactly one account owns the mount, so there is nothing to choose.
        if (str_starts_with($storageId, 'home::')) {
            $userId = substr($storageId, 6);
            $file = $this->resolveFileAsUser($userId, $fileId, $requireWrite);
            if ($file !== null) {
                return $file;
            }

            throw $this->cannotAccessException($fileId, $requireWrite);
        }

        // Case 2: Group folder / Team folder
        //   - Local:         local::.../__groupfolders/{id}/
        //   - Object store:  object::groupfolder:{id}.{objectstore_id}
        if (
            preg_match('#__groupfolders/(\d+)/#', $storageId, $matches)
            || preg_match('#^object::groupfolder:(\d+)#', $storageId, $matches)
        ) {
            $groupFolderId = (int)$matches[1];

            foreach ($this->findUsersWithGroupFolderAccess($groupFolderId) as $userId) {
                $file = $this->resolveFileAsUser($userId, $fileId, $requireWrite);
                if ($file !== null) {
                    return $file;
                }
            }

            throw $this->cannotAccessException($fileId, $requireWrite);
        }

        // Case 3: External storage (SMB, SFTP, S3, local mounts, etc.)
        foreach ($this->findUsersWithStorage((int)$row['numeric_id']) as $userId) {
            $file = $this->resolveFileAsUser($userId, $fileId, $requireWrite);
            if ($file !== null) {
                return $file;
            }
        }

        throw $this->cannotAccessException($fileId, $requireWrite);
    }

    /**
     * Open a file through one account's view of the filesystem.
     *
     * Returns null when that account cannot see the file at all, or — when
     * $requireWrite is set — when it can see but not update it. Checking
     * isUpdateable() here covers both refusing layers at once: the group
     * permission mask and the per-path ACL.
     */
    private function resolveFileAsUser(string $userId, int $fileId, bool $requireWrite): ?File
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable $e) {
            // Account disabled or otherwise unusable — try the next candidate.
            return null;
        }

        $nodes = $userFolder->getById($fileId);
        if (empty($nodes) || !($nodes[0] instanceof File)) {
            return null;
        }

        $file = $nodes[0];
        if ($requireWrite && !$file->isUpdateable()) {
            return null;
        }

        return $file;
    }

    /**
     * Build the failure for a form nobody could open.
     *
     * Distinguishes "no such form" from "found it, but no account may write to
     * it", so a misconfigured share surfaces as a real error instead of a
     * silently dropped response.
     */
    private function cannotAccessException(int $fileId, bool $requireWrite): \Exception
    {
        if ($requireWrite) {
            return new \RuntimeException(
                'No account with write access to the form file (fileId ' . $fileId . ') could be found. '
                . 'If the form is in a team or group folder, check that the folder grants write permission '
                . 'and that no advanced-permission rule blocks writing to it.'
            );
        }

        return new NotFoundException('Form not found');
    }

    /**
     * Candidate accounts for opening a file in a group/team folder.
     *
     * Groups whose membership grants write are listed first, so a writable
     * account is normally found on the first try. The permission column is
     * only a hint though — a groupfolders ACL rule can still deny writing to
     * this particular path — so the caller confirms with isUpdateable() and
     * falls through to the next candidate. Read-only groups stay in the list
     * because read-only callers must keep working (#90).
     *
     * @return list<string> user ids, best candidates first
     */
    private function findUsersWithGroupFolderAccess(int $groupFolderId): array
    {
        // Reuse the result within this request — the member lookup below can do
        // one LDAP round-trip per group, and this runs on every public
        // getFileByIdPublic() call (render + capacity + branding + upload).
        if (isset($this->groupFolderMembersCache[$groupFolderId])) {
            return $this->groupFolderMembersCache[$groupFolderId];
        }
        // Get groups that have access to this group folder, most-permissive
        // first. Members of several groups get their permissions OR'd together
        // by groupfolders, so a write-capable group is the better bet.
        // groupfolders stores group and circle entries in the same table
        // (group_folders_groups): a row is a circle when its circle_id column is
        // set, and we read the circle id from that circle_id column; otherwise
        // it is a plain group and we read group_id. The circle_id column exists
        // since groupfolders 14.1, well below our minimum, but stay defensive —
        // an older or patched schema should degrade to groups-only (detected by
        // groupFolderGroupsHasCircleId()) rather than break every lookup.
        $hasCircles = $this->groupFolderGroupsHasCircleId();

        $qb = $this->db->getQueryBuilder();
        $columns = $hasCircles
            ? ['group_id', 'circle_id', 'permissions']
            : ['group_id', 'permissions'];
        $qb->select(...$columns)
            ->from('group_folders_groups')
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupFolderId, \PDO::PARAM_INT)))
            ->orderBy('permissions', 'DESC')
            ->setMaxResults(self::ACCESS_CANDIDATE_GROUPS);

        $result = $qb->executeQuery();
        $entities = [];
        while ($row = $result->fetch()) {
            $isCircle = $hasCircles && !empty($row['circle_id']);
            $entities[] = [
                'id' => (string)($isCircle ? $row['circle_id'] : $row['group_id']),
                'isCircle' => $isCircle,
            ];
        }
        $result->closeCursor();

        $userIds = [];
        foreach ($entities as $entity) {
            $members = $entity['isCircle']
                ? $this->membersOfCircle($entity['id'])
                : $this->membersOfGroup($entity['id']);
            foreach ($members as $uid) {
                $userIds[] = $uid;
            }
        }

        $result = array_values(array_unique($userIds));
        $this->groupFolderMembersCache[$groupFolderId] = $result;
        return $result;
    }

    /**
     * Does groupfolders' applicable-entity table carry circle entries?
     *
     * The column arrived in groupfolders 14.1, far below anything that runs on
     * a supported Nextcloud, so this is true in practice. It is probed rather
     * than assumed so an older or hand-patched schema degrades to groups-only
     * instead of erroring on every form in a team folder.
     */
    private function groupFolderGroupsHasCircleId(): bool
    {
        if ($this->hasCircleIdColumn !== null) {
            return $this->hasCircleIdColumn;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('circle_id')
                ->from('group_folders_groups')
                ->setMaxResults(1);
            $qb->executeQuery()->closeCursor();
            $this->hasCircleIdColumn = true;
        } catch (\Throwable $e) {
            $this->hasCircleIdColumn = false;
        }

        return $this->hasCircleIdColumn;
    }

    /**
     * Members of a group, whatever backend it lives in.
     *
     * This must go through IGroupManager rather than reading oc_group_user: that
     * table only holds *local* group membership. On an LDAP/AD-backed instance
     * membership lives in the directory, so the SQL query returned nothing, no
     * account was available to open the file, and every public link to a form in
     * a team folder answered 404 (#136). IGroupManager asks each configured
     * backend in turn, so LDAP, SAML and local groups all resolve.
     *
     * @return list<string>
     */
    private function membersOfGroup(string $groupId): array
    {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return [];
        }

        // Sorted so the candidates we consider are deterministic across
        // requests — without it a backend may return an arbitrary subset, and if
        // the only ACL-writable member falls outside that subset the write is
        // lost even though a valid writer exists (#90). Bounded because an LDAP
        // group can hold thousands of accounts and we only need a few.
        $userIds = array_map(
            static fn (\OCP\IUser $user): string => $user->getUID(),
            $group->searchUsers('', self::ACCESS_CANDIDATE_USERS_PER_GROUP)
        );
        $userIds = array_values($userIds);
        sort($userIds);

        return $userIds;
    }

    /**
     * Members of a circle (Teams), for team folders shared with one.
     *
     * Circles are optional: the app may be absent or disabled, in which case
     * this contributes nothing and group-based access still works.
     *
     * @return list<string>
     */
    private function membersOfCircle(string $circleId): array
    {
        if (!class_exists(\OCA\Circles\CirclesManager::class)) {
            return [];
        }

        try {
            /** @var \OCA\Circles\CirclesManager $circlesManager */
            $circlesManager = $this->serverContainer->get(\OCA\Circles\CirclesManager::class);
            $circlesManager->startSuperSession();
            $circle = $circlesManager->getCircle($circleId);

            // Collect ALL single-user members first, then dedupe + sort, and
            // only then truncate. Truncating before sorting would pick whatever
            // arbitrary subset getInheritedMembers() yields first (its order is
            // not stable across requests once nested circles/groups are
            // flattened), so the ACL-writable member could fall outside the cap
            // on some requests and the write would be intermittently lost (#90).
            // getInheritedMembers() is an in-memory list, so iterating it fully
            // costs no extra queries.
            $userIds = [];
            foreach ($circle->getInheritedMembers() as $member) {
                if ($member->getUserType() !== 1) {
                    // Only single users can own a filesystem view; nested
                    // circles and groups arrive flattened as their members.
                    continue;
                }
                $userIds[] = (string)$member->getUserId();
            }
            $userIds = array_values(array_unique($userIds));
            sort($userIds);

            return array_slice($userIds, 0, self::ACCESS_CANDIDATE_USERS_PER_GROUP);
        } catch (\Throwable $e) {
            // Circle gone, app disabled mid-request, or API change — fall back
            // to the group entries rather than failing the whole lookup.
            return [];
        } finally {
            if (isset($circlesManager)) {
                try {
                    $circlesManager->stopSession();
                } catch (\Throwable $e) {
                    // best effort
                }
            }
        }
    }

    /**
     * Candidate accounts for opening a file on an external storage mount.
     *
     * As with group folders, several accounts may mount the same storage with
     * differing rights, so return a list (deterministically ordered) and let
     * the caller confirm writability. (#90)
     *
     * @return list<string> user ids
     */
    private function findUsersWithStorage(int $storageNumericId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
            ->from('mounts')
            ->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($storageNumericId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->neq('user_id', $qb->createNamedParameter('')))
            ->orderBy('user_id', 'ASC')
            ->setMaxResults(self::ACCESS_CANDIDATE_USERS_PER_GROUP);

        $result = $qb->executeQuery();
        $userIds = [];
        while ($row = $result->fetch()) {
            $userIds[] = $row['user_id'];
        }
        $result->closeCursor();

        return array_values(array_unique($userIds));
    }
}
