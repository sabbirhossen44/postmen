<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\WorkspaceMember;

class WorkspaceMemberRepository extends Repository
{
    public static function model()
    {
        return WorkspaceMember::class;
    }

    public static function forWorkspace(int $workspaceId)
    {
        return self::query()->where('workspace_id', $workspaceId)
            ->with('user')->orderBy('created_at')->get();
    }

    public static function findMember(int $workspaceId, int $userId): ?WorkspaceMember
    {
        return self::query()->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)->first();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
