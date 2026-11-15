<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Controller;

use OC\AppFramework\DependencyInjection\DIContainer;
use OC\AppFramework\Http\Dispatcher;
use OC\AppFramework\Http\Request;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Controller\ResponseController;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponseService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\Server;

/**
 * Requests as the browser sends them, through Nextcloud's own dispatcher and middleware, with the rules the running
 * Nextcloud applies to a controller's parameters: Nextcloud 35 holds any `limit` to 500 unless the method declares its
 * own range. A new version with stricter rules fails here, not on a customer's server.
 *
 * @group DB
 */
class DispatchedRequestsIntegrationTest extends IntegrationTestCase {
	private int $fileId = 0;

	protected function setUp(): void {
		parent::setUp();
		$repository = Server::get(FormRepository::class);
		$this->fileId = (int)$repository->create('Dispatched ' . uniqid())['fileId'];
		$repository->update($this->fileId, [
			'questions' => [['id' => 'q', 'type' => 'text', 'question' => 'Q']],
			'settings' => ['anonymous' => false, 'allow_multiple' => true],
		]);
		foreach (['Anna', 'Bob', 'Cleo'] as $name) {
			Server::get(ResponseService::class)->submitAuthenticated($this->fileId, $repository->loadPublic($this->fileId), ['q' => $name], $this->ownerUid, $name);
		}
	}

	/**
	 * One GET through a fresh app container, so its dispatcher reads this request. A new container registers itself as
	 * the app's, as for a request of its own: the app's own is put back, or later tests run on other instances.
	 */
	private function get(string $controller, string $method, array $params): Response {
		$server = \OC::$server;
		$own = $server->getRegisteredAppContainer(Application::APP_ID);
		try {
			$container = new DIContainer(Application::APP_ID);
			$request = new Request(
				['get' => $params, 'urlParams' => $params, 'method' => 'GET', 'server' => ['REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'HTTP_OCS_APIREQUEST' => 'true']],
				Server::get(IRequestId::class),
				Server::get(IConfig::class),
				Server::get(CsrfTokenManager::class),
			);
			$container->registerService(IRequest::class, fn () => $request);
			$result = $container->get(Dispatcher::class)->dispatch($container->get($controller), $method);
		} finally {
			$server->registerAppContainer(Application::APP_ID, $own);
		}
		$response = end($result);
		$this->assertInstanceOf(Response::class, $response);
		return $response;
	}

	/** The page size the results page asks for, read from the page itself. */
	private static function resultsPage(): int {
		preg_match('/const PAGE = (\d+);/', (string)file_get_contents(__DIR__ . '/../../../src/views/Results.vue'), $match);
		return (int)($match[1] ?? 0);
	}

	public function testTheResultsPageGetsThePagesItAsksFor(): void {
		$this->assertGreaterThan(500, self::resultsPage(), 'above what Nextcloud 35 allows a `limit` by default');
		$this->assertLessThanOrEqual(ResponseController::MAX_PAGE, self::resultsPage());

		$response = $this->get(ResponseController::class, 'getResponses', ['fileId' => $this->fileId, 'limit' => self::resultsPage()]);

		$this->assertSame(200, $response->getStatus());
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(['Anna', 'Bob', 'Cleo'], array_column(array_column($response->getData()['responses'], 'answers'), 'q'));
	}

	public function testALargerPageIsTheRequestsFault(): void {
		$response = $this->get(ResponseController::class, 'getResponses', ['fileId' => $this->fileId, 'limit' => ResponseController::MAX_PAGE + 1]);

		$this->assertSame(400, $response->getStatus());
	}
}
