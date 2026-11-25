<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoredCookie;
use App\Repositories\CookieJarRepository;
use App\Repositories\WorkspaceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CookieController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->query('workspace_id'), auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');

        return response()->json([
            'success' => true,
            'message' => 'Cookies retrieved.',
            'data' => CookieJarRepository::forUserWorkspace(auth()->id(), $workspace->id),
        ]);
    }

    public function destroy(StoredCookie $cookie): JsonResponse
    {
        abort_unless($cookie->user_id === auth()->id(), 403, 'You do not own this cookie.');
        CookieJarRepository::delete($cookie->id);

        return response()->json([
            'success' => true,
            'message' => 'Cookie deleted.',
            'data' => null,
        ]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $workspace = WorkspaceRepository::findForUser((int) $request->query('workspace_id'), auth()->id());
        abort_unless($workspace, 404, 'Workspace not found.');

        CookieJarRepository::clearForWorkspace(auth()->id(), $workspace->id);

        return response()->json([
            'success' => true,
            'message' => 'Cookies cleared.',
            'data' => null,
        ]);
    }
}
