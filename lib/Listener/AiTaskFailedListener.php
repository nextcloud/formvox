<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Db\AiPendingMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\Events\TaskFailedEvent;

/**
 * @template-implements IEventListener<Event>
 */
class AiTaskFailedListener implements IEventListener
{
    private AiPendingMapper $pendingMapper;
    private INotificationManager $notificationManager;

    public function __construct(AiPendingMapper $pendingMapper, INotificationManager $notificationManager)
    {
        $this->pendingMapper = $pendingMapper;
        $this->notificationManager = $notificationManager;
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof TaskFailedEvent) {
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

        $err = method_exists($task, 'getErrorMessage') ? $task->getErrorMessage() : 'Unknown error';

        try {
            $n = $this->notificationManager->createNotification();
            $n->setApp(Application::APP_ID)
                ->setUser($task->getUserId())
                ->setDateTime(new \DateTime())
                ->setObject('task', (string)$task->getId())
                ->setSubject('ai_form_failed', [
                    'formTitle' => $pending->getTitle(),
                    'reason' => $err ?? 'Unknown error',
                ]);
            $this->notificationManager->notify($n);
        } catch (\Exception $e) {
            // best effort
        }

        $this->pendingMapper->deleteByTaskId($task->getId());
    }
}
