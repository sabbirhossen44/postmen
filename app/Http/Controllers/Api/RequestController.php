<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MoveRequestRequest;
use App\Http\Requests\StoreExampleRequest;
use App\Http\Requests\StoreRequestRequest;
use App\Http\Requests\UpdateRequestRequest;
use App\Models\ApiRequest;
use App\Models\Folder;
use App\Repositories\ApiRequestRepository;
use App\Repositories\CollectionRepository;
use App\Services\ActivityService;
use App\Services\CollectionDuplicator;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;

class RequestController extends Controller
{
    public function store(StoreRequestRequest $request): JsonResponse
    {
        $collection = $this->editableCollection((int) $request->collection_id);

        $apiRequest = ApiRequestRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        ActivityService::created($collection->workspace, $request->user(), $apiRequest, "request {$apiRequest->name}");

        return response()->json([
            'success' => true,
            'message' => 'Request created.',
            'data' => $apiRequest,
        ], 201);
    }

    public function show(ApiRequest $apiRequest): JsonResponse
    {
        WorkspaceAccess::requireCollectionView(auth()->user(), $apiRequest->collection);

        return response()->json([
            'success' => true,
            'message' => 'Request retrieved.',
            'data' => $apiRequest->load(['collection', 'folder', 'bodyFile']),
        ]);
    }

    public function update(UpdateRequestRequest $request, ApiRequest $apiRequest): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $apiRequest->collection);
        $this->abortIfStale($apiRequest, $request->validated('updated_at'));

        $data = $request->validated();
        unset($data['updated_at']);
        $data['updated_by'] = auth()->id();

        ApiRequestRepository::update($apiRequest, $data);
        ActivityService::updated($apiRequest->collection->workspace, $request->user(), $apiRequest, "request {$apiRequest->name}");

        return response()->json([
            'success' => true,
            'message' => 'Request updated.',
            'data' => $apiRequest->fresh(),
        ]);
    }

    public function destroy(ApiRequest $apiRequest): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $apiRequest->collection);

        $workspace = $apiRequest->collection->workspace;
        $name = $apiRequest->name;
        ApiRequestRepository::delete($apiRequest->id);
        ActivityService::deleted($workspace, auth()->user(), $apiRequest, "request {$name}");

        return response()->json([
            'success' => true,
            'message' => 'Request deleted.',
            'data' => null,
        ]);
    }

    public function duplicate(ApiRequest $apiRequest, CollectionDuplicator $duplicator): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $apiRequest->collection);

        $copy = $duplicator->duplicateRequest($apiRequest, auth()->id());

        ActivityService::created($apiRequest->collection->workspace, auth()->user(), $copy, "request {$copy->name}");

        return response()->json([
            'success' => true,
            'message' => 'Request duplicated.',
            'data' => $copy,
        ], 201);
    }

    /** Move a request to another collection and/or folder. */
    public function move(MoveRequestRequest $request, ApiRequest $apiRequest): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $apiRequest->collection);

        $targetCollectionId = $request->filled('collection_id')
            ? (int) $request->collection_id
            : $apiRequest->collection_id;
        $targetFolderId = $request->has('folder_id') ? $request->input('folder_id') : $apiRequest->folder_id;

        $this->ownedCollection($targetCollectionId);

        if ($targetFolderId !== null) {
            $folder = Folder::query()
                ->where('id', (int) $targetFolderId)
                ->where('collection_id', $targetCollectionId)
                ->first();
            abort_unless($folder, 422, 'Folder does not belong to the target collection.');
        }

        ApiRequestRepository::update($apiRequest, [
            'collection_id' => $targetCollectionId,
            'folder_id' => $targetFolderId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request moved.',
            'data' => $apiRequest->fresh(),
        ]);
    }

    /** Store the last response as a named example on the request. */
    public function storeExample(StoreExampleRequest $request, ApiRequest $apiRequest): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $apiRequest->collection);

        $examples = $apiRequest->examples ?? [];
        $examples[] = [
            ...$request->validated(),
            'saved_at' => now()->toISOString(),
        ];
        ApiRequestRepository::update($apiRequest, ['examples' => $examples]);

        return response()->json([
            'success' => true,
            'message' => 'Example saved.',
            'data' => $apiRequest->fresh()->examples,
        ], 201);
    }

    public function destroyExample(ApiRequest $apiRequest, int $index): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $apiRequest->collection);

        $examples = $apiRequest->examples ?? [];
        abort_unless(array_key_exists($index, $examples), 404, 'Example not found.');
        array_splice($examples, $index, 1);
        ApiRequestRepository::update($apiRequest, ['examples' => array_values($examples)]);

        return response()->json([
            'success' => true,
            'message' => 'Example deleted.',
            'data' => $apiRequest->fresh()->examples,
        ]);
    }

    /** A collection the user may edit, or 404/403. */
    protected function ownedCollection(int $collectionId)
    {
        return $this->editableCollection($collectionId);
    }

    /** A collection in a workspace the user may edit, or 404. */
    protected function editableCollection(int $collectionId)
    {
        $collection = CollectionRepository::findForUser($collectionId, auth()->id());
        abort_unless($collection, 404, 'Collection not found.');
        WorkspaceAccess::requireCan(auth()->user(), $collection->workspace, WorkspaceAccess::EDIT);

        return $collection;
    }

    /**
     * Optimistic locking: when the client sends the updated_at it last
     * saw and the stored row is newer, reject with 409 and the fresh row.
     */
    protected function abortIfStale(ApiRequest $apiRequest, ?string $clientUpdatedAt): void
    {
        if ($clientUpdatedAt === null) {
            return;
        }

        if ($apiRequest->updated_at->gt($clientUpdatedAt)) {
            abort(response()->json([
                'success' => false,
                'message' => 'This request was changed by someone else. Reload and try again.',
                'data' => $apiRequest->fresh(),
            ], 409));
        }
    }
}
