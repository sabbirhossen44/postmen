<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteMemberRequest;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceInvitationRepository;
use App\Repositories\WorkspaceMemberRepository;
use App\Services\ActivityService;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class WorkspaceInvitationController extends Controller
{
    /** POST /workspaces/{workspace}/invite — invite by email. */
    public function invite(InviteMemberRequest $request, Workspace $workspace): JsonResponse
    {
        abort_unless($workspace->isTeam(), 403, 'Only team workspaces can invite members.');
        WorkspaceAccess::requireCan($request->user(), $workspace, WorkspaceAccess::MANAGE);

        $data = $request->validated();
        $email = strtolower($data['email']);

        abort_if($email === strtolower($workspace->user->email), 422, 'That user already owns this workspace.');

        $existingUser = UserRepository::findByEmail($email);

        if ($existingUser !== null
            && WorkspaceMemberRepository::findMember($workspace->id, $existingUser->id) !== null) {
            return response()->json([
                'success' => false,
                'message' => 'That user is already a member of this workspace.',
                'data' => null,
            ], 422);
        }

        $pending = WorkspaceInvitationRepository::findPending($workspace->id, $email);

        if ($pending !== null) {
            return response()->json([
                'success' => false,
                'message' => 'That email already has a pending invitation to this workspace.',
                'data' => $pending,
            ], 422);
        }

        $invitation = WorkspaceInvitationRepository::create([
            'workspace_id' => $workspace->id,
            'email' => $email,
            'role' => $data['role'],
            'token' => Str::random(40),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        ActivityService::log(
            $workspace, $request->user(), 'member_invited', $invitation,
            "Invited {$email} as {$data['role']}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Invitation sent.',
            'data' => $invitation,
        ], 201);
    }

    /** GET /workspaces/{workspace}/invitations — pending invitations. */
    public function index(Workspace $workspace): JsonResponse
    {
        WorkspaceAccess::requireCan(auth()->user(), $workspace, WorkspaceAccess::MANAGE);

        return response()->json([
            'success' => true,
            'message' => 'Workspace invitations retrieved.',
            'data' => WorkspaceInvitationRepository::forWorkspace($workspace->id),
        ]);
    }

    /** POST /workspaces/invitations/{token}/accept — join the workspace. */
    public function accept(string $token): JsonResponse
    {
        $user = auth()->user();
        $invitation = WorkspaceInvitationRepository::findByToken($token);
        abort_unless($invitation, 404, 'Invitation not found.');

        abort_if($invitation->accepted_at !== null, 422, 'This invitation was already accepted.');
        abort_if($invitation->isExpired(), 422, 'This invitation has expired.');
        abort_unless(
            strtolower($invitation->email) === strtolower($user->email),
            403,
            'This invitation was sent to a different email address.'
        );

        $workspace = $invitation->workspace;
        abort_unless($workspace->isTeam(), 422, 'This workspace is no longer a team workspace.');

        $member = WorkspaceMemberRepository::findMember($workspace->id, $user->id);

        if ($member === null) {
            $member = WorkspaceMemberRepository::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => $invitation->role,
                'invited_by' => $invitation->invited_by,
                'joined_at' => now(),
            ]);
        }

        WorkspaceInvitationRepository::update($invitation, ['accepted_at' => now()]);
        ActivityService::log(
            $workspace, $user, 'member_joined', $member,
            "{$user->name} joined the workspace as {$invitation->role}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Invitation accepted.',
            'data' => [
                'workspace' => $workspace->fresh(),
                'role' => $member->role,
            ],
        ]);
    }

    /** POST /workspaces/invitations/{invitation}/decline — decline or cancel. */
    public function decline(WorkspaceInvitation $invitation): JsonResponse
    {
        $user = auth()->user();
        $isInvitee = strtolower($invitation->email) === strtolower($user->email);
        $isManager = WorkspaceAccess::can($user, $invitation->workspace, WorkspaceAccess::MANAGE);

        abort_unless($isInvitee || $isManager, 403, 'You cannot decline this invitation.');

        WorkspaceInvitationRepository::delete($invitation->id);
        ActivityService::log(
            $invitation->workspace, $user, 'member_invitation_declined', $invitation,
            "{$user->name} declined the invitation for {$invitation->email}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Invitation declined.',
            'data' => null,
        ]);
    }
}
