<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportCollectionRequest;
use App\Http\Requests\StoreCollectionRequest;
use App\Http\Requests\UpdateCollectionRequest;
use App\Models\Collection;
use App\Repositories\CollectionRepository;
use App\Repositories\WorkspaceRepository;
use App\Services\CollectionExporter;
use App\Services\CollectionImporter;
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

        $collection = CollectionRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Collection created.',
            'data' => $collection,
        ], 201);
    }

    public function show(Collection $collection): JsonResponse
    {
        $this->ensureOwner($collection);

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
        $this->ensureOwner($collection);
        CollectionRepository::update($collection, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Collection updated.',
            'data' => $collection->fresh(),
        ]);
    }

    public function destroy(Collection $collection): JsonResponse
    {
        $this->ensureOwner($collection);
        CollectionRepository::delete($collection->id);

        return response()->json([
            'success' => true,
            'message' => 'Collection deleted.',
            'data' => null,
        ]);
    }

    public function export(Collection $collection): JsonResponse
    {
        $this->ensureOwner($collection);

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

    protected function ensureOwner(Collection $collection): void
    {
        abort_unless($collection->user_id === auth()->id(), 403, 'You do not own this collection.');
    }
}
