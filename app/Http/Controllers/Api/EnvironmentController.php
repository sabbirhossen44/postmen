<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEnvironmentRequest;
use App\Http\Requests\UpdateEnvironmentRequest;
use App\Models\Environment;
use App\Repositories\EnvironmentRepository;
use App\Repositories\WorkspaceRepository;
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
            'data' => EnvironmentRepository::forWorkspace($workspace->id),
        ]);
    }

    public function store(StoreEnvironmentRequest $request): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->workspace_id, auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');

        $environment = EnvironmentRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        if ($environment->is_active) {
            EnvironmentRepository::deactivateOthers($workspace->id, $environment->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Environment created.',
            'data' => $environment,
        ], 201);
    }

    public function show(Environment $environment): JsonResponse
    {
        $this->ensureOwner($environment);

        return response()->json([
            'success' => true,
            'message' => 'Environment retrieved.',
            'data' => $environment,
        ]);
    }

    public function update(UpdateEnvironmentRequest $request, Environment $environment): JsonResponse
    {
        $this->ensureOwner($environment);
        EnvironmentRepository::update($environment, $request->validated());

        if ($environment->fresh()->is_active) {
            EnvironmentRepository::deactivateOthers($environment->workspace_id, $environment->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Environment updated.',
            'data' => $environment->fresh(),
        ]);
    }

    public function destroy(Environment $environment): JsonResponse
    {
        $this->ensureOwner($environment);
        EnvironmentRepository::delete($environment->id);

        return response()->json([
            'success' => true,
            'message' => 'Environment deleted.',
            'data' => null,
        ]);
    }

    public function activate(Environment $environment): JsonResponse
    {
        $this->ensureOwner($environment);

        EnvironmentRepository::deactivateOthers($environment->workspace_id, $environment->id);
        EnvironmentRepository::update($environment, ['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Environment activated.',
            'data' => $environment->fresh(),
        ]);
    }

    protected function ensureOwner(Environment $environment): void
    {
        abort_unless($environment->user_id === auth()->id(), 403, 'You do not own this environment.');
    }
}
