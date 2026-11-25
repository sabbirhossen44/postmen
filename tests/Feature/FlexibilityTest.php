<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlexibilityTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    /** Workspace + collection + folder + request, built through the API. */
    protected function makeCollection(string $name = 'API'): array
    {
        // Workspace names are unique per user, so each call gets its own.
        $workspace = $this->postJson('/api/workspaces', ['name' => 'WS '.uniqid()])
            ->assertCreated()->json('data');

        $collection = $this->postJson('/api/collections', [
            'workspace_id' => $workspace['id'],
            'name' => $name,
            'description' => 'Test collection',
        ])->assertCreated()->json('data');

        $folder = $this->postJson('/api/folders', [
            'collection_id' => $collection['id'],
            'name' => 'Users',
        ])->assertCreated()->json('data');

        $request = $this->postJson('/api/requests', [
            'collection_id' => $collection['id'],
            'folder_id' => $folder['id'],
            'name' => 'List users',
            'method' => 'GET',
            'url' => 'https://example.com/users',
            'description' => 'Lists users.',
            'params' => [['key' => 'page', 'value' => '1', 'enabled' => true]],
            'headers' => [['key' => 'Accept', 'value' => 'application/json', 'enabled' => true]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/requests', [
            'collection_id' => $collection['id'],
            'name' => 'Root request',
            'method' => 'POST',
            'url' => 'https://example.com/things',
        ])->assertCreated();

        return compact('workspace', 'collection', 'folder', 'request');
    }

    public function test_fork_collection_deep_copies_into_workspace(): void
    {
        $made = $this->makeCollection();

        $fork = $this->postJson('/api/collections/'.$made['collection']['id'].'/fork', [
            'workspace_id' => $made['workspace']['id'],
        ])->assertCreated()->json('data');

        $this->assertSame('API (fork)', $fork['name']);
        $this->assertNotSame($made['collection']['id'], $fork['id']);
        $this->assertSame($made['workspace']['id'], $fork['workspace_id']);

        $tree = $this->getJson('/api/collections/'.$fork['id'])->assertOk()->json('data.tree');
        $this->assertSame('Users', $tree['folders'][0]['folder']['name']);
        $this->assertSame('List users', $tree['folders'][0]['requests'][0]['name']);
        $this->assertSame('Root request', $tree['requests'][0]['name']);
        $this->assertNotSame($made['folder']['id'], $tree['folders'][0]['folder']['id']);
        $this->assertNotSame($made['request']['id'], $tree['folders'][0]['requests'][0]['id']);
    }

    public function test_fork_defaults_to_source_workspace(): void
    {
        $made = $this->makeCollection();

        $fork = $this->postJson('/api/collections/'.$made['collection']['id'].'/fork', [])
            ->assertCreated()->json('data');

        $this->assertSame($made['workspace']['id'], $fork['workspace_id']);
    }

    public function test_duplicate_collection(): void
    {
        $made = $this->makeCollection();

        $copy = $this->postJson('/api/collections/'.$made['collection']['id'].'/duplicate')
            ->assertCreated()->json('data');

        $this->assertSame('API (copy)', $copy['name']);
        $this->assertNotSame($made['collection']['id'], $copy['id']);

        $tree = $this->getJson('/api/collections/'.$copy['id'])->assertOk()->json('data.tree');
        $this->assertSame('List users', $tree['folders'][0]['requests'][0]['name']);
        $this->assertSame('Root request', $tree['requests'][0]['name']);
    }

    public function test_duplicate_request_keeps_examples_and_description(): void
    {
        $made = $this->makeCollection();
        $id = $made['request']['id'];

        $this->postJson('/api/requests/'.$id.'/examples', [
            'name' => 'Success 200',
            'status' => 200,
            'body' => '{"ok":true}',
        ])->assertCreated();

        $copy = $this->postJson('/api/requests/'.$id.'/duplicate')
            ->assertCreated()->json('data');

        $this->assertSame('List users (copy)', $copy['name']);
        $this->assertSame('Lists users.', $copy['description']);
        $this->assertSame('Success 200', $copy['examples'][0]['name']);
    }

    public function test_move_request_to_another_collection_and_folder(): void
    {
        $made = $this->makeCollection();
        $other = $this->makeCollection('Other');

        $moved = $this->patchJson('/api/requests/'.$made['request']['id'].'/move', [
            'collection_id' => $other['collection']['id'],
            'folder_id' => $other['folder']['id'],
        ])->assertOk()->json('data');

        $this->assertSame($other['collection']['id'], $moved['collection_id']);
        $this->assertSame($other['folder']['id'], $moved['folder_id']);
    }

    public function test_move_request_rejects_folder_from_another_collection(): void
    {
        $made = $this->makeCollection();
        $other = $this->makeCollection('Other');

        $this->patchJson('/api/requests/'.$made['request']['id'].'/move', [
            'collection_id' => $other['collection']['id'],
            'folder_id' => $made['folder']['id'],
        ])->assertStatus(422);
    }

    public function test_save_and_delete_response_example(): void
    {
        $made = $this->makeCollection();
        $id = $made['request']['id'];

        $examples = $this->postJson('/api/requests/'.$id.'/examples', [
            'name' => 'Success 200',
            'status' => 200,
            'status_text' => 'OK',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"ok":true}',
            'content_type' => 'application/json',
        ])->assertCreated()->json('data');

        $this->assertCount(1, $examples);
        $this->assertSame(200, $examples[0]['status']);

        $remaining = $this->deleteJson('/api/requests/'.$id.'/examples/0')
            ->assertOk()->json('data');
        $this->assertSame([], $remaining);

        $this->deleteJson('/api/requests/'.$id.'/examples/0')->assertNotFound();
    }

    public function test_user_settings_defaults_and_update(): void
    {
        $settings = $this->getJson('/api/settings/me')->assertOk()->json('data');
        $this->assertSame('dark', $settings['theme']);
        $this->assertSame('comfortable', $settings['layout_density']);
        $this->assertSame(30, $settings['default_timeout']);
        $this->assertSame(320, $settings['sidebar_width']);

        $updated = $this->patchJson('/api/settings/me', [
            'theme' => 'light',
            'layout_density' => 'compact',
            'default_body_type' => 'raw',
            'default_timeout' => 60,
            'sidebar_width' => 400,
        ])->assertOk()->json('data');

        $this->assertSame('light', $updated['theme']);
        $this->assertSame('compact', $updated['layout_density']);
        $this->assertSame(60, $updated['default_timeout']);
        $this->assertSame(400, $updated['sidebar_width']);

        // One row per user: a second GET returns the same row.
        $again = $this->getJson('/api/settings/me')->assertOk()->json('data');
        $this->assertSame($updated['id'], $again['id']);
        $this->assertSame('light', $again['theme']);
    }

    public function test_user_settings_reject_invalid_values(): void
    {
        $this->patchJson('/api/settings/me', ['theme' => 'pink'])->assertStatus(422);
        $this->patchJson('/api/settings/me', ['default_timeout' => 0])->assertStatus(422);
    }

    public function test_collection_documentation_lists_requests_with_details(): void
    {
        $made = $this->makeCollection();

        $doc = $this->getJson('/api/collections/'.$made['collection']['id'].'/documentation')
            ->assertOk()->json('data');

        $this->assertSame('API', $doc['name']);
        $this->assertSame('List users', $doc['tree']['folders'][0]['requests'][0]['name']);
        $this->assertSame('Lists users.', $doc['tree']['folders'][0]['requests'][0]['description']);
        $this->assertSame('page', $doc['tree']['folders'][0]['requests'][0]['params'][0]['key']);
        $this->assertSame('Root request', $doc['tree']['requests'][0]['name']);
    }

    public function test_fork_and_duplicate_require_ownership(): void
    {
        $made = $this->makeCollection();

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->postJson('/api/collections/'.$made['collection']['id'].'/fork', [])
            ->assertForbidden();
        $this->postJson('/api/collections/'.$made['collection']['id'].'/duplicate')
            ->assertForbidden();
        $this->patchJson('/api/requests/'.$made['request']['id'].'/move', [])
            ->assertForbidden();
    }
}
