<?php

declare(strict_types=1);

namespace OCA\FormVox\SetupCheck;

use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * FormVox keeps responses in the database; SQLite takes one write at a time, so it is not fully supported.
 * Mirrors Nextcloud's own database check, link included.
 */
class DatabaseCheck implements ISetupCheck {
	public function __construct(
		private IDBConnection $db,
		private IL10N $l,
		private IURLGenerator $urls,
	) {
	}

	public function getCategory(): string {
		return 'database';
	}

	public function getName(): string {
		// TRANSLATORS: Name of a check in the admin overview about the database FormVox stores responses in
		return $this->l->t('FormVox database');
	}

	public function run(): SetupResult {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
			return SetupResult::success();
		}
		return SetupResult::warning(
			// TRANSLATORS: Warning in the admin overview; keep "occ db:convert-type" as it is, it is a command
			$this->l->t('FormVox does not fully support SQLite. Responses are stored in the database, and SQLite handles one write at a time, so many respondents at once can get errors. Use MySQL, MariaDB or PostgreSQL; to move, use the command line tool "occ db:convert-type".'),
			$this->urls->linkToDocs('admin-db-conversion'),
		);
	}
}
