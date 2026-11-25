<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Http\Requests\TransferWorkspaceRequest;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Models\Workspace;
use App\Repositories\WorkspaceActivityRepository;
use App\Repositories\WorkspaceMemberRepository;
use App\Repositories\WorkspaceRepository;
use App\Services\ActivityService;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;

class WorkspaceController extends Controller
{
    public function index(): JsonResponse
    {
        $workspaces = WorkspaceRepository::forUser(auth()->id())
            ->map(fn (Workspace $workspace) => $this->present($workspace));

        return response()->json([
            'success' => true,
            'message' => 'Workspaces retrieved.',
            'data' => $workspaces,
        ]);
    }

    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $workspace = WorkspaceRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        ActivityService::log($workspace, $request->user(), 'workspace_created', $workspace, "Created workspace {$workspace->name}.");

        return response()->json([
            'success' => true,
            'message' => 'Workspace created.',
            'data' => $this->present($workspace->fresh()),
        ], 201);
    }

    public function show(Workspace $workspace): JsonResponse
    {
        WorkspaceAccess::requireAccess(auth()->user(), $workspace);

        return response()->json([
            'success' => true,
            'message' => 'Workspace retrieved.',
            'data' => $this->present($workspace->load(['media', 'collections', 'environments'])),
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::MANAGE);

        $data = $request->validated();

        if (($data['type'] ?? $workspace->type) === 'personal'
            && $workspace->isTeam()
            && WorkspaceMemberRepository::forWorkspace($workspace->id)->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Remove all members before switching a team workspace to personal.',
                'data' => null,
            ], 422);
        }

        WorkspaceRepository::update($workspace, $data);
        ActivityService::log($workspace, $request->user(), 'workspace_updated', $workspace, "Updated workspace {$workspace->name}.");

        return response()->json([
            'success' => true,
            'message' => 'Workspace updated.',
            'data' => $this->present($workspace->fresh()),
        ]);
    }

    public function destroy(Workspace $workspace): JsonResponse
    {
        WorkspaceAccess::requireCan(auth()->user(), $workspace, WorkspaceAccess::DELETE_WORKSPACE);
        WorkspaceRepository::delete($workspace->id);

        return response()->json([
            'success' => true,
            'message' => 'Workspace deleted.',
            'data' => null,
        ]);
    }

    /** POST /workspaces/{workspace}/transfer — hand ownership to a member. */
    public function transfer(TransferWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::TRANSFER);
        abort_unless($workspace->isTeam(), 422, 'Only team workspaces can change owner.');

        $newOwnerId = $request->validated('user_id');
        abort_if($newOwnerId === (int) $workspace->user_id, 422, 'That user already owns this workspace.');

        $member = WorkspaceMemberRepository::findMember($workspace->id, $newOwnerId);
        abort_unless($member, 422, 'Only a member of this workspace can become its owner.');

        $oldOwnerId = (int) $workspace->user_id;

        WorkspaceMemberRepository::delete($member->id);
        WorkspaceRepository::update($workspace, ['user_id' => $newOwnerId]);
        WorkspaceMemberRepository::create([
            'workspace_id' => $workspace->id,
            'user_id' => $oldOwnerId,
            'role' => 'admin',
            'invited_by' => $newOwnerId,
            'joined_at' => now(),
        ]);

        ActivityService::log(
            $workspace->fresh(), $request->user(), 'workspace_transferred', $workspace,
            "Transferred ownership to {$member->user->name}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Workspace ownership transferred.',
            'data' => $this->present($workspace->fresh()),
        ]);
    }

    /** GET /workspaces/{workspace}/activity — newest first. */
    public function activity(Workspace $workspace): JsonResponse
    {
        WorkspaceAccess::requireAccess(auth()->user(), $workspace);

        return response()->json([
            'success' => true,
            'message' => 'Workspace activity retrieved.',
            'data' => WorkspaceActivityRepository::forWorkspace($workspace->id)->paginate(50),
        ]);
    }

    /** Workspace plus the current user's role in it. */
    protected function present(Workspace $workspace): array
    {
        return array_merge($workspace->toArray(), [
            'role' => WorkspaceAccess::role(auth()->user(), $workspace),
        ]);
    }
}
