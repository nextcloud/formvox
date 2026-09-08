<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\Versions\IFormVersionCleaner;
use OCP\Files\File;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * The concurrency core: serializes every read-modify-write of a .fvform file
 * behind a per-file database lock (#3/#7/#90/#97/#101).
 *
 * All writers of a form file — append response, update form, delete response —
 * go through {@see mutateFormFileWithLock()} so concurrent writes to the same
 * form can never clobber each other. The lock is a row in the `preferences`
 * table taken via a unique-constraint insert, with a TTL reclaim so a crashed
 * worker can't wedge a form forever, a short-write verification so a silently
 * truncated write is caught, and diagnostic context captured on a refused write
 * (both the group-folder mask and the ACL wrapper return false rather than
 * throwing). Extracted verbatim from FormService; the only behavioural change is
 * that version cleanup is delegated to the injected {@see IFormVersionCleaner}
 * instead of resolving IVersionManager/IUserManager via static calls.
 */
class FormLockManager
{
    /**
     * How long (seconds) a response-write lock row may live before it is
     * considered stale and reclaimed. Guards against a worker dying mid-write
     * (timeout/OOM) leaving a lock that would otherwise DoS the form forever.
     */
    private const LOCK_TTL_SECONDS = 60;

    private IDBConnection $db;
    private IUserSession $userSession;
    private IFormVersionCleaner $versionCleaner;

    public function __construct(
        IDBConnection $db,
        IUserSession $userSession,
        IFormVersionCleaner $versionCleaner
    ) {
        $this->db = $db;
        $this->userSession = $userSession;
        $this->versionCleaner = $versionCleaner;
    }

    /**
     * Serialize a read-modify-write of a form file behind the per-file lock.
     *
     * This is the single mutual-exclusion point for every writer of a .fvform
     * file (append, update, delete): they all go through here so concurrent
     * writes to the same form can never clobber each other (last-writer-wins was
     * a real data-loss bug for update()/delete* which used to write unlocked).
     *
     * The $mutator receives the freshly-read form array by reference and mutates
     * it in place; it runs UNDER the lock against current on-disk state, so any
     * capacity / duplicate / limit re-checks it performs are race-free. Throwing
     * from the mutator aborts the write (nothing is persisted) and the exception
     * propagates to the caller.
     *
     * @param callable $mutator function(array &$form): void
     * @return mixed whatever $mutator returns (usually null)
     */
    public function mutateFormFileWithLock(File $file, callable $mutator)
    {
        $lockKey = 'formvox_response_' . $file->getId();
        $maxRetries = 30;
        $retryDelay = 100000; // 100ms in microseconds

        for ($retry = 0; $retry < $maxRetries; $retry++) {
            // Reclaim a stale lock before trying to take it, so a worker that
            // died mid-write (timeout/OOM) can't DoS the form forever (#7).
            $this->reclaimStaleLock($lockKey);

            // Try to acquire lock via database using unique constraint
            $qb = $this->db->getQueryBuilder();
            $qb->insert('preferences')
                ->values([
                    'userid' => $qb->createNamedParameter('__formvox_lock__'),
                    'appid' => $qb->createNamedParameter('formvox'),
                    'configkey' => $qb->createNamedParameter($lockKey),
                    'configvalue' => $qb->createNamedParameter((string)time()),
                ]);

            try {
                $qb->executeStatement();

                try {
                    // Get storage for direct access (bypasses versioning)
                    $storage = $file->getStorage();
                    $internalPath = $file->getInternalPath();

                    // Read content directly from storage
                    $content = $storage->file_get_contents($internalPath);
                    $form = json_decode($content, true);

                    if ($form === null) {
                        throw new \RuntimeException('Invalid form file format');
                    }

                    // Apply the caller's mutation under the lock, against the
                    // freshly-read form. May throw to abort the write.
                    $result = $mutator($form);
                    $form['modified_at'] = date('c');

                    // Write through the node API rather than the raw storage.
                    // Several storage wrappers (the group-folder permission
                    // mask, the groupfolders ACL wrapper, some object stores)
                    // return false from file_put_contents() instead of
                    // throwing, so a refused write looked like success and the
                    // response was lost while the respondent saw "Thank you!"
                    // (#90, #97, #101). putContent() raises a typed exception
                    // instead. Versions created by this write are removed
                    // below, which is what the raw-storage call used to avoid.
                    $payload = json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $expectedBytes = strlen($payload);
                    try {
                        $file->putContent($payload);
                    } catch (\Throwable $e) {
                        throw new \RuntimeException(
                            'Could not write form file (fileId ' . $file->getId() . '): ' . $e->getMessage()
                            . ' [' . $this->describeWriteContext($file, $storage, $internalPath) . ']',
                            0,
                            $e
                        );
                    }

                    // Verify the write actually persisted in full. putContent()
                    // turns a false storage return into an exception, but a
                    // backend that reports a positive-but-truncated write would
                    // slip through — so confirm the stored size matches, keeping
                    // the full #97 short-write guarantee. filesize() reflects the
                    // size putContent() just wrote (object stores update the
                    // cache synchronously to the real byte count before returning;
                    // the encryption wrapper reports the plaintext size, which is
                    // what we compare against). The `!== false` guard tolerates a
                    // backend that can't report a size rather than false-failing.
                    $writtenBytes = $storage->filesize($internalPath);
                    if ($writtenBytes !== false && $writtenBytes < $expectedBytes) {
                        throw new \RuntimeException(
                            'Short write for fileId ' . $file->getId()
                            . ' (stored ' . var_export($writtenBytes, true) . ' of ' . $expectedBytes . ' bytes)'
                            . ' [' . $this->describeWriteContext($file, $storage, $internalPath) . ']'
                        );
                    }

                    // Delete any versions created during this write
                    $this->versionCleaner->deleteVersionsForFile($file);

                    return $result;
                } finally {
                    // Always release lock
                    $this->releaseLock($lockKey);
                }
            } catch (\OCP\DB\Exception $e) {
                // Lock acquisition failed (unique violation) → retry. Use
                // Nextcloud's typed reason codes where available; fall back
                // to string matching for older NC versions that don't
                // populate the reason. Covers MySQL ("Duplicate entry"),
                // SQLite ("UNIQUE constraint") and Postgres ("duplicate key").
                if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    usleep($retryDelay * ($retry + 1));
                    continue;
                }
                $msg = $e->getMessage();
                if (strpos($msg, 'Duplicate') !== false ||
                    strpos($msg, 'UNIQUE constraint') !== false ||
                    strpos($msg, 'duplicate key') !== false ||
                    strpos($msg, '23505') !== false) {
                    usleep($retryDelay * ($retry + 1));
                    continue;
                }
                throw $e;
            }
        }

        throw new \RuntimeException('Could not acquire lock after ' . $maxRetries . ' retries');
    }

    /**
     * Delete the lock row for $lockKey if its stored timestamp is older than
     * LOCK_TTL_SECONDS. The timestamp is written at acquisition time; without
     * this reclaim a crashed worker's row would live forever and block every
     * future submission for that form (#7).
     */
    private function reclaimStaleLock(string $lockKey): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('configvalue')
                ->from('preferences')
                ->where($qb->expr()->eq('userid', $qb->createNamedParameter('__formvox_lock__')))
                ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('formvox')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($lockKey)));
            $row = $qb->executeQuery()->fetch();
            if ($row === false) {
                return; // no lock held
            }
            $acquiredAt = (int)($row['configvalue'] ?? 0);
            if ($acquiredAt > 0 && (time() - $acquiredAt) < self::LOCK_TTL_SECONDS) {
                return; // lock is fresh, leave it
            }
            // Stale (or unparseable timestamp) → reclaim.
            $this->releaseLock($lockKey);
        } catch (\Exception $e) {
            // Best-effort; if the reclaim probe fails we just fall through to
            // the normal insert-and-retry path.
        }
    }

    /**
     * Release a database lock
     */
    private function releaseLock(string $lockKey): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('preferences')
                ->where($qb->expr()->eq('userid', $qb->createNamedParameter('__formvox_lock__')))
                ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('formvox')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($lockKey)));
            $qb->executeStatement();
        } catch (\Exception $e) {
            // Log but don't throw - lock will expire anyway
        }
    }

    /**
     * Describe the storage context of a failed response write (#90, #101).
     *
     * Both the group-folder PermissionsMask (built from the group's
     * permissions column) and the innermost ACLStorageWrapper return false
     * from file_put_contents() instead of throwing, so a denied write is
     * indistinguishable from a full disk by the return value alone. This
     * records which layer refused: an unwritable node points at the mask or
     * the ACL, a writable one at the backend itself.
     *
     * Diagnostics only — must never throw, or it would mask the real error.
     */
    private function describeWriteContext(File $file, $storage, string $internalPath): string
    {
        $parts = [];

        try {
            $parts[] = 'storage=' . get_class($storage);
        } catch (\Throwable $e) {
            $parts[] = 'storage=?';
        }

        try {
            $parts[] = 'updatable=' . var_export($file->isUpdateable(), true);
        } catch (\Throwable $e) {
            $parts[] = 'updatable=?';
        }

        try {
            $parts[] = 'storagePerms=' . var_export($storage->getPermissions($internalPath), true);
        } catch (\Throwable $e) {
            $parts[] = 'storagePerms=?';
        }

        try {
            $parts[] = 'freeSpace=' . var_export($storage->free_space($internalPath), true);
        } catch (\Throwable $e) {
            $parts[] = 'freeSpace=?';
        }

        try {
            $parts[] = 'user=' . ($this->userSession->getUser()?->getUID() ?? 'anonymous');
        } catch (\Throwable $e) {
            $parts[] = 'user=?';
        }

        return implode(' ', $parts);
    }
}
