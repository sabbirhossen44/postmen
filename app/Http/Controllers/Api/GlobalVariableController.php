<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGlobalVariableRequest;
use App\Http\Requests\UpdateGlobalVariableRequest;
use App\Models\GlobalVariable;
use App\Repositories\GlobalVariableRepository;
use App\Repositories\WorkspaceRepository;
use App\Services\ActivityService;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalVariableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspaceId = $request->query('workspace_id') !== null
            ? (int) $request->query('workspace_id')
            : null;

        if ($workspaceId !== null) {
            abort_unless(
                WorkspaceRepository::findForUser($workspaceId, auth()->id()),
                404,
                'Workspace not found.'
            );
        }

        $globals = GlobalVariableRepository::forAccessibleUser(auth()->id(), $workspaceId)
            ->map(fn (GlobalVariable $global) => $global->toDisplayArray());

        return response()->json([
            'success' => true,
            'message' => 'Global variables retrieved.',
            'data' => $globals,
        ]);
    }

    public function store(StoreGlobalVariableRequest $request): JsonResponse
    {
        $workspace = null;

        if ($request->filled('workspace_id')) {
            $workspace = WorkspaceRepository::findForUser((int) $request->workspace_id, auth()->id());
            abort_unless($workspace, 404, 'Workspace not found.');
            WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::EDIT);
        }

        $global = GlobalVariableRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        if ($workspace !== null) {
            ActivityService::created($workspace, $request->user(), $global, "global variable {$global->key}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Global variable created.',
            'data' => $global->toDisplayArray(),
        ], 201);
    }

    /**
     * Show one global. Secret values stay hidden unless the owner
     * explicitly asks (?include_secrets=1) for the edit fetch.
     */
    public function show(Request $request, GlobalVariable $globalVariable): JsonResponse
    {
        $this->ensureVisible($globalVariable);

        return response()->json([
            'success' => true,
            'message' => 'Global variable retrieved.',
            'data' => $globalVariable->toDisplayArray($request->boolean('include_secrets')),
        ]);
    }

    public function update(UpdateGlobalVariableRequest $request, GlobalVariable $globalVariable): JsonResponse
    {
        $this->ensureEditable($globalVariable);

        GlobalVariableRepository::update($globalVariable, $request->validated());

        if ($globalVariable->workspace !== null) {
            ActivityService::updated($globalVariable->workspace, $request->user(), $globalVariable, "global variable {$globalVariable->key}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Global variable updated.',
            'data' => $globalVariable->fresh()->toDisplayArray(),
        ]);
    }

    public function destroy(GlobalVariable $globalVariable): JsonResponse
    {
        $this->ensureEditable($globalVariable);

        $workspace = $globalVariable->workspace;
        $key = $globalVariable->key;
        GlobalVariableRepository::delete($globalVariable->id);

        if ($workspace !== null) {
            ActivityService::deleted($workspace, auth()->user(), $globalVariable, "global variable {$key}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Global variable deleted.',
            'data' => null,
        ]);
    }

    /** A personal global is visible only to its owner; a workspace global to its members. */
    protected function ensureVisible(GlobalVariable $globalVariable): void
    {
        if ($globalVariable->workspace_id === null) {
            abort_unless($globalVariable->user_id === auth()->id(), 403, 'You do not own this global variable.');
        }

        abort_unless(
            GlobalVariableRepository::findAccessible($globalVariable->id, auth()->id()),
            404,
            'Global variable not found.'
        );
    }

    /** Personal globals are owner-only; workspace globals need edit rights. */
    protected function ensureEditable(GlobalVariable $globalVariable): void
    {
        if ($globalVariable->workspace_id === null) {
            abort_unless($globalVariable->user_id === auth()->id(), 403, 'You do not own this global variable.');
        } else {
            WorkspaceAccess::requireCan(auth()->user(), $globalVariable->workspace, WorkspaceAccess::EDIT);
        }
    }
}
