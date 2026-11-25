<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\Workspace;
use App\Repositories\WorkspaceMemberRepository;
use App\Services\ActivityService;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;

class WorkspaceMemberController extends Controller
{
    /** GET /workspaces/{workspace}/members — owner first, then members. */
    public function index(Workspace $workspace): JsonResponse
    {
        WorkspaceAccess::requireAccess(auth()->user(), $workspace);

        return response()->json([
            'success' => true,
            'message' => 'Workspace members retrieved.',
            'data' => $this->presentMembers($workspace),
        ]);
    }

    /** PATCH /workspaces/{workspace}/members/{userId} — change a member's role. */
    public function update(UpdateMemberRoleRequest $request, Workspace $workspace, int $userId): JsonResponse
    {
        $this->ensureTeamAndManage($workspace);

        abort_if($userId === (int) $workspace->user_id, 422, 'The workspace owner role cannot be changed. Use transfer instead.');

        $member = WorkspaceMemberRepository::findMember($workspace->id, $userId);
        abort_unless($member, 404, 'Member not found.');

        WorkspaceMemberRepository::update($member, ['role' => $request->validated('role')]);
        ActivityService::log(
            $workspace, $request->user(), 'member_role_changed', $member,
            "Changed {$member->user->name} to {$request->validated('role')}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Member role updated.',
            'data' => $member->fresh()->load('user'),
        ]);
    }

    /** DELETE /workspaces/{workspace}/members/{userId} — remove a member. */
    public function destroy(Workspace $workspace, int $userId): JsonResponse
    {
        $this->ensureTeamAndManage($workspace);

        abort_if($userId === (int) $workspace->user_id, 422, 'The workspace owner cannot be removed. Transfer ownership first.');

        $member = WorkspaceMemberRepository::findMember($workspace->id, $userId);
        abort_unless($member, 404, 'Member not found.');

        // Only the owner may remove an admin; an admin may remove
        // editors and viewers.
        if ($member->role === 'admin') {
            WorkspaceAccess::requireCan(auth()->user(), $workspace, WorkspaceAccess::DELETE_WORKSPACE);
        }

        $name = $member->user->name;
        WorkspaceMemberRepository::delete($member->id);
        ActivityService::log($workspace, auth()->user(), 'member_removed', $member, "Removed {$name} from the workspace.");

        return response()->json([
            'success' => true,
            'message' => 'Member removed.',
            'data' => null,
        ]);
    }

    /** POST /workspaces/{workspace}/leave — leave a team workspace. */
    public function leave(Workspace $workspace): JsonResponse
    {
        abort_unless($workspace->isTeam(), 403, 'Only team workspaces have members.');
        abort_if((int) $workspace->user_id === auth()->id(), 422, 'The workspace owner cannot leave. Transfer ownership or delete the workspace.');

        $member = WorkspaceMemberRepository::findMember($workspace->id, auth()->id());
        abort_unless($member, 404, 'You are not a member of this workspace.');

        WorkspaceMemberRepository::delete($member->id);
        ActivityService::log($workspace, auth()->user(), 'member_left', $member, auth()->user()->name.' left the workspace.');

        return response()->json([
            'success' => true,
            'message' => 'You left the workspace.',
            'data' => null,
        ]);
    }

    /** The owner as a member row, followed by the stored members. */
    protected function presentMembers(Workspace $workspace): array
    {
        $owner = $workspace->user;

        $members = [[
            'user_id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'role' => 'owner',
            'joined_at' => $workspace->created_at,
            'is_owner' => true,
        ]];

        foreach (WorkspaceMemberRepository::forWorkspace($workspace->id) as $member) {
            $members[] = [
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'role' => $member->role,
                'joined_at' => $member->joined_at,
                'is_owner' => false,
            ];
        }

        return $members;
    }

    protected function ensureTeamAndManage(Workspace $workspace): void
    {
        abort_unless($workspace->isTeam(), 403, 'Only team workspaces have members.');
        WorkspaceAccess::requireCan(auth()->user(), $workspace, WorkspaceAccess::MANAGE);
    }
}
