<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Response;

use OC\AppFramework\DependencyInjection\DIContainer;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\BackgroundJob\SideEffectsJob;
use OCA\FormVox\Pdf\ResponsePdf;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Service\WebhookService;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\OutboxStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Against a REAL Nextcloud: a submission stores what it sets off with the response and asks for one background job;
 * that job sends it (here: the creator's notification) and crosses it off, so the respondent never waits for a
 * webhook or a mail server (7a, 3a-4).
 *
 * @group DB
 */
class SideEffectsIntegrationTest extends IntegrationTestCase {
	public function testASubmissionIsAnnouncedFromTheBackgroundAndCrossedOff(): void {
		$file = $this->writeFormFile($this->userFolder, 'Side Effects ' . uniqid(), [
			'settings' => ['allow_multiple' => true, 'anonymous' => false, 'public_token' => 'tok'],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
		]);
		$fileId = $file->getId();
		$repository = Server::get(FormRepository::class);
		$jobs = Server::get(IJobList::class);

		$response = Server::get(ResponseService::class)->submitAuthenticated($fileId, $repository->loadPublic($fileId), ['q1' => 'Bob'], 'formvox-int-respondent', 'Bob');

		$this->assertSame([$response['id'] . ':response.created'], array_column($this->outbox($fileId), 'id'), 'stored with the response');
		$this->assertTrue($jobs->has(SideEffectsJob::class, ['fileId' => $fileId]), 'one job asked for');

		// Cron loads every app; the notifications app keeps a notification only once it is loaded.
		$notifications = Server::get(IAppManager::class)->isEnabledForAnyone('notifications');
		$notifications && Server::get(IAppManager::class)->loadApp('notifications');
		$job = Server::get(SideEffectsJob::class);
		$job->setArgument(['fileId' => $fileId]);
		$started = time();
		$job->start($jobs);

		$this->assertSame([], $this->outbox($fileId), 'crossed off');
		$this->assertSame([$response['id']], $this->responseIds($fileId));
		// Only the backstop a run sets before it claims, which finds an empty outbox and only reads.
		$this->assertGreaterThanOrEqual($started + SideEffectsJob::CLAIM_SECONDS, $this->nextRun($fileId), 'nothing left to try sooner');
		if ($notifications) {
			$this->assertSame(1, $this->notificationsFor($this->ownerUid, $response['id']), 'the creator heard about it');
		}
	}

	/** @return list<array> what waits to be sent, from the database the submission moved the form into */
	private function outbox(int $fileId): array {
		$stored = Server::get(FormStore::class)->byFileId($fileId);
		$this->assertNotNull($stored, 'the form is in the database');
		return Server::get(OutboxStore::class)->entries($stored->id);
	}

	/** @return list<string> */
	private function responseIds(int $fileId): array {
		return array_column(Server::get(FormRepository::class)->load($fileId)['responses'], 'id');
	}

	/** When the form's job runs next, as the job list has it. */
	private function nextRun(int $fileId): ?int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('argument', 'last_checked')->from('jobs')
			->where($qb->expr()->eq('class', $qb->createNamedParameter(SideEffectsJob::class)));
		$result = $qb->executeQuery();
		$next = null;
		while ($row = $result->fetch()) {
			if ((json_decode((string)$row['argument'], true)['fileId'] ?? null) === $fileId) {
				$next = (int)$row['last_checked'];
			}
		}
		$result->closeCursor();
		return $next;
	}

	private function notificationsFor(string $uid, string $responseId): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('notification_id')->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('formvox')))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($responseId)));
		$result = $qb->executeQuery();
		$count = count($result->fetchAll());
		$result->closeCursor();
		return $count;
	}

	/**
	 * The browser sends a submission again when its answer got lost or the form was busy: stored once, set off once,
	 * and the second gets the first's answer, also where only one response is allowed (7a, 3a-5).
	 */
	public function testASubmissionSentTwiceIsStoredAndAnnouncedOnce(): void {
		$file = $this->writeFormFile($this->userFolder, 'Sent Twice ' . uniqid(), [
			'settings' => ['allow_multiple' => false, 'anonymous' => false, 'public_token' => 'tok'],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
		]);
		$fileId = $file->getId();
		$repository = Server::get(FormRepository::class);
		$responses = Server::get(ResponseService::class);

		$first = $responses->submitAuthenticated($fileId, $repository->loadPublic($fileId), ['q1' => 'Bob'], 'formvox-int-respondent', 'Bob', '0f8b6c1e-aaaa-4bbb-8ccc-123456789abc');
		$again = $responses->submitAuthenticated($fileId, $repository->loadPublic($fileId), ['q1' => 'Bob'], 'formvox-int-respondent', 'Bob', '0f8b6c1e-aaaa-4bbb-8ccc-123456789abc');

		$this->assertSame([$first['id'], $first['submitted_at']], [$again['id'], $again['submitted_at']]);
		$this->assertSame([$first['id']], $this->responseIds($fileId));
		$this->assertCount(1, $this->outbox($fileId));

		// A new fill by the same person is still their second answer, which this form does not take.
		$this->expectException(\OCA\FormVox\Service\ResponseRefusedException::class);
		$responses->submitAuthenticated($fileId, $repository->loadPublic($fileId), ['q1' => 'Bob'], 'formvox-int-respondent', 'Bob', '1f8b6c1e-aaaa-4bbb-8ccc-123456789abc');
	}

	/**
	 * A webhook whose receiver cannot be reached, on a real Nextcloud: the rest goes out, the webhook waits five minutes
	 * in the form's outbox, and the job is asked for again; the response itself is safe (review 3a-4).
	 */
	public function testAWebhookThatCannotBeReachedWaitsInTheOutbox(): void {
		$file = $this->writeFormFile($this->userFolder, 'Unreachable ' . uniqid(), [
			'settings' => ['allow_multiple' => true, 'anonymous' => false, 'public_token' => 'tok', 'notify_owner' => false,
				'webhooks' => [['id' => 'wh_down', 'url' => 'http://127.0.0.1:9/hook', 'secret' => 'whsec', 'created_by' => $this->ownerUid, 'enabled' => true]]],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
		]);
		$fileId = $file->getId();
		$jobs = Server::get(IJobList::class);
		$response = Server::get(ResponseService::class)->submitAuthenticated($fileId, Server::get(FormRepository::class)->loadPublic($fileId), ['q1' => 'Bob'], 'formvox-int-respondent', 'Bob');

		$job = Server::get(SideEffectsJob::class);
		$job->setArgument(['fileId' => $fileId]);
		$job->start($jobs);

		$this->assertSame([$response['id']], $this->responseIds($fileId));
		$entry = $this->outbox($fileId)[0];
		$this->assertSame([['webhook:wh_down'], 1], [$entry['todo'], $entry['attempts']]);
		$this->assertGreaterThan(time() + 200, $entry['notBefore']);
		$this->assertLessThanOrEqual($entry['notBefore'], $this->nextRun($fileId), 'asked for again when it is due, before the backstop');
	}

	/**
	 * What a receiver gets for a real submission: the event id under both names, the form by its id, which answers
	 * applied (the question a condition hid and the one on the page routing skipped did not), and that the PDF it
	 * names is stored, with the moment the kept file was written.
	 */
	public function testAReceiverIsToldWhichAnswersAppliedAndThatThePdfIsStored(): void {
		$title = 'Applicable ' . uniqid();
		$file = $this->writeFormFile($this->userFolder, $title, [
			'settings' => ['allow_multiple' => true, 'anonymous' => false, 'public_token' => 'tok', 'notify_owner' => false, 'keepResponsePdf' => true,
				'webhooks' => [['id' => 'wh_in', 'url' => 'http://receiver.example/hook', 'secret' => 'whsec', 'created_by' => $this->ownerUid, 'enabled' => true]]],
			'questions' => [
				['id' => 'q1', 'type' => 'choice', 'question' => 'Member?', 'options' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]],
				['id' => 'q2', 'type' => 'text', 'question' => 'Member number', 'showIf' => ['questionId' => 'q1', 'operator' => 'equals', 'value' => 'yes']],
				['id' => 'q3', 'type' => 'text', 'question' => 'Which benefits do you use?'],
				['id' => 'q4', 'type' => 'text', 'question' => 'Remarks'],
			],
			'pages' => [
				['id' => 'p1', 'questions' => ['q1', 'q2'], 'routing' => [['questionId' => 'q1', 'operator' => 'equals', 'value' => 'no', 'targetPageId' => 'p3']]],
				['id' => 'p2', 'questions' => ['q3']],
				['id' => 'p3', 'questions' => ['q4']],
			],
		]);
		$fileId = $file->getId();
		$response = Server::get(ResponseService::class)->submitAuthenticated($fileId, Server::get(FormRepository::class)->loadPublic($fileId), ['q1' => 'no', 'q4' => 'None'], 'formvox-int-respondent', 'Bob');

		$received = $this->runJobWithReceiver($fileId);

		$this->assertCount(1, $received, 'the receiver got the submission once');
		$body = $received[0]['body'];
		$headers = $received[0]['headers'];
		$this->assertTrue(Server::get(WebhookService::class)->verifySignature($body, $headers['X-FormVox-Signature'], 'whsec', (int)$headers['X-FormVox-Timestamp']));
		$payload = json_decode($body, true);
		$this->assertSame($response['id'] . ':response.created', $payload['event_id']);
		$this->assertSame([$payload['event_id'], $payload['event_id']], [$payload['id'], $headers['X-FormVox-Event-Id']]);
		$this->assertSame(['id' => $fileId, 'fileId' => $fileId, 'title' => $title], $payload['form']);
		$stored = Server::get(FormRepository::class)->load($fileId)['responses'][0];
		$this->assertSame($stored['answers'], $payload['data']['answers'], 'answers as stored');
		$this->assertSame(['q1' => 'no', 'q4' => 'None'], $payload['data']['applicable_answers']);
		$this->assertSame(['q2', 'q3'], $payload['data']['not_applicable']);
		$kept = Server::get(ResponsePdf::class)->stored($file, $stored);
		$this->assertNotNull($kept, 'the PDF the webhook names is kept');
		$this->assertSame('stored', $payload['pdf']['status']);
		$this->assertSame($kept->getMTime(), strtotime($payload['pdf']['generated_at']), 'when the kept file was written');
		$this->assertSame(hash('sha256', $kept->getContent()), $payload['pdf']['sha256']);
		$this->assertStringEndsWith('/forms/' . $fileId . '/responses/' . $response['id'] . '/pdf', $payload['pdf']['url']);
	}

	/**
	 * Run the form's job with a receiver that keeps what it is sent. The job is built in a container of its own, so the
	 * webhooks it sends go to this receiver and nothing else in the suite is touched.
	 *
	 * @return list<array{body: string, headers: array<string, string>}>
	 */
	private function runJobWithReceiver(int $fileId): array {
		$received = [];
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options) use (&$received) {
			$received[] = ['body' => $options['body'], 'headers' => $options['headers']];
			return $this->createMock(IResponse::class);
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$own = \OC::$server->getRegisteredAppContainer(Application::APP_ID);
		try {
			$container = new DIContainer(Application::APP_ID);
			$container->registerService(IClientService::class, fn () => $clients);
			/** @var SideEffectsJob $job */
			$job = $container->get(SideEffectsJob::class);
			$job->setArgument(['fileId' => $fileId]);
			$job->start(Server::get(IJobList::class));
		} finally {
			\OC::$server->registerAppContainer(Application::APP_ID, $own);
		}
		return $received;
	}

	/** Two tries of one submission reach the lock: the second finds the first there and stores nothing (review 3a-5). */
	public function testASecondTryUnderTheLockFindsTheFirst(): void {
		$file = $this->writeFormFile($this->userFolder, 'Under Lock ' . uniqid(), [
			'settings' => ['allow_multiple' => true, 'anonymous' => true, 'public_token' => 'tok'],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
		]);
		$persistence = Server::get(\OCA\FormVox\Service\ResponsePersistenceService::class);
		$submission = \OCA\FormVox\Service\SubmissionIds::of('0f8b6c1e-aaaa-4bbb-8ccc-123456789abc', ['q1' => 'Bob']);
		$response = ['submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => ['q1' => 'Bob']];

		$first = $persistence->appendResponsePublic($file->getId(), ['id' => 'first'] + $response, [], '', $submission);
		$second = $persistence->appendResponsePublic($file->getId(), ['id' => 'second'] + $response, [], '', $submission);

		$this->assertSame(['first', 'first'], [$first['id'], $second['id']]);
		$this->assertSame(['first'], $this->responseIds($file->getId()));
	}
}
