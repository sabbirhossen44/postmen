<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\Folder;
use Illuminate\Support\Facades\DB;

/** Deep-copies a collection (folders + requests) under a new id. */
class CollectionDuplicator
{
    /**
     * Append $suffix to a name, without stacking it: duplicating
     * "API (copy)" gives "API (copy)", not "API (copy) (copy)".
     */
    protected function withSuffix(string $name, string $suffix): string
    {
        $base = preg_replace('/\s*\((copy|fork)\)$/', '', $name) ?? $name;

        return $base.$suffix;
    }

    /**
     * Duplicate $source into $workspaceId, owned by $userId.
     * $nameSuffix is appended to the copy's name ('' keeps the name).
     */
    public function duplicate(Collection $source, int $workspaceId, int $userId, string $nameSuffix = ''): Collection
    {
        $source->loadMissing(['folders', 'requests']);

        return DB::transaction(function () use ($source, $workspaceId, $userId, $nameSuffix) {
            $copy = $source->replicate()->fill([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'created_by' => $userId,
                'updated_by' => $userId,
                'name' => $nameSuffix === '' ? $source->name : $this->withSuffix($source->name, $nameSuffix),
            ]);
            $copy->save();

            // Folders first, shallowest first, keeping an id map so
            // nested folders and requests point at the new folders.
            $folderMap = [];
            $byId = $source->folders->keyBy('id');
            $depth = function (Folder $folder) use (&$depth, $byId): int {
                $d = 0;
                $current = $folder;
                while ($current->parent_id && $byId->has($current->parent_id) && $d <= 10) {
                    $d++;
                    $current = $byId->get($current->parent_id);
                }

                return $d;
            };
            $folders = $source->folders->sortBy(fn (Folder $folder) => $depth($folder));

            foreach ($folders as $folder) {
                $folderCopy = $folder->replicate()->fill([
                    'collection_id' => $copy->id,
                    'parent_id' => $folder->parent_id ? ($folderMap[$folder->parent_id] ?? null) : null,
                ]);
                $folderCopy->save();
                $folderMap[$folder->id] = $folderCopy->id;
            }

            foreach ($source->requests as $request) {
                $requestCopy = $request->replicate()->fill([
                    'collection_id' => $copy->id,
                    'user_id' => $userId,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'folder_id' => $request->folder_id ? ($folderMap[$request->folder_id] ?? null) : null,
                ]);
                $requestCopy->save();
            }

            return $copy->fresh()->load(['folders', 'requests']);
        });
    }

    /** Duplicate one request inside its own collection, new id, "(copy)" name. */
    public function duplicateRequest(ApiRequest $request, ?int $userId = null): ApiRequest
    {
        $userId ??= $request->user_id;

        $copy = $request->replicate()->fill([
            'name' => $this->withSuffix($request->name, ' (copy)'),
            'user_id' => $userId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $copy->save();

        return $copy->fresh();
    }
}
