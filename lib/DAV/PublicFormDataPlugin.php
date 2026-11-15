<?php

declare(strict_types=1);

namespace OCA\FormVox\DAV;

use OCA\DAV\Connector\Sabre\FilesPlugin;
use OCA\DAV\Connector\Sabre\Node as DavNode;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Security\HiddenNodes;
use OCA\FormVox\Security\HiddenPath;
use Sabre\DAV\Exception as DavException;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * On a public link, a form's own folders do not exist: they are left out of listings, and any request that reaches
 * one, or the link's own root inside one, is answered as for a missing item.
 */
class PublicFormDataPlugin extends ServerPlugin {
	public const NAME = 'formvox-public-form-data';

	private ?Server $server = null;

	public function __construct(
		private HiddenNodes $hiddenNodes,
		private PublicDavScope $scope,
	) {
	}

	public function getPluginName(): string {
		return self::NAME;
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		$this->scope->enter();
		// After Nextcloud builds the link's tree (beforeMethod:*, priority 30).
		$server->on('beforeMethod:*', $this->refuseHidden(...), 40);
		$server->on('beforeMultiStatus', $this->leaveOutHidden(...));
	}

	public function refuseHidden(RequestInterface $request, ResponseInterface $response): void {
		$paths = [$request->getPath()];
		$destination = $request->getHeader('Destination');
		if ($destination !== null && $destination !== '') {
			try {
				$paths[] = $this->server->calculateUri($destination);
			} catch (\Throwable) {
				// Sabre refuses a Destination outside this server itself.
			}
		}

		foreach ($paths as $path) {
			if (HiddenPath::isHidden($path) || $this->liesInHidden($path)) {
				throw new NotFound();
			}
		}
	}

	/**
	 * @param iterable<array<array-key, mixed>> $fileProperties
	 */
	public function leaveOutHidden(iterable &$fileProperties): void {
		$fileProperties = (static function (iterable $entries): \Generator {
			foreach ($entries as $entry) {
				$href = (string)($entry['href'] ?? '');
				if (HiddenPath::isHidden($href)) {
					continue;
				}
				if (self::isForm($href)) {
					unset($entry[200][FilesPlugin::DOWNLOADURL_PROPERTYNAME]);
				}
				yield $entry;
			}
		})($fileProperties);
	}

	/** The nearest existing item, seen from its owner: the link itself may point into one of a form's folders. */
	private function liesInHidden(string $path): bool {
		$path = trim($path, '/');
		while (true) {
			try {
				$node = $this->server->tree->getNodeForPath($path);
			} catch (NotFound) {
				if ($path === '') {
					return false;
				}
				$path = str_contains($path, '/') ? substr($path, 0, (int)strrpos($path, '/')) : '';
				continue;
			} catch (DavException) {
				// Nextcloud answers this request with the same refusal itself.
				return false;
			}
			return $node instanceof DavNode && $this->hiddenNodes->isHidden($node->getFileInfo());
		}
	}

	private static function isForm(string $href): bool {
		return strtolower(pathinfo(rtrim($href, '/'), PATHINFO_EXTENSION)) === Application::FILE_EXTENSION;
	}
}
