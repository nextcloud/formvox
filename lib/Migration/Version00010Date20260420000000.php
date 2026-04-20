<?php

declare(strict_types=1);

namespace OCA\FormVox\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the formvox_ai_pending table — pending AI form-generation tasks
 * keyed by their TaskProcessing task id. Same shape as nextcloud/assistant's
 * assistant_task_notif, with the extra fields needed to build the form once
 * the task finishes asynchronously.
 */
class Version00010Date20260420000000 extends SimpleMigrationStep
{
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('formvox_ai_pending')) {
            $changed = true;
            $table = $schema->createTable('formvox_ai_pending');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('ocp_task_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('path', Types::STRING, [
                'notnull' => false,
                'length' => 4000,
                'default' => '',
            ]);
            $table->addColumn('timestamp', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['ocp_task_id'], 'fvx_ai_pending_tid');
        }

        return $changed ? $schema : null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
    }
}
