<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Service for collecting FormVox usage statistics
 */
class StatisticsService
{
    private IRootFolder $rootFolder;
    private IDBConnection $db;
    private IUserManager $userManager;
    private LoggerInterface $logger;
    private IMimeTypeLoader $mimeTypeLoader;

    public function __construct(
        IRootFolder $rootFolder,
        IDBConnection $db,
        IUserManager $userManager,
        LoggerInterface $logger,
        IMimeTypeLoader $mimeTypeLoader
    ) {
        $this->rootFolder = $rootFolder;
        $this->db = $db;
        $this->userManager = $userManager;
        $this->logger = $logger;
        $this->mimeTypeLoader = $mimeTypeLoader;
    }

    /**
     * Get all statistics for the admin panel
     */
    public function getStatistics(): array
    {
        return [
            'totalForms' => $this->getFormCount(),
            'totalResponses' => $this->getTotalResponseCount(),
            'totalUsers' => $this->getUserCount(),
            'activeUsers30d' => $this->getActiveUserCount(30),
        ];
    }

    /**
     * Count total .fvform files in the system
     */
    public function getFormCount(): int
    {
        try {
            $mimeTypeId = $this->mimeTypeLoader->getId(Application::MIME_TYPE);

            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('filecache', 'fc')
                ->where($qb->expr()->eq('fc.mimetype', $qb->createNamedParameter($mimeTypeId, IQueryBuilder::PARAM_INT)))
                // Version and trash copies keep the form mimetype — count
                // only the live files
                ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter($this->db->escapeLikeParameter('files_versions/') . '%')))
                ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter($this->db->escapeLikeParameter('files_trashbin/') . '%')));

            $result = $qb->executeQuery();
            $count = (int)$result->fetchOne();
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->warning('StatisticsService: Failed to count forms', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Count total responses across all forms
     * This reads from the _index in each form file for efficiency
     */
    public function getTotalResponseCount(): int
    {
        try {
            $mimeTypeId = $this->mimeTypeLoader->getId(Application::MIME_TYPE);

            $qb = $this->db->getQueryBuilder();
            $qb->select('fc.fileid', 'fc.storage')
                ->from('filecache', 'fc')
                ->where($qb->expr()->eq('fc.mimetype', $qb->createNamedParameter($mimeTypeId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter($this->db->escapeLikeParameter('files_versions/') . '%')))
                ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter($this->db->escapeLikeParameter('files_trashbin/') . '%')));

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            // Resolve numeric storage ids to string ids separately — a join
            // against filecache is not allowed on sharded instances.
            $storageNames = $this->getStorageNames(array_map(
                static fn (array $row): int => (int)$row['storage'],
                $rows
            ));

            $totalResponses = 0;

            foreach ($rows as $row) {
                try {
                    $storageId = $storageNames[(int)$row['storage']] ?? '';
                    $fileId = (int)$row['fileid'];

                    // Only process personal folders for now (home::username)
                    if (strpos($storageId, 'home::') === 0) {
                        $userId = substr($storageId, 6);
                        $userFolder = $this->rootFolder->getUserFolder($userId);
                        $nodes = $userFolder->getById($fileId);

                        if (!empty($nodes)) {
                            $file = $nodes[0];
                            $content = $file->getContent();
                            $form = json_decode($content, true);

                            if ($form !== null) {
                                // Use index if available, otherwise count responses array
                                $totalResponses += $form['_index']['response_count'] ?? count($form['responses'] ?? []);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Skip files we can't access
                    continue;
                }
            }

            return $totalResponses;
        } catch (\Exception $e) {
            $this->logger->warning('StatisticsService: Failed to count responses', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Map numeric storage ids to their string ids (e.g. "home::alice")
     *
     * @param int[] $numericIds
     * @return array<int, string>
     */
    private function getStorageNames(array $numericIds): array
    {
        $names = [];
        foreach (array_chunk(array_unique($numericIds), 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('numeric_id', 'id')
                ->from('storages')
                ->where($qb->expr()->in('numeric_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $names[(int)$row['numeric_id']] = (string)$row['id'];
            }
            $result->closeCursor();
        }
        return $names;
    }

    /**
     * Get active user count for the last N days
     */
    public function getActiveUserCount(int $days): int
    {
        try {
            $cutoffTime = time() - ($days * 24 * 60 * 60);
            $count = 0;

            $this->userManager->callForSeenUsers(function ($user) use (&$count, $cutoffTime) {
                $lastLogin = $user->getLastLogin();
                if ($lastLogin >= $cutoffTime) {
                    $count++;
                }
            });

            return $count;
        } catch (\Exception $e) {
            $this->logger->warning('StatisticsService: Failed to count active users', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get total user count
     */
    public function getUserCount(): int
    {
        try {
            $count = 0;
            $this->userManager->callForAllUsers(function ($user) use (&$count) {
                $count++;
            });
            return max(1, $count);
        } catch (\Exception $e) {
            $this->logger->warning('StatisticsService: Failed to count users', [
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }
}
