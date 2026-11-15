<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Against a real database: a form's row, its binding to a file, and the rows it owns.
 *
 * @group DB
 */
class FormStoreIntegrationTest extends IntegrationTestCase {
	private FormStore $store;
	private IDBConnection $db;
	/** @var list<int> */
	private array $made = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store = Server::get(FormStore::class);
		$this->db = Server::get(IDBConnection::class);
	}

	protected function tearDown(): void {
		foreach ($this->made as $id) {
			$this->store->delete($id);
		}
		parent::tearDown();
	}

	private function create(int $fileId, int $storageId = 7): \OCA\FormVox\Store\StoredForm {
		$form = $this->store->create(self::uuid(), $fileId, $storageId);
		$this->made[] = $form->id;
		return $form;
	}

	private static function uuid(): string {
		return sprintf('%s-%s-4%s-a%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
	}

	public function testAFormIsFoundByItsFileAndItsUuid(): void {
		$form = $this->create(900001);

		$this->assertSame($form->id, $this->store->byFileId(900001)?->id);
		$this->assertSame(900001, $this->store->byUuid($form->uuid)?->fileId);
		$this->assertNull($this->store->byFileId(900002));
	}

	public function testAUuidBelongsToOneFormOnly(): void {
		$form = $this->create(900003);

		try {
			$this->store->create($form->uuid, 900004, 7);
			$this->fail('a second form with the same uuid');
		} catch (Exception $e) {
			$this->assertSame(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, $e->getReason());
		}
		$this->assertNull($this->store->byFileId(900004));
	}

	public function testAnOrphanIsNoLongerTheFilesFormButIsFoundAgainByUuid(): void {
		$form = $this->create(900005);

		$this->store->markOrphan($form->id);

		$this->assertNull($this->store->byFileId(900005));
		$this->assertSame($form->id, $this->store->byUuid($form->uuid)?->id);

		$this->store->rebind($form->id, 900006, 8, md5('files/moved.fvform'));

		$found = $this->store->byFileId(900006);
		$this->assertSame($form->id, $found?->id);
		$this->assertSame(8, $found?->storageId);
		$this->assertSame(md5('files/moved.fvform'), $found?->pathHash);
	}

	public function testDeletingAFormRemovesEveryRowItOwns(): void {
		$form = $this->create(900007);
		$other = $this->create(900008);
		foreach ([$form->id, $other->id] as $id) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('formvox_resp')->values([
				'form_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
				'rid' => $qb->createNamedParameter('r-' . $id),
				'created' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'data' => $qb->createNamedParameter('{}'),
			])->executeStatement();
		}

		$this->store->delete($form->id);
		$this->made = [$other->id];

		$this->assertNull($this->store->byUuid($form->uuid));
		$this->assertSame(0, $this->responses($form->id));
		$this->assertSame(1, $this->responses($other->id));
	}

	private function responses(int $formId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('formvox_resp')->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
