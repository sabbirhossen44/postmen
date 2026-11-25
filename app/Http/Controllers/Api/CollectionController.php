<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForkCollectionRequest;
use App\Http\Requests\ImportCollectionRequest;
use App\Http\Requests\StoreCollectionRequest;
use App\Http\Requests\UpdateCollectionRequest;
use App\Models\Collection;
use App\Repositories\CollectionRepository;
use App\Repositories\WorkspaceRepository;
use App\Services\ActivityService;
use App\Services\CollectionDuplicator;
use App\Services\CollectionExporter;
use App\Services\CollectionImporter;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->query('workspace_id'), auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');

        return response()->json([
            'success' => true,
            'message' => 'Collections retrieved.',
            'data' => CollectionRepository::forWorkspace($workspace->id),
        ]);
    }

    public function store(StoreCollectionRequest $request): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->workspace_id, auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::EDIT);

        $collection = CollectionRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        ActivityService::created($workspace, $request->user(), $collection, "collection {$collection->name}");

        return response()->json([
            'success' => true,
            'message' => 'Collection created.',
            'data' => $collection,
        ], 201);
    }

    public function show(Collection $collection): JsonResponse
    {
        WorkspaceAccess::requireCollectionView(auth()->user(), $collection);

        $collection->load(['folders.children.requests', 'folders.requests', 'requests']);

        // Flat shape: the collection itself carries folders + requests,
        // plus the nested tree the sidebar can use as a fallback.
        return response()->json([
            'success' => true,
            'message' => 'Collection retrieved.',
            'data' => array_merge($collection->toArray(), [
                'tree' => $this->tree($collection),
            ]),
        ]);
    }

    public function update(UpdateCollectionRequest $request, Collection $collection): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $collection);
        $this->abortIfStale($collection, $request->validated('updated_at'));

        $data = $request->validated();
        unset($data['updated_at']);
        $data['updated_by'] = auth()->id();

        CollectionRepository::update($collection, $data);
        ActivityService::updated($collection->workspace, $request->user(), $collection, "collection {$collection->name}");

        return response()->json([
            'success' => true,
            'message' => 'Collection updated.',
            'data' => $collection->fresh(),
        ]);
    }

    public function destroy(Collection $collection): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $collection);

        $workspace = $collection->workspace;
        $name = $collection->name;
        CollectionRepository::delete($collection->id);
        ActivityService::deleted($workspace, auth()->user(), $collection, "collection {$name}");

        return response()->json([
            'success' => true,
            'message' => 'Collection deleted.',
            'data' => null,
        ]);
    }

    /** Deep-copy the collection (folders + requests) into a workspace. */
    public function fork(ForkCollectionRequest $request, Collection $collection, CollectionDuplicator $duplicator): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $collection);

        $workspaceId = $request->filled('workspace_id')
            ? (int) $request->workspace_id
            : $collection->workspace_id;

        $workspace = WorkspaceRepository::findForUser($workspaceId, auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::EDIT);

        $copy = $duplicator->duplicate($collection, $workspace->id, auth()->id(), ' (fork)');
        ActivityService::created($workspace, $request->user(), $copy, "collection {$copy->name} (forked)");

        return response()->json([
            'success' => true,
            'message' => 'Collection forked.',
            'data' => $copy,
        ], 201);
    }

    /** Deep-copy the collection inside its own workspace. */
    public function duplicate(Collection $collection, CollectionDuplicator $duplicator): JsonResponse
    {
        WorkspaceAccess::requireCollectionEdit(auth()->user(), $collection);

        $copy = $duplicator->duplicate($collection, $collection->workspace_id, auth()->id(), ' (copy)');
        ActivityService::created($collection->workspace, auth()->user(), $copy, "collection {$copy->name} (duplicated)");

        return response()->json([
            'success' => true,
            'message' => 'Collection duplicated.',
            'data' => $copy,
        ], 201);
    }

    /** Read-only documentation payload: every request with its details. */
    public function documentation(Collection $collection): JsonResponse
    {
        WorkspaceAccess::requireCollectionView(auth()->user(), $collection);

        $collection->load(['folders.children.requests', 'folders.requests', 'requests']);

        return response()->json([
            'success' => true,
            'message' => 'Collection documentation retrieved.',
            'data' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'description' => $collection->description,
                'tree' => $this->tree($collection),
            ],
        ]);
    }

    public function export(Collection $collection): JsonResponse
    {
        WorkspaceAccess::requireCollectionView(auth()->user(), $collection);

        return response()->json([
            'success' => true,
            'message' => 'Collection exported.',
            'data' => (new CollectionExporter)->export($collection),
        ]);
    }

    public function import(ImportCollectionRequest $request, CollectionImporter $importer): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->workspace_id, auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::EDIT);

        $json = $request->filled('json')
            ? $request->input('json')
            : json_decode($request->file('file')->getContent(), true);

        if (! is_array($json)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid collection JSON.',
                'data' => null,
            ], 422);
        }

        try {
            $collection = $importer->import($json, $workspace, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }

        ActivityService::created($workspace, $request->user(), $collection, "collection {$collection->name} (imported)");

        return response()->json([
            'success' => true,
            'message' => 'Collection imported.',
            'data' => $collection,
        ], 201);
    }

    /** Nested folder tree with requests attached at each level. */
    protected function tree(Collection $collection): array
    {
        $folders = $collection->folders->keyBy('id');
        $requests = $collection->requests->groupBy('folder_id');

        $build = function (?int $parentId) use (&$build, $folders, $requests): array {
            $nodes = [];

            foreach ($folders->where('parent_id', $parentId)->sortBy('sort_order') as $folder) {
                $nodes[] = [
                    'type' => 'folder',
                    'folder' => $folder,
                    'children' => $build($folder->id),
                    'requests' => array_values(($requests->get($folder->id) ?? collect())->sortBy('sort_order')->all()),
                ];
            }

            return $nodes;
        };

        return [
            'folders' => $build(null),
            'requests' => array_values(($requests->get(null) ?? collect())->sortBy('sort_order')->all()),
        ];
    }

    /**
     * Optimistic locking: when the client sends the updated_at it last
     * saw and the stored row is newer, reject with 409 and the fresh row.
     */
    protected function abortIfStale(Collection $collection, ?string $clientUpdatedAt): void
    {
        if ($clientUpdatedAt === null) {
            return;
        }

        if ($collection->updated_at->gt($clientUpdatedAt)) {
            abort(response()->json([
                'success' => false,
                'message' => 'This collection was changed by someone else. Reload and try again.',
                'data' => $collection->fresh(),
            ], 409));
        }
    }
}
