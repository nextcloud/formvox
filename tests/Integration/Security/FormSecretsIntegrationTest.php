<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\Security;

use OCA\FormVox\Controller\FormController;
use OCA\FormVox\Controller\IntegrationController;
use OCA\FormVox\Controller\PageController;
use OCA\FormVox\Middleware\ExceptionMiddleware;
use OCA\FormVox\Middleware\FormAccessMiddleware;
use OCA\FormVox\Security\FormSecrets;
use OCA\FormVox\Service\ApiKeyService;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\WebhookService;
use OCA\FormVox\Store\FormStore;
use OCA\FormVox\Store\SecretStore;
use OCA\FormVox\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\Constants;
use OCP\Files\File;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Secrets live sealed in the database, never in the form file; a client save cannot overwrite them, and no endpoint hands them out;
 * what else of the link and integrations a user sees follows their Nextcloud rights.
 *
 * @group DB
 */
class FormSecretsIntegrationTest extends IntegrationTestCase {
	private const EDITOR = 'fvsec-editor';
	private const READER = 'fvsec-reader';
	/** Long enough for Nextcloud's password policy app, which a real server usually runs. */
	private const LINK_PASSWORD = 'fv-link-password-27';

	private int $fileId = 0;
	private string $apiKey = '';
	private string $webhookSecret = '';

	protected function setUp(): void {
		parent::setUp();
		$this->fileId = (int)Server::get(FormRepository::class)->create('Secrets IT ' . uniqid())['fileId'];
		$this->share(self::EDITOR, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$this->share(self::READER, Constants::PERMISSION_READ);

		// A link with a password, an API key and a webhook, made the way the app makes them.
		$this->assertSame(Http::STATUS_OK, $this->call(FormController::class, 'update', [$this->fileId, null, null, null, ['public_token' => 'new', 'share_password' => self::LINK_PASSWORD]])->getStatus());
		$key = $this->integrations([])->createApiKey($this->fileId, 'CRM', ['read_form']);
		$this->apiKey = $key->getData()['key'];
		$hook = $this->integrations(['url' => 'https://example.com/hook', 'name' => 'Hook'])->createWebhook($this->fileId);
		$this->webhookSecret = $hook->getData()['secret'];
	}

	public static function tearDownAfterClass(): void {
		foreach ([self::EDITOR, self::READER] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDownAfterClass();
	}

	public function testTheFileHoldsNoSecretsAndTheDatabaseOnlySealedOnes(): void {
		$raw = $this->rawContent();
		$token = Server::get(FormRepository::class)->load($this->fileId)['settings']['public_token'];

		foreach (SecretStore::KEYS as $key) {
			$this->assertArrayNotHasKey($key, json_decode($raw, true)['settings'], $key);
		}
		$rows = $this->secretRows();
		$this->assertSame(['token', 'password', 'apikey', 'webhook'], array_column($rows, 'kind'));
		$this->assertStringStartsWith('enc:v1:', $rows[0]['value']);
		$this->assertStringStartsWith('enc:v1:', $rows[1]['value']);
		$this->assertStringStartsWith('enc:v1:', json_decode($rows[2]['meta'], true)['hash']);
		$this->assertStringStartsWith('enc:v1:', json_decode($rows[3]['meta'], true)['secret']);
		$stored = json_encode($rows);
		foreach ([self::LINK_PASSWORD, $this->apiKey, $this->webhookSecret, '$2y$', $token] as $plain) {
			$this->assertStringNotContainsString($plain, $raw);
			$this->assertStringNotContainsString($plain, $stored);
		}
	}

	public function testASecretWrittenIntoTheFileCountsForNothing(): void {
		$file = $this->formFile();
		$form = json_decode($this->rawContent(), true);
		$form['settings']['api_keys'] = [['id' => 'planted', 'name' => 'x', 'hash' => password_hash('fvx_planted', PASSWORD_BCRYPT), 'permissions' => ['read_responses']]];
		$form['settings']['public_token'] = 'planted-token';
		$file->getStorage()->file_put_contents($file->getInternalPath(), json_encode($form));

		$settings = Server::get(FormRepository::class)->load($this->fileId)['settings'];

		$this->assertNull(Server::get(ApiKeyService::class)->findValidKey('fvx_planted', $settings['api_keys']));
		$this->assertNotNull(Server::get(ApiKeyService::class)->findValidKey($this->apiKey, $settings['api_keys']));
		$this->assertNotSame('planted-token', $settings['public_token']);
	}

	public function testTheApiKeySurvivesAnEditorSaveThatSendsPlaceholders(): void {
		$this->loginAs($this->ownerUid);
		$response = $this->call(FormController::class, 'update', [$this->fileId, null, null, null, [
			'api_keys' => [['id' => 'x', 'hash' => '[hidden]']],
			'webhooks' => [],
			'share_password_hash' => '[hidden]',
			'has_share_password' => false,
		]]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$settings = Server::get(FormRepository::class)->load($this->fileId)['settings'];
		$this->assertNotNull(Server::get(ApiKeyService::class)->findValidKey($this->apiKey, $settings['api_keys']));
		$this->assertCount(1, $settings['webhooks']);
		$this->assertStringStartsWith('enc:v1:', $settings['share_password_hash']);
	}

	/** A form that still keeps its secrets in its file, as before the database. */
	public function testSecretsFromBeforeEncryptionWorkAndAreEncryptedOnTheNextSave(): void {
		$file = $this->formFile();
		$form = Server::get(FormRepository::class)->load($this->fileId);
		$stores = Server::get(FormStore::class);
		$stores->delete($stores->byFileId($this->fileId)->id);
		$form['settings']['api_keys'][0]['hash'] = password_hash('fvx_legacy', PASSWORD_BCRYPT);
		$form['settings']['webhooks'][0]['secret'] = 'legacy-secret';
		$file->getStorage()->file_put_contents($file->getInternalPath(), json_encode($form));

		$settings = Server::get(FormRepository::class)->load($this->fileId)['settings'];
		$this->assertNotNull(Server::get(ApiKeyService::class)->findValidKey('fvx_legacy', $settings['api_keys']));

		$this->call(FormController::class, 'update', [$this->fileId, null, 'any change']);

		$raw = $this->rawContent();
		$this->assertStringNotContainsString('legacy-secret', $raw);
		$this->assertStringNotContainsString('$2y$', $raw);
		$settings = Server::get(FormRepository::class)->load($this->fileId)['settings'];
		$this->assertNotNull(Server::get(ApiKeyService::class)->findValidKey('fvx_legacy', $settings['api_keys']));
	}

	/**
	 * Everyone who can open the form sees all of it; what they may change is decided elsewhere.
	 *
	 * @return array<string, array{string|null}>
	 */
	public static function audiences(): array {
		return [
			'owner (full rights)' => [null],
			'shared with edit (no reshare, no delete)' => [self::EDITOR],
			'shared read-only' => [self::READER],
		];
	}

	/**
	 * @dataProvider audiences
	 */
	public function testEveryoneSeesTheWholeFormButNeverASecret(?string $uid): void {
		$sees = ['public_token', 'has_share_password', 'api_keys', 'webhooks', 'allow_multiple'];
		$doesNotSee = ['share_password_hash', 'share_password'];
		$this->loginAs($uid ?? $this->ownerUid);

		$response = $this->call(FormController::class, 'get', [$this->fileId]);
		$this->call(PageController::class, 'editor', [$this->fileId]);
		$pageState = Server::get(\OC\InitialStateService::class)->getInitialStates()['formvox-form'] ?? null;
		$this->assertNotNull($pageState, 'the editor page provided no form');
		$pageState = is_string($pageState) ? $pageState : json_encode($pageState);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$settings = $response->getData()['form']['settings'];
		foreach ($sees as $key) {
			$this->assertArrayHasKey($key, $settings);
		}
		foreach ($doesNotSee as $key) {
			$this->assertArrayNotHasKey($key, $settings);
		}
		foreach ([json_encode($response->getData()), $pageState] as $output) {
			foreach (['enc:v1:', '$2y$', self::LINK_PASSWORD, $this->apiKey, $this->webhookSecret, 'share_password_hash'] as $secret) {
				$this->assertStringNotContainsString($secret, $output);
			}
		}
	}

	/**
	 * With Nextcloud's "enforce password for links" on (M39).
	 */
	public function testWhereNextcloudRequiresALinkPasswordOneIsGeneratedAndCannotBeRemoved(): void {
		$config = Server::get(IConfig::class);
		$config->setAppValue('core', 'shareapi_enforce_links_password', 'yes');
		try {
			$fileId = (int)Server::get(FormRepository::class)->create('Enforced ' . uniqid())['fileId'];
			$this->fileId = $fileId;

			$created = $this->call(FormController::class, 'update', [$fileId, null, null, null, ['public_token' => 'new']]);
			$this->assertSame(Http::STATUS_OK, $created->getStatus());
			$generated = $created->getData()['generatedSharePassword'] ?? '';
			$this->assertNotSame('', $generated);
			$stored = Server::get(FormRepository::class)->load($fileId)['settings']['share_password_hash'];
			$this->assertTrue(password_verify($generated, Server::get(FormSecrets::class)->open($stored)));

			$removed = $this->call(FormController::class, 'update', [$fileId, null, null, null, ['share_password' => null]]);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $removed->getStatus());

			$expiry = $this->call(FormController::class, 'update', [$fileId, null, null, null, ['share_expires_at' => '2099-01-01T00:00:00Z']]);
			$this->assertSame(Http::STATUS_OK, $expiry->getStatus(), 'other link settings still save');
		} finally {
			$config->deleteAppValue('core', 'shareapi_enforce_links_password');
		}
	}

	/**
	 * A link password that cannot be saved stays what it was: the old one keeps guarding the link, as the share dialog
	 * says it does, instead of the link opening because the save got halfway.
	 */
	public function testAPasswordChangeThatCannotBeWrittenLeavesTheOldPasswordInForce(): void {
		$rows = $this->secretRows();
		$this->owner->setQuota((string)($this->userFolder->getSize() + 16) . ' B');
		// The quota applies from the next filesystem setup on, as in a new request.
		$this->loginAs($this->ownerUid, $this->owner);
		try {
			$response = $this->call(FormController::class, 'update', [$this->fileId, null, null, null, ['share_password' => 'another-link-password-31']]);
			$this->assertSame(Http::STATUS_INSUFFICIENT_STORAGE, $response->getStatus());
		} finally {
			$this->owner->setQuota('none');
			$this->loginAs($this->ownerUid, $this->owner);
		}

		$this->assertSame($rows, $this->secretRows(), 'the secrets are as they were');
		$stored = Server::get(FormRepository::class)->load($this->fileId)['settings']['share_password_hash'];
		$this->assertTrue(password_verify(self::LINK_PASSWORD, Server::get(FormSecrets::class)->open($stored)));
		$this->assertTrue($this->call(FormController::class, 'get', [$this->fileId])->getData()['form']['settings']['has_share_password']);
	}

	/**
	 * Opening the form to the world or dropping its password takes full rights, whichever value an editor sends for it.
	 */
	public function testAnEditorCanNeitherOpenALinkNorDropItsPassword(): void {
		$rows = $this->secretRows();
		$this->loginAs(self::EDITOR);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->call(FormController::class, 'update', [$this->fileId, null, null, null, ['share_password' => null]])->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->call(FormController::class, 'update', [$this->fileId, null, null, null, ['share_password' => '']])->getStatus());
		$this->assertSame($rows, $this->secretRows(), 'the password is still there');

		$this->loginAs($this->ownerUid);
		$this->fileId = (int)Server::get(FormRepository::class)->create('No link ' . uniqid())['fileId'];
		$this->share(self::EDITOR, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$this->loginAs(self::EDITOR);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->call(FormController::class, 'update', [$this->fileId, null, null, null, ['public_token' => '0']])->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->call(FormController::class, 'update', [$this->fileId, 'Renamed by the editor'])->getStatus(), 'editing itself is fine');
		$this->assertSame([], $this->secretRows(), 'no link was made');
	}

	private function integrations(array $params): IntegrationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $params[$key] ?? $default);
		return new IntegrationController(
			$request,
			Server::get(FormRepository::class),
			Server::get(ApiKeyService::class),
			Server::get(WebhookService::class),
			Server::get(IUserSession::class),
			Server::get(FormAccessService::class),
		);
	}

	private function share(string $uid, int $permissions): void {
		$this->ensureUser($uid);
		$shares = Server::get(IShareManager::class);
		$share = $shares->newShare();
		$share->setNode($this->formFile())
			->setShareType(IShare::TYPE_USER)
			->setSharedWith($uid)
			->setSharedBy($this->ownerUid)
			->setShareOwner($this->ownerUid)
			->setPermissions($permissions);
		// Nextcloud accepts a user share on creation; on Nextcloud 32 that listener no longer runs once another test class tore down.
		$shares->acceptShare($shares->createShare($share), $uid);
	}

	private function formFile(): File {
		$file = $this->rootFolder->getUserFolder($this->ownerUid)->getFirstNodeById($this->fileId);
		$this->assertInstanceOf(File::class, $file);
		return $file;
	}

	/** @return list<array{kind: string, value: ?string, meta: ?string}> */
	private function secretRows(): array {
		$db = Server::get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('kind', 'value', 'meta')->from(SecretStore::TABLE)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter(Server::get(FormStore::class)->byFileId($this->fileId)->id)))
			->orderBy('id');
		return $qb->executeQuery()->fetchAll();
	}

	private function rawContent(): string {
		$file = $this->formFile();
		return (string)$file->getStorage()->file_get_contents($file->getInternalPath());
	}

	/** Through FormVox's middleware, as Nextcloud's dispatcher calls it. */
	private function call(string $class, string $method, array $args): Response {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $key === 'fileId' ? (string)$this->fileId : $default);
		$access = new FormAccessMiddleware($request, Server::get(FormAccessService::class));
		$errors = new ExceptionMiddleware($request, Server::get(\OCP\IConfig::class), Server::get(\OCP\L10N\IFactory::class)->get('formvox'), Server::get(LoggerInterface::class));
		/** @var Controller $controller */
		$controller = Server::get($class);
		try {
			$access->beforeController($controller, $method);
			return $controller->$method(...$args);
		} catch (\Exception $e) {
			return $errors->afterException($controller, $method, $e);
		}
	}
}
