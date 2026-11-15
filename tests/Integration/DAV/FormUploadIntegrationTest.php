<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\DAV;

use OC\Files\Filesystem;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\LockPlugin;
use OCA\DAV\Connector\Sabre\ObjectTree;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FormVox\DAV\FormUploadPlugin;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Mount\IMountManager;
use OCP\Server;
use Sabre\DAV\Server as SabreServer;
use Sabre\DAV\SimpleCollection;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * A form put through Files or WebDAV, or written over by any other route, brings its content only: the responses,
 * link, keys, webhooks and creator stay, and content that would break the form is refused or undone
 * (review L1, qual#42, secu#49, test#113; PO 4b).
 *
 * @group DB
 */
class FormUploadIntegrationTest extends IntegrationTestCase {
	private const STORED = [
		'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
		'responses' => [['id' => 'r1', 'answers' => ['q1' => 'Anna']]],
		'_index' => ['response_count' => 1, 'answer_counts' => []],
		'settings' => [
			'anonymous' => true, 'allow_multiple' => false, 'require_login' => false,
			'public_token' => 'stored-token', 'share_password_hash' => 'enc:v1:pw',
			'api_keys' => [['id' => 'k1', 'hash' => 'enc:v1:key', 'created_by' => 'formvox-int-owner']],
		],
	];

	protected function setUp(): void {
		parent::setUp();
		$this->reconnectNodeEvents();
	}

	/** A downloaded form, edited, with what the uploader would like the server to hold. */
	private function upload(array $changes = []): string {
		return json_encode(array_replace([
			'title' => 'Edited over WebDAV',
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Full name'], ['id' => 'q2', 'type' => 'text', 'question' => 'City']],
			'settings' => ['anonymous' => false, 'public_token' => 'planted', 'api_keys' => [['id' => 'k9', 'hash' => 'known', 'created_by' => 'formvox-int-owner']]],
			'permissions' => ['owner' => 'someone-else'],
			'responses' => [],
			'_index' => ['response_count' => 0],
		], $changes));
	}

	/** A PUT through Nextcloud's own files tree, with FormVox's plugins as Nextcloud adds them. */
	private function put(string $name, string $body): Response {
		$view = Filesystem::getView();
		$tree = new ObjectTree();
		$tree->init(new Directory($view, $view->getFileInfo(''), $tree), $view, Server::get(IMountManager::class));
		CapturingSapi::$sent = null;
		$server = new SabreServer($tree, new CapturingSapi());
		$server->setBaseUri('/');
		$server->addPlugin(new LockPlugin());
		Server::get(IEventDispatcher::class)->dispatchTyped(new SabrePluginAddEvent($server));
		$server->httpRequest = new Request('PUT', '/' . rawurlencode($name), ['Content-Length' => (string)strlen($body)], $body);
		$server->httpResponse = new Response();
		$server->start();
		$this->assertInstanceOf(Response::class, CapturingSapi::$sent);
		return CapturingSapi::$sent;
	}

	private function stored(File $file): array {
		return $this->readFormFromStorage($file);
	}

	public function testNextcloudAddsTheUploadPluginOnEveryWebDavEntryPoint(): void {
		foreach ([SabrePluginAddEvent::class, BeforeSabrePubliclyLoadedEvent::class] as $event) {
			$server = new SabreServer(new SimpleCollection('root'));
			Server::get(IEventDispatcher::class)->dispatchTyped(new $event($server));
			$this->assertInstanceOf(FormUploadPlugin::class, $server->getPlugin(FormUploadPlugin::NAME), $event);
		}
	}

	public function testAFormPutOverWebDavKeepsItsResponsesLinkKeysAndCreator(): void {
		$file = $this->writeFormFile($this->userFolder, 'Put over', self::STORED);

		$response = $this->put($file->getName(), $this->upload());

		$this->assertSame(204, $response->getStatus());
		$form = $this->stored($file);
		$this->assertSame('Edited over WebDAV', $form['title']);
		$this->assertSame(['q1', 'q2'], array_column($form['questions'], 'id'));
		$this->assertFalse($form['settings']['anonymous']);
		$this->assertSame($this->ownerUid, $form['permissions']['owner']);
		// Moved into the database first: the file holds the form, the database what it kept.
		$this->assertSame([], $form['responses']);
		$this->assertArrayNotHasKey('public_token', $form['settings']);
		$kept = Server::get(FormRepository::class)->load($file->getId());
		$this->assertSame(['r1'], array_column($kept['responses'], 'id'));
		$this->assertSame(1, $kept['_index']['response_count']);
		$this->assertSame('stored-token', $kept['settings']['public_token']);
		$this->assertSame('enc:v1:pw', $kept['settings']['share_password_hash']);
		$this->assertSame(['k1'], array_column($kept['settings']['api_keys'], 'id'));
		// A client that keeps the ETag sees the file it now is.
		$this->assertSame('"' . $this->userFolder->get($file->getName())->getEtag() . '"', $response->getHeader('ETag'));
	}

	public function testAPutThatWouldBreakTheFormIsRefusedWithTheReasonAndChangesNothing(): void {
		$file = $this->writeFormFile($this->userFolder, 'Put refused', self::STORED);
		$before = $file->getStorage()->file_get_contents($file->getInternalPath());

		$response = $this->put($file->getName(), $this->upload(['questions' => [['id' => 'q2', 'type' => 'scale', 'scaleMin' => 1, 'scaleMax' => 100000000]]]));

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('question q2', (string)$response->getBodyAsString());
		$this->assertSame($before, $file->getStorage()->file_get_contents($file->getInternalPath()));
	}

	/**
	 * A form put over one still in its file, on an account with little room: its responses move into the database first,
	 * so the file asks for no room beyond the form itself.
	 */
	public function testAPutOverAFormOnAFullAccountMovesTheResponsesIn(): void {
		$responses = array_map(fn (int $i) => ['id' => 'r' . $i, 'answers' => ['q1' => str_repeat('x', 100)]], range(1, 200));
		$file = $this->writeFormFile($this->userFolder, 'Put without room', ['responses' => $responses] + self::STORED);
		$upload = $this->upload();
		$this->owner->setQuota((string)($this->userFolder->getSize() + strlen($upload) + 4096) . ' B');
		// The quota applies from the next filesystem setup on, as in a new request.
		$this->loginAs($this->ownerUid, $this->owner);
		try {
			$response = $this->put($file->getName(), $upload);
		} finally {
			$this->owner->setQuota('none');
		}

		$this->assertSame(204, $response->getStatus());
		$this->assertSame('Edited over WebDAV', $this->stored($file)['title']);
		$this->assertSame([], $this->stored($file)['responses'], 'the file holds the form only');
		$this->assertCount(200, Server::get(FormRepository::class)->load($file->getId())['responses']);
	}

	public function testSomethingThatIsNoFormIsNotPutOverAForm(): void {
		$file = $this->writeFormFile($this->userFolder, 'Put text', self::STORED);

		$this->assertSame(400, $this->put($file->getName(), 'just text')->getStatus());
		$this->assertSame(['r1'], array_column($this->stored($file)['responses'], 'id'));
	}

	public function testANewFormPutOverWebDavStartsLikeACopyByWhoeverPutIt(): void {
		$name = 'Uploaded ' . uniqid() . '.fvform';

		$response = $this->put($name, $this->upload(['responses' => [['id' => 'forged']], '_index' => ['response_count' => 1]]));

		$this->assertSame(201, $response->getStatus());
		$form = $this->stored($this->userFolder->get($name));
		$this->assertSame('Edited over WebDAV', $form['title']);
		$this->assertSame([], $form['responses']);
		$this->assertSame($this->ownerUid, $form['permissions']['owner']);
		$this->assertArrayNotHasKey('public_token', $form['settings']);
		$this->assertArrayNotHasKey('api_keys', $form['settings']);
	}

	public function testANewFormThatWouldNotOpenIsRefused(): void {
		$name = 'Broken ' . uniqid() . '.fvform';

		$this->assertSame(400, $this->put($name, $this->upload(['questions' => 'x']))->getStatus());
		$this->assertFalse($this->userFolder->nodeExists($name));
	}

	/** The sync client's bulk upload, a chunked upload or another app: Nextcloud writes, FormIntake puts the data back. */
	public function testAFormWrittenOverOutsideFormVoxGetsItsDataBack(): void {
		$file = $this->writeFormFile($this->userFolder, 'Written over', self::STORED);

		$this->userFolder->get($file->getName())->putContent($this->upload());

		$form = $this->stored($file);
		$this->assertSame('Edited over WebDAV', $form['title']);
		$this->assertSame(['r1'], array_column($form['responses'], 'id'));
		$this->assertSame('stored-token', $form['settings']['public_token']);
		$this->assertSame($this->ownerUid, $form['permissions']['owner']);
	}

	public function testAWriteOutsideFormVoxThatWouldBreakTheFormIsUndone(): void {
		$file = $this->writeFormFile($this->userFolder, 'Written broken', self::STORED);
		$before = $file->getStorage()->file_get_contents($file->getInternalPath());

		$this->userFolder->get($file->getName())->putContent($this->upload(['questions' => 'x']));

		$this->assertSame($before, $file->getStorage()->file_get_contents($file->getInternalPath()));
	}

	public function testANewFormFileWrittenOutsideFormVoxIsTakenIn(): void {
		$name = 'Bulk ' . uniqid() . '.fvform';

		$this->userFolder->newFile($name, $this->upload(['responses' => [['id' => 'forged']]]));

		$form = $this->stored($this->userFolder->get($name));
		$this->assertSame([], $form['responses']);
		$this->assertSame($this->ownerUid, $form['permissions']['owner']);
	}

	/** What a request that ends does: the refusal is no test error, and Nextcloud lets go of the refused write's locks. */
	private function endOfRequest(): void {
		\OC_Hook::$thrownExceptions = [];
		Server::get(\OCP\Lock\ILockingProvider::class)->releaseAll();
	}

	/** A FormVox save in another process holds the form, as a submission's write would. */
	private function heldElsewhere(File $file): array {
		$other = new \OCA\FormVox\Service\FormLock(Server::get(\OCP\ICacheFactory::class), Server::get(\OCP\IDBConnection::class), Server::get(\Psr\Log\LoggerInterface::class));
		return [$other, $other->acquire($file->getId(), microtime(true) + 1)];
	}

	/**
	 * Written over from outside while a FormVox save holds it: refused after a moment, the one way Nextcloud's file
	 * hooks stop a write, and the form stays as it was (7a, 3b-3).
	 */
	public function testAWriteFromOutsideWaitsForAFormVoxSaveOrIsRefused(): void {
		$file = $this->writeFormFile($this->userFolder, 'Held ' . uniqid(), self::STORED);
		$before = $file->getStorage()->file_get_contents($file->getInternalPath());
		[$other, $token] = $this->heldElsewhere($file);

		try {
			$this->userFolder->get($file->getName())->putContent($this->upload());
			$this->fail('An outside write went ahead over a FormVox save');
		} catch (\OCA\FormVox\Service\FormBusyHint) {
			$this->endOfRequest();
		} finally {
			$other->release($file->getId(), $token);
		}
		$this->assertSame($before, $file->getStorage()->file_get_contents($file->getInternalPath()));

		$this->userFolder->get($file->getName())->putContent($this->upload());
		$this->assertSame(['r1'], array_column($this->stored($file)['responses'], 'id'), 'once free, the write goes through and the data comes back');
	}

	/** Moved while a FormVox save holds it: refused, the form stays where it was (3b-3). */
	public function testAMoveWaitsForAFormVoxSaveOrIsRefused(): void {
		$file = $this->writeFormFile($this->userFolder, 'Held Move ' . uniqid(), self::STORED);
		$name = $file->getName();
		[$other, $token] = $this->heldElsewhere($file);

		try {
			$file->move($this->userFolder->getPath() . '/Moved ' . $name);
			$this->fail('A held form was moved');
		} catch (\OCA\FormVox\Service\FormBusyHint) {
			$this->endOfRequest();
		} finally {
			$other->release($file->getId(), $token);
		}
		$this->assertTrue($this->userFolder->nodeExists($name));
	}

	/** Over WebDAV the same move is a 503 with Retry-After, so a client tries again (3b-3). */
	public function testAWebDavMoveOfAHeldFormIsBusy(): void {
		$file = $this->writeFormFile($this->userFolder, 'Held Dav ' . uniqid(), self::STORED);
		[$other, $token] = $this->heldElsewhere($file);
		try {
			$view = Filesystem::getView();
			$tree = new ObjectTree();
			$tree->init(new Directory($view, $view->getFileInfo(''), $tree), $view, Server::get(IMountManager::class));
			CapturingSapi::$sent = null;
			$server = new SabreServer($tree, new CapturingSapi());
			$server->setBaseUri('/');
			$server->addPlugin(new LockPlugin());
			Server::get(IEventDispatcher::class)->dispatchTyped(new SabrePluginAddEvent($server));
			$server->httpRequest = new Request('MOVE', '/' . rawurlencode($file->getName()), ['Destination' => '/' . rawurlencode('Moved ' . $file->getName())]);
			$server->httpResponse = new Response();
			$server->start();
		} finally {
			$other->release($file->getId(), $token);
		}

		$this->assertSame([503, '2'], [CapturingSapi::$sent?->getStatus(), CapturingSapi::$sent?->getHeader('Retry-After')]);
		$this->assertTrue($this->userFolder->nodeExists($file->getName()));
	}

	/** Moved onto another form while a FormVox save holds that one: refused, both stay as they were (review of 3b-3). */
	public function testAMoveOntoAHeldFormIsRefused(): void {
		$moving = $this->writeFormFile($this->userFolder, 'Moving ' . uniqid(), self::STORED);
		$target = $this->writeFormFile($this->userFolder, 'Target ' . uniqid(), self::STORED);
		$this->assertLessThan($target->getId(), $moving->getId(), 'the moving form is held first, so its release is what is checked');
		$before = $target->getStorage()->file_get_contents($target->getInternalPath());
		[$other, $token] = $this->heldElsewhere($target);

		try {
			$moving->move($target->getPath());
			$this->fail('A form was moved onto a held form');
		} catch (\OCA\FormVox\Service\FormBusyHint) {
			$this->endOfRequest();
		} finally {
			$other->release($target->getId(), $token);
		}
		$this->assertSame($before, $target->getStorage()->file_get_contents($target->getInternalPath()));
		$this->assertTrue($this->userFolder->nodeExists($moving->getName()));
		// The moving form, held first, was let go with the refusal (review of 3b-3b).
		[$probe, $free] = $this->heldElsewhere($moving);
		$probe->release($moving->getId(), $free);
	}
}
