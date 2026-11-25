<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CollectionExporter;
use App\Services\CollectionImporter;
use App\Services\RequestSender;
use App\Services\VariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostmenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_and_login(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Sabbir',
            'email' => 'sabbir@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $register->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'sabbir@example.com')
            ->assertJsonStructure(['data' => ['token']]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'sabbir@example.com',
            'password' => 'password123',
        ]);

        $login->assertOk()->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token']]);

        $me = $this->withToken($login->json('data.token'))->getJson('/api/auth/user');
        $me->assertOk()->assertJsonPath('data.email', 'sabbir@example.com');
    }

    public function test_workspace_collection_request_crud(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $this->postJson('/api/workspaces', ['name' => 'My Workspace'])
            ->assertCreated()->json('data');

        $collection = $this->postJson('/api/collections', [
            'workspace_id' => $workspace['id'],
            'name' => 'API',
            'variables' => [['key' => 'baseUrl', 'value' => 'https://example.com']],
        ])->assertCreated()->json('data');

        $folder = $this->postJson('/api/folders', [
            'collection_id' => $collection['id'],
            'name' => 'Users',
        ])->assertCreated()->json('data');

        $apiRequest = $this->postJson('/api/requests', [
            'collection_id' => $collection['id'],
            'folder_id' => $folder['id'],
            'name' => 'List users',
            'method' => 'GET',
            'url' => '{{baseUrl}}/users',
        ])->assertCreated()->json('data');

        $this->getJson('/api/collections/'.$collection['id'])
            ->assertOk()
            ->assertJsonPath('data.tree.folders.0.folder.name', 'Users')
            ->assertJsonPath('data.tree.folders.0.requests.0.name', 'List users');

        $this->postJson('/api/requests/'.$apiRequest['id'].'/duplicate')
            ->assertCreated()
            ->assertJsonPath('data.name', 'List users (copy)');

        $this->patchJson('/api/requests/'.$apiRequest['id'], ['name' => 'List all users'])
            ->assertOk()->assertJsonPath('data.name', 'List all users');

        $this->deleteJson('/api/requests/'.$apiRequest['id'])->assertOk();

        $this->deleteJson('/api/collections/'.$collection['id'])->assertOk();
        $this->deleteJson('/api/workspaces/'.$workspace['id'])->assertOk();
    }

    public function test_environment_activate_switches_active(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $this->postJson('/api/workspaces', ['name' => 'WS'])->assertCreated()->json('data');

        $first = $this->postJson('/api/environments', [
            'workspace_id' => $workspace['id'],
            'name' => 'Dev',
            'is_active' => true,
            'variables' => [['key' => 'host', 'value' => 'dev.example.com', 'enabled' => true]],
        ])->assertCreated()->json('data');

        $second = $this->postJson('/api/environments', [
            'workspace_id' => $workspace['id'],
            'name' => 'Prod',
        ])->assertCreated()->json('data');

        $this->postJson('/api/environments/'.$second['id'].'/activate')->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('environments', ['id' => $first['id'], 'is_active' => false]);
        $this->assertDatabaseHas('environments', ['id' => $second['id'], 'is_active' => true]);
    }

    public function test_variable_resolver_replaces_placeholders(): void
    {
        $resolved = VariableResolver::resolve(
            'https://{{host}}/users/{{id}}',
            [['key' => 'host', 'value' => 'api.example.com'], ['key' => 'id', 'value' => '42']],
        );

        $this->assertSame('https://api.example.com/users/42', $resolved);

        // Disabled variables are skipped; unknown keys stay as-is.
        $resolved = VariableResolver::resolve(
            '{{off}} and {{missing}}',
            [['key' => 'off', 'value' => 'x', 'enabled' => false]],
        );

        $this->assertSame('{{off}} and {{missing}}', $resolved);

        // Environment variables win over collection variables.
        $merged = VariableResolver::merge(
            [['key' => 'host', 'value' => 'env.example.com']],
            [['key' => 'host', 'value' => 'col.example.com']],
        );

        $this->assertSame('env.example.com', $merged['host']);
    }

    public function test_collection_importer_exporter_round_trip(): void
    {
        $user = User::factory()->create();
        $workspace = \App\Models\Workspace::create(['user_id' => $user->id, 'name' => 'WS']);

        $postman = [
            'info' => ['name' => 'Imported', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [
                [
                    'name' => 'Users',
                    'item' => [
                        [
                            'name' => 'Get user',
                            'request' => [
                                'method' => 'GET',
                                'header' => [['key' => 'Accept', 'value' => 'application/json']],
                                'url' => ['raw' => 'https://api.example.com/users/1', 'query' => [['key' => 'verbose', 'value' => '1']]],
                                'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => 'abc']]],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'Create user',
                    'request' => [
                        'method' => 'POST',
                        'url' => 'https://api.example.com/users',
                        'body' => ['mode' => 'raw', 'raw' => '{"name":"Sabbir"}'],
                    ],
                ],
            ],
        ];

        $collection = (new CollectionImporter)->import($postman, $workspace, $user);

        $this->assertSame('Imported', $collection->name);
        $this->assertSame(1, $collection->folders()->count());
        $this->assertSame(2, $collection->requests()->count());

        $exported = (new CollectionExporter)->export($collection);

        $this->assertSame('Imported', $exported['info']['name']);
        $this->assertSame('Users', $exported['item'][0]['name']);
        $this->assertSame('Get user', $exported['item'][0]['item'][0]['name']);
        $this->assertSame('bearer', $exported['item'][0]['item'][0]['request']['auth']['type']);
        $this->assertSame('Create user', $exported['item'][1]['name']);
        $this->assertSame('raw', $exported['item'][1]['request']['body']['mode']);
    }

    public function test_collection_import_rejects_bad_format(): void
    {
        $user = User::factory()->create();
        $workspace = \App\Models\Workspace::create(['user_id' => $user->id, 'name' => 'WS']);

        $this->expectException(\InvalidArgumentException::class);
        (new CollectionImporter)->import(['info' => []], $workspace, $user);
    }

    public function test_send_blocks_private_and_loopback_urls(): void
    {
        // The SSRF guard refuses private/loopback targets only when the
        // toggle is off (ALLOW_PRIVATE_IPS=false, public deployment).
        config(['postmen.allow_private_ips' => false]);

        $user = User::factory()->create();
        $sender = new RequestSender;

        foreach (['http://localhost:8000/admin', 'http://127.0.0.1/secret', 'http://192.168.1.5/x', 'http://10.0.0.9/x'] as $url) {
            $result = $sender->send(['method' => 'GET', 'url' => $url], $user);

            $this->assertNotNull($result['error'], "Expected $url to be blocked");
            $this->assertNull($result['status']);
        }

        // Blocked attempts are still recorded in history.
        $this->assertDatabaseCount('histories', 4);
    }

    public function test_media_upload_and_delete(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $media = $this->postJson('/api/media', [
            'file' => UploadedFile::fake()->image('avatar.png'),
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('media', ['id' => $media['id'], 'original_name' => 'avatar.png']);
        $this->assertNotEmpty($media['url']);

        $this->deleteJson('/api/media/'.$media['id'])->assertOk();
        $this->assertDatabaseMissing('media', ['id' => $media['id']]);
    }

    public function test_history_lists_and_clears(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        (new RequestSender)->send(['method' => 'GET', 'url' => 'http://127.0.0.1/x'], $user);

        $this->getJson('/api/histories')->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->deleteJson('/api/histories')->assertOk();
        $this->assertDatabaseCount('histories', 0);
    }
}
