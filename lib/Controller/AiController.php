<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager as ITaskManager;
use OCP\TaskProcessing\Task;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Db\AiPendingMapper;
use OCA\FormVox\Service\AiFormGeneratorService;

class AiController extends Controller
{
    private AiFormGeneratorService $aiService;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;
    private ITaskManager $taskManager;
    private AiPendingMapper $pendingMapper;

    public function __construct(
        IRequest $request,
        AiFormGeneratorService $aiService,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        ITaskManager $taskManager,
        AiPendingMapper $pendingMapper
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->aiService = $aiService;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->taskManager = $taskManager;
        $this->pendingMapper = $pendingMapper;
    }

    /**
     * Schedule an AI form-generation task. Returns the task id immediately —
     * the caller should poll /api/ai/task/{taskId} for completion.
     *
     * The pending row stores `title` and `path` so the TaskSuccessfulListener
     * can build the form once the task finishes (refresh-proof, tab-close-proof).
     */
    #[NoAdminRequired]
    public function generateForm(string $title, string $description = '', ?int $sourceFileId = null, string $path = ''): DataResponse
    {
        $description = trim($description);
        $title = trim($title);

        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($title === '') {
            return new DataResponse(['error' => 'Title is required.'], Http::STATUS_BAD_REQUEST);
        }
        if ($description === '' && ($sourceFileId === null || $sourceFileId <= 0)) {
            return new DataResponse(
                ['error' => 'Please describe the form you want to create or pick a source document.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (!$this->aiService->isAvailable()) {
            return new DataResponse(
                ['error' => 'AI form generation is not available on this instance.'],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $taskId = $this->aiService->scheduleGeneration($description, $userId, $sourceFileId);
            $this->pendingMapper->createPending($taskId, $title, $path);
            return new DataResponse(['taskId' => $taskId]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Polling endpoint — returns the task status, and the resulting fileId once
     * the listener has materialised the form.
     */
    #[NoAdminRequired]
    public function getTask(int $taskId): DataResponse
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $task = $this->taskManager->getTask($taskId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Task not found'], Http::STATUS_NOT_FOUND);
        }
        if ($task->getUserId() !== $userId) {
            return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
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

    private function statusName(int $status): string
    {
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
    public function resolveFile(string $path): DataResponse
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $node = $userFolder->get($path);
            return new DataResponse(['fileId' => $node->getId()]);
        } catch (\OCP\Files\NotFoundException $e) {
            return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
