<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\InvalidAnswerException;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ResponseRefusedException;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\ResponseStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Server;

/**
 * Against a real database: a new form keeps its responses in the database, under the same rules as before.
 *
 * @group DB
 */
class StoredResponsesIntegrationTest extends IntegrationTestCase {
	private FormRepository $repository;
	private ResponseService $responses;
	private int $visitor = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = Server::get(FormRepository::class);
		$this->responses = Server::get(ResponseService::class);
	}

	/** @param array<string, mixed> $settings */
	private function form(array $settings = [], array $questions = []): int {
		$fileId = (int)$this->repository->create('Stored ' . uniqid())['fileId'];
		$this->repository->update($fileId, [
			'questions' => $questions ?: [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'settings' => $settings + ['anonymous' => true, 'allow_multiple' => true],
		]);
		return $fileId;
	}

	/** A respondent from their own address, unless $same names an earlier one. */
	private function submit(int $fileId, array $answers, ?int $same = null, ?string $submissionId = null): array {
		$who = $same ?? ++$this->visitor;
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('192.0.2.' . $who);
		$request->method('getHeader')->willReturn('IT browser');
		return $this->responses->submitAnonymousWithForm($fileId, $this->repository->loadPublic($fileId), $answers, $request, null, $submissionId);
	}

	private function stored(int $fileId): int {
		return $this->repository->loadPublic($fileId)['_index']['response_count'];
	}

	public function testAResponseGoesToTheDatabaseAndNotIntoTheFile(): void {
		$fileId = $this->form();

		$response = $this->submit($fileId, ['q' => 'hello']);

		$raw = $this->readFormFromStorage(Server::get(FormFileLocator::class)->getFileById($fileId));
		$this->assertSame([], $raw['responses'] ?? []);
		$this->assertSame(0, $raw['_index']['response_count'] ?? 0);
		$this->assertSame([$response['id']], array_column($this->repository->load($fileId)['responses'], 'id'));
		$this->assertSame(1, $this->stored($fileId));
		$listed = array_values(array_filter($this->repository->listForms(), fn (array $f) => $f['fileId'] === $fileId));
		$this->assertSame(1, $listed[0]['responseCount']);
	}

	public function testTheLimitHoldsExactly(): void {
		$fileId = $this->form(['max_responses' => 2]);
		$this->submit($fileId, ['q' => 'a']);
		$this->submit($fileId, ['q' => 'b']);

		try {
			$this->submit($fileId, ['q' => 'c']);
			$this->fail('a third response on a form that takes two');
		} catch (ResponseRefusedException $e) {
			$this->assertSame(2, $this->stored($fileId));
		}
	}

	public function testOnePersonAnswersOnceWhereOnlyOnceIsAllowed(): void {
		$fileId = $this->form(['allow_multiple' => false]);
		$this->submit($fileId, ['q' => 'first'], 1);

		try {
			$this->submit($fileId, ['q' => 'again'], 1);
			$this->fail('the same respondent answered twice');
		} catch (ResponseRefusedException $e) {
			$this->assertSame(1, $this->stored($fileId));
		}
		$this->submit($fileId, ['q' => 'someone else'], 2);
		$this->assertSame(2, $this->stored($fileId));
	}

	public function testAnOptionsCapacityCountsOnlyItsOwnQuestion(): void {
		$fileId = $this->form([], [
			['id' => 'slot', 'type' => 'choice', 'question' => 'Slot', 'options' => [['id' => 'opt1', 'label' => 'Morning', 'capacity' => 1], ['id' => 'opt2', 'label' => 'Noon']]],
			['id' => 'meal', 'type' => 'choice', 'question' => 'Meal', 'options' => [['id' => 'opt1', 'label' => 'Soup'], ['id' => 'opt2', 'label' => 'Salad']]],
		]);
		$this->submit($fileId, ['slot' => 'opt1', 'meal' => 'opt1']);
		$this->submit($fileId, ['slot' => 'opt2', 'meal' => 'opt1']);

		try {
			$this->submit($fileId, ['slot' => 'opt1', 'meal' => 'opt2']);
			$this->fail('a full option took another response');
		} catch (InvalidAnswerException $e) {
			$this->assertSame('slot', $e->questionId);
		}
		$counts = $this->repository->loadPublic($fileId)['_index']['answer_counts'];
		$this->assertSame(['opt1' => 1, 'opt2' => 1], $counts['slot']);
		$this->assertSame(['opt1' => 2], $counts['meal']);
	}

	public function testTheSameFillIsStoredOnce(): void {
		$fileId = $this->form();

		$first = $this->submit($fileId, ['q' => 'x'], 1, 'fill-0123456789abcdef');
		$again = $this->submit($fileId, ['q' => 'x'], 1, 'fill-0123456789abcdef');

		$this->assertSame($first['id'], $again['id']);
		$this->assertSame(1, $this->stored($fileId));
	}

	/** A retry whose answer got lost is answered as stored, also when the form filled up with it. */
	public function testTheSameFillIsAnsweredAsStoredWhenItFilledTheForm(): void {
		$fileId = $this->form(['max_responses' => 1]);

		$first = $this->submit($fileId, ['q' => 'x'], 1, 'fill-fedcba9876543210');
		$again = $this->submit($fileId, ['q' => 'x'], 1, 'fill-fedcba9876543210');

		$this->assertSame($first['id'], $again['id']);
		$this->assertSame(1, $this->stored($fileId));
	}

	public function testChangingAndRemovingAResponseMoveTheCounts(): void {
		$fileId = $this->form([], [
			['id' => 'slot', 'type' => 'choice', 'question' => 'Slot', 'options' => [['id' => 'a', 'label' => 'A', 'capacity' => 1], ['id' => 'b', 'label' => 'B']]],
		]);
		$response = $this->submit($fileId, ['slot' => 'a']);
		$file = Server::get(FormFileLocator::class)->getFileById($fileId);
		$persistence = Server::get(ResponsePersistenceService::class);

		$persistence->update($file, $response['id'], fn (array $r) => ['answers' => ['slot' => 'b']] + $r);
		$this->assertSame(['b' => 1], $this->repository->loadPublic($fileId)['_index']['answer_counts']['slot']);
		$this->submit($fileId, ['slot' => 'a']);

		$persistence->delete($file, $response['id']);
		$public = $this->repository->loadPublic($fileId)['_index'];
		$this->assertSame(['a' => 1], $public['answer_counts']['slot']);
		$this->assertSame(1, $public['response_count']);
	}

	public function testDeletingAllEmptiesTheForm(): void {
		$fileId = $this->form(['allow_multiple' => false]);
		$this->submit($fileId, ['q' => 'a'], 1);
		$this->submit($fileId, ['q' => 'b'], 2);

		Server::get(ResponsePersistenceService::class)->deleteAll(Server::get(FormFileLocator::class)->getFileById($fileId));

		$this->assertSame(0, $this->stored($fileId));
		$this->assertSame([], $this->repository->load($fileId)['responses']);
		// Who answered is forgotten with the answers.
		$this->submit($fileId, ['q' => 'a again'], 1);
		$this->assertSame(1, $this->stored($fileId));
	}

	public function testAWebhookWaitsInTheOutbox(): void {
		$fileId = $this->form();
		$this->repository->mutateSettings($fileId, fn (array $settings) => ['webhooks' => [['id' => 'w1', 'url' => 'https://example.com/hook', 'secret' => 'whsec_test', 'events' => ['response.created'], 'enabled' => true, 'created_by' => $this->ownerUid]]] + $settings);

		$response = $this->submit($fileId, ['q' => 'x']);

		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('rid', 'event', 'todo')->from(ResponseStore::OUTBOX)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter(Server::get(FormStore::class)->byFileId($fileId)->id, IQueryBuilder::PARAM_INT)));
		$rows = $qb->executeQuery()->fetchAll();
		$this->assertCount(1, $rows);
		$this->assertSame($response['id'], $rows[0]['rid']);
		$this->assertSame('response.created', $rows[0]['event']);
		$this->assertContains('webhooks', explode(',', $rows[0]['todo']));
	}

	public function testCountingAgainMatchesTheResponses(): void {
		$fileId = $this->form([], [
			['id' => 'slot', 'type' => 'choice', 'question' => 'Slot', 'options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']]],
		]);
		$this->submit($fileId, ['slot' => 'a']);
		$this->submit($fileId, ['slot' => 'a']);
		$before = $this->repository->loadPublic($fileId)['_index'];

		Server::get(ResponsePersistenceService::class)->rebuildIndex($fileId);

		$after = $this->repository->loadPublic($fileId)['_index'];
		$this->assertSame($before['answer_counts'], $after['answer_counts']);
		$this->assertSame(2, $after['response_count']);
	}

	/**
	 * An upload folder two responses name (a fill sent again after a lost answer) is each one's: removing or changing
	 * the first does not make it nobody's, so the clean-up and a removal leave the other's files alone (H5).
	 */
	public function testAnUploadFolderTwoResponsesNameStaysNamedUntilBothLetGo(): void {
		$group = '5f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f';
		$fileId = $this->form([], [['id' => 'cv', 'type' => 'file', 'question' => 'CV']]);
		$file = Server::get(FormFileLocator::class)->getFileById($fileId);
		$stored = Server::get(FormStore::class)->byFileId($fileId);
		$this->assertNotNull($stored);
		$store = Server::get(ResponseStore::class);
		$persistence = Server::get(ResponsePersistenceService::class);
		$names = fn (string $id, string $filename) => ['id' => $id, 'submitted_at' => date('c'), 'answers' => ['cv' => ['responseId' => $group, 'filename' => $filename]]];
		$persistence->append($file, $names('first', 'cv.pdf'));
		$persistence->append($file, $names('second', 'cv.pdf'));

		$this->assertSame(['second'], array_column($store->namingUploads($stored->id, [$group], 'first'), 'id'));
		$this->assertSame([], $store->namingUploads($stored->id, ['6f0c1d2e-3a4b-4c5d-8e6f-7a8b9c0d1e2f']));

		$persistence->update($file, 'first', fn (array $r) => ['answers' => []] + $r);
		$this->assertSame(['second'], array_column($store->namingUploads($stored->id, [$group]), 'id'), 'let go by a change');
		$this->assertArrayHasKey($group, $store->uploadGroups($stored->id));

		$persistence->delete($file, 'second');
		$this->assertSame([], $store->namingUploads($stored->id, [$group]));
		$this->assertArrayNotHasKey($group, $store->uploadGroups($stored->id));
	}
}
