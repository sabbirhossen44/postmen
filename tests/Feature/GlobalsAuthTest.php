<?php

namespace Tests\Feature;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AwsSigner;
use App\Services\CookieService;
use App\Services\OAuthTokenService;
use App\Services\RequestSender;
use App\Services\VariableResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalsAuthTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Workspace $workspace;
    protected Collection $collection;
    protected Environment $environment;

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
            'variables' => [['key' => 'base', 'value' => 'collection']],
        ]);
        $this->environment = Environment::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'name' => 'Dev',
            'variables' => [['key' => 'base', 'value' => 'env']],
        ]);

        // Routes for the new controllers are not in routes/api.php yet
        // (parent adds them); register them here so the controllers
        // can be tested end-to-end. The 'api' middleware group is
        // needed for SubstituteBindings (implicit route-model binding).
        // They must come before web.php's GET /{any} SPA catch-all,
        // which would otherwise swallow GETs to these paths — so move
        // them to the front.
        Route::middleware(['api', 'auth:sanctum'])->prefix('api')->group(function () {
            Route::apiResource('globals', \App\Http\Controllers\Api\GlobalVariableController::class)
                ->parameters(['globals' => 'globalVariable']);
            Route::get('/variables/preview', [\App\Http\Controllers\Api\VariableController::class, 'preview']);
            Route::post('/requests/{apiRequest}/oauth/token', [\App\Http\Controllers\Api\OAuthController::class, 'exchange']);
            Route::get('/cookies', [\App\Http\Controllers\Api\CookieController::class, 'index']);
            Route::delete('/cookies', [\App\Http\Controllers\Api\CookieController::class, 'destroyAll']);
            Route::delete('/cookies/{cookie}', [\App\Http\Controllers\Api\CookieController::class, 'destroy']);
        });

        $mine = ['api/globals', 'api/variables/preview', 'api/cookies'];
        $routes = new \Illuminate\Routing\RouteCollection;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $isMine = $uri === 'api/requests/{apiRequest}/oauth/token'
                || collect($mine)->contains(fn ($prefix) => $uri === $prefix || str_starts_with($uri, $prefix.'/'));
            if ($isMine) {
                $routes->add($route);
            }
        }
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $isMine = $uri === 'api/requests/{apiRequest}/oauth/token'
                || collect($mine)->contains(fn ($prefix) => $uri === $prefix || str_starts_with($uri, $prefix.'/'));
            if (! $isMine) {
                $routes->add($route);
            }
        }
        Route::setRoutes($routes);
    }

    public function test_globals_crud_masks_secrets(): void
    {
        $created = $this->postJson('/api/globals', [
            'key' => 'api_key',
            'initial_value' => 'init-secret',
            'current_value' => 'live-secret',
            'type' => 'secret',
        ])->assertCreated()->json('data');

        // Secret current value is never returned in create/list.
        $this->assertNull($created['current_value']);
        $this->assertNull($created['initial_value']);

        $list = $this->getJson('/api/globals')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertNull($list[0]['current_value']);

        // Explicit edit fetch reveals it.
        $shown = $this->getJson("/api/globals/{$created['id']}?include_secrets=1")
            ->assertOk()->json('data');
        $this->assertSame('live-secret', $shown['current_value']);

        $this->putJson("/api/globals/{$created['id']}", ['current_value' => 'rotated'])
            ->assertOk();
        $this->assertSame(
            'rotated',
            $this->getJson("/api/globals/{$created['id']}?include_secrets=1")->json('data.current_value')
        );

        $this->deleteJson("/api/globals/{$created['id']}")->assertOk();
        $this->assertDatabaseMissing('global_variables', ['id' => $created['id']]);

        // Another user cannot see it.
        $other = User::factory()->create();
        $global = \App\Models\GlobalVariable::create([
            'user_id' => $this->user->id, 'key' => 'k', 'type' => 'text',
        ]);
        Sanctum::actingAs($other);
        $this->getJson("/api/globals/{$global->id}")->assertForbidden();
    }

    public function test_variable_precedence_local_env_collection_global(): void
    {
        \App\Models\GlobalVariable::create([
            'user_id' => $this->user->id, 'key' => 'base', 'initial_value' => 'global',
            'current_value' => 'global', 'type' => 'text',
        ]);
        \App\Models\GlobalVariable::create([
            'user_id' => $this->user->id, 'key' => 'only_global', 'initial_value' => 'g',
            'current_value' => '', 'type' => 'text',
        ]);

        // Environment current empty -> falls back to initial.
        $this->environment->update(['variables' => [
            ['key' => 'base', 'initial_value' => 'env-init', 'current_value' => 'env', 'type' => 'text'],
            ['key' => 'only_env', 'initial_value' => 'env-init', 'current_value' => '', 'type' => 'text'],
        ]]);

        $resolved = VariableResolver::resolveAll(
            $this->workspace,
            $this->collection,
            $this->environment->fresh(),
            [['key' => 'base', 'value' => 'local']],
            $this->user->id,
        );

        $this->assertSame('local', $resolved['values']['base']);
        $this->assertSame('local', $resolved['scopes']['base']);
        $this->assertSame('env-init', $resolved['values']['only_env']);
        $this->assertSame('environment', $resolved['scopes']['only_env']);
        $this->assertSame('g', $resolved['values']['only_global']);
        $this->assertSame('global', $resolved['scopes']['only_global']);

        // Without a local override, environment beats collection beats global.
        $resolved = VariableResolver::resolveAll(
            $this->workspace, $this->collection, $this->environment->fresh(), [], $this->user->id,
        );
        $this->assertSame('env', $resolved['values']['base']);
        $this->assertSame('environment', $resolved['scopes']['base']);

        // The preview endpoint exposes the same map.
        $this->getJson("/api/variables/preview?workspace_id={$this->workspace->id}&collection_id={$this->collection->id}&environment_id={$this->environment->id}")
            ->assertOk()
            ->assertJsonPath('data.values.base', 'env')
            ->assertJsonPath('data.scopes.base', 'environment');
    }

    public function test_environment_secrets_masked_in_api(): void
    {
        $env = $this->postJson('/api/environments', [
            'workspace_id' => $this->workspace->id,
            'name' => 'Prod',
            'variables' => [
                ['key' => 'token', 'initial_value' => 'i', 'current_value' => 'live', 'type' => 'secret'],
                ['key' => 'host', 'value' => 'example.com', 'type' => 'text'],
            ],
        ])->assertCreated()->json('data');

        $this->assertNull($env['variables'][0]['current_value']);
        $this->assertSame('example.com', $env['variables'][1]['value']);

        $shown = $this->getJson("/api/environments/{$env['id']}?include_secrets=1")->assertOk()->json('data');
        $this->assertSame('live', $shown['variables'][0]['current_value']);
    }

    public function test_new_auth_types_can_be_saved(): void
    {
        foreach (['inherit', 'digest', 'oauth2', 'aws'] as $type) {
            $this->postJson("/api/collections/{$this->collection->id}/requests", [
                'name' => "Req $type",
                'method' => 'GET',
                'url' => 'https://example.com',
                'auth_type' => $type,
                'auth' => [],
            ])->assertCreated()->assertJsonPath('data.auth_type', $type);
        }
    }

    public function test_digest_auth_sets_guzzle_auth_option(): void
    {
        $options = $this->buildOptions([
            'method' => 'GET', 'url' => 'https://example.com',
            'auth_type' => 'digest',
            'auth' => ['username' => 'u', 'password' => 'p'],
        ]);

        $this->assertSame(['u', 'p', 'digest'], $options['auth']);
    }

    public function test_oauth2_auth_sends_bearer_token(): void
    {
        $options = $this->buildOptions([
            'method' => 'GET', 'url' => 'https://example.com',
            'auth_type' => 'oauth2',
            'auth' => ['access_token' => 'tok123', 'token_type' => 'Bearer'],
        ]);

        $this->assertSame('Bearer tok123', $options['headers']['Authorization']);
    }

    public function test_inherit_auth_resolves_from_collection(): void
    {
        $this->collection->update(['auth' => ['type' => 'bearer', 'token' => 'parent-token']]);

        $options = $this->buildOptions([
            'method' => 'GET', 'url' => 'https://example.com',
            'auth_type' => 'inherit', 'auth' => [],
            'collection_id' => $this->collection->id,
        ]);

        $this->assertSame('Bearer parent-token', $options['headers']['Authorization']);
    }

    public function test_aws_auth_signs_request(): void
    {
        $options = $this->buildOptions([
            'method' => 'GET', 'url' => 'https://example.com/api',
            'auth_type' => 'aws',
            'auth' => [
                'access_key' => 'AKID', 'secret_key' => 'secret',
                'region' => 'us-east-1', 'service' => 'execute-api',
            ],
        ]);

        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/', $options['headers']['Authorization']);
        $this->assertArrayHasKey('X-Amz-Date', $options['headers']);
    }

    public function test_settings_normalize_defaults_and_overrides(): void
    {
        $this->assertSame([
            'follow_redirects' => true,
            'timeout' => 30,
            'verify_ssl' => true,
            'max_redirects' => 5,
            'send_cookies' => true,
        ], RequestSender::normalizeSettings(null));

        $overridden = RequestSender::normalizeSettings([
            'timeout' => 5, 'verify_ssl' => false, 'max_redirects' => 2, 'send_cookies' => false,
        ]);
        $this->assertSame(5, $overridden['timeout']);
        $this->assertFalse($overridden['verify_ssl']);
        $this->assertSame(2, $overridden['max_redirects']);
        $this->assertFalse($overridden['send_cookies']);
    }

    public function test_cookie_service_stores_and_replays(): void
    {
        $response = new Response(200, [
            'Set-Cookie' => 'session=abc123; Domain=example.com; Path=/; Expires=Wed, 01 Jan 2031 00:00:00 GMT',
        ]);
        CookieService::storeFromResponse($this->user->id, $this->workspace->id, $response);

        $this->assertDatabaseHas('cookies', [
            'user_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'domain' => 'example.com',
            'name' => 'session',
            'value' => 'abc123',
        ]);

        $jar = CookieService::jarFor($this->user->id, $this->workspace->id);
        $cookies = $jar->toArray();
        $this->assertCount(1, $cookies);
        $this->assertSame('abc123', $cookies[0]['Value']);

        $listed = $this->getJson("/api/cookies?workspace_id={$this->workspace->id}")
            ->assertOk()->json('data');
        $this->assertCount(1, $listed);

        $this->deleteJson('/api/cookies?workspace_id='.$this->workspace->id)->assertOk();
        $this->assertDatabaseMissing('cookies', ['name' => 'session']);
    }

    public function test_oauth_token_exchange_stores_token(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'new-token', 'token_type' => 'Bearer', 'expires_in' => 3600,
            ])),
        ]);
        $service = new OAuthTokenService(new Client(['handler' => HandlerStack::create($mock)]));
        $this->app->instance(OAuthTokenService::class, $service);

        $request = ApiRequest::create([
            'collection_id' => $this->collection->id,
            'user_id' => $this->user->id,
            'name' => 'OAuth req',
            'method' => 'GET',
            'url' => 'https://example.com',
            'auth_type' => 'oauth2',
            'auth' => ['token_url' => 'https://auth.example.com/token', 'client_id' => 'cid'],
        ]);

        $this->postJson("/api/requests/{$request->id}/oauth/token", [
            'grant_type' => 'client_credentials',
        ])->assertOk()->assertJsonPath('data.access_token', 'new-token');

        $this->assertSame('new-token', $request->fresh()->auth['access_token']);
    }

    /** Call the protected buildOptions() without sending a request. */
    protected function buildOptions(array $payload): array
    {
        $sender = new RequestSender;
        $method = new \ReflectionMethod($sender, 'buildOptions');
        $method->setAccessible(true);

        return $method->invoke($sender, $payload, [], $this->user, $payload['url'], null);
    }
}
