<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Files\File;
use OCP\Constants;

class PermissionService
{
    public const ROLE_NONE = 'none';
    public const ROLE_RESPONDENT = 'respondent';
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OWNER = 'owner';

    /**
     * Get user's role based on Nextcloud file permissions
     * This is the primary method for determining access rights
     *
     * Mapping:
     * - Owner = OWNER (full control)
     * - Read + Edit = EDITOR (full form control, share permission controls public link)
     * - Read only = VIEWER (view only)
     */
    public function getRoleFromFile(File $file, string $userId): string
    {
        // Check if user is file owner
        if ($file->getOwner()?->getUID() === $userId) {
            return self::ROLE_OWNER;
        }

        $perms = $file->getPermissions();

        // NC Constants: READ=1, UPDATE=2, CREATE=4, DELETE=8, SHARE=16
        $canRead = ($perms & Constants::PERMISSION_READ) !== 0;
        $canEdit = ($perms & Constants::PERMISSION_UPDATE) !== 0;

        if (!$canRead) {
            return self::ROLE_NONE;
        }

        // Editor gets full form control (settings, branding, responses)
        // Share permission is checked separately for public link creation
        if ($canEdit) {
            return self::ROLE_EDITOR;
        }

        return self::ROLE_VIEWER;
    }

    /**
     * Check if user can share (create public response link)
     * Based on NC SHARE permission
     */
    public function canShareFromFile(File $file, string $userId): bool
    {
        if ($file->getOwner()?->getUID() === $userId) {
            return true;
        }

        $perms = $file->getPermissions();
        return ($perms & Constants::PERMISSION_SHARE) !== 0;
    }

    /**
     * Check if user can respond to form
     */
    public function canRespond(string $role): bool
    {
        return in_array($role, [
            self::ROLE_RESPONDENT,
            self::ROLE_VIEWER,
            self::ROLE_EDITOR,
            self::ROLE_ADMIN,
            self::ROLE_OWNER,
        ]);
    }

    /**
     * Check if user can view responses
     */
    public function canViewResponses(string $role): bool
    {
        return in_array($role, [
            self::ROLE_VIEWER,
            self::ROLE_EDITOR,
            self::ROLE_ADMIN,
            self::ROLE_OWNER,
        ]);
    }

    /**
     * Check if user can edit questions
     */
    public function canEditQuestions(string $role): bool
    {
        return in_array($role, [
            self::ROLE_EDITOR,
            self::ROLE_ADMIN,
            self::ROLE_OWNER,
        ]);
    }

    /**
     * Check if user can modify settings
     * Editor and above can modify settings
     */
    public function canEditSettings(string $role): bool
    {
        return in_array($role, [
            self::ROLE_EDITOR,
            self::ROLE_ADMIN,
            self::ROLE_OWNER,
        ]);
    }

    /**
     * Check if user can delete responses
     * Editor and above can delete responses
     */
    public function canDeleteResponses(string $role): bool
    {
        return in_array($role, [
            self::ROLE_EDITOR,
            self::ROLE_ADMIN,
            self::ROLE_OWNER,
        ]);
    }

    /**
     * Check if user can delete the form
     */
    public function canDeleteForm(string $role): bool
    {
        return $role === self::ROLE_OWNER;
    }

    /**
     * Check if user can manage permissions (open NC share dialog)
     * Editor and above can manage permissions
     */
    public function canManagePermissions(string $role): bool
    {
        return in_array($role, [
            self::ROLE_EDITOR,
            self::ROLE_ADMIN,
            self::ROLE_OWNER,
        ]);
    }

    /**
     * Get all permissions for a role as array
     * Note: canShare must be set separately using canShareFromFile()
     */
    public function getPermissionsForRole(string $role, bool $canShare = false): array
    {
        return [
            'respond' => $this->canRespond($role),
            'viewResponses' => $this->canViewResponses($role),
            'editQuestions' => $this->canEditQuestions($role),
            'editSettings' => $this->canEditSettings($role),
            'deleteResponses' => $this->canDeleteResponses($role),
            'deleteForm' => $this->canDeleteForm($role),
            'managePermissions' => $this->canManagePermissions($role),
            'canShare' => $canShare,
        ];
    }
}
