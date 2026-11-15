<?php

declare(strict_types=1);

namespace OCA\FormVox\Middleware;

use OCA\FormVox\Exception\HiddenShareItemException;
use OCA\FormVox\Security\HiddenNodes;
use OCA\FormVox\Security\HiddenPath;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\L10N\IFactory;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;

/**
 * Behind a public link, Nextcloud's own pages outside WebDAV (the share page and its download, previews, share info,
 * adding a link to another server) answer an item in one of a form's own folders as they answer a missing one.
 * Decided by name, never by looking the item up, so the answer tells nothing about what exists.
 */
class PublicShareMiddleware extends Middleware {
	private const SHARE_PAGE = 'OCA\Files_Sharing\Controller\ShareController';
	private const PREVIEW = 'OCA\Files_Sharing\Controller\PublicPreviewController';
	private const SHARE_INFO = 'OCA\Files_Sharing\Controller\ShareInfoController';
	private const MOUNT = 'OCA\FederatedFileSharing\Controller\MountPublicLinkController';
	/** Nextcloud's own words for a missing share, in its own translations. */
	private const SHARE_GONE = 'The requested share does not exist anymore';

	public function __construct(
		private IRequest $request,
		private IShareManager $shareManager,
		private HiddenNodes $hiddenNodes,
		private IFactory $l10nFactory,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		$route = $this->route($controller);
		if ($route === null) {
			return;
		}

		$paths = match ($route) {
			self::SHARE_PAGE => [$this->string('path'), ...$this->files()],
			self::PREVIEW => [$this->string('file')],
			self::SHARE_INFO => [$this->string('dir')],
			default => [],
		};
		foreach ($paths as $path) {
			if (HiddenPath::isHidden($path)) {
				throw new HiddenShareItemException($this->missing($route));
			}
		}

		$token = $this->string($route === self::SHARE_INFO ? 't' : 'token');
		if ($token === '') {
			return;
		}
		try {
			$root = $this->shareManager->getShareByToken($token)->getNode();
		} catch (ShareNotFound|\OCP\Files\NotFoundException) {
			// files_sharing answers this itself.
			return;
		}
		if ($this->hiddenNodes->isHidden($root)) {
			throw new HiddenShareItemException($this->missing($route));
		}
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if ($exception instanceof HiddenShareItemException) {
			return $exception->response;
		}
		throw $exception;
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if ($this->route($controller) === self::SHARE_INFO && $response instanceof JSONResponse) {
			$data = $response->getData();
			if (is_array($data)) {
				$response->setData(self::withoutHidden($data));
			}
		}
		return $response;
	}

	private function route(Controller $controller): ?string {
		foreach ([self::SHARE_PAGE, self::PREVIEW, self::SHARE_INFO, self::MOUNT] as $class) {
			if (is_a($controller, $class)) {
				return $class;
			}
		}
		return null;
	}

	/** As files_sharing answers a missing item on that route. */
	private function missing(string $route): Response {
		return match ($route) {
			self::SHARE_PAGE => new NotFoundResponse(),
			self::PREVIEW => new DataResponse([], Http::STATUS_NOT_FOUND),
			self::SHARE_INFO => new JSONResponse([], Http::STATUS_NOT_FOUND),
			default => new JSONResponse(
				['message' => $this->l10nFactory->get('lib')->t(self::SHARE_GONE)],
				Http::STATUS_BAD_REQUEST,
			),
		};
	}

	private function string(string $name): string {
		$value = $this->request->getParam($name);
		return is_string($value) ? $value : '';
	}

	/**
	 * The names a download asks for: a JSON list, one name, or a list.
	 *
	 * @return list<string>
	 */
	private function files(): array {
		$files = $this->request->getParam('files');
		if (is_string($files)) {
			$decoded = json_decode($files, true);
			$files = is_array($decoded) ? $decoded : [$files];
		}
		return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
	}

	/**
	 * @param array<array-key, mixed> $node
	 * @return array<array-key, mixed>
	 */
	private static function withoutHidden(array $node): array {
		if (isset($node['children']) && is_array($node['children'])) {
			$children = [];
			foreach ($node['children'] as $child) {
				if (is_array($child) && HiddenPath::isHidden((string)($child['name'] ?? ''))) {
					continue;
				}
				$children[] = is_array($child) ? self::withoutHidden($child) : $child;
			}
			$node['children'] = $children;
		}
		return $node;
	}
}
