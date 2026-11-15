<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Store;

use OCA\FormVox\Controller\ExternalApiController;
use OCA\FormVox\Controller\IntegrationController;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\IRequest;
use OCP\Server;

/**
 * Against a real database: what reads a form in the database gets only what it needs, and pages through the rest.
 *
 * @group DB
 */
class StoredReadsIntegrationTest extends IntegrationTestCase {
	private int $fileId = 0;
	/** @var list<string> */
	private array $ids = [];

	protected function setUp(): void {
		parent::setUp();
		$repository = Server::get(FormRepository::class);
		$this->fileId = (int)$repository->create('Stored reads ' . uniqid())['fileId'];
		$repository->update($this->fileId, [
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'settings' => ['anonymous' => true, 'allow_multiple' => true],
		]);
		$this->ids = [];
		for ($i = 1; $i <= 5; $i++) {
			$request = $this->createMock(IRequest::class);
			$request->method('getRemoteAddress')->willReturn('192.0.2.' . $i);
			$request->method('getHeader')->willReturn('IT browser');
			$this->ids[] = Server::get(ResponseService::class)->submitAnonymousWithForm($this->fileId, $repository->loadPublic($this->fileId), ['q' => "answer $i"], $request)['id'];
		}
	}

	public function testTheEditorGetsTheCountsNotTheResponses(): void {
		$form = Server::get(FormRepository::class)->loadForm($this->fileId);

		$this->assertSame([], $form['responses'] ?? [], 'none of the five responses is read');
		$this->assertSame(5, $form['_index']['response_count']);
	}

	public function testTheResultsPageThroughTheResponsesOldestFirst(): void {
		$responses = Server::get(ResponseService::class);

		$first = $responses->getResponsePage($this->fileId, 2);
		$next = $responses->getResponsePage($this->fileId, 2, $first['next']);
		$last = $responses->getResponsePage($this->fileId, 2, $next['next']);

		$this->assertSame($this->ids, array_column([...$first['responses'], ...$next['responses'], ...$last['responses']], 'id'));
		$this->assertNull($last['next'], 'a page shorter than the limit is the last');
		$this->assertSame($this->ids, array_column($responses->getResponses($this->fileId), 'id'), 'without a limit: all, as before');
		$this->assertSame([], $responses->getResponsePage($this->fileId, 2, 'no-such-response')['responses']);
		$this->assertSame([$this->ids[4]], array_column($responses->getResponsePage($this->fileId, 2, $this->ids[3])['responses'], 'id'), 'a response id still starts a page');
	}

	/** Paged by row: the last response of a page removed, or one that does not decode, does not end the pages early. */
	public function testARemovedOrUnreadableResponseDoesNotEndThePagesEarly(): void {
		$responses = Server::get(ResponseService::class);
		$first = $responses->getResponsePage($this->fileId, 2);
		Server::get(\OCA\FormVox\Service\ResponsePersistenceService::class)->delete(Server::get(\OCA\FormVox\Service\FormFileLocator::class)->getFileById($this->fileId), $this->ids[1]);
		$qb = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->update(\OCA\FormVox\Store\ResponseStore::TABLE)->set('data', $qb->createNamedParameter('not json'))
			->where($qb->expr()->eq('rid', $qb->createNamedParameter($this->ids[2])))->executeStatement();

		$next = $responses->getResponsePage($this->fileId, 2, $first['next']);
		$last = $responses->getResponsePage($this->fileId, 2, $next['next']);

		$this->assertSame([$this->ids[3]], array_column($next['responses'], 'id'));
		$this->assertSame([$this->ids[4]], array_column($last['responses'], 'id'));
		$this->assertNull($last['next']);
	}

	public function testTheApiPagesWithACursorAndFindsOneResponseDirectly(): void {
		$key = Server::get(IntegrationController::class)->createApiKey($this->fileId, 'IT ' . uniqid(), ['read_form', 'read_responses'])->getData()['key'];

		$page = $this->api($key, ['limit' => '2'])->getResponses($this->fileId)->getData();
		$this->assertSame(5, $page['count']);
		$this->assertSame(array_slice($this->ids, 0, 2), array_column($page['responses'], 'id'));
		$this->assertNotNull($page['next']);
		$this->assertSame(array_slice($this->ids, 2, 2), array_column($this->api($key, ['limit' => '2', 'after' => $page['next']])->getResponses($this->fileId)->getData()['responses'], 'id'));

		$tail = $this->api($key, ['limit' => '2', 'after' => $this->ids[3]])->getResponses($this->fileId)->getData();
		$this->assertSame([$this->ids[4]], array_column($tail['responses'], 'id'));
		$this->assertNull($tail['next'], 'a page shorter than the limit is the last');

		$all = $this->api($key)->getResponses($this->fileId)->getData();
		$this->assertSame($this->ids, array_column($all['responses'], 'id'), 'without a limit: all, as before');
		$this->assertArrayNotHasKey('next', $all);

		$one = $this->api($key)->getResponse($this->fileId, $this->ids[2])->getData();
		$this->assertSame($this->ids[2], $one['id']);
	}

	public function testTheStatisticsCountTheResponsesInTheDatabase(): void {
		$statistics = Server::get(\OCA\FormVox\Service\StatisticsService::class);
		$before = $statistics->getTotalResponseCount();
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('192.0.2.99');
		$request->method('getHeader')->willReturn('IT browser');
		Server::get(ResponseService::class)->submitAnonymousWithForm($this->fileId, Server::get(FormRepository::class)->loadPublic($this->fileId), ['q' => 'one more'], $request);

		$this->assertSame($before + 1, $statistics->getTotalResponseCount());
	}

	/** Paged by row: a response removed meanwhile, or a row that does not decode, does not end the walk early. */
	public function testEveryResponseIsWalkedAPageAtATime(): void {
		$store = Server::get(\OCA\FormVox\Store\ResponseStore::class);
		$formId = Server::get(\OCA\FormVox\Store\FormStore::class)->byFileId($this->fileId)->id;
		$this->assertSame($this->ids, array_column(iterator_to_array($store->each($formId, 2), false), 'id'));

		$qb = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->update(\OCA\FormVox\Store\ResponseStore::TABLE)->set('data', $qb->createNamedParameter('not json'))
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rid', $qb->createNamedParameter($this->ids[1])))
			->executeStatement();

		$this->assertSame([$this->ids[0], ...array_slice($this->ids, 2)], array_column(iterator_to_array($store->each($formId, 2), false), 'id'));
	}

	public function testTheSummaryAndTheExportsHoldEveryResponse(): void {
		$responses = Server::get(ResponseService::class);

		$summary = $responses->getSummary($this->fileId);
		$this->assertSame(5, $summary['responseCount']);
		$this->assertSame(5, $summary['questions'][0]['uniqueAnswers']);

		$this->assertSame($this->ids, array_column(json_decode($responses->exportJson($this->fileId), true)['responses'], 'id'));
		$csv = $responses->exportCsv($this->fileId);
		$this->assertSame(6, substr_count($csv, "\r\n"), 'a header and five rows');
		$this->assertStringContainsString('answer 5', $csv);
		$xlsx = tempnam(sys_get_temp_dir(), 'fvit_');
		file_put_contents($xlsx, $responses->exportXlsx($this->fileId));
		$zip = new \ZipArchive();
		$zip->open($xlsx);
		$this->assertSame(6, substr_count((string)$zip->getFromName('xl/worksheets/sheet1.xml'), '<row '));
		$zip->close();
		unlink($xlsx);
	}

	/** The external API as a client calls it: no session, the key in the header. */
	private function api(string $key, array $params = []): ExternalApiController {
		Server::get(\OCP\IUserSession::class)->setUser(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name) => $name === 'X-FormVox-API-Key' ? $key : '');
		$request->method('getParam')->willReturnCallback(fn (string $name, $default = null) => $params[$name] ?? $default);
		return new ExternalApiController(
			$request,
			Server::get(FormRepository::class),
			Server::get(ResponseService::class),
			Server::get(\OCA\FormVox\Service\ApiKeyService::class),
			Server::get(\OCA\FormVox\Service\AccountAccess::class),
			Server::get(\OCA\FormVox\Security\FormSanitizer::class),
			Server::get(\OCP\L10N\IFactory::class)->get('formvox'),
			Server::get(\OCA\FormVox\Pdf\ResponsePdf::class),
		);
	}
}
