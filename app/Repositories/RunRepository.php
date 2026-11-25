<?php

namespace App\Repositories;

use App\Models\Run;
use App\Services\WorkspaceAccess;
use Arafat\LaravelRepository\Repository;

class RunRepository extends Repository
{
    public static function model()
    {
        return Run::class;
    }

    public static function forUser(int $userId, ?int $collectionId = null)
    {
        $query = self::query()
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereIn('workspace_id', WorkspaceAccess::accessibleWorkspaceIds($userId));
            })->latest();

        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        return $query->limit(50)->get();
    }

    public static function findForUser(int $id, int $userId): ?Run
    {
        return self::query()->where('id', $id)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereIn('workspace_id', WorkspaceAccess::accessibleWorkspaceIds($userId));
            })->first();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
