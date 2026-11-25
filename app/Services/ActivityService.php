<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Repositories\WorkspaceActivityRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Small activity log for team workspaces. Controllers call this after
 * a successful change; a failed action is never logged.
 */
class ActivityService
{
    public static function log(
        Workspace $workspace,
        ?User $user,
        string $action,
        ?Model $subject,
        string $description
    ): void {
        WorkspaceActivityRepository::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
        ]);
    }

    public static function created(Workspace $workspace, ?User $user, Model $subject, string $label): void
    {
        self::log($workspace, $user, 'created', $subject, "Created {$label}.");
    }

    public static function updated(Workspace $workspace, ?User $user, Model $subject, string $label): void
    {
        self::log($workspace, $user, 'updated', $subject, "Updated {$label}.");
    }

    public static function deleted(Workspace $workspace, ?User $user, Model $subject, string $label): void
    {
        self::log($workspace, $user, 'deleted', $subject, "Deleted {$label}.");
    }
}
