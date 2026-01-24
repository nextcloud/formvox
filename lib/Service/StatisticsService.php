<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
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

    public function __construct(
        IRootFolder $rootFolder,
        IDBConnection $db,
        IUserManager $userManager,
        LoggerInterface $logger
    ) {
        $this->rootFolder = $rootFolder;
        $this->db = $db;
        $this->userManager = $userManager;
        $this->logger = $logger;
    }

    /**
     * Get all statistics for the admin panel
     */
    public function getStatistics(): array
    {
        return [
            'totalForms' => $this->getFormCount(),
            'totalResponses' => $this->getTotalResponseCount(),
            'activeUsers30d' => $this->getActiveUserCount(30),
        ];
    }

    /**
     * Count total .fvform files in the system
     */
    public function getFormCount(): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('filecache', 'fc')
                ->where($qb->expr()->like('fc.name', $qb->createNamedParameter('%.fvform')));

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
            $qb = $this->db->getQueryBuilder();
            $qb->select('fc.fileid', 'fc.storage', 's.id')
                ->from('filecache', 'fc')
                ->innerJoin('fc', 'storages', 's', 'fc.storage = s.numeric_id')
                ->where($qb->expr()->like('fc.name', $qb->createNamedParameter('%.fvform')));

            $result = $qb->executeQuery();
            $totalResponses = 0;

            while ($row = $result->fetch()) {
                try {
                    $storageId = $row['id'];
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
            $result->closeCursor();

            return $totalResponses;
        } catch (\Exception $e) {
            $this->logger->warning('StatisticsService: Failed to count responses', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
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
}
