<?php

declare(strict_types=1);

namespace OCA\FormVox\Listener;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Db\AiPendingMapper;
use OCA\FormVox\Exception\AiGenerationException;
use OCA\FormVox\Exception\ClientErrorException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Service\AiFormGeneratorService;
use OCA\FormVox\Service\FormRepository;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class AiTaskSuccessfulListener implements IEventListener {
	public function __construct(
		private AiPendingMapper $pendingMapper,
		private AiFormGeneratorService $aiService,
		private FormRepository $formRepository,
		private INotificationManager $notificationManager,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
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
			$result = $this->formRepository->createAsUser(
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
		} catch (AiGenerationException|ClientErrorException $e) {
			// Our own reason, in English for the Notifier to translate.
			$this->failed($task, $pending->getTitle(), $e->getMessage(), $e);
		} catch (\Throwable $e) {
			// TRANSLATORS: Shown in a notification when an AI form could not be created for a reason the log has
			$this->failed($task, $pending->getTitle(), Translatable::t('Something went wrong while creating the form.'), $e);
		}

		$this->pendingMapper->deleteByTaskId($task->getId());
	}

	private function failed(Task $task, string $title, string $reason, \Throwable $e): void {
		$this->logger->warning('FormVox AI listener: failed to materialise form from task ' . $task->getId(), ['exception' => $e]);
		$this->sendNotification($task->getUserId(), 'ai_form_failed', ['formTitle' => $title, 'reason' => $reason], 'task', (string)$task->getId());
	}

	private function sendNotification(string $userId, string $subject, array $params, string $objectType, string $objectId): void {
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
