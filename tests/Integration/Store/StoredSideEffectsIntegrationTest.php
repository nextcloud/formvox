<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\BackgroundJob\SideEffectsJob;
use OCA\FormVox\BackgroundJob\UploadCleanupJob;
use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\UploadService;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\OutboxStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Against a real Nextcloud: for a form in the database, what a response sets off waits in outbox rows, the background
 * job sends it and crosses it off by the same rules as for a form file, and unsubmitted uploads are cleaned up.
 *
 * @group DB
 */
class StoredSideEffectsIntegrationTest extends IntegrationTestCase {
	private const GROUP = '7a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	/** @param array<string, mixed> $settings */
	private function form(array $settings = []): int {
		$repository = Server::get(FormRepository::class);
		$fileId = (int)$repository->create('Stored effects ' . uniqid())['fileId'];
		$repository->update($fileId, [
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name'], ['id' => 'cv', 'type' => 'file', 'question' => 'CV']],
			'settings' => $settings + ['allow_multiple' => true, 'anonymous' => false],
		]);
		return $fileId;
	}

	private function submit(int $fileId, array $answers = ['q1' => 'Bob']): array {
		return Server::get(ResponseService::class)->submitAuthenticated($fileId, Server::get(FormRepository::class)->loadPublic($fileId), $answers, 'formvox-int-respondent', 'Bob');
	}

	/** @return list<array> */
	private function outbox(int $fileId): array {
		return Server::get(OutboxStore::class)->entries(Server::get(FormStore::class)->byFileId($fileId)->id);
	}

	private function runJob(int $fileId): void {
		$job = Server::get(SideEffectsJob::class);
		$job->setArgument(['fileId' => $fileId]);
		$job->start(Server::get(IJobList::class));
	}

	public function testAResponseIsAnnouncedFromTheBackgroundAndCrossedOff(): void {
		$fileId = $this->form();
		$response = $this->submit($fileId);
		$this->assertSame([$response['id'] . ':response.created'], array_column($this->outbox($fileId), 'id'));
		$this->assertTrue(Server::get(IJobList::class)->has(SideEffectsJob::class, ['fileId' => $fileId]), 'one job asked for');
		// Cron loads every app; the notifications app keeps a notification only once it is loaded.
		$notifications = Server::get(IAppManager::class)->isEnabledForAnyone('notifications');
		$notifications && Server::get(IAppManager::class)->loadApp('notifications');

		$this->runJob($fileId);

		$this->assertSame([], $this->outbox($fileId), 'crossed off');
		if ($notifications) {
			$qb = Server::get(IDBConnection::class)->getQueryBuilder();
			$qb->select('notification_id')->from('notifications')
				->where($qb->expr()->eq('app', $qb->createNamedParameter('formvox')))
				->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($response['id'])));
			$this->assertCount(1, $qb->executeQuery()->fetchAll(), 'the creator heard about it');
		}
	}

	public function testAWebhookThatCannotBeReachedWaitsAndARemovedResponseTakesItsRowsAlong(): void {
		$fileId = $this->form(['notify_owner' => false]);
		Server::get(FormRepository::class)->mutateSettings($fileId, fn (array $settings) => ['webhooks' => [['id' => 'wh_down', 'url' => 'http://127.0.0.1:9/hook', 'secret' => 'whsec', 'created_by' => $this->ownerUid, 'enabled' => true]]] + $settings);
		$response = $this->submit($fileId);

		$this->runJob($fileId);

		$entry = $this->outbox($fileId)[0];
		$this->assertSame([['webhook:wh_down'], 1], [$entry['todo'], $entry['attempts']]);
		$this->assertGreaterThan(time() + 200, $entry['notBefore']);

		Server::get(ResponsePersistenceService::class)->delete(Server::get(FormFileLocator::class)->getFileById($fileId), $response['id']);

		$this->assertSame([$response['id'] . ':response.deleted'], array_column($this->outbox($fileId), 'id'), 'only the deletion is left to tell');
	}

	/** As with a form file: a removal still to be told, and what a run is sending now, outlast removing every response. */
	public function testRemovingEveryResponseKeepsWhatIsStillToBeToldOrBeingSent(): void {
		$fileId = $this->form(['notify_owner' => false]);
		Server::get(FormRepository::class)->mutateSettings($fileId, fn (array $settings) => ['webhooks' => [['id' => 'wh_down', 'url' => 'http://127.0.0.1:9/hook', 'secret' => 'whsec', 'created_by' => $this->ownerUid, 'enabled' => true]]] + $settings);
		[$first, $second, $third] = [$this->submit($fileId)['id'], $this->submit($fileId)['id'], $this->submit($fileId)['id']];
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('formvox_outbox')->set('claimed_at', $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('rid', $qb->createNamedParameter($third)))->executeStatement();
		$persistence = Server::get(ResponsePersistenceService::class);
		$file = Server::get(FormFileLocator::class)->getFileById($fileId);

		$persistence->delete($file, $first);
		$persistence->deleteAll($file);

		$this->assertEqualsCanonicalizing(
			[$first . ':response.deleted', $third . ':response.created', $second . ':response.deleted', $third . ':response.deleted'],
			array_column($this->outbox($fileId), 'id'),
		);
	}

	public function testUploadsNoResponseNamesAreCleanedUpAndNamedOnesStay(): void {
		$fileId = $this->form();
		$uploads = Server::get(UploadService::class);
		$kept = '8b2c3d4e-5f6a-4b7c-9d8e-0f1a2b3c4d5e';
		$uploads->storeUploadPublic($fileId, self::GROUP, $this->makeUploadedFile('lost.pdf', 'never submitted', 'application/pdf'));
		$named = $uploads->storeUploadPublic($fileId, $kept, $this->makeUploadedFile('cv.pdf', 'submitted', 'application/pdf'));
		$this->submit($fileId, ['q1' => 'Bob', 'cv' => ['responseId' => $kept, 'filename' => $named['filename'], 'originalName' => 'cv.pdf']]);

		foreach ([self::GROUP, $kept] as $group) {
			$job = Server::get(UploadCleanupJob::class);
			$job->setArgument(['fileId' => $fileId, 'group' => $group]);
			$job->start(Server::get(IJobList::class));
		}

		$folder = $uploads->getUploadsFolder(Server::get(FormFileLocator::class)->getFileById($fileId));
		$this->assertFalse($folder->nodeExists(self::GROUP), 'nobody submitted it');
		$this->assertTrue($folder->nodeExists($kept), 'a response names it');
	}
}
