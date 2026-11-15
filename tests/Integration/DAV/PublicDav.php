<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\DAV;

use OC\AppFramework\Http\Request as NcRequest;
use OC\Files\View;
use OC\Session\Memory;
use OCA\DAV\Connector\LegacyPublicAuth;
use OCA\DAV\Connector\Sabre\PublicAuth;
use OCA\DAV\Connector\Sabre\ServerFactory;
use OCA\DAV\Files\Sharing\FilesDropPlugin;
use OCA\DAV\Files\Sharing\PublicLinkCheckPlugin;
use OCA\DAV\Upload\ChunkingPlugin;
use OCA\DAV\Upload\ChunkingV2Plugin;
use OCA\FormVox\DAV\PublicDavScope;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\Constants;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IRequestId;
use OCP\ITagManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Server;
use OCP\Share\IManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server as SabreServer;
use Sabre\HTTP\Request;
use Sabre\HTTP\ResponseInterface;

/**
 * Nextcloud's two public WebDAV servers, built inside the test as public.php builds them: public.php/dav
 * (apps/dav/appinfo/v2/publicremote.php) and public.php/webdav (apps/dav/appinfo/v1/publicwebdav.php), with the
 * plugins apps add through BeforeSabrePubliclyLoadedEvent. Not their storage wrappers: those would stay for the
 * rest of the test process.
 */
final class PublicDav {
	public const DAV = 'dav';
	public const WEBDAV = 'webdav';

	/** The URI of a path inside the shared item. */
	public static function uri(string $kind, string $token, string $path = ''): string {
		$path = ltrim($path, '/');
		return $kind === self::DAV ? "/public.php/dav/files/$token/$path" : "/public.php/webdav/$path";
	}

	/**
	 * @param array<string, string> $headers
	 */
	public static function request(string $kind, string $token, string $method, string $uri, array $headers = [], ?string $body = null): ResponseInterface {
		if ($kind === self::WEBDAV) {
			$headers['Authorization'] = 'Basic ' . base64_encode($token . ':');
		}
		$vars = ['REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/public.php', 'REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '127.0.0.1'];
		foreach ($headers as $name => $value) {
			$vars['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
		}
		$ncRequest = new NcRequest(['server' => $vars], Server::get(IRequestId::class), Server::get(IConfig::class));

		$session = new Memory();
		$authBackend = $kind === self::DAV
			? new PublicAuth($ncRequest, Server::get(IManager::class), $session, Server::get(IThrottler::class), Server::get(LoggerInterface::class), Server::get(IURLGenerator::class))
			: new LegacyPublicAuth($ncRequest, Server::get(IManager::class), $session, Server::get(IThrottler::class));
		$factory = new ServerFactory(
			Server::get(IConfig::class),
			Server::get(LoggerInterface::class),
			Server::get(IDBConnection::class),
			Server::get(IUserSession::class),
			Server::get(IMountManager::class),
			Server::get(ITagManager::class),
			$ncRequest,
			Server::get(IPreview::class),
			Server::get(IEventDispatcher::class),
			Server::get(IFactory::class)->get('dav'),
		);
		$linkCheck = new PublicLinkCheckPlugin();
		$filesDrop = new FilesDropPlugin();

		$server = $factory->createServer(true, "/public.php/$kind/", $uri, new \Sabre\DAV\Auth\Plugin($authBackend), static function () use ($authBackend, $linkCheck, $filesDrop): View {
			$share = $authBackend->getShare();
			$node = Server::get(IRootFolder::class)->getUserFolder($share->getSharedBy())->getFirstNodeById($share->getNodeId());
			if ($node === null) {
				throw new NotFound();
			}
			$linkCheck->setFileInfo($node);
			if (($share->getPermissions() & Constants::PERMISSION_READ) === 0) {
				$filesDrop->enable();
			}
			$filesDrop->setShare($share);
			return new View($node->getPath());
		});
		$server->addPlugin($linkCheck);
		$server->addPlugin($filesDrop);
		if ($kind === self::DAV) {
			$server->addPlugin(new ChunkingV2Plugin(Server::get(ICacheFactory::class)));
			$server->addPlugin(new ChunkingPlugin());
		}
		Server::get(IEventDispatcher::class)->dispatchTyped(new BeforeSabrePubliclyLoadedEvent($server));

		$server->httpRequest = new Request($method, $uri, $headers, $body);
		$server->sapi = new CapturingSapi();
		CapturingSapi::$sent = null;
		try {
			ob_start();
			$server->start();
			$streamed = (string)ob_get_clean();
			$response = CapturingSapi::$sent ?? $server->httpResponse;
			$body = $response->getBody();
			if (is_callable($body)) {
				ob_start();
				$body();
				$response->setBody((string)ob_get_clean());
			} elseif ($streamed !== '' && $body === null) {
				$response->setBody($streamed);
			}
			return $response;
		} finally {
			\OC_User::setIncognitoMode(false);
			SabreServer::$streamMultiStatus = false;
			Server::get(PublicDavScope::class)->leave();
		}
	}
}
