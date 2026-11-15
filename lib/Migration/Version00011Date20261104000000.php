<?php

declare(strict_types=1);

namespace OCA\FormVox\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Responses, their keys and counters, the outbox and secrets move out of the form file into the database. Names stay
 * within Oracle's limits: tables at most 22 characters without prefix, columns and indexes 30.
 */
class Version00011Date20261104000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('formvox_forms')) {
			$changed = true;
			$table = $schema->createTable('formvox_forms');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('storage_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('path_hash', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('state', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('orphaned_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('n', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// When a form file's responses moved in: from then on the database counts, not what the file says.
			$table->addColumn('migrated_at', Types::BIGINT, ['notnull' => false]);
			// For a form moved in: when its files reached app data, and when its file was tidied. A new form has both.
			$table->addColumn('files_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('tidied_at', Types::BIGINT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'fvx_forms_uuid');
			$table->addIndex(['file_id'], 'fvx_forms_file');
			$table->addIndex(['storage_id'], 'fvx_forms_storage');
		}

		if (!$schema->hasTable('formvox_resp')) {
			$changed = true;
			$table = $schema->createTable('formvox_resp');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('rid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('data', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['form_id', 'rid'], 'fvx_resp_rid');
			$table->addIndex(['form_id', 'id'], 'fvx_resp_form');
		}

		if (!$schema->hasTable('formvox_keys')) {
			$changed = true;
			$table = $schema->createTable('formvox_keys');
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 1]);
			$table->addColumn('k', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('rid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['form_id', 'kind', 'k']);
			$table->addIndex(['form_id', 'rid'], 'fvx_keys_rid');
		}

		if (!$schema->hasTable('formvox_counts')) {
			$changed = true;
			$table = $schema->createTable('formvox_counts');
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('qid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('val', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('n', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['form_id', 'qid', 'val']);
		}

		if (!$schema->hasTable('formvox_outbox')) {
			$changed = true;
			$table = $schema->createTable('formvox_outbox');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('rid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('event', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Room for a webhook each, comma-separated; NULL when nothing is left, as Oracle keeps '' anyway.
			$table->addColumn('todo', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$table->addColumn('lang', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('signed_in', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			// A signed-in respondent's account, whose address the confirmation goes to; held only until it is sent.
			$table->addColumn('account', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('claimed_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('not_before', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('created', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['form_id', 'id'], 'fvx_outbox_form');
		}

		if (!$schema->hasTable('formvox_secrets')) {
			$changed = true;
			$table = $schema->createTable('formvox_secrets');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('form_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('kid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('value', Types::TEXT, ['notnull' => false]);
			$table->addColumn('meta', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['form_id', 'kind', 'kid'], 'fvx_secret_kid');
		}

		return $changed ? $schema : null;
	}
}
