<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CodeController;
use App\Http\Controllers\Api\EnvironmentController;
use App\Http\Controllers\Api\ImportController;
use App\Models\Collection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CodeGenerator;
use App\Services\CollectionExporter;
use App\Services\CollectionImporter;
use App\Services\CurlImporter;
use App\Services\OpenApiImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Importers (cURL + OpenAPI), code generation, environment import/export,
// and the Postman Collection v2.1 round-trip.
//
// The new routes are registered here (mirroring the route lines reported
// for routes/api.php) so the controller endpoints can be exercised
// end-to-end without editing routes/api.php in this task.
class ImportCodegenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 'api' group matters: it carries SubstituteBindings, without
        // which {apiRequest}/{environment} would arrive as empty models.
        Route::middleware(['api', 'auth:sanctum'])->group(function () {
            Route::post('/api/collections/import/curl', [ImportController::class, 'importCurl']);
            Route::post('/api/collections/import/openapi', [ImportController::class, 'importOpenApi']);
            Route::get('/api/requests/{apiRequest}/code', [CodeController::class, 'show']);
            Route::post('/api/code/generate', [CodeController::class, 'generate']);
            Route::post('/api/environments/import', [EnvironmentController::class, 'import']);
            Route::get('/api/environments/{environment}/export', [EnvironmentController::class, 'export']);
        });

        // routes/api.php loads before routes/web.php in production, so api
        // routes win over the SPA catch-all /{any}. Test routes are added
        // after boot, so move them ahead of the catch-all to mirror that.
        $this->moveTestRoutesFirst();
    }

    /** Move the routes registered above ahead of the web catch-all. */
    protected function moveTestRoutesFirst(): void
    {
        $mine = [
            'GET' => ['api/requests/{apiRequest}/code', 'api/environments/{environment}/export'],
            'POST' => ['api/collections/import/curl', 'api/collections/import/openapi', 'api/code/generate', 'api/environments/import'],
        ];
        $uris = array_merge(...array_values($mine));

        $collection = Route::getRoutes();

        $routesRef = new \ReflectionProperty($collection, 'routes');
        $routesRef->setAccessible(true);
        $routes = $routesRef->getValue($collection);

        foreach ($mine as $method => $methodUris) {
            $first = [];
            foreach ($methodUris as $uri) {
                if (isset($routes[$method][$uri])) {
                    $first[$uri] = $routes[$method][$uri];
                }
            }
            $routes[$method] = [...$first, ...array_diff_key($routes[$method] ?? [], $first)];
        }
        $routesRef->setValue($collection, $routes);

        $allRef = new \ReflectionProperty($collection, 'allRoutes');
        $allRef->setAccessible(true);
        $all = $allRef->getValue($collection);
        usort($all, fn ($a, $b) => (in_array($a->uri(), $uris, true) ? 0 : 1) <=> (in_array($b->uri(), $uris, true) ? 0 : 1));
        $allRef->setValue($collection, $all);
    }

    protected function workspaceFor(User $user): Workspace
    {
        return Workspace::create(['user_id' => $user->id, 'name' => 'WS']);
    }

    protected function openApiSpec(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Pet API', 'description' => 'Demo API', 'version' => '1.0.0'],
            'servers' => [['url' => 'https://api.example.com/v1']],
            'paths' => [
                '/users' => [
                    'get' => [
                        'tags' => ['Users'],
                        'summary' => 'List users',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                    'post' => [
                        'tags' => ['Users'],
                        'summary' => 'Create user',
                        'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/User']]]],
                        'security' => [['bearerAuth' => []]],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
                '/users/{id}' => [
                    'get' => [
                        'tags' => ['Users'],
                        'summary' => 'Get user',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'User' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'age' => ['type' => 'integer'],
                    ]],
                ],
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
    }

    // -- cURL importer -----------------------------------------------------

    public function test_curl_parse_get_with_headers_and_query(): void
    {
        $parsed = (new CurlImporter)->parse(
            "curl -X GET 'https://example.com/users?page=2&role=admin' -H 'Accept: application/json' -H 'X-Key: secret'"
        );

        $this->assertSame('GET', $parsed['method']);
        $this->assertSame('https://example.com/users?page=2&role=admin', $parsed['url']);
        $this->assertSame('page', $parsed['params'][0]['key']);
        $this->assertSame('2', $parsed['params'][0]['value']);
        $this->assertSame('role', $parsed['params'][1]['key']);
        $this->assertSame('Accept', $parsed['headers'][0]['key']);
        $this->assertSame('application/json', $parsed['headers'][0]['value']);
        $this->assertSame('none', $parsed['auth_type']);
    }

    public function test_curl_parse_post_json_with_quoting(): void
    {
        $parsed = (new CurlImporter)->parse(
            'curl -X POST "https://example.com/search?q=hello world" '.
            "-H 'Content-Type: application/json' --data-raw \$'{\"name\":\"a\\nb\"}'"
        );

        $this->assertSame('POST', $parsed['method']);
        $this->assertSame('https://example.com/search?q=hello world', $parsed['url']);
        $this->assertSame('raw', $parsed['body_type']);
        $this->assertSame("{\"name\":\"a\nb\"}", $parsed['body']['raw']);
        $this->assertSame('json', $parsed['body']['raw_language']);
    }

    public function test_curl_parse_form_data_and_urlencode(): void
    {
        $parsed = (new CurlImporter)->parse(
            "curl https://example.com/upload -F 'name=Sabbir' -F 'avatar=@photo.png;type=image/png'"
        );

        $this->assertSame('POST', $parsed['method']);
        $this->assertSame('form-data', $parsed['body_type']);
        $this->assertSame('name', $parsed['body'][0]['key']);
        $this->assertSame('text', $parsed['body'][0]['type']);
        $this->assertSame('avatar', $parsed['body'][1]['key']);
        $this->assertSame('file', $parsed['body'][1]['type']);
        $this->assertSame('photo.png', $parsed['body'][1]['value']);

        $encoded = (new CurlImporter)->parse(
            "curl https://example.com/login --data-urlencode 'user=sabbir' --data-urlencode 'pass=p%40ss'"
        );
        $this->assertSame('urlencoded', $encoded['body_type']);
        $this->assertSame('pass', $encoded['body'][1]['key']);
    }

    public function test_curl_parse_basic_auth_user_agent_and_compressed(): void
    {
        $parsed = (new CurlImporter)->parse(
            "curl -u 'sabbir:s3cret' -A 'MyAgent/1.0' --compressed https://example.com/protected"
        );

        $this->assertSame('basic', $parsed['auth_type']);
        $this->assertSame('sabbir', $parsed['auth']['username']);
        $this->assertSame('s3cret', $parsed['auth']['password']);
        $this->assertSame('MyAgent/1.0', collect($parsed['headers'])->firstWhere('key', 'User-Agent')['value']);
        $this->assertSame('gzip, deflate', collect($parsed['headers'])->firstWhere('key', 'Accept-Encoding')['value']);
    }

    public function test_curl_parse_many_commands_and_import(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $result = (new CurlImporter)->import(
            "curl https://example.com/one\ncurl -X DELETE https://example.com/two",
            $workspace, $user,
        );

        $this->assertSame('cURL Import', $result['collection']->name);
        $this->assertCount(2, $result['requests']);
        $this->assertSame('GET', $result['requests'][0]->method);
        $this->assertSame('DELETE', $result['requests'][1]->method);
        $this->assertDatabaseHas('requests', ['url' => 'https://example.com/two', 'method' => 'DELETE']);
    }

    public function test_curl_import_endpoint_creates_requests(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $workspace = $this->workspaceFor($user);

        $this->postJson('/api/collections/import/curl', [
            'workspace_id' => $workspace->id,
            'curl' => "curl -X GET 'https://example.com/users' -H 'Accept: application/json'",
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.collection.name', 'cURL Import')
            ->assertJsonPath('data.requests.0.method', 'GET');

        $this->postJson('/api/collections/import/curl', [
            'workspace_id' => $workspace->id,
            'curl' => 'not a curl command',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    // -- OpenAPI importer --------------------------------------------------

    public function test_openapi_import_creates_collection_folders_and_requests(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $collection = (new OpenApiImporter)->import($this->openApiSpec(), $workspace, $user);

        $this->assertSame('Pet API', $collection->name);
        $this->assertSame('baseUrl', $collection->variables[0]['key']);
        $this->assertSame('https://api.example.com/v1', $collection->variables[0]['value']);
        $this->assertSame('Users', $collection->folders->first()->name);
        $this->assertCount(3, $collection->requests);

        $list = $collection->requests->firstWhere('name', 'List users');
        $this->assertSame('GET', $list->method);
        $this->assertSame('{{baseUrl}}/users', $list->url);
        $this->assertSame('page', $list->params[0]['key']);

        $getOne = $collection->requests->firstWhere('name', 'Get user');
        $this->assertSame('{{baseUrl}}/users/:id', $getOne->url);
        $this->assertSame('path', $getOne->params[0]['in']);

        $create = $collection->requests->firstWhere('name', 'Create user');
        $this->assertSame('POST', $create->method);
        $this->assertSame('bearer', $create->auth_type);
        $this->assertSame('raw', $create->body_type);
        $this->assertStringContainsString('user@example.com', $create->body['raw']);
    }

    public function test_openapi_import_endpoint_and_invalid_documents_return_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $workspace = $this->workspaceFor($user);

        $this->postJson('/api/collections/import/openapi', [
            'workspace_id' => $workspace->id,
            'json' => $this->openApiSpec(),
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Pet API');

        $this->postJson('/api/collections/import/openapi', [
            'workspace_id' => $workspace->id,
            'json' => ['openapi' => '2.0', 'info' => ['title' => 'Old'], 'paths' => []],
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->postJson('/api/collections/import/openapi', [
            'workspace_id' => $workspace->id,
            'content' => "openapi: 3.0.0\ninfo:\n  title: YAML API\npaths: {}",
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_openapi_yaml_error_names_missing_package(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        try {
            (new OpenApiImporter)->import("openapi: 3.0.0\ninfo:\n  title: YAML API\npaths: {}", $workspace, $user);
            $this->fail('Expected InvalidArgumentException for YAML without symfony/yaml.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('symfony/yaml', $e->getMessage());
        }
    }

    // -- Code generator ----------------------------------------------------

    protected function sampleRequest(): array
    {
        return [
            'method' => 'POST',
            'url' => '{{baseUrl}}/users',
            'params' => [['key' => 'page', 'value' => '2', 'enabled' => true]],
            'headers' => [['key' => 'Accept', 'value' => 'application/json', 'enabled' => true]],
            'auth_type' => 'bearer',
            'auth' => ['token' => 'tok123'],
            'body_type' => 'raw',
            'body' => ['raw' => '{"name":"Sabbir"}', 'raw_language' => 'json'],
        ];
    }

    public function test_codegen_curl_fetch_python_contain_method_and_url(): void
    {
        $generator = new CodeGenerator;
        $request = $this->sampleRequest();

        $curl = $generator->generate($request, 'curl');
        $this->assertStringContainsString('POST', $curl);
        $this->assertStringContainsString('{{baseUrl}}/users?page=2', $curl);
        $this->assertStringContainsString('Authorization: Bearer tok123', $curl);
        $this->assertStringContainsString('{"name":"Sabbir"}', $curl);

        $fetch = $generator->generate($request, 'javascript-fetch');
        $this->assertStringContainsString('fetch(', $fetch);
        $this->assertStringContainsString('POST', $fetch);
        $this->assertStringContainsString('{{baseUrl}}/users?page=2', $fetch);

        $python = $generator->generate($request, 'python-requests');
        $this->assertStringContainsString('requests.request("POST"', $python);
        $this->assertStringContainsString('{{baseUrl}}/users?page=2', $python);
        $this->assertStringContainsString('Bearer tok123', $python);
    }

    public function test_codegen_all_languages_contain_url_and_reject_unknown(): void
    {
        $generator = new CodeGenerator;

        foreach (CodeGenerator::LANGUAGES as $language) {
            $code = $generator->generate($this->sampleRequest(), $language);
            $this->assertStringContainsString('{{baseUrl}}/users', $code, "Language {$language} lost the URL.");
        }

        $this->expectException(InvalidArgumentException::class);
        $generator->generate($this->sampleRequest(), 'ruby');
    }

    public function test_codegen_endpoints(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $workspace = $this->workspaceFor($user);

        $collection = Collection::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'name' => 'API']);
        $apiRequest = $collection->requests()->create([
            'user_id' => $user->id,
            'name' => 'Create user',
            'method' => 'POST',
            'url' => 'https://example.com/users',
            'headers' => [['key' => 'Accept', 'value' => 'application/json', 'enabled' => true]],
            'auth_type' => 'none',
            'body_type' => 'raw',
            'body' => ['raw' => '{"name":"Sabbir"}'],
        ]);

        $this->getJson('/api/requests/'.$apiRequest->id.'/code?language=python-requests')
            ->assertOk()
            ->assertJsonPath('data.language', 'python-requests')
            ->assertJsonPath('data.code', fn ($code) => str_contains($code, 'https://example.com/users'));

        $this->getJson('/api/requests/'.$apiRequest->id.'/code?language=ruby')
            ->assertStatus(422)->assertJsonPath('success', false);

        $this->postJson('/api/code/generate', [
            'language' => 'javascript-axios',
            'method' => 'GET',
            'url' => 'https://example.com/users',
        ])->assertOk()
            ->assertJsonPath('data.language', 'javascript-axios')
            ->assertJsonPath('data.code', fn ($code) => str_contains($code, 'axios('));
    }

    // -- Environment import/export -----------------------------------------

    public function test_environment_import_export_round_trip(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $workspace = $this->workspaceFor($user);

        $imported = $this->postJson('/api/environments/import', [
            'workspace_id' => $workspace->id,
            'json' => [
                'name' => 'Postman Env',
                'values' => [
                    ['key' => 'host', 'value' => 'api.example.com'],
                    ['key' => 'token', 'value' => 'secret', 'enabled' => false],
                ],
            ],
        ])->assertCreated()->assertJsonPath('success', true)->json('data');

        $this->assertSame('host', $imported['variables'][0]['key']);
        $this->assertFalse($imported['variables'][1]['enabled']);

        $this->getJson('/api/environments/'.$imported['id'].'/export')
            ->assertOk()
            ->assertJsonPath('data.name', 'Postman Env')
            ->assertJsonPath('data.values.0.key', 'host')
            ->assertJsonPath('data.values.0.value', 'api.example.com')
            ->assertJsonPath('data.values.1.enabled', false)
            ->assertJsonPath('data._postman_variable_scope', 'environment');

        $this->postJson('/api/environments/import', [
            'workspace_id' => $workspace->id,
            'json' => ['values' => []],
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    // -- Postman Collection v2.1 round-trip --------------------------------

    public function test_postman_collection_round_trip_keeps_requests_and_methods(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $postman = [
            'info' => ['name' => 'Round Trip', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [
                [
                    'name' => 'Users',
                    'item' => [
                        ['name' => 'List users', 'request' => ['method' => 'GET', 'url' => 'https://example.com/users', 'header' => []]],
                        ['name' => 'Create user', 'request' => ['method' => 'POST', 'url' => 'https://example.com/users', 'header' => [], 'body' => ['mode' => 'raw', 'raw' => '{}']]],
                    ],
                ],
                ['name' => 'Delete user', 'request' => ['method' => 'DELETE', 'url' => 'https://example.com/users/1', 'header' => []]],
            ],
        ];

        $collection = (new CollectionImporter)->import($postman, $workspace, $user);
        $exported = (new CollectionExporter)->export($collection);

        $methods = [];
        $this->collectMethods($exported['item'], $methods);

        $this->assertCount(3, $methods);
        $this->assertSame(['GET', 'POST', 'DELETE'], $methods);
        $this->assertSame('Round Trip', $exported['info']['name']);
    }

    protected function collectMethods(array $items, array &$methods): void
    {
        foreach ($items as $item) {
            if (isset($item['item'])) {
                $this->collectMethods($item['item'], $methods);
            } elseif (isset($item['request']['method'])) {
                $methods[] = $item['request']['method'];
            }
        }
    }
}
