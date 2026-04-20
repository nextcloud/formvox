<?php

declare(strict_types=1);

namespace OCA\FormVox\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getOcpTaskId()
 * @method void setOcpTaskId(int $ocpTaskId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method int getTimestamp()
 * @method void setTimestamp(int $timestamp)
 */
class AiPending extends Entity implements \JsonSerializable
{
    /** @var int */
    protected $ocpTaskId;
    /** @var string */
    protected $title;
    /** @var string */
    protected $path;
    /** @var int */
    protected $timestamp;

    public function __construct()
    {
        $this->addType('ocp_task_id', Types::INTEGER);
        $this->addType('title', Types::STRING);
        $this->addType('path', Types::STRING);
        $this->addType('timestamp', Types::INTEGER);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'id' => $this->id,
            'ocp_task_id' => $this->ocpTaskId,
            'title' => $this->title,
            'path' => $this->path,
            'timestamp' => $this->timestamp,
        ];
    }
}
