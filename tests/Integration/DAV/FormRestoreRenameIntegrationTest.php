<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\DAV;

use OC\Files\Filesystem;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\ObjectTree;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files_Versions\Sabre\RestoreFolder;
use OCA\Files_Versions\Sabre\VersionFile;
use OCA\Files_Versions\Versions\IVersionManager;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Mount\IMountManager;
use OCP\Server;
use Sabre\DAV\ICollection;
use Sabre\DAV\Server as SabreServer;
use Sabre\DAV\SimpleCollection;
use Sabre\DAV\Tree;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * Putting back a version of a form brings its content only; the responses, link, keys and creator stay as they
 * are now (qual#31, PO 5). A file is not renamed or copied into a form over WebDAV, nor a form out of being one,
 * and a file another app renames into a form is taken in as a new one.
 *
 * @group DB
 */
class FormRestoreRenameIntegrationTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->reconnectNodeEvents();
	}

	/** A request through a Sabre server with FormVox's plugins, as Nextcloud adds them. */
	private function dav(Tree|ICollection $root, string $method, string $path, array $headers = []): Response {
		CapturingSapi::$sent = null;
		$server = new SabreServer($root, new CapturingSapi());
		$server->setBaseUri('/');
		Server::get(IEventDispatcher::class)->dispatchTyped(new SabrePluginAddEvent($server));
		$server->httpRequest = new Request($method, $path, $headers);
		$server->httpResponse = new Response();
		$server->start();
		$this->assertInstanceOf(Response::class, CapturingSapi::$sent);
		return CapturingSapi::$sent;
	}

	private function filesTree(): ObjectTree {
		$view = Filesystem::getView();
		$tree = new ObjectTree();
		$tree->init(new Directory($view, $view->getFileInfo(''), $tree), $view, Server::get(IMountManager::class));
		return $tree;
	}

	private function form(string $title, array $overrides = []): File {
		return $this->writeFormFile($this->userFolder, $title . ' ' . uniqid(), $overrides + [
			'settings' => ['allow_multiple' => true, 'anonymous' => true, 'public_token' => 'stored-token'],
			'questions' => [['id' => 'q1', 'type' => 'text', 'question' => 'Name']],
		]);
	}

	private function respond(File $file, string $id): void {
		Server::get(ResponsePersistenceService::class)->append($this->userFolder->getFirstNodeById($file->getId()), [
			'id' => $id, 'submitted_at' => date('c'), 'respondent' => ['type' => 'anonymous'], 'answers' => ['q1' => $id],
		]);
	}

	public function testPuttingBackAVersionBringsItsQuestionsAndKeepsTheResponses(): void {
		if (!Server::get(IAppManager::class)->isEnabledForUser('files_versions')) {
			$this->markTestSkipped('files_versions is not enabled');
		}
		$file = $this->form('Restore');
		$this->respond($file, 'r1');
		$this->userFolder->getFirstNodeById($file->getId())->touch(time() - 60);
		Server::get(FormRepository::class)->update($file->getId(), ['questions' => [
			['id' => 'q1', 'type' => 'text', 'question' => 'Name'],
			['id' => 'q2', 'type' => 'text', 'question' => 'Added later'],
		]]);
		$this->respond($file, 'r2');

		// The version from before q2 was added; it holds only r1.
		$manager = Server::get(IVersionManager::class);
		$node = $this->userFolder->getFirstNodeById($file->getId());
		$old = null;
		foreach ($manager->getVersionsForFile($this->owner, $node) as $version) {
			try {
				$form = json_decode((string)stream_get_contents($manager->read($version)), true);
			} catch (\OCP\Files\NotFoundException) {
				// The list starts with the file as it is now, which is no stored version.
				continue;
			}
			if (is_array($form) && count($form['questions'] ?? []) === 1) {
				$old = $version;
			}
		}
		$this->assertNotNull($old, 'no version from before the change');

		$root = new SimpleCollection('root', [new SimpleCollection('versions', [new VersionFile($old, $manager)]), new RestoreFolder()]);
		$response = $this->dav($root, 'MOVE', '/versions/' . rawurlencode((string)$old->getRevisionId()), ['Destination' => '/restore/target']);

		$this->assertSame(204, $response->getStatus());
		// The responses and the link are kept in the database, the questions in the file.
		$form = Server::get(FormRepository::class)->load($file->getId());
		$this->assertSame(['q1'], array_column($form['questions'], 'id'));
		$this->assertSame(['r1', 'r2'], array_column($form['responses'], 'id'));
		$this->assertSame('stored-token', $form['settings']['public_token']);
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function kindChanges(): array {
		return [
			'a form renamed to a plain file' => ['MOVE', 'form', 'Answers.json'],
			'a file renamed into a form' => ['MOVE', 'file', 'Planted.fvform'],
			'a form copied as a plain file' => ['COPY', 'form', 'Answers copy.json'],
			'a file copied as a form' => ['COPY', 'file', 'Planted copy.fvform'],
		];
	}

	/**
	 * @dataProvider kindChanges
	 */
	public function testAFileDoesNotBecomeAFormNorAFormAPlainFile(string $method, string $what, string $to): void {
		$source = $what === 'form' ? $this->form('Kind')->getName() : 'notes ' . uniqid() . '.json';
		if ($what === 'file') {
			$this->userFolder->newFile($source, json_encode(['title' => 'x', 'responses' => [['id' => 'forged']]]));
		}

		$response = $this->dav($this->filesTree(), $method, '/' . rawurlencode($source), ['Destination' => '/' . rawurlencode($to)]);

		$this->assertSame(403, $response->getStatus());
		$this->assertFalse($this->userFolder->nodeExists($to));
		$this->assertTrue($this->userFolder->nodeExists($source));
	}

	public function testAFormRenamedToAnotherFormNameStaysAsItIs(): void {
		$file = $this->form('Rename', ['responses' => [['id' => 'r1', 'answers' => ['q1' => 'Anna']]]]);
		$to = 'Renamed ' . uniqid() . '.fvform';

		$response = $this->dav($this->filesTree(), 'MOVE', '/' . rawurlencode($file->getName()), ['Destination' => '/' . rawurlencode($to)]);

		$this->assertContains($response->getStatus(), [201, 204]);
		$this->assertSame(['r1'], array_column($this->readFormFromStorage($this->userFolder->get($to))['responses'], 'id'));
	}

	/** Another app renaming a file into a form, where WebDAV is not in the way. */
	public function testAFileAnotherAppRenamesIntoAFormIsTakenInAsANewOne(): void {
		$name = 'notes ' . uniqid() . '.json';
		$this->userFolder->newFile($name, json_encode(['title' => 'Notes', 'responses' => [['id' => 'forged']], 'permissions' => ['owner' => 'someone-else']]));
		$to = 'From notes ' . uniqid() . '.fvform';

		$this->userFolder->get($name)->move($this->userFolder->getPath() . '/' . $to);

		$form = $this->readFormFromStorage($this->userFolder->get($to));
		$this->assertSame([], $form['responses']);
		$this->assertSame($this->ownerUid, $form['permissions']['owner']);
	}
}
