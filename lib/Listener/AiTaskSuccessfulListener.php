<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Db\AiPendingMapper;
use OCA\FormVox\Service\AiFormGeneratorService;
use OCA\FormVox\Service\FormService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class AiTaskSuccessfulListener implements IEventListener
{
    private AiPendingMapper $pendingMapper;
    private AiFormGeneratorService $aiService;
    private FormService $formService;
    private INotificationManager $notificationManager;
    private IUserManager $userManager;
    private LoggerInterface $logger;

    public function __construct(
        AiPendingMapper $pendingMapper,
        AiFormGeneratorService $aiService,
        FormService $formService,
        INotificationManager $notificationManager,
        IUserManager $userManager,
        LoggerInterface $logger
    ) {
        $this->pendingMapper = $pendingMapper;
        $this->aiService = $aiService;
        $this->formService = $formService;
        $this->notificationManager = $notificationManager;
        $this->userManager = $userManager;
        $this->logger = $logger;
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof TaskSuccessfulEvent) {
            return;
        }

        $task = $event->getTask();
        if ($task->getAppId() !== Application::APP_ID) {
            return;
        }
        if ($task->getUserId() === null) {
            return;
        }

        $pending = $this->pendingMapper->getByTaskId($task->getId());
        if ($pending === null) {
            return;
        }

        try {
            $output = $task->getOutput();
            $raw = (string)($output['output'] ?? '');
            $parsed = $this->aiService->parseAiResponse($raw);

            // Build the form using the title from the pending row, but inject the
            // AI-generated questions and description.
            $result = $this->formService->createAsUser(
                $task->getUserId(),
                $pending->getTitle(),
                $pending->getPath() ?? '',
                null,
                [
                    'description' => $parsed['description'] ?? '',
                    'questions' => $parsed['questions'] ?? [],
                ]
            );

            $this->sendNotification(
                $task->getUserId(),
                'ai_form_ready',
                [
                    'formTitle' => $pending->getTitle(),
                    'fileId' => $result['fileId'] ?? 0,
                ],
                'form',
                (string)($result['fileId'] ?? 0)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('FormVox AI listener: failed to materialise form from task ' . $task->getId(), [
                'exception' => $e,
            ]);
            $this->sendNotification(
                $task->getUserId(),
                'ai_form_failed',
                ['formTitle' => $pending->getTitle(), 'reason' => $e->getMessage()],
                'task',
                (string)$task->getId()
            );
        }

        $this->pendingMapper->deleteByTaskId($task->getId());
    }

    private function sendNotification(string $userId, string $subject, array $params, string $objectType, string $objectId): void
    {
        try {
            $n = $this->notificationManager->createNotification();
            $n->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject($objectType, $objectId)
                ->setSubject($subject, $params);
            $this->notificationManager->notify($n);
        } catch (\Exception $e) {
            // notifications are best effort
        }
    }
}
