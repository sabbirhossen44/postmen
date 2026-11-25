<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\WorkspaceActivity;

class WorkspaceActivityRepository extends Repository
{
    public static function model()
    {
        return WorkspaceActivity::class;
    }

    public static function forWorkspace(int $workspaceId)
    {
        return self::query()->where('workspace_id', $workspaceId)
            ->with('user:id,name,email')->latest();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
