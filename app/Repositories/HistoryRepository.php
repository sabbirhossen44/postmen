<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\History;
use App\Services\WorkspaceAccess;

class HistoryRepository extends Repository
{
    public static function model()
    {
        return History::class;
    }

    public static function forUser(int $userId)
    {
        return self::query()->where('user_id', $userId)->latest();
    }

    /**
     * History the user sent, plus history for requests in workspaces
     * they own or are a member of.
     */
    public static function forAccessibleUser(int $userId)
    {
        $workspaceIds = WorkspaceAccess::accessibleWorkspaceIds($userId);

        return self::query()->where('user_id', $userId)
            ->orWhereIn('request_id', function ($query) use ($workspaceIds) {
                $query->select('requests.id')->from('requests')
                    ->join('collections', 'collections.id', '=', 'requests.collection_id')
                    ->whereIn('collections.workspace_id', $workspaceIds);
            })->latest();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
