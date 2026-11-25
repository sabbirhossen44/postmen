<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Repositories\CollectionRepository;
use App\Repositories\EnvironmentRepository;
use App\Repositories\WorkspaceRepository;
use App\Services\VariableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Variable preview: the resolved value of every variable in scope,
 * with the scope each one came from (global / collection /
 * environment / local). Powers the frontend variable preview popover.
 */
class VariableController extends Controller
{
    /**
     * Resolve variables for one environment (route form of preview).
     * The frontend calls GET /environments/{id}/resolve.
     */
    public function resolve(Request $request, Environment $environment): JsonResponse
    {
        abort_unless($environment->user_id === auth()->id(), 403, 'You do not own this environment.');

        $request->merge(['environment_id' => $environment->id]);

        return $this->preview($request);
    }

    public function preview(Request $request): JsonResponse
    {
        $userId = auth()->id();
        $workspace = null;
        if ($request->filled('workspace_id')) {
            $workspace = WorkspaceRepository::findForUser((int) $request->query('workspace_id'), $userId);
            abort_unless($workspace, 404, 'Workspace not found.');
        }

        $collection = null;
        if ($request->filled('collection_id')) {
            $collection = CollectionRepository::findForUser((int) $request->query('collection_id'), $userId);
            abort_unless($collection, 404, 'Collection not found.');
            $workspace ??= $collection->workspace;
        }

        $environment = null;
        if ($request->filled('environment_id')) {
            $environment = EnvironmentRepository::findOrFail((int) $request->query('environment_id'));
            abort_unless($environment->user_id === $userId, 403, 'You do not own this environment.');
            $workspace ??= $environment->workspace;
        }

        return response()->json([
            'success' => true,
            'message' => 'Variables resolved.',
            'data' => VariableResolver::resolveAll(
                $workspace,
                $collection,
                $environment,
                (array) $request->query('local', []),
                $userId,
            ),
        ]);
    }
}
