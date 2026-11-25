<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\WorkspaceInvitation;

class WorkspaceInvitationRepository extends Repository
{
    public static function model()
    {
        return WorkspaceInvitation::class;
    }

    public static function forWorkspace(int $workspaceId)
    {
        return self::query()->where('workspace_id', $workspaceId)
            ->whereNull('accepted_at')->orderBy('created_at')->get();
    }

    public static function findByToken(string $token): ?WorkspaceInvitation
    {
        return self::query()->where('token', $token)->first();
    }

    public static function findPending(int $workspaceId, string $email): ?WorkspaceInvitation
    {
        return self::query()->where('workspace_id', $workspaceId)
            ->where('email', $email)->whereNull('accepted_at')->first();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
