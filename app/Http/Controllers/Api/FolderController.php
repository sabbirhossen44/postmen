<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFolderRequest;
use App\Models\Collection;
use App\Models\Folder;
use App\Repositories\CollectionRepository;
use App\Repositories\FolderRepository;
use App\Services\ActivityService;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function store(StoreFolderRequest $request): JsonResponse
    {
        $collection = $this->editableCollection((int) $request->collection_id);

        $folder = FolderRepository::create($request->validated());
        ActivityService::created($collection->workspace, $request->user(), $folder, "folder {$folder->name}");

        return response()->json([
            'success' => true,
            'message' => 'Folder created.',
            'data' => $folder,
        ], 201);
    }

    public function update(Request $request, Folder $folder): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $folder->collection);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        FolderRepository::update($folder, $data);
        ActivityService::updated($folder->collection->workspace, $request->user(), $folder, "folder {$folder->name}");

        return response()->json([
            'success' => true,
            'message' => 'Folder updated.',
            'data' => $folder->fresh(),
        ]);
    }

    public function destroy(Folder $folder): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $folder->collection);

        $workspace = $folder->collection->workspace;
        $name = $folder->name;
        FolderRepository::delete($folder->id);
        ActivityService::deleted($workspace, auth()->user(), $folder, "folder {$name}");

        return response()->json([
            'success' => true,
            'message' => 'Folder deleted.',
            'data' => null,
        ]);
    }

    /** A collection in a workspace the user may edit, or 404. */
    protected function editableCollection(int $collectionId): Collection
    {
        $collection = CollectionRepository::findForUser($collectionId, auth()->id());
        abort_unless($collection, 404, 'Collection not found.');
        WorkspaceAccess::requireCan(auth()->user(), $collection->workspace, WorkspaceAccess::EDIT);

        return $collection;
    }
}
