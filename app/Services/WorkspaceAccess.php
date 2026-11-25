<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * Team workspace permissions. The workspace owner is stored on the
 * workspace itself (`user_id`); everyone else is a WorkspaceMember.
 * A personal workspace has no members — only its owner can access it.
 *
 * Roles, strongest first: owner > admin > editor > viewer.
 */
class WorkspaceAccess
{
    public const VIEW = 'view';
    public const SEND = 'send';
    public const EDIT = 'edit';
    public const MANAGE = 'manage';
    public const DELETE_WORKSPACE = 'delete_workspace';
    public const TRANSFER = 'transfer';

    /** Which roles may perform each action. */
    protected const PERMISSIONS = [
        self::VIEW => ['owner', 'admin', 'editor', 'viewer'],
        self::SEND => ['owner', 'admin', 'editor', 'viewer'],
        self::EDIT => ['owner', 'admin', 'editor'],
        self::MANAGE => ['owner', 'admin'],
        self::DELETE_WORKSPACE => ['owner'],
        self::TRANSFER => ['owner'],
    ];

    /** The user's role in a workspace, or null when they have no access. */
    public static function role(?User $user, ?Workspace $workspace): ?string
    {
        if ($user === null || $workspace === null) {
            return null;
        }

        if ((int) $workspace->user_id === (int) $user->id) {
            return 'owner';
        }

        if (! $workspace->isTeam()) {
            return null;
        }

        $member = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->first();

        return $member?->role;
    }

    public static function can(?User $user, ?Workspace $workspace, string $action): bool
    {
        $role = self::role($user, $workspace);

        return $role !== null && in_array($role, self::PERMISSIONS[$action] ?? [], true);
    }

    public static function canAccess(?User $user, ?Workspace $workspace): bool
    {
        return self::role($user, $workspace) !== null;
    }

    /** Abort with 403 unless the user may perform the action. */
    public static function requireCan(?User $user, ?Workspace $workspace, string $action): void
    {
        abort_unless(
            self::can($user, $workspace, $action),
            403,
            'You do not have permission to do that in this workspace.'
        );
    }

    /** Abort with 404 unless the user can see the workspace at all. */
    public static function requireAccess(?User $user, ?Workspace $workspace): void
    {
        abort_unless(self::canAccess($user, $workspace), 404, 'Workspace not found.');
    }

    /** Ids of every workspace the user owns or is a member of. */
    public static function accessibleWorkspaceIds(int $userId): array
    {
        $owned = Workspace::query()->where('user_id', $userId)->pluck('id')->all();
        $member = WorkspaceMember::query()->where('user_id', $userId)->pluck('workspace_id')->all();

        return array_values(array_unique(array_merge($owned, $member)));
    }

    /** The workspace of a collection, or null. */
    public static function workspaceForCollection(?Collection $collection): ?Workspace
    {
        return $collection?->workspace;
    }

    /** The workspace of an environment, or null. */
    public static function workspaceForEnvironment(?Environment $environment): ?Workspace
    {
        return $environment?->workspace;
    }

    /** Abort with 403 unless the user may edit inside a collection's workspace. */
    public static function requireCollectionEdit(?User $user, ?Collection $collection): void
    {
        $workspace = self::workspaceForCollection($collection);
        abort_unless($workspace, 404, 'Collection not found.');
        self::requireCan($user, $workspace, self::EDIT);
    }

    /** Abort with 403 unless the user may view inside a collection's workspace. */
    public static function requireCollectionView(?User $user, ?Collection $collection): void
    {
        $workspace = self::workspaceForCollection($collection);
        abort_unless($workspace, 404, 'Collection not found.');
        self::requireCan($user, $workspace, self::VIEW);
    }
}
