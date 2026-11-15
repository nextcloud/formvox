<?php

declare(strict_types=1);

namespace OCA\FormVox\DAV;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\Service\FormIntake;
use OCA\FormVox\Service\FormLockedException;
use OCA\FormVox\Service\FormValidationException;
use OCA\FormVox\Service\OutsideWriteHold;
use OCP\Files\File;
use OCP\Files\ForbiddenException;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception as DavException;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\InsufficientStorage;
use Sabre\DAV\Exception\Locked;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * A form put through Files or WebDAV goes through FormIntake. Over a stored form only its content counts: FormVox
 * merges it under the form's lock, so the responses, link, keys, webhooks and creator stay. A new form file is
 * written by Nextcloud and taken in right after, like a copy. Content that would break the form is refused with
 * the reason; other ways of writing (chunked or bulk upload, other apps) are covered by FormIntake::written().
 *
 * Restoring a version is the same: its content, the server's data (PO 5). A file is not renamed or copied into a
 * form, nor a form out of being one.
 */
class FormUploadPlugin extends ServerPlugin {
	public const NAME = 'formvox-upload';

	/** Far above any form within FormSchemaValidator's limits: what is put carries no responses. */
	private const MAX_BYTES = 20 * 1024 * 1024;

	private ?Server $server = null;

	/**
	 * The container, so FormVox's services are only built, in the user's language, when a form arrives.
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		// After the tree is set up (30), before Nextcloud locks the file for the PUT (50): the merge writes it itself.
		$server->on('beforeMethod:PUT', [$this, 'beforePut'], 40);
		// Before the chunked-upload plugins (90, 100), which handle their own moves.
		$server->on('beforeMove', [$this, 'beforeMove'], 80);
		$server->on('beforeCopy', [$this, 'beforeCopy'], 80);
		$server->on('afterMove', fn () => $this->container->get(OutsideWriteHold::class)->releaseAll(), 80);
	}

	public function beforeMove(string $source, string $destination): bool {
		$node = $this->nodeAt($source);
		if (is_object($node) && method_exists($node, 'getVersion')) {
			return $this->restore($node);
		}
		$this->keepsItsKind($node, $source, $destination);
		// A save under way finishes first: the form moved, or the one an upload is assembled over, waits briefly (3b-3).
		$this->holdForms([$node, $this->nodeAt($destination)]);
		return true;
	}

	/**
	 * @param list<mixed> $nodes
	 * @throws FormBusy a FormVox save held one of them all that time
	 */
	private function holdForms(array $nodes): void {
		$forms = [];
		foreach ($nodes as $node) {
			$file = is_object($node) && method_exists($node, 'getNode') ? $node->getNode() : null;
			// Only what this request may change: Nextcloud refuses the rest after this, and no reader holds a form.
			if ($file instanceof File && $this->hasFormName($file->getName()) && ($file->isDeletable() || $file->isUpdateable())) {
				$forms[(int)$file->getId()] = true;
			}
		}
		// In one order, so two crossing moves do not wait on each other.
		ksort($forms);
		foreach (array_keys($forms) as $fileId) {
			try {
				$this->container->get(OutsideWriteHold::class)->take($fileId);
			} catch (FormLockedException) {
				// TRANSLATORS: Error shown when another save holds the form for a moment
				throw new FormBusy($this->l()->t('The form is busy, please try again'));
			} catch (\Throwable $e) {
				// The lock only keeps a submission from being written over; whatever keeps it from us, the move goes on.
				$this->logger->warning('FormVox: moved a form without its lock: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
	}

	public function beforeCopy(string $source, string $destination): bool {
		$this->keepsItsKind($this->nodeAt($source), $source, $destination);
		return true;
	}

	/**
	 * A renamed file would become a form nobody checked; a renamed form a plain file with every answer in it.
	 */
	private function keepsItsKind(mixed $node, string $source, string $destination): void {
		$file = is_object($node) && method_exists($node, 'getNode') ? $node->getNode() : null;
		if (!($file instanceof File)) {
			// Folders keep the names inside them; an upload being assembled is checked when it is written.
			return;
		}
		$wasForm = $this->hasFormName($source);
		if ($wasForm === $this->hasFormName($destination)) {
			return;
		}
		throw new Forbidden($wasForm
			// TRANSLATORS: Error when renaming or copying a FormVox form to another file type in Files
			? $this->l()->t('A form keeps the .fvform extension')
			// TRANSLATORS: Error when renaming or copying a file to the .fvform extension in Files
			: $this->l()->t('Only FormVox turns a file into a form'));
	}

	/**
	 * A version put back through the Files sidebar: its questions and pages, the responses, link, keys, webhooks
	 * and creator as they are now (qual#31). It answers itself, so Nextcloud's own restore does not run.
	 */
	private function restore(object $versionNode): bool {
		$version = $versionNode->getVersion();
		if (!is_object($version) || !method_exists($version, 'getSourceFile') || !method_exists($version, 'getSourceFileName')
			|| !$this->hasFormName((string)$version->getSourceFileName())) {
			return true;
		}
		$user = $this->container->get(IUserSession::class)->getUser();
		$file = $user === null ? null : $this->container->get(IRootFolder::class)->getUserFolder($user->getUID())->getFirstNodeById($version->getSourceFile()->getId());
		if (!($file instanceof File)) {
			return true;
		}
		if (!$file->isUpdateable()) {
			throw new Forbidden();
		}
		$content = $versionNode->get();
		$content = is_resource($content) ? stream_get_contents($content, self::MAX_BYTES + 1) : (string)$content;

		try {
			if (!$this->intake()->overwrite($file, is_string($content) ? $content : '')) {
				return true;
			}
		} catch (FormValidationException $e) {
			throw new BadRequest($this->describe($e));
		} catch (ClientErrorException $e) {
			throw $this->refusal($e->getStatus(), $this->l()->t($e->getMessage(), $e->getParams()));
		} catch (FormLockedException) {
			// TRANSLATORS: Error shown when another save holds the form for a moment
			throw new FormBusy($this->l()->t('The form is busy, please try again'));
		} catch (\Throwable $e) {
			throw $this->writeFailed($e);
		}
		$response = $this->server?->httpResponse;
		$response?->setHeader('Content-Length', '0');
		$response?->setStatus(204);
		return false;
	}

	private function nodeAt(string $path): mixed {
		try {
			return $this->server?->tree->getNodeForPath($path);
		} catch (NotFound) {
			return null;
		}
	}

	private function hasFormName(string $path): bool {
		return preg_match('/\.' . Application::FILE_EXTENSION . '$/i', $path) === 1;
	}

	public function beforePut(RequestInterface $request, ResponseInterface $response): bool {
		$path = $request->getPath();
		if ($this->server === null || !$this->hasFormName($path)) {
			return true;
		}
		try {
			$node = $this->server->tree->getNodeForPath($path);
		} catch (NotFound) {
			$node = null;
		}
		$file = $node !== null && method_exists($node, 'getNode') ? $node->getNode() : null;
		if ($node !== null && !($file instanceof File)) {
			// Not a file in Files, such as an upload chunk: Nextcloud answers as usual.
			return true;
		}
		if ($request->getHeader('Content-Range')) {
			throw new BadRequest('Content-Range on PUT requests are forbidden.');
		}
		$upload = $this->readBody($request);

		try {
			if ($file !== null) {
				if (!$file->isUpdateable()) {
					throw new Forbidden();
				}
				if ($this->overwrite($path, $node, $file, $upload, $request, $response)) {
					return false;
				}
			}
			// A new form: refused here when it would not open; Nextcloud writes it and FormIntake takes it in.
			$this->intake()->takeIn(null, $upload);
		} catch (FormValidationException $e) {
			throw new BadRequest($this->describe($e));
		} catch (ClientErrorException $e) {
			throw $this->refusal($e->getStatus(), $this->l()->t($e->getMessage(), $e->getParams()));
		}
		$request->setBody($upload);
		return true;
	}

	/**
	 * @return bool false when the stored file is no form FormVox can read; Nextcloud then writes the upload as usual
	 */
	private function overwrite(string $path, object $node, File $file, string $upload, RequestInterface $request, ResponseInterface $response): bool {
		$server = $this->server;
		if ($server === null || !$server->checkPreconditions($request, $response)) {
			return false;
		}
		$modified = false;
		$body = $upload;
		// Quota and other checks Sabre runs before it writes a file.
		if (!$server->emit('beforeWriteContent', [$path, $node, &$body, &$modified])) {
			return false;
		}

		try {
			if (!$this->intake()->overwrite($file, $upload)) {
				return false;
			}
		} catch (FormLockedException) {
			// TRANSLATORS: Error shown when another save holds the form for a moment
			throw new FormBusy($this->l()->t('The form is busy, please try again'));
		} catch (ClientErrorException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->writeFailed($e);
		}

		// What Sabre does after it wrote a file (CorePlugin::httpPut(), Server::invokeMethod()).
		$server->tree->markDirty($path);
		$written = $server->tree->getNodeForPath($path);
		$server->emit('afterWriteContent', [$path, $written]);
		$response->setHeader('Content-Length', '0');
		$response->setHeader('ETag', $written->getETag());
		$response->setStatus(204);
		$server->emit('afterMethod:PUT', [$request, $response]);
		$server->sapi->sendResponse($response);
		$server->emit('afterResponse', [$request, $response]);
		return true;
	}

	private function readBody(RequestInterface $request): string {
		$upload = stream_get_contents($request->getBodyAsStream(), self::MAX_BYTES + 1);
		if (!is_string($upload)) {
			return '';
		}
		if (strlen($upload) > self::MAX_BYTES) {
			// TRANSLATORS: Error when a form file put through Files or WebDAV is far larger than any form can be
			throw new BadRequest($this->l()->t('This form file is too large'));
		}
		return $upload;
	}

	/** The first problem, with the question it is on: already in the user's language. */
	private function describe(FormValidationException $invalid): string {
		$error = $invalid->getErrors()[0] ?? ['question' => null, 'message' => ''];
		if ($error['question'] === null) {
			return $error['message'];
		}
		// TRANSLATORS: Error when a form file put through Files or WebDAV would break the form; %1$s is what is wrong, %2$s the question's id
		return $this->l()->t('%1$s (question %2$s)', [$error['message'], $error['question']]);
	}

	/**
	 * Our refusal in the user's language: out of room is 507, so a sync client treats it as quota, not as a bad file.
	 */
	private function refusal(int $status, string $message): DavException {
		return $status === 507 ? new InsufficientStorage($message) : new BadRequest($message);
	}

	/** Nextcloud's own refusals keep their meaning; anything else is logged, not shown. */
	private function writeFailed(\Throwable $e): DavException {
		for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
			if ($cause instanceof NotPermittedException || $cause instanceof ForbiddenException) {
				return new Forbidden();
			}
			if ($cause instanceof LockedException) {
				return new Locked();
			}
		}
		$this->logger->error('FormVox: a form put through WebDAV could not be written: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
		// TRANSLATORS: Error when saving a form put through Files or WebDAV failed on the server
		return new DavException($this->l()->t('The form could not be saved, please try again'));
	}

	private function intake(): FormIntake {
		return $this->container->get(FormIntake::class);
	}

	private function l(): IL10N {
		return $this->container->get(IL10N::class);
	}

	public function getPluginName(): string {
		return self::NAME;
	}

	public function getPluginInfo(): array {
		return [
			'name' => $this->getPluginName(),
			'description' => 'Keeps the responses, link, keys and creator of a FormVox form that is put or restored over WebDAV',
		];
	}
}
