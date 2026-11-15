<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\RequireFormPermission;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

class PresenceController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * "I am editing" heartbeat; it locks others out of editing, so only editors may send it.
	 */
	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function sendPresence(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		$userId = $user->getUID();
		$displayName = $user->getDisplayName();

		// Preserve firstSeen timestamp if already present
		$existing = $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			'presence_' . $fileId,
			''
		);
		$firstSeen = time();
		if ($existing) {
			$existingData = json_decode($existing, true);
			if ($existingData && isset($existingData['firstSeen'])) {
				// Only keep firstSeen if still within timeout (60s)
				if (time() - ($existingData['timestamp'] ?? 0) <= 60) {
					$firstSeen = $existingData['firstSeen'];
				}
			}
		}

		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			'presence_' . $fileId,
			json_encode([
				'userId' => $userId,
				'displayName' => $displayName,
				'timestamp' => time(),
				'firstSeen' => $firstSeen,
			])
		);

		return new DataResponse(['status' => 'ok']);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::READ)]
	public function getPresence(int $fileId): DataResponse {
		$currentUserId = $this->userSession->getUser()->getUID();
		$editors = [];
		$myFirstSeen = null;
		$now = time();
		$timeout = 60; // seconds

		// Check all known users for presence on this form
		$this->userManager->callForAllUsers(function ($user) use ($fileId, $now, $timeout, $currentUserId, &$editors, &$myFirstSeen) {
			$userId = $user->getUID();
			$value = $this->config->getUserValue(
				$userId,
				Application::APP_ID,
				'presence_' . $fileId,
				''
			);

			if (!$value) {
				return;
			}

			$data = json_decode($value, true);
			if (!$data || !isset($data['timestamp'])) {
				return;
			}

			// Skip stale entries
			if ($now - $data['timestamp'] > $timeout) {
				return;
			}

			// Track current user's firstSeen
			if ($userId === $currentUserId) {
				$myFirstSeen = $data['firstSeen'] ?? $data['timestamp'];
				return;
			}

			$editors[] = [
				'userId' => $data['userId'],
				'displayName' => $data['displayName'],
				'firstSeen' => $data['firstSeen'] ?? $data['timestamp'],
			];
		});

		return new DataResponse([
			'editors' => $editors,
			'myFirstSeen' => $myFirstSeen,
		]);
	}
}
