<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\Environment;

class EnvironmentRepository extends Repository
{
    public static function model()
    {
        return Environment::class;
    }

    public static function forWorkspace(int $workspaceId)
    {
        return self::query()->where('workspace_id', $workspaceId)->orderBy('name')->get();
    }

    public static function activeForWorkspace(int $workspaceId): ?Environment
    {
        return self::query()->where('workspace_id', $workspaceId)
            ->where('is_active', true)->first();
    }

    public static function deactivateOthers(int $workspaceId, int $exceptId): void
    {
        self::query()->where('workspace_id', $workspaceId)
            ->where('id', '!=', $exceptId)->update(['is_active' => false]);
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
