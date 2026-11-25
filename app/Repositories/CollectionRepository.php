<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\Collection;

class CollectionRepository extends Repository
{
    public static function model()
    {
        return Collection::class;
    }

    public static function forWorkspace(int $workspaceId)
    {
        return self::query()->where('workspace_id', $workspaceId)->orderBy('name')->get();
    }

    public static function findForUser(int $id, int $userId): ?Collection
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
