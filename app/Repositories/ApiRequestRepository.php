<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\ApiRequest;
use App\Services\WorkspaceAccess;

class ApiRequestRepository extends Repository
{
    public static function model()
    {
        return ApiRequest::class;
    }

    public static function forCollection(int $collectionId)
    {
        return self::query()->where('collection_id', $collectionId)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** One request whose collection's workspace the user owns or is a member of. */
    public static function findForUser(int $id, int $userId): ?ApiRequest
    {
        $request = self::query()->where('id', $id)->first();

        if ($request === null) {
            return null;
        }

        $workspaceIds = WorkspaceAccess::accessibleWorkspaceIds($userId);
        $workspaceId = $request->collection?->workspace_id;

        return $workspaceId !== null && in_array($workspaceId, $workspaceIds, true) ? $request : null;
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
