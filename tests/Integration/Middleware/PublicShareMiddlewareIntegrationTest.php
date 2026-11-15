<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Middleware;

use OC\AppFramework\Bootstrap\Coordinator;
use OC\AppFramework\Http\Request as NcRequest;
use OCA\FederatedFileSharing\Controller\MountPublicLinkController;
use OCA\Files_Sharing\Controller\PublicPreviewController;
use OCA\Files_Sharing\Controller\ShareController;
use OCA\Files_Sharing\Controller\ShareInfoController;
use OCA\FormVox\Controller\PublicController;
use OCA\FormVox\Exception\HiddenShareItemException;
use OCA\FormVox\Middleware\PublicShareMiddleware;
use OCA\FormVox\Security\HiddenNodes;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IRequestId;
use OCP\L10N\IFactory;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * Behind a public link, Nextcloud's own pages outside WebDAV answer an item in a form's own folders as a missing one.
 * The controllers are Nextcloud's own classes, so a rename in a later Nextcloud fails here.
 *
 * @group DB
 */
class PublicShareMiddlewareIntegrationTest extends IntegrationTestCase {
	private Folder $shared;
	private string $token;

	protected function setUp(): void {
		parent::setUp();
		$this->shared = $this->userFolder->newFolder('Shared-' . bin2hex(random_bytes(4)));
		$this->shared->newFolder('.formvox-pdfs-7')->newFile('r1.pdf', '%PDF answers');
		$this->shared->newFile('notes.txt', 'visible');
		$this->token = $this->link($this->shared);
	}

	protected function tearDown(): void {
		try {
			$this->shared->delete();
		} catch (\Throwable) {
		}
		parent::tearDown();
	}

	public function testItRunsForEveryAppsControllers(): void {
		$global = [];
		foreach (Server::get(Coordinator::class)->getRegistrationContext()->getMiddlewareRegistrations() as $registration) {
			if ($registration->getService() === PublicShareMiddleware::class) {
				$global[] = $registration->isGlobal();
			}
		}

		$this->assertSame([true], $global);
	}

	/**
	 * @return array<string, array{class-string<Controller>, array<string, mixed>, class-string<Response>, int}>
	 */
	public static function hiddenRequests(): array {
		return [
			'share page' => [ShareController::class, ['path' => '/.formvox-pdfs-7'], NotFoundResponse::class, 404],
			'download, one name' => [ShareController::class, ['files' => '.formvox-pdfs-7'], NotFoundResponse::class, 404],
			'download, a list' => [ShareController::class, ['files' => '["notes.txt",".formvox-pdfs-7"]'], NotFoundResponse::class, 404],
			'download, a folder path' => [ShareController::class, ['path' => '/.formvox-pdfs-7', 'files' => 'r1.pdf'], NotFoundResponse::class, 404],
			'preview' => [PublicPreviewController::class, ['file' => '/.formvox-pdfs-7/r1.pdf'], DataResponse::class, 404],
			'preview, a NUL byte Nextcloud drops' => [PublicPreviewController::class, ['file' => "/.formv\0ox-pdfs-7/r1.pdf"], DataResponse::class, 404],
			'preview, a backslash Nextcloud reads as a slash' => [PublicPreviewController::class, ['file' => '.\\.formvox-pdfs-7\\r1.pdf'], DataResponse::class, 404],
			'share info' => [ShareInfoController::class, ['dir' => '/.formvox-pdfs-7'], JSONResponse::class, 404],
		];
	}

	/**
	 * @dataProvider hiddenRequests
	 * @param class-string<Controller> $class
	 * @param array<string, mixed> $params
	 * @param class-string<Response> $answer
	 */
	public function testAPathIntoThemIsAnsweredAsMissing(string $class, array $params, string $answer, int $status): void {
		$response = $this->refusal($class, $params);

		$this->assertInstanceOf($answer, $response);
		$this->assertSame($status, $response->getStatus());
		if ($response instanceof DataResponse || $response instanceof JSONResponse) {
			$this->assertSame([], $response->getData(), 'the same body as for a missing item');
		}
	}

	public function testVisiblePathsAndUnknownLinksPassOn(): void {
		foreach ([
			[ShareController::class, ['path' => '/', 'files' => '["notes.txt"]']],
			[PublicPreviewController::class, ['file' => 'notes.txt']],
			[ShareInfoController::class, ['dir' => '/']],
			[PublicPreviewController::class, ['token' => 'no-such-link', 'file' => 'notes.txt']],
		] as [$class, $params]) {
			$this->assertNull($this->refusal($class, $params), $class);
		}
	}

	public function testOtherAppsAreLeftAlone(): void {
		$this->assertNull($this->refusal(PublicController::class, ['file' => '.formvox-pdfs-7/r1.pdf']));
	}

	/**
	 * A link made before its folder ended up in a form's folder finds nothing on any of these pages.
	 */
	public function testALinkWhoseRootLiesInThemFindsNothing(): void {
		$inside = $this->shared->newFolder('later-hidden');
		$token = $this->link($inside);
		$inside->move($this->shared->getPath() . '/.formvox-uploads-7');

		foreach ([
			[ShareController::class, NotFoundResponse::class, 404],
			[PublicPreviewController::class, DataResponse::class, 404],
			[ShareInfoController::class, JSONResponse::class, 404],
			[MountPublicLinkController::class, JSONResponse::class, 400],
		] as [$class, $answer, $status]) {
			$response = $this->refusal($class, ['token' => $token, 't' => $token]);

			$this->assertInstanceOf($answer, $response, $class);
			$this->assertSame($status, $response->getStatus(), $class);
		}
		$mount = $this->refusal(MountPublicLinkController::class, ['token' => $token]);
		$this->assertInstanceOf(JSONResponse::class, $mount);
		$this->assertSame(['message' => Server::get(IFactory::class)->get('lib')->t('The requested share does not exist anymore')], $mount->getData());
	}

	public function testShareInfoLeavesThemOutOfItsListing(): void {
		$middleware = $this->middleware(['t' => $this->token]);
		$info = new JSONResponse(['name' => 'Shared', 'children' => [
			['name' => 'notes.txt'],
			['name' => '.formvox-pdfs-7', 'children' => [['name' => 'r1.pdf']]],
			['name' => 'Sub', 'children' => [['name' => '.FORMVOX-uploads-3'], ['name' => 'a.txt']]],
		]]);

		$response = $middleware->afterController($this->controller(ShareInfoController::class), 'info', $info);

		$this->assertSame(['name' => 'Shared', 'children' => [
			['name' => 'notes.txt'],
			['name' => 'Sub', 'children' => [['name' => 'a.txt']]],
		]], $response->getData());
	}

	/**
	 * @param class-string<Controller> $class
	 * @param array<string, mixed> $params
	 */
	private function refusal(string $class, array $params): ?Response {
		$params += ['token' => $this->token, 't' => $this->token];
		$middleware = $this->middleware($params);
		$controller = $this->controller($class);
		try {
			$middleware->beforeController($controller, 'any');
		} catch (HiddenShareItemException $e) {
			return $middleware->afterException($controller, 'any', $e);
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function middleware(array $params): PublicShareMiddleware {
		$request = new NcRequest(['get' => $params], Server::get(IRequestId::class), Server::get(IConfig::class));
		return new PublicShareMiddleware($request, Server::get(IShareManager::class), Server::get(HiddenNodes::class), Server::get(IFactory::class));
	}

	/**
	 * @param class-string<Controller> $class
	 */
	private function controller(string $class): Controller {
		return $this->getMockBuilder($class)->disableOriginalConstructor()->getMock();
	}

	private function link(\OCP\Files\Node $node): string {
		$shares = Server::get(IShareManager::class);
		$share = $shares->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_LINK)
			->setSharedBy($this->ownerUid)
			->setShareOwner($this->ownerUid)
			->setPermissions(Constants::PERMISSION_READ);
		return $shares->createShare($share)->getToken();
	}
}
