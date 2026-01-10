<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\IGroupManager;

class PermissionService
{
    public const ROLE_NONE = 'none';
    public const ROLE_RESPONDENT = 'respondent';
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OWNER = 'owner';

    private IGroupManager $groupManager;

    public function __construct(IGroupManager $groupManager)
    {
        $this->groupManager = $groupManager;
    }

    /**
     * Get user's role for a form
     */
    public function getRole(array $form, string $userId): string
    {
        $permissions = $form['permissions'] ?? [];

        // Check owner
        if (($permissions['owner'] ?? '') === $userId) {
            return self::ROLE_OWNER;
        }

        // Check explicit user roles
        foreach ($permissions['roles'] ?? [] as $role) {
            if (isset($role['user']) && $role['user'] === $userId) {
                return $role['role'];
            }

            // Check group membership
            if (isset($role['group'])) {
                if ($this->groupManager->isInGroup($userId, $role['group'])) {
                    return $role['role'];
                }
            }
        }

        // Check public access
        foreach ($permissions['roles'] ?? [] as $role) {
            if (isset($role['type']) && $role['type'] === 'public') {
                return $role['role'];
            }
        }

        return self::ROLE_NONE;
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
     */
    public function canEditSettings(string $role): bool
    {
        return in_array($role, [
            self::ROLE_ADMIN,
            self::ROLE_OWNER,
        ]);
    }

    /**
     * Check if user can delete responses
     */
    public function canDeleteResponses(string $role): bool
    {
        return in_array($role, [
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
     * Check if user can manage permissions
     */
    public function canManagePermissions(string $role): bool
    {
        return in_array($role, [
            self::ROLE_ADMIN,
            self::ROLE_OWNER,
        ]);
    }

    /**
     * Get all permissions for a role as array
     */
    public function getPermissionsForRole(string $role): array
    {
        return [
            'respond' => $this->canRespond($role),
            'viewResponses' => $this->canViewResponses($role),
            'editQuestions' => $this->canEditQuestions($role),
            'editSettings' => $this->canEditSettings($role),
            'deleteResponses' => $this->canDeleteResponses($role),
            'deleteForm' => $this->canDeleteForm($role),
            'managePermissions' => $this->canManagePermissions($role),
        ];
    }

    /**
     * Add a user role to the form
     */
    public function addUserRole(array &$form, string $userId, string $role): void
    {
        if (!isset($form['permissions']['roles'])) {
            $form['permissions']['roles'] = [];
        }

        // Remove existing role for this user
        $form['permissions']['roles'] = array_filter(
            $form['permissions']['roles'],
            fn($r) => !isset($r['user']) || $r['user'] !== $userId
        );

        // Add new role
        $form['permissions']['roles'][] = [
            'user' => $userId,
            'role' => $role,
        ];
    }

    /**
     * Add a group role to the form
     */
    public function addGroupRole(array &$form, string $groupId, string $role): void
    {
        if (!isset($form['permissions']['roles'])) {
            $form['permissions']['roles'] = [];
        }

        // Remove existing role for this group
        $form['permissions']['roles'] = array_filter(
            $form['permissions']['roles'],
            fn($r) => !isset($r['group']) || $r['group'] !== $groupId
        );

        // Add new role
        $form['permissions']['roles'][] = [
            'group' => $groupId,
            'role' => $role,
        ];
    }

    /**
     * Set public access role
     */
    public function setPublicRole(array &$form, ?string $role): void
    {
        if (!isset($form['permissions']['roles'])) {
            $form['permissions']['roles'] = [];
        }

        // Remove existing public role
        $form['permissions']['roles'] = array_filter(
            $form['permissions']['roles'],
            fn($r) => !isset($r['type']) || $r['type'] !== 'public'
        );

        // Add new public role if specified
        if ($role !== null) {
            $form['permissions']['roles'][] = [
                'type' => 'public',
                'role' => $role,
            ];
        }
    }

    /**
     * Remove a user's role from the form
     */
    public function removeUserRole(array &$form, string $userId): void
    {
        $form['permissions']['roles'] = array_filter(
            $form['permissions']['roles'] ?? [],
            fn($r) => !isset($r['user']) || $r['user'] !== $userId
        );
    }

    /**
     * Remove a group's role from the form
     */
    public function removeGroupRole(array &$form, string $groupId): void
    {
        $form['permissions']['roles'] = array_filter(
            $form['permissions']['roles'] ?? [],
            fn($r) => !isset($r['group']) || $r['group'] !== $groupId
        );
    }
}
