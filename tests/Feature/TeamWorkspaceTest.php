<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $editor;

    protected User $viewer;

    protected User $outsider;

    protected Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->editor = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->outsider = User::factory()->create();

        Sanctum::actingAs($this->owner);

        $this->workspace = Workspace::create([
            'user_id' => $this->owner->id,
            'name' => 'Team Space',
            'type' => 'team',
        ]);

        $this->inviteAndAccept($this->editor, 'editor');
        $this->inviteAndAccept($this->viewer, 'viewer');
    }

    protected function inviteAndAccept(User $user, string $role): array
    {
        Sanctum::actingAs($this->owner);

        $invitation = $this->postJson("/api/workspaces/{$this->workspace->id}/invite", [
            'email' => $user->email,
            'role' => $role,
        ])->assertCreated()->json('data');

        Sanctum::actingAs($user);

        $this->postJson("/api/workspaces/invitations/{$invitation['token']}/accept")
            ->assertOk()
            ->assertJsonPath('data.role', $role);

        Sanctum::actingAs($this->owner);

        return $invitation;
    }

    public function test_viewer_cannot_create_collection(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson('/api/collections', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Viewer Collection',
        ])->assertForbidden();
    }

    public function test_editor_can_create_collection_and_viewer_can_read_it(): void
    {
        Sanctum::actingAs($this->editor);

        $collection = $this->postJson('/api/collections', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Shared API',
        ])->assertCreated()->json('data');

        $this->assertSame($this->editor->id, $collection['created_by']);
        $this->assertSame($this->editor->id, $collection['updated_by']);

        Sanctum::actingAs($this->viewer);

        $this->getJson('/api/collections/'.$collection['id'])->assertOk();

        // A viewer may not edit what they can read.
        $this->putJson('/api/collections/'.$collection['id'], ['name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_invite_and_accept_flow(): void
    {
        $invitee = User::factory()->create();

        Sanctum::actingAs($this->owner);

        $invitation = $this->postJson("/api/workspaces/{$this->workspace->id}/invite", [
            'email' => $invitee->email,
            'role' => 'editor',
        ])->assertCreated()->json('data');

        $this->assertSame('editor', $invitation['role']);
        $this->assertNotEmpty($invitation['token']);

        // Inviting the same email twice is rejected while it is pending.
        $this->postJson("/api/workspaces/{$this->workspace->id}/invite", [
            'email' => $invitee->email,
            'role' => 'editor',
        ])->assertUnprocessable();

        // A different user cannot accept someone else's invitation.
        Sanctum::actingAs($this->outsider);
        $this->postJson("/api/workspaces/invitations/{$invitation['token']}/accept")
            ->assertForbidden();

        Sanctum::actingAs($invitee);
        $this->postJson("/api/workspaces/invitations/{$invitation['token']}/accept")
            ->assertOk()
            ->assertJsonPath('data.role', 'editor');

        // The new member now sees the workspace in their list.
        $this->getJson('/api/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->workspace->id);

        // An accepted invitation cannot be accepted again.
        $this->postJson("/api/workspaces/invitations/{$invitation['token']}/accept")
            ->assertUnprocessable();
    }

    public function test_non_member_cannot_access_workspace(): void
    {
        Sanctum::actingAs($this->outsider);

        $this->getJson('/api/workspaces/'.$this->workspace->id)->assertNotFound();
        $this->getJson('/api/collections?workspace_id='.$this->workspace->id)->assertNotFound();
        $this->postJson('/api/collections', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Sneaky',
        ])->assertNotFound();
        $this->getJson('/api/workspaces/'.$this->workspace->id.'/activity')->assertNotFound();
    }

    public function test_activity_is_recorded(): void
    {
        Sanctum::actingAs($this->editor);

        $collection = $this->postJson('/api/collections', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Activity API',
        ])->assertCreated()->json('data');

        Sanctum::actingAs($this->owner);

        $activity = $this->getJson("/api/workspaces/{$this->workspace->id}/activity")
            ->assertOk()->json('data.data');

        $actions = array_column($activity, 'action');

        $this->assertContains('member_invited', $actions);
        $this->assertContains('member_joined', $actions);
        $this->assertContains('created', $actions);

        $created = collect($activity)->firstWhere('subject_id', $collection['id']);
        $this->assertSame('Created collection Activity API.', $created['description']);
        $this->assertSame($this->editor->id, $created['user_id']);
    }

    public function test_personal_workspace_cannot_invite(): void
    {
        Sanctum::actingAs($this->owner);

        $personal = $this->postJson('/api/workspaces', ['name' => 'Just Mine'])
            ->assertCreated()->json('data');

        $this->assertSame('personal', $personal['type']);

        $this->postJson("/api/workspaces/{$personal['id']}/invite", [
            'email' => $this->outsider->email,
            'role' => 'viewer',
        ])->assertForbidden();
    }

    public function test_admin_can_change_role_and_member_can_leave(): void
    {
        Sanctum::actingAs($this->owner);

        // Demote the editor to viewer; they lose write access.
        $this->patchJson("/api/workspaces/{$this->workspace->id}/members/{$this->editor->id}", [
            'role' => 'viewer',
        ])->assertOk()->assertJsonPath('data.role', 'viewer');

        Sanctum::actingAs($this->editor);
        $this->postJson('/api/collections', [
            'workspace_id' => $this->workspace->id,
            'name' => 'No Longer Allowed',
        ])->assertForbidden();

        // Leaving removes access entirely.
        $this->postJson("/api/workspaces/{$this->workspace->id}/leave")->assertOk();
        $this->getJson('/api/workspaces/'.$this->workspace->id)->assertNotFound();
    }

    public function test_request_update_with_stale_updated_at_returns_409(): void
    {
        Sanctum::actingAs($this->owner);

        $collection = $this->postJson('/api/collections', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Stale API',
        ])->assertCreated()->json('data');

        $apiRequest = $this->postJson('/api/requests', [
            'collection_id' => $collection['id'],
            'name' => 'List users',
            'method' => 'GET',
            'url' => 'https://example.com/users',
        ])->assertCreated()->json('data');

        // A client holding an old copy gets 409 with the fresh row.
        $this->putJson('/api/requests/'.$apiRequest['id'], [
            'name' => 'List users v2',
            'updated_at' => '2000-01-01 00:00:00',
        ])->assertConflict()
            ->assertJsonPath('data.name', 'List users');

        // A client holding the current copy succeeds.
        $this->putJson('/api/requests/'.$apiRequest['id'], [
            'name' => 'List users v2',
            'updated_at' => $apiRequest['updated_at'],
        ])->assertOk()->assertJsonPath('data.name', 'List users v2');
    }
}
