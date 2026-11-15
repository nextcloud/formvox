<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\DAV;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FormVox\DAV\PublicFormDataPlugin;
use OCA\FormVox\DAV\StripFormDataPlugin;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\Constants;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Sabre\DAV\Server as SabreServer;
use Sabre\DAV\SimpleCollection;

/**
 * Through a public link, the folders a form keeps next to it do not exist, on both public WebDAV servers.
 *
 * @group DB
 */
class PublicFormDataIntegrationTest extends IntegrationTestCase {
	/** Its own colleague: Nextcloud remembers a deleted user's mounts within one test run. */
	private const COLLEAGUE = 'formvox-int-colleague-dav';

	private Folder $shared;
	private string $hidden;
	private string $formName;

	protected function setUp(): void {
		parent::setUp();
		$this->shared = $this->userFolder->newFolder('Shared-' . bin2hex(random_bytes(4)));
		$form = $this->writeFormFile($this->shared, 'Survey');
		$this->formName = $form->getName();
		$this->hidden = '.formvox-pdfs-' . $form->getId();
		$this->shared->newFolder($this->hidden)->newFile('r1.pdf', '%PDF-1.4 answers');
		$this->shared->newFile('notes.txt', 'visible');
	}

	public static function tearDownAfterClass(): void {
		Server::get(\OCP\IUserManager::class)->get(self::COLLEAGUE)?->delete();
		parent::tearDownAfterClass();
	}

	protected function tearDown(): void {
		$this->loginAs($this->ownerUid, $this->owner);
		try {
			$this->shared->delete();
		} catch (\Throwable) {
		}
		parent::tearDown();
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function servers(): array {
		return ['public.php/dav' => [PublicDav::DAV], 'public.php/webdav' => [PublicDav::WEBDAV]];
	}

	public function testOnlyPublicServersGetThePlugin(): void {
		foreach ([SabrePluginAddEvent::class => false, BeforeSabrePubliclyLoadedEvent::class => true] as $event => $public) {
			$server = new SabreServer(new SimpleCollection('root'));
			Server::get(IEventDispatcher::class)->dispatchTyped(new $event($server));

			$this->assertSame($public, $server->getPlugin(PublicFormDataPlugin::NAME) !== null, $event);
			$this->assertNotNull($server->getPlugin(StripFormDataPlugin::NAME), 'forms are still stripped on ' . $event);
		}
	}

	/**
	 * @dataProvider servers
	 */
	public function testAListingLeavesTheFormsFoldersOut(string $kind): void {
		$token = $this->link($this->shared);
		$this->logout();

		foreach (['1', 'infinity'] as $depth) {
			$response = PublicDav::request($kind, $token, 'PROPFIND', PublicDav::uri($kind, $token), ['Depth' => $depth]);

			$this->assertSame(207, $response->getStatus(), $depth);
			$body = $response->getBodyAsString();
			$this->assertStringContainsString($this->formName, $body, $depth);
			$this->assertStringContainsString('notes.txt', $body, $depth);
			$this->assertStringNotContainsString('.formvox-', $body, $depth);
			$this->assertStringNotContainsString('r1.pdf', $body, $depth);
		}
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function hiddenPaths(): array {
		$cases = [];
		foreach (self::servers() as $name => [$kind]) {
			$cases["$name the folder"] = [$kind, '{hidden}'];
			$cases["$name a file in it"] = [$kind, '{hidden}/r1.pdf'];
			$cases["$name a NUL byte Nextcloud drops"] = [$kind, '{nul}/r1.pdf'];
			$cases["$name a backslash Nextcloud reads as a slash"] = [$kind, '.%5C{hidden}/r1.pdf'];
		}
		return $cases;
	}

	/**
	 * @dataProvider hiddenPaths
	 */
	public function testNothingInThemCanBeReadOrListed(string $kind, string $path): void {
		$token = $this->link($this->shared);
		$this->logout();

		foreach (['GET', 'HEAD', 'PROPFIND'] as $method) {
			$response = PublicDav::request($kind, $token, $method, PublicDav::uri($kind, $token, $this->path($path)), ['Depth' => '0']);

			$this->assertSame(404, $response->getStatus(), $method);
			$this->assertStringNotContainsString('answers', $response->getBodyAsString(), $method);
		}
	}

	/**
	 * @dataProvider servers
	 */
	public function testNothingCanBeWrittenIntoThem(string $kind): void {
		$token = $this->link($this->shared);
		$this->logout();
		$uri = fn (string $path): string => PublicDav::uri($kind, $token, $path);

		$this->assertSame(404, PublicDav::request($kind, $token, 'PUT', $uri($this->hidden . '/planted.pdf'), [], 'x')->getStatus());
		$this->assertSame(404, PublicDav::request($kind, $token, 'MKCOL', $uri('.formvox-uploads-999999'))->getStatus());
		$this->assertSame(404, PublicDav::request($kind, $token, 'DELETE', $uri($this->hidden . '/r1.pdf'))->getStatus());
		$this->assertSame(404, PublicDav::request($kind, $token, 'MOVE', $uri('notes.txt'), ['Destination' => $uri($this->hidden . '/notes.txt')])->getStatus());
		$this->assertSame(404, PublicDav::request($kind, $token, 'COPY', $uri($this->hidden . '/r1.pdf'), ['Destination' => $uri('copied.pdf')])->getStatus());

		$this->loginAs($this->ownerUid, $this->owner);
		$folder = $this->shared->get($this->hidden);
		$this->assertInstanceOf(Folder::class, $folder);
		$this->assertSame(['r1.pdf'], array_map(fn ($node) => $node->getName(), $folder->getDirectoryListing()));
		$this->assertTrue($this->shared->nodeExists('notes.txt'));
		$this->assertFalse($this->shared->nodeExists('copied.pdf'));
		$this->assertFalse($this->shared->nodeExists('.formvox-uploads-999999'));
	}

	public function testAChunkedUploadCannotLandInThem(): void {
		$token = $this->link($this->shared);
		$this->logout();
		$destination = PublicDav::uri(PublicDav::DAV, $token, $this->hidden . '/chunked.pdf');

		$response = PublicDav::request(PublicDav::DAV, $token, 'MKCOL', "/public.php/dav/uploads/$token/upload-1", ['Destination' => $destination]);

		$this->assertSame(404, $response->getStatus());
	}

	/**
	 * A link made before the item ended up in a form's folder.
	 *
	 * @dataProvider servers
	 */
	public function testALinkOnTheirContentsFindsNothing(string $kind): void {
		$inside = $this->shared->newFolder('later-hidden');
		$inside->newFile('upload.jpg', 'respondent photo');
		$token = $this->link($inside);
		$inside->move($this->shared->getPath() . '/.formvox-uploads-424242');
		$this->logout();

		foreach (['', 'upload.jpg'] as $path) {
			$response = PublicDav::request($kind, $token, 'GET', PublicDav::uri($kind, $token, $path));

			$this->assertSame(404, $response->getStatus(), "'$path'");
			$this->assertStringNotContainsString('respondent photo', $response->getBodyAsString());
		}
	}

	/**
	 * A colleague's link on a folder shared with them, renamed in their files, that the owner later moved into a form's
	 * folder: only the owner's path shows where it lies.
	 *
	 * @dataProvider servers
	 */
	public function testALinkOnARenamedShareIsJudgedByTheOwnersPath(string $kind): void {
		$colleague = self::COLLEAGUE;
		$this->ensureUser($colleague);
		$original = $this->shared->newFolder('handed-over');
		$original->newFile('upload.jpg', 'respondent photo');
		$shares = Server::get(IShareManager::class);
		$share = $shares->newShare();
		$share->setNode($original)
			->setShareType(IShare::TYPE_USER)
			->setSharedWith($colleague)
			->setSharedBy($this->ownerUid)
			->setPermissions(Constants::PERMISSION_ALL);
		$shares->createShare($share);

		$this->switchToUser($colleague);
		$received = $this->userFolder->get('handed-over');
		$received->move($this->userFolder->getPath() . '/Renamed');
		// The link comes in a request of its own, as in Files.
		$this->switchToUser($colleague);
		$link = $shares->newShare();
		$link->setNode($this->userFolder->get('Renamed'))
			->setShareType(IShare::TYPE_LINK)
			->setSharedBy($colleague)
			->setPermissions(Constants::PERMISSION_READ);
		$token = $shares->createShare($link)->getToken();

		$this->loginAs($this->ownerUid, $this->owner);
		$original->move($this->shared->getPath() . '/.formvox-uploads-777');
		$this->logout();

		foreach (['', 'upload.jpg'] as $path) {
			$response = PublicDav::request($kind, $token, 'GET', PublicDav::uri($kind, $token, $path));

			$this->assertSame(404, $response->getStatus(), "'$path'");
			$this->assertStringNotContainsString('respondent photo', $response->getBodyAsString());
		}
	}

	/**
	 * @dataProvider servers
	 */
	public function testAZipWithAFormOrItsFoldersIsRefused(string $kind): void {
		$token = $this->link($this->shared);
		$this->logout();

		foreach (['', '?files=' . rawurlencode(json_encode([$this->hidden]))] as $query) {
			$response = PublicDav::request($kind, $token, 'GET', PublicDav::uri($kind, $token) . ($query === '' ? '?accept=zip' : $query . '&accept=zip'));

			$this->assertSame(403, $response->getStatus(), $query);
			$this->assertStringContainsString('Folders with forms cannot be downloaded through a public link.', $response->getBodyAsString());
			$this->assertStringNotContainsString('answers', $response->getBodyAsString());
		}
	}

	private function path(string $template): string {
		return strtr($template, [
			'{hidden}' => $this->hidden,
			'{nul}' => '.formv%00' . substr($this->hidden, 6),
		]);
	}

	private function link(\OCP\Files\Node $node): string {
		$shares = Server::get(IShareManager::class);
		$share = $shares->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_LINK)
			->setSharedBy($this->ownerUid)
			->setShareOwner($this->ownerUid)
			->setPermissions(Constants::PERMISSION_ALL & ~Constants::PERMISSION_SHARE);
		return $shares->createShare($share)->getToken();
	}
}
