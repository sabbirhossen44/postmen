<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\StoredCookie;

class CookieJarRepository extends Repository
{
    public static function model()
    {
        return StoredCookie::class;
    }

    public static function forUserWorkspace(int $userId, int $workspaceId)
    {
        return self::query()->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->orderBy('domain')->orderBy('name')->get();
    }

    public static function findForUser(int $id, int $userId): ?StoredCookie
    {
        return self::query()->where('id', $id)->where('user_id', $userId)->first();
    }

    /** One row per (user, workspace, domain, name, path). */
    public static function upsertCookie(array $attributes): StoredCookie
    {
        return self::query()->updateOrCreate(
            [
                'user_id' => $attributes['user_id'],
                'workspace_id' => $attributes['workspace_id'],
                'domain' => $attributes['domain'],
                'name' => $attributes['name'],
                'path' => $attributes['path'] ?? '/',
            ],
            $attributes,
        );
    }

    public static function deleteExpired(int $userId, int $workspaceId): int
    {
        return self::query()->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }

    public static function clearForWorkspace(int $userId, int $workspaceId): int
    {
        return self::query()->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)->delete();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
