<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCA\FormVox\AppInfo\Application;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Decides who hears about a new response, for every channel that stores one.
 */
class ResponseNotificationService {
	public function __construct(
		private FormCreatorService $creators,
		private INotificationManager $notificationManager,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Never throws: the response is already stored.
	 *
	 * @return bool false when nobody was notified for a failure worth trying again; one recipient that fails is only
	 *              logged, so the others are not notified twice
	 */
	public function notifyNewResponse(int $fileId, array $form, array $response): bool {
		$isUser = ($response['respondent']['type'] ?? '') === 'user';
		$respondentId = $isUser ? (string)($response['respondent']['user_id'] ?? '') : '';

		// The title is left out when there is none, so the Notifier translates its fallback.
		$subject = isset($form['title']) ? ['formTitle' => $form['title']] : [];
		$subject['fileId'] = $fileId;
		// Left out for anonymous respondents so the Notifier translates "Anonymous".
		if ($isUser) {
			$subject['respondentName'] = $response['respondent']['display_name'] ?? $respondentId;
		}

		try {
			$recipients = $this->getRecipientIds($fileId, $form, $respondentId);
		} catch (\Throwable $e) {
			$this->logger->warning('FormVox: could not tell who to notify of a new response: ' . $e->getMessage(), ['fileId' => $fileId, 'exception' => $e]);
			return false;
		}
		foreach ($recipients as $userId) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($userId)
					->setDateTime(new \DateTime())
					->setObject('response', (string)($response['id'] ?? ''))
					->setSubject('response_submitted', $subject);

				$this->notificationManager->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->warning('FormVox: could not notify ' . $userId . ' of a new response: ' . $e->getMessage(), ['fileId' => $fileId, 'exception' => $e]);
			}
		}
		return true;
	}

	/**
	 * @return list<string> respondent excluded
	 */
	public function getRecipientIds(int $fileId, array $form, string $respondentId = ''): array {
		$ids = [];

		if (($form['settings']['notify_owner'] ?? true) !== false) {
			$creator = $this->creators->getCreator($fileId, $form);
			if ($creator['status'] === FormCreatorService::STATUS_OK) {
				$ids[] = (string)$creator['id'];
			} elseif ($creator['status'] !== FormCreatorService::STATUS_NONE) {
				$this->logger->info(
					'FormVox: not notifying form creator "' . $creator['id'] . '": ' . $creator['status'],
					['fileId' => $fileId]
				);
			}
		}

		foreach ($form['settings']['notify_recipients'] ?? [] as $recipient) {
			$type = $recipient['type'] ?? '';
			$id = (string)($recipient['id'] ?? '');
			if ($id === '') {
				continue;
			}
			if ($type === 'user') {
				$ids[] = $id;
			} elseif ($type === 'group') {
				$group = $this->groupManager->get($id);
				if ($group !== null) {
					foreach ($group->getUsers() as $user) {
						$ids[] = $user->getUID();
					}
				}
			}
		}

		return array_values(array_filter(
			array_unique($ids),
			fn (string $id) => $id !== $respondentId
		));
	}
}
