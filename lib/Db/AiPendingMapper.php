<?php

declare(strict_types=1);

namespace OCA\FormVox\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<AiPending>
 */
class AiPendingMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'formvox_ai_pending', AiPending::class);
    }

    /**
     * @throws Exception
     * @throws MultipleObjectsReturnedException
     */
    public function getByTaskId(int $ocpTaskId): ?AiPending
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('ocp_task_id', $qb->createNamedParameter($ocpTaskId, IQueryBuilder::PARAM_INT))
            );
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * @throws Exception
     * @throws MultipleObjectsReturnedException
     */
    public function deleteByTaskId(int $ocpTaskId): void
    {
        $existing = $this->getByTaskId($ocpTaskId);
        if ($existing !== null) {
            $this->delete($existing);
        }
    }

    /**
     * Idempotent: returns the existing row if one exists, otherwise inserts.
     *
     * @throws Exception
     * @throws MultipleObjectsReturnedException
     */
    public function createPending(int $ocpTaskId, string $title, string $path = ''): ?AiPending
    {
        $existing = $this->getByTaskId($ocpTaskId);
        if ($existing !== null) {
            return $existing;
        }
        $entity = new AiPending();
        $entity->setOcpTaskId($ocpTaskId);
        $entity->setTitle($title);
        $entity->setPath($path);
        $entity->setTimestamp((new DateTime())->getTimestamp());
        $this->insert($entity);
        return $this->getByTaskId($ocpTaskId);
    }
}
