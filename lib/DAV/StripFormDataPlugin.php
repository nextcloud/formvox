<?php

declare(strict_types=1);

namespace OCA\FormVox\DAV;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Security\FormSanitizer;
use Sabre\DAV\IFile;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * A .fvform fetched over WebDAV (files, versions, trash, public links) carries only its questions.
 */
class StripFormDataPlugin extends ServerPlugin {
	public const NAME = 'formvox-strip-data';

	private ?Server $server = null;

	public function __construct(
		private FormSanitizer $sanitizer,
	) {
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		// After authentication (priority 10): versions and trash only resolve for a signed-in user.
		$server->on('beforeMethod:GET', [$this, 'beforeGet'], 100);
		$server->on('afterMethod:GET', [$this, 'afterGet'], 90);
	}

	/**
	 * A partial body cannot be stripped, so a form is always sent whole.
	 */
	public function beforeGet(RequestInterface $request, ResponseInterface $response): void {
		if ($this->isFormDownload($request->getPath())) {
			$request->removeHeader('Range');
			$request->removeHeader('If-Range');
		}
	}

	public function afterGet(RequestInterface $request, ResponseInterface $response): void {
		$status = $response->getStatus();
		if ($status < 200 || $status >= 300 || !$this->isFormDownload($request->getPath())) {
			return;
		}

		$body = $response->getBody();
		if (is_resource($body) && (stream_get_meta_data($body)['seekable'] ?? false)) {
			rewind($body);
		}
		$content = is_resource($body) ? stream_get_contents($body) : (string)$body;
		$form = json_decode((string)$content, true);
		if (!is_array($form)) {
			if ($status === 206) {
				// A piece of a form that cannot be stripped is not sent at all.
				$response->setStatus(416);
				$response->removeHeader('Content-Range');
				$response->setBody('');
				$response->setHeader('Content-Length', '0');
			}
			return;
		}

		$form = $this->sanitizer->structureOnly($form);
		$form['downloaded_at'] = date('c');
		$stripped = json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

		// Whole, even if a range got through: a range of the stored file would not match this body.
		$response->setStatus(200);
		$response->removeHeader('Content-Range');
		$response->setBody($stripped);
		$response->setHeader('Content-Length', (string)strlen($stripped));
		// The checksum described the stored file, not what is sent.
		$response->removeHeader('OC-Checksum');
	}

	private function isFormDownload(string $path): bool {
		if ($this->hasFormName(basename($path))) {
			return true;
		}
		try {
			$node = $this->server?->tree->getNodeForPath($path);
		} catch (\Throwable) {
			return false;
		}
		if ($node instanceof IFile && str_starts_with((string)$node->getContentType(), Application::MIME_TYPE)) {
			return true;
		}
		// A version is named after its timestamp; the file it belongs to tells.
		if (is_object($node) && method_exists($node, 'getVersion')) {
			$version = $node->getVersion();
			return is_object($version) && method_exists($version, 'getSourceFileName') && $this->hasFormName((string)$version->getSourceFileName());
		}
		return false;
	}

	/** Also matches trash entries, which carry a ".d<timestamp>" suffix. */
	private function hasFormName(string $name): bool {
		return preg_match('/\.' . Application::FILE_EXTENSION . '(\.d\d+)?$/i', $name) === 1;
	}

	public function getPluginName(): string {
		return self::NAME;
	}

	public function getPluginInfo(): array {
		return [
			'name' => $this->getPluginName(),
			'description' => 'Strips responses and settings from FormVox files on download',
		];
	}
}
