<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Integration\DAV;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FormVox\DAV\StripFormDataPlugin;
use OCA\FormVox\Listener\RegisterDavPluginListener;
use OCA\FormVox\Security\FormSanitizer;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Server;
use Sabre\DAV\Server as SabreServer;
use Sabre\DAV\SimpleCollection;
use Sabre\DAV\SimpleFile;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Test\TestCase;

/**
 * A form fetched over WebDAV carries only its questions — on every entry point Nextcloud has,
 * for versions and trash too, and also when only part of the file is asked for.
 *
 * @group DB
 */
class StripFormDataIntegrationTest extends TestCase {
	private const FORM = [
		'title' => 'Survey',
		'questions' => [['id' => 'q1', 'type' => 'text']],
		'responses' => [['id' => 'r1', 'answers' => ['q1' => 'private answer']]],
		'_index' => ['response_count' => 1],
		'permissions' => ['owner' => 'anna'],
		'settings' => [
			'anonymous' => true,
			'public_token' => 'public-token-value',
			'share_password_hash' => 'enc:v1:pw',
			'api_keys' => [['id' => 'k1', 'hash' => 'enc:v1:key']],
		],
	];

	/**
	 * remote.php/dav and legacy webdav dispatch SabrePluginAddEvent, public links BeforeSabrePubliclyLoadedEvent.
	 */
	public function testNextcloudAddsTheStripPluginOnEveryWebDavEntryPoint(): void {
		foreach ([SabrePluginAddEvent::class, BeforeSabrePubliclyLoadedEvent::class] as $event) {
			$server = new SabreServer(new SimpleCollection('root'));

			Server::get(IEventDispatcher::class)->dispatchTyped(new $event($server));

			$this->assertInstanceOf(StripFormDataPlugin::class, $server->getPlugin(StripFormDataPlugin::NAME), $event);
		}
	}

	public function testTheListenerAddsTheStripPluginOnce(): void {
		$server = new SabreServer(new SimpleCollection('root'));
		$listener = Server::get(RegisterDavPluginListener::class);

		$listener->handle(new SabrePluginAddEvent($server));
		$listener->handle(new BeforeSabrePubliclyLoadedEvent($server));

		$this->assertInstanceOf(StripFormDataPlugin::class, $server->getPlugin(StripFormDataPlugin::NAME));
		$this->assertCount(1, $server->listeners('afterMethod:GET'));
	}

	/**
	 * @return array<string, array{\Sabre\DAV\INode}>
	 */
	public static function formNodes(): array {
		$json = json_encode(self::FORM);
		return [
			'the file itself' => [new SimpleFile('Survey.fvform', $json)],
			'by its mimetype, whatever the name' => [new SimpleFile('Survey', $json, 'application/x-fvform')],
			'a trashed form' => [new SimpleFile('Survey.fvform.d1700000000', $json)],
			'a version of a form' => [new FormVersionNode('1700000000', $json, 'Survey.fvform')],
		];
	}

	/**
	 * @dataProvider formNodes
	 */
	public function testAFormIsSentWithoutResponsesSecretsOrLink(\Sabre\DAV\INode $node): void {
		$response = $this->get($node, ['Range' => 'bytes=0-20']);

		$this->assertSame(200, $response->getStatus(), 'a form is always sent whole');
		$body = $response->getBodyAsString();
		$form = json_decode($body, true);
		$this->assertSame(self::FORM['questions'], $form['questions']);
		$this->assertSame([], $form['responses']);
		$this->assertSame(['anonymous' => true, 'allow_multiple' => false, 'require_login' => false], $form['settings']);
		foreach (['private answer', 'public-token-value', 'enc:v1:', 'anna'] as $hidden) {
			$this->assertStringNotContainsString($hidden, $body);
		}
		$this->assertSame((string)strlen($body), $response->getHeader('Content-Length'));
	}

	/**
	 * Versions and trash only resolve once Nextcloud's auth plugin (beforeMethod:*, priority 10) has run.
	 */
	public function testARangeIsDroppedAlsoForNodesThatNeedAuthenticationToResolve(): void {
		$json = json_encode(self::FORM);
		$root = new AuthGatedCollection('root', [new FormVersionNode('1700000000', $json, 'Survey.fvform')]);
		$server = new SabreServer($root);
		$server->setBaseUri('/');
		$server->on('beforeMethod:*', function () use ($root): void {
			$root->authenticated = true;
		}, 10);
		$server->addPlugin(new StripFormDataPlugin(new FormSanitizer()));
		$server->httpRequest = new Request('GET', '/1700000000', ['Range' => 'bytes=100-']);
		$server->httpResponse = new Response();

		$server->invokeMethod($server->httpRequest, $server->httpResponse, false);

		$this->assertNull($server->httpRequest->getHeader('Range'), 'dropped before Sabre applies it');
		$this->assertSame(200, $server->httpResponse->getStatus());
		$this->assertNull($server->httpResponse->getHeader('Content-Range'));
		$this->assertSame([], json_decode($server->httpResponse->getBodyAsString(), true)['responses']);
	}

	public function testOtherFilesAreUntouched(): void {
		$json = json_encode(self::FORM);

		$response = $this->get(new SimpleFile('notes.json', $json), ['Range' => 'bytes=0-9']);

		$this->assertSame(206, $response->getStatus());
		$this->assertSame(substr($json, 0, 10), $response->getBodyAsString());
	}

	private function get(\Sabre\DAV\INode $node, array $headers): Response {
		$server = new SabreServer(new SimpleCollection('root', [$node]));
		$server->setBaseUri('/');
		$server->addPlugin(new StripFormDataPlugin(new FormSanitizer()));
		// Sabre reads the Range header from the server's own request, as in a real request.
		$server->httpRequest = new Request('GET', '/' . rawurlencode($node->getName()), $headers);
		$server->httpResponse = new Response();
		$server->invokeMethod($server->httpRequest, $server->httpResponse, false);
		return $server->httpResponse;
	}
}

/** Shaped like OCA\DAV\Versions\VersionFile: named after its timestamp, the source file known through getVersion(). */
class FormVersionNode extends SimpleFile {
	public function __construct(
		string $name,
		string $contents,
		private string $sourceName,
	) {
		parent::__construct($name, $contents);
	}

	public function getVersion(): object {
		return new class($this->sourceName) {
			public function __construct(
				private string $name,
			) {
			}

			public function getSourceFileName(): string {
				return $this->name;
			}
		};
	}
}

/** Resolves its children only once the request is authenticated, like Nextcloud's versions and trash roots. */
class AuthGatedCollection extends SimpleCollection {
	public bool $authenticated = false;

	public function getChild($name) {
		if (!$this->authenticated) {
			throw new \Sabre\DAV\Exception\NotAuthenticated('No user');
		}
		return parent::getChild($name);
	}
}
