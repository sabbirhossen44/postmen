<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFolderRequest;
use App\Models\Collection;
use App\Models\Folder;
use App\Repositories\CollectionRepository;
use App\Repositories\FolderRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function store(StoreFolderRequest $request): JsonResponse
    {
        $collection = $this->ownedCollection((int) $request->collection_id);

        $folder = FolderRepository::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Folder created.',
            'data' => $folder,
        ], 201);
    }

    public function update(Request $request, Folder $folder): JsonResponse
    {
        $this->ensureOwner($folder);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        FolderRepository::update($folder, $data);

        return response()->json([
            'success' => true,
            'message' => 'Folder updated.',
            'data' => $folder->fresh(),
        ]);
    }

    public function destroy(Folder $folder): JsonResponse
    {
        $this->ensureOwner($folder);
        FolderRepository::delete($folder->id);

        return response()->json([
            'success' => true,
            'message' => 'Folder deleted.',
            'data' => null,
        ]);
    }

    protected function ownedCollection(int $collectionId): Collection
    {
        $collection = CollectionRepository::findForUser($collectionId, auth()->id());
        abort_unless($collection, 404, 'Collection not found.');

        return $collection;
    }

    protected function ensureOwner(Folder $folder): void
    {
        abort_unless($folder->collection->user_id === auth()->id(), 403, 'You do not own this folder.');
    }
}
