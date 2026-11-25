<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\Workspace;
use App\Services\WorkspaceAccess;

class WorkspaceRepository extends Repository
{
    public static function model()
    {
        return Workspace::class;
    }

    /** Workspaces the user owns or is a member of. */
    public static function forUser(int $userId)
    {
        return self::query()->whereIn('id', WorkspaceAccess::accessibleWorkspaceIds($userId))
            ->orderBy('name')->get();
    }

    /** One workspace the user owns or is a member of, or null. */
    public static function findForUser(int $id, int $userId): ?Workspace
    {
        return self::query()->where('id', $id)
            ->whereIn('id', WorkspaceAccess::accessibleWorkspaceIds($userId))->first();
    }

    /** One workspace the user owns, or null. */
    public static function findOwned(int $id, int $userId): ?Workspace
    {
        return self::query()->where('id', $id)->where('user_id', $userId)->first();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
