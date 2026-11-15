<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Db\AiPendingMapper;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Service\AiFormGeneratorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager as ITaskManager;
use OCP\TaskProcessing\Task;

class AiController extends Controller {
	public function __construct(
		IRequest $request,
		private AiFormGeneratorService $aiService,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private ITaskManager $taskManager,
		private AiPendingMapper $pendingMapper,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Schedule an AI form-generation task. Returns the task id immediately —
	 * the caller should poll /api/ai/task/{taskId} for completion.
	 *
	 * The pending row stores `title` and `path` so the TaskSuccessfulListener
	 * can build the form once the task finishes (refresh-proof, tab-close-proof).
	 */
	#[NoAdminRequired]
	public function generateForm(string $title, string $description = '', ?int $sourceFileId = null, string $path = ''): DataResponse {
		$description = trim($description);
		$title = trim($title);

		$userId = $this->userSession->getUser()?->getUID() ?? throw RequestException::notLoggedIn();
		if ($title === '') {
			// TRANSLATORS: Error shown when an AI form is asked for without a title
			throw RequestException::invalid(Translatable::t('A title is required.'));
		}
		if ($description === '' && ($sourceFileId === null || $sourceFileId <= 0)) {
			// TRANSLATORS: Error shown when an AI form is asked for without a description or a document
			throw RequestException::invalid(Translatable::t('Please describe the form you want to create or pick a source document.'));
		}
		if (!$this->aiService->isAvailable()) {
			// TRANSLATORS: Error shown when the instance has no AI provider for generating forms
			throw RequestException::turnedOff(Translatable::t('AI form generation is not available on this instance.'));
		}

		$taskId = $this->aiService->scheduleGeneration($description, $userId, $sourceFileId);
		$this->pendingMapper->createPending($taskId, $title, $path);
		return new DataResponse(['taskId' => $taskId]);
	}

	/**
	 * Polling endpoint — returns the task status, and the resulting fileId once
	 * the listener has materialised the form.
	 */
	#[NoAdminRequired]
	public function getTask(int $taskId): DataResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? throw RequestException::notLoggedIn();
		try {
			$task = $this->taskManager->getTask($taskId);
		} catch (\OCP\TaskProcessing\Exception\NotFoundException|\OCP\TaskProcessing\Exception\Exception) {
			$task = null;
		}
		// Someone else's task looks the same as one that does not exist.
		if ($task === null || $task->getUserId() !== $userId) {
			// TRANSLATORS: Error shown when polling an AI task that does not exist or is someone else's
			throw RequestException::notFound(Translatable::t('Task not found'));
		}

		$body = [
			'status' => $task->getStatus(),
			'statusName' => $this->statusName($task->getStatus()),
		];

		// The listener removes the pending row once the form is created. So:
		//   - status SUCCESSFUL + no pending row = listener has finished
		//   - status SUCCESSFUL + pending row    = listener still running
		if ($task->getStatus() === Task::STATUS_SUCCESSFUL) {
			$pending = $this->pendingMapper->getByTaskId($taskId);
			$body['pendingRow'] = $pending !== null;
		}
		if ($task->getStatus() === Task::STATUS_FAILED && method_exists($task, 'getErrorMessage')) {
			$body['error'] = $task->getErrorMessage();
		}

		return new DataResponse($body);
	}

	private function statusName(int $status): string {
		return match ($status) {
			Task::STATUS_SCHEDULED => 'scheduled',
			Task::STATUS_RUNNING => 'running',
			Task::STATUS_SUCCESSFUL => 'successful',
			Task::STATUS_FAILED => 'failed',
			Task::STATUS_CANCELLED => 'cancelled',
			default => 'unknown',
		};
	}

	/**
	 * Resolve a file path (as returned by the file picker) to its fileId.
	 */
	#[NoAdminRequired]
	public function resolveFile(string $path): DataResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? throw RequestException::notLoggedIn();
		// A missing file is a 404 through ExceptionMiddleware.
		return new DataResponse(['fileId' => $this->rootFolder->getUserFolder($userId)->get($path)->getId()]);
	}
}
