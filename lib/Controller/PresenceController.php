<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IConfig;
use OCA\FormVox\AppInfo\Application;

class PresenceController extends Controller
{
    private IUserSession $userSession;
    private IUserManager $userManager;
    private IConfig $config;

    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IUserManager $userManager,
        IConfig $config
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->config = $config;
    }

    /**
     * Send presence heartbeat for a form
     */
    #[NoAdminRequired]
    public function sendPresence(int $fileId): DataResponse
    {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

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
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get active editors for a form
     */
    #[NoAdminRequired]
    public function getPresence(int $fileId): DataResponse
    {
        try {
            $currentUser = $this->userSession->getUser();
            if (!$currentUser) {
                return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

            $currentUserId = $currentUser->getUID();
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

                if (!$value) return;

                $data = json_decode($value, true);
                if (!$data || !isset($data['timestamp'])) return;

                // Skip stale entries
                if ($now - $data['timestamp'] > $timeout) return;

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
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
