<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\GlobalVariable;
use App\Services\WorkspaceAccess;

class GlobalVariableRepository extends Repository
{
    public static function model()
    {
        return GlobalVariable::class;
    }

    public static function forUser(int $userId)
    {
        return self::query()->where('user_id', $userId)->orderBy('key')->get();
    }

    /**
     * Globals the user owns, plus globals shared with a workspace they
     * own or are a member of. When $workspaceId is given, only that
     * workspace's globals (that the user can see) are returned.
     */
    public static function forAccessibleUser(int $userId, ?int $workspaceId = null)
    {
        $query = self::query()->orderBy('key');

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId)
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)
                        ->orWhereIn('workspace_id', WorkspaceAccess::accessibleWorkspaceIds($userId));
                });
        } else {
            $query->where('user_id', $userId)
                ->orWhereIn('workspace_id', WorkspaceAccess::accessibleWorkspaceIds($userId));
        }

        return $query->get();
    }

    public static function findForUser(int $id, int $userId): ?GlobalVariable
    {
        return self::query()->where('id', $id)->where('user_id', $userId)->first();
    }

    /** One global the user owns or can see via a shared workspace. */
    public static function findAccessible(int $id, int $userId): ?GlobalVariable
    {
        $global = self::query()->where('id', $id)->first();

        if ($global === null) {
            return null;
        }

        if ($global->user_id === $userId) {
            return $global;
        }

        if ($global->workspace_id !== null
            && in_array($global->workspace_id, WorkspaceAccess::accessibleWorkspaceIds($userId), true)) {
            return $global;
        }

        return null;
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
