<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
use OCP\Constants;
use OCA\FormVox\AppInfo\Application;

class FilePermissionController extends Controller
{
    private IRootFolder $rootFolder;
    private IUserSession $userSession;

    public function __construct(
        IRequest $request,
        IRootFolder $rootFolder,
        IUserSession $userSession
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
    }

    /**
     * Get the current user's permissions for a file
     */
    #[NoAdminRequired]
    public function getPermissions(int $fileId): DataResponse
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(
                ['error' => 'Not authenticated'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $nodes = $userFolder->getById($fileId);

            if (empty($nodes)) {
                return new DataResponse(
                    ['error' => 'File not found'],
                    Http::STATUS_NOT_FOUND
                );
            }

            $file = $nodes[0];
            $perms = $file->getPermissions();
            $isOwner = $file->getOwner()?->getUID() === $user->getUID();

            $canRead = ($perms & Constants::PERMISSION_READ) !== 0;
            $canEdit = ($perms & Constants::PERMISSION_UPDATE) !== 0;
            $canDelete = ($perms & Constants::PERMISSION_DELETE) !== 0;
            $canShare = ($perms & Constants::PERMISSION_SHARE) !== 0;

            return new DataResponse([
                'isOwner' => $isOwner,
                'canRead' => $canRead,
                'canEdit' => $canEdit,
                'canDelete' => $canDelete,
                'canShare' => $canShare,
                'role' => $this->calculateRole($isOwner, $canRead, $canEdit, $canDelete),
            ]);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Calculate FormVox role based on Nextcloud file permissions
     */
    private function calculateRole(bool $isOwner, bool $canRead, bool $canEdit, bool $canDelete): string
    {
        if ($isOwner) {
            return 'owner';
        }

        if (!$canRead) {
            return 'none';
        }

        if ($canEdit && $canDelete) {
            return 'admin';
        }

        if ($canEdit) {
            return 'editor';
        }

        return 'viewer';
    }
}
