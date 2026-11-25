<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportCurlRequest;
use App\Http\Requests\ImportOpenApiRequest;
use App\Repositories\CollectionRepository;
use App\Repositories\WorkspaceRepository;
use App\Services\ActivityService;
use App\Services\CurlImporter;
use App\Services\OpenApiImporter;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ImportController extends Controller
{
    public function importCurl(ImportCurlRequest $request, CurlImporter $importer): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->workspace_id, auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::EDIT);

        $collection = null;

        if ($request->filled('collection_id')) {
            $collection = CollectionRepository::findForUser((int) $request->collection_id, auth()->id());
            abort_unless($collection && $collection->workspace_id === $workspace->id, 404, 'Collection not found.');
        }

        try {
            $result = $importer->import($request->string('curl'), $workspace, $request->user(), $collection);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }

        ActivityService::created($workspace, $request->user(), $result['collection'], "collection {$result['collection']->name} (imported)");

        return response()->json([
            'success' => true,
            'message' => 'cURL command(s) imported.',
            'data' => [
                'collection' => $result['collection'],
                'requests' => $result['requests'],
            ],
        ], 201);
    }

    public function importOpenApi(ImportOpenApiRequest $request, OpenApiImporter $importer): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->workspace_id, auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::EDIT);

        $spec = $request->filled('json')
            ? $request->input('json')
            : ($request->filled('content')
                ? $request->string('content')
                : $request->file('file')->getContent());

        try {
            $collection = $importer->import($spec, $workspace, $request->user());
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
            'message' => 'OpenAPI document imported.',
            'data' => $collection->load(['folders', 'requests']),
        ], 201);
    }
}
