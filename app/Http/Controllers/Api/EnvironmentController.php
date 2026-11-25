<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportEnvironmentRequest;
use App\Http\Requests\StoreEnvironmentRequest;
use App\Http\Requests\UpdateEnvironmentRequest;
use App\Models\Environment;
use App\Repositories\EnvironmentRepository;
use App\Repositories\WorkspaceRepository;
use App\Services\ActivityService;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnvironmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->query('workspace_id'), auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');

        return response()->json([
            'success' => true,
            'message' => 'Environments retrieved.',
            'data' => EnvironmentRepository::forWorkspace($workspace->id)
                ->map(fn (Environment $environment) => $environment->toDisplayArray()),
        ]);
    }

    public function store(StoreEnvironmentRequest $request): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->workspace_id, auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::EDIT);

        $environment = EnvironmentRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        if ($environment->is_active) {
            EnvironmentRepository::deactivateOthers($workspace->id, $environment->id);
        }

        ActivityService::created($workspace, $request->user(), $environment, "environment {$environment->name}");

        return response()->json([
            'success' => true,
            'message' => 'Environment created.',
            'data' => $environment->toDisplayArray(),
        ], 201);
    }

    /**
     * Show one environment. Secret values stay hidden unless the owner
     * explicitly asks (?include_secrets=1) for the edit fetch.
     */
    public function show(Request $environmentRequest, Environment $environment): JsonResponse
    {
        WorkspaceAccess::requireCan(auth()->user(), $environment->workspace, WorkspaceAccess::VIEW);

        return response()->json([
            'success' => true,
            'message' => 'Environment retrieved.',
            'data' => $environment->toDisplayArray($environmentRequest->boolean('include_secrets')),
        ]);
    }

    public function update(UpdateEnvironmentRequest $request, Environment $environment): JsonResponse
    {
        WorkspaceAccess::requireCan(auth()->user(), $environment->workspace, WorkspaceAccess::EDIT);

        EnvironmentRepository::update($environment, [
            ...$request->validated(),
            'updated_by' => auth()->id(),
        ]);

        if ($environment->fresh()->is_active) {
            EnvironmentRepository::deactivateOthers($environment->workspace_id, $environment->id);
        }

        ActivityService::updated($environment->workspace, $request->user(), $environment, "environment {$environment->name}");

        return response()->json([
            'success' => true,
            'message' => 'Environment updated.',
            'data' => $environment->fresh()->toDisplayArray(),
        ]);
    }

    public function destroy(Environment $environment): JsonResponse
    {
        WorkspaceAccess::requireCan(auth()->user(), $environment->workspace, WorkspaceAccess::EDIT);

        $workspace = $environment->workspace;
        $name = $environment->name;
        EnvironmentRepository::delete($environment->id);
        ActivityService::deleted($workspace, auth()->user(), $environment, "environment {$name}");

        return response()->json([
            'success' => true,
            'message' => 'Environment deleted.',
            'data' => null,
        ]);
    }

    public function activate(Environment $environment): JsonResponse
    {
        WorkspaceAccess::requireCan(auth()->user(), $environment->workspace, WorkspaceAccess::EDIT);

        EnvironmentRepository::deactivateOthers($environment->workspace_id, $environment->id);
        EnvironmentRepository::update($environment, ['is_active' => true, 'updated_by' => auth()->id()]);

        return response()->json([
            'success' => true,
            'message' => 'Environment activated.',
            'data' => $environment->fresh()->toDisplayArray(),
        ]);
    }

    /** Import a Postman environment JSON file ({name, values:[{key,value}]}). */
    public function import(ImportEnvironmentRequest $request): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->workspace_id, auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::EDIT);

        $json = $request->filled('json')
            ? $request->input('json')
            : json_decode($request->file('file')->getContent(), true);

        if (! is_array($json) || empty($json['name']) || ! is_string($json['name'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Postman environment: missing name.',
                'data' => null,
            ], 422);
        }

        $environment = EnvironmentRepository::create([
            'workspace_id' => $workspace->id,
            'user_id' => auth()->id(),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
            'name' => $json['name'],
            'is_active' => false,
            'variables' => $this->toVariables($json['values'] ?? $json['variables'] ?? []),
        ]);

        ActivityService::created($workspace, $request->user(), $environment, "environment {$environment->name} (imported)");

        return response()->json([
            'success' => true,
            'message' => 'Environment imported.',
            'data' => $environment,
        ], 201);
    }

    /** Export as Postman environment JSON v2.1. */
    public function export(Environment $environment): JsonResponse
    {
        WorkspaceAccess::requireCan(auth()->user(), $environment->workspace, WorkspaceAccess::VIEW);

        return response()->json([
            'success' => true,
            'message' => 'Environment exported.',
            'data' => [
                'id' => (string) $environment->id,
                'name' => $environment->name,
                'values' => array_map(fn ($variable) => [
                    'key' => $variable['key'],
                    'value' => (string) ($variable['value'] ?? ''),
                    'type' => 'default',
                    'enabled' => (bool) ($variable['enabled'] ?? true),
                ], $environment->variables ?? []),
                '_postman_variable_scope' => 'environment',
                '_postman_exported_at' => now()->toISOString(),
                '_postman_exported_using' => 'Postmen',
            ],
        ]);
    }

    /** Postman values [{key, value, enabled/disabled}] -> our variables. */
    protected function toVariables(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $variables = [];

        foreach ($values as $value) {
            if (! is_array($value) || ! isset($value['key'])) {
                continue;
            }

            $variables[] = [
                'key' => $value['key'],
                'value' => (string) ($value['value'] ?? ''),
                'enabled' => ! ($value['disabled'] ?? false) && ($value['enabled'] ?? true),
            ];
        }

        return $variables;
    }
}
