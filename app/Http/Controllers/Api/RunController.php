<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRunRequest;
use App\Models\Collection;
use App\Models\Run;
use App\Repositories\CollectionRepository;
use App\Repositories\EnvironmentRepository;
use App\Repositories\RunRepository;
use App\Services\ActivityService;
use App\Services\CollectionRunner;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class RunController extends Controller
{
    /**
     * Run a collection (or one folder of it) synchronously and return the
     * finished run with its per-request results.
     *
     * POST /collections/{collection}/run
     */
    public function store(StoreRunRequest $request, Collection $collection, CollectionRunner $runner): JsonResponse
    {
        $user = $request->user();
        // Any role may run a collection in a workspace they can see
        // (running is a send, not an edit).
        $collection = CollectionRepository::findForUser($collection->id, $user->id);
        abort_unless($collection, 404, 'Collection not found.');

        $data = $request->validated();

        $folderId = $data['folder_id'] ?? null;
        if ($folderId !== null) {
            $folder = $collection->folders()->where('id', $folderId)->first();
            abort_unless($folder, 404, 'Folder not found.');
        }

        $environment = null;
        if (! empty($data['environment_id'])) {
            $environment = EnvironmentRepository::findForUser((int) $data['environment_id'], $user->id);
            abort_unless($environment, 404, 'Environment not found.');
            abort_unless(
                $environment->workspace_id === $collection->workspace_id,
                422,
                'Environment belongs to a different workspace.',
            );
        }

        $run = RunRepository::create([
            'user_id' => $user->id,
            'workspace_id' => $collection->workspace_id,
            'collection_id' => $collection->id,
            'folder_id' => $folderId,
            'environment_id' => $environment?->id,
            'iterations' => $data['iterations'] ?? 1,
            // The Runner UI sends `delay`; `delay_ms` is accepted too.
            'delay_ms' => $data['delay_ms'] ?? $data['delay'] ?? 0,
            'status' => 'running',
            'summary' => null,
        ]);

        try {
            $runner->run($run, $user, [
                'stop_on_error' => $data['stop_on_error'] ?? false,
                'data' => $data['data'] ?? [],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Run failed: '.$e->getMessage(),
                'data' => $this->present($run->fresh()),
            ], 500);
        }

        ActivityService::log(
            $collection->workspace, $user, 'run', $run,
            "Ran collection {$collection->name}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Run finished.',
            'data' => $this->present($run->fresh()),
        ]);
    }

    /** GET /runs — latest runs, newest first. */
    public function index(Request $request): JsonResponse
    {
        $collectionId = $request->query('collection_id');

        $runs = RunRepository::forUser(
            $request->user()->id,
            $collectionId !== null ? (int) $collectionId : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Runs retrieved.',
            'data' => $runs->map(fn (Run $run) => $this->present($run->load('results.request'))),
        ]);
    }

    /** GET /runs/{run} — one run with its per-request results. */
    public function show(Run $run): JsonResponse
    {
        abort_unless(RunRepository::findForUser($run->id, auth()->id()), 404, 'Run not found.');

        return response()->json([
            'success' => true,
            'message' => 'Run retrieved.',
            'data' => $this->present($run->load('results.request')),
        ]);
    }

    /**
     * Shape a run for the Runner UI: `name` / `collection_name` for the
     * history list, and per-request rows with the HTTP status under
     * `status` (the stored outcome — passed/failed/error — is `outcome`).
     */
    protected function present(Run $run): array
    {
        $collectionName = $run->collection?->name;

        $results = ($run->relationLoaded('results') ? $run->results : $run->results()->with('request')->get())
            ->map(function ($result) {
                $apiRequest = $result->request;

                return [
                    'id' => $result->id,
                    'request_id' => $result->request_id,
                    'iteration' => $result->iteration,
                    'method' => $apiRequest?->method ?? 'GET',
                    'name' => $apiRequest?->name,
                    'request_name' => $apiRequest?->name,
                    'url' => $apiRequest?->url,
                    // HTTP status, as the Runner table expects.
                    'status' => $result->status_code,
                    'status_code' => $result->status_code,
                    // Stored outcome: passed | failed | error.
                    'outcome' => $result->status,
                    'time_ms' => $result->time_ms,
                    'size' => $result->size,
                    'test_results' => $result->test_results ?? [],
                    'error' => $result->error,
                ];
            })->values()->all();

        return array_merge($run->toArray(), [
            'name' => ($collectionName ?? 'Run').' run',
            'collection_name' => $collectionName,
            'results' => $results,
        ]);
    }
}
