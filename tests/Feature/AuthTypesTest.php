<?php

namespace Tests\Feature;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RequestSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Exposes the protected Guzzle-options builder for auth assertions. */
class ExposedRequestSender extends RequestSender
{
    public function exposedBuildOptions(array $payload, array $variables = [], ?User $user = null, string $url = '', ?ApiRequest $savedRequest = null): array
    {
        return $this->buildOptions($payload, $variables, $user, $url, $savedRequest);
    }
}

/**
 * The Authorization tab sends 8 types (RequestBuilder.vue):
 * none, inherit, bearer, basic, digest, api_key, oauth2, aws.
 * Validation, storage and RequestSender::applyAuth() must accept all 8 —
 * picking one must never 422.
 */
class AuthTypesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Workspace $workspace;

    protected Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->workspace = Workspace::create(['user_id' => $this->user->id, 'name' => 'WS']);
        $this->collection = Collection::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'name' => 'API',
            'variables' => [],
        ]);
    }

    public static function authTypes(): array
    {
        return [
            ['none'], ['inherit'], ['bearer'], ['basic'],
            ['digest'], ['api_key'], ['oauth2'], ['aws'],
        ];
    }

    #[DataProvider('authTypes')]
    public function test_all_auth_types_can_be_saved(string $type): void
    {
        $this->postJson('/api/requests', [
            'collection_id' => $this->collection->id,
            'name' => 'Auth '.$type,
            'method' => 'GET',
            'url' => 'https://example.com/x',
            'auth_type' => $type,
            'auth' => ['token' => 't', 'username' => 'u', 'password' => 'p'],
        ])->assertCreated()->assertJsonPath('data.auth_type', $type);
    }

    #[DataProvider('authTypes')]
    public function test_send_accepts_all_auth_types_without_422(string $type): void
    {
        $this->mock(RequestSender::class, function ($mock) {
            $mock->shouldReceive('send')->andReturn([
                'status' => 200, 'status_text' => 'OK', 'time_ms' => 1, 'size' => 2,
                'headers' => [], 'body' => '{}', 'content_type' => 'application/json',
                'is_json' => true, 'error' => null, 'history_id' => null, 'request_echo' => [],
            ]);
        });

        $this->postJson('/api/send', [
            'method' => 'GET',
            'url' => 'https://example.com/x',
            'auth_type' => $type,
            'auth' => ['token' => 't', 'username' => 'u', 'password' => 'p', 'access_token' => 'tok'],
        ])->assertOk();
    }

    public function test_apply_auth_digest_uses_guzzle_digest(): void
    {
        $options = (new ExposedRequestSender)->exposedBuildOptions([
            'method' => 'GET', 'url' => 'https://example.com/x',
            'auth_type' => 'digest', 'auth' => ['username' => 'u', 'password' => 'p'],
        ]);

        $this->assertSame(['u', 'p', 'digest'], $options['auth']);
    }

    public function test_apply_auth_oauth2_sends_access_token(): void
    {
        $options = (new ExposedRequestSender)->exposedBuildOptions([
            'method' => 'GET', 'url' => 'https://example.com/x',
            'auth_type' => 'oauth2', 'auth' => ['access_token' => 'tok-123', 'token_type' => 'Bearer'],
        ]);

        $this->assertSame('Bearer tok-123', $options['headers']['Authorization']);
    }

    public function test_apply_auth_aws_signs_request(): void
    {
        $options = (new ExposedRequestSender)->exposedBuildOptions([
            'method' => 'GET', 'url' => 'https://example.com/x',
            'auth_type' => 'aws',
            'auth' => ['access_key' => 'AKID', 'secret_key' => 'secret', 'region' => 'us-east-1', 'service' => 'execute-api'],
        ]);

        $this->assertStringStartsWith('AWS4-HMAC-SHA256 ', $options['headers']['Authorization']);
    }

    public function test_apply_auth_inherit_uses_collection_auth(): void
    {
        $this->collection->update(['auth' => ['type' => 'bearer', 'token' => 'parent-tok']]);

        $saved = ApiRequest::create([
            'collection_id' => $this->collection->id, 'user_id' => $this->user->id,
            'name' => 'Child', 'method' => 'GET', 'url' => 'https://example.com/x',
            'auth_type' => 'inherit', 'auth' => [],
        ]);

        $options = (new ExposedRequestSender)->exposedBuildOptions([
            'method' => 'GET', 'url' => 'https://example.com/x',
            'auth_type' => 'inherit', 'auth' => [],
            'collection_id' => $this->collection->id,
        ], [], $this->user, 'https://example.com/x', $saved);

        $this->assertSame('Bearer parent-tok', $options['headers']['Authorization']);
    }
}
