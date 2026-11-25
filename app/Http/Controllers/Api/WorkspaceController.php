<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Models\Workspace;
use App\Repositories\WorkspaceRepository;
use Illuminate\Http\JsonResponse;

class WorkspaceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Workspaces retrieved.',
            'data' => WorkspaceRepository::forUser(auth()->id()),
        ]);
    }

    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $workspace = WorkspaceRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Workspace created.',
            'data' => $workspace,
        ], 201);
    }

    public function show(Workspace $workspace): JsonResponse
    {
        $this->ensureOwner($workspace);

        return response()->json([
            'success' => true,
            'message' => 'Workspace retrieved.',
            'data' => $workspace->load(['media', 'collections', 'environments']),
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        $this->ensureOwner($workspace);
        WorkspaceRepository::update($workspace, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Workspace updated.',
            'data' => $workspace->fresh(),
        ]);
    }

    public function destroy(Workspace $workspace): JsonResponse
    {
        $this->ensureOwner($workspace);
        WorkspaceRepository::delete($workspace->id);

        return response()->json([
            'success' => true,
            'message' => 'Workspace deleted.',
            'data' => null,
        ]);
    }

    protected function ensureOwner(Workspace $workspace): void
    {
        abort_unless($workspace->user_id === auth()->id(), 403, 'You do not own this workspace.');
    }
}
