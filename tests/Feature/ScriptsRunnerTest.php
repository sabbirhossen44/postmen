<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RunController;
use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\Run;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CollectionRunner;
use App\Services\RequestSender;
use App\Services\ScriptRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Fake sender: no network, records every call for ordering assertions. */
class FakeRequestSender extends RequestSender
{
    public array $calls = [];

    public function send(array $payload, ?User $user = null, ?ApiRequest $savedRequest = null): array
    {
        $this->calls[] = $payload;

        return [
            'status' => 200,
            'status_text' => 'OK',
            'time_ms' => 5,
            'size' => 2,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"ok":true}',
            'content_type' => 'application/json',
            'is_json' => true,
            'error' => null,
            'history_id' => null,
            'request_echo' => [],
        ];
    }
}

class ScriptsRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Workspace $workspace;

    protected Collection $collection;

    protected Environment $environment;

    protected ScriptRunner $runner;

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
        $this->environment = Environment::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'name' => 'Dev',
            'is_active' => true,
            'variables' => [],
        ]);
        $this->runner = new ScriptRunner;

        // Run routes are not in routes/api.php yet (parent adds them);
        // register them here so the runner can be tested end-to-end.
        // The 'api' group matters: it carries SubstituteBindings, without
        // which {collection} never resolves to a Collection model.
        Route::middleware(['api', 'auth:sanctum'])->prefix('api')->group(function () {
            Route::post('/collections/{collection}/run', [RunController::class, 'store']);
            Route::get('/runs/{run}', [RunController::class, 'show']);
            Route::get('/runs', [RunController::class, 'index']);
        });
    }

    protected function responseContext(): array
    {
        return [
            'variables' => ['environment' => [], 'collectionVariables' => [], 'globals' => [], 'data' => []],
            'request' => ['method' => 'GET', 'url' => 'https://example.com/x', 'headers' => [], 'body' => null],
            'response' => ['code' => 200, 'status' => 'OK', 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"user":{"name":"Sabbir"}}'],
        ];
    }

    public function test_pm_test_pass_and_fail(): void
    {
        $result = $this->runner->run(
            'pm.test("status is 200", () => { pm.expect(pm.response.code).to.equal(200); });'
            .'pm.test("failing one", () => { pm.expect(pm.response.code).to.equal(201); });',
            $this->responseContext(),
        );

        $this->assertNull($result['error']);
        $this->assertCount(2, $result['tests']);
        $this->assertTrue($result['tests'][0]['passed']);
        $this->assertFalse($result['tests'][1]['passed']);
        $this->assertSame('expected 200 to equal 201', $result['tests'][1]['error']);
    }

    public function test_pm_response_to_have_status(): void
    {
        // The UI snippet picker inserts exactly this Postman idiom.
        $result = $this->runner->run(
            'pm.test("Status is 200", () => pm.response.to.have.status(200));'
            .'pm.test("Status is 201 fails", () => pm.response.to.have.status(201));'
            .'pm.test("Response is ok", () => pm.response.to.be.ok());',
            $this->responseContext(),
        );

        $this->assertNull($result['error']);
        $this->assertCount(3, $result['tests']);
        $this->assertTrue($result['tests'][0]['passed']);
        $this->assertFalse($result['tests'][1]['passed']);
        $this->assertTrue($result['tests'][2]['passed']);
    }

    public function test_pm_expect_helpers(): void
    {
        $result = $this->runner->run(
            'pm.test("helpers", () => {'
            .'  pm.expect(pm.response.json().user).to.have.property("name", "Sabbir");'
            .'  pm.expect(pm.response.json().user.name).to.include("Sab");'
            .'  pm.expect(pm.response.code).to.be.above(199);'
            .'  pm.expect(pm.response.code).to.be.below(300);'
            .'  pm.expect(pm.response.headers.get("Content-Type")).to.include("json");'
            .'  pm.expect(pm.variables.get("missing")).to.not.exist;'
            .'});',
            $this->responseContext(),
        );

        $this->assertNull($result['error']);
        $this->assertTrue($result['tests'][0]['passed'], $result['tests'][0]['error'] ?? '');
    }

    public function test_pm_environment_set_is_returned_in_map(): void
    {
        $result = $this->runner->run(
            'pm.environment.set("token", "tok-123");'
            .'pm.collectionVariables.set("base", "https://example.com");'
            .'console.log("hello", 42);',
            $this->responseContext(),
        );

        $this->assertNull($result['error']);
        $this->assertSame('tok-123', $result['variables']['environment']['token']);
        $this->assertSame('https://example.com', $result['variables']['collectionVariables']['base']);
        $this->assertSame(['hello 42'], $result['logs']);
    }

    public function test_sandbox_blocks_require_process_and_fs(): void
    {
        $result = $this->runner->run(
            'pm.test("no require", () => { pm.expect(typeof require).to.equal("undefined"); });'
            .'pm.test("no process", () => { pm.expect(typeof process).to.equal("undefined"); });'
            .'try { require("fs"); } catch (e) { console.log("blocked: " + e.message); }',
            $this->responseContext(),
        );

        $this->assertNull($result['error']);
        $this->assertTrue($result['tests'][0]['passed']);
        $this->assertTrue($result['tests'][1]['passed']);
        $this->assertStringContainsString('blocked: require is not defined', $result['logs'][0]);
    }

    public function test_script_syntax_error_returns_error_not_exception(): void
    {
        $result = $this->runner->run('pm.test( oops', $this->responseContext());

        $this->assertNotNull($result['error']);
        $this->assertSame([], $result['tests']);
    }

    public function test_script_timeout_returns_error(): void
    {
        $result = $this->runner->run('while (true) {}', $this->responseContext());

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('timed out', strtolower($result['error']));
    }

    public function test_empty_script_does_not_spawn_node(): void
    {
        $result = $this->runner->run(null, $this->responseContext());

        $this->assertSame(['tests' => [], 'logs' => [], 'variables' => [
            'environment' => [], 'collectionVariables' => [], 'globals' => [],
        ], 'error' => null], $result);
    }

    public function test_flat_script_fields_are_stored_in_scripts_json(): void
    {
        $created = $this->postJson('/api/requests', [
            'collection_id' => $this->collection->id,
            'name' => 'With scripts',
            'method' => 'GET',
            'url' => 'https://example.com/x',
            'pre_request_script' => 'pm.environment.set("a", "1");',
            'test_script' => 'pm.test("ok", () => {});',
        ])->assertCreated()->json('data');

        $this->assertSame('pm.environment.set("a", "1");', $created['scripts']['pre_request']);
        $this->assertSame('pm.test("ok", () => {});', $created['scripts']['tests']);
        $this->assertSame('pm.environment.set("a", "1");', $created['pre_request_script']);
        $this->assertSame('pm.test("ok", () => {});', $created['test_script']);
    }

    public function test_send_runs_scripts_and_returns_test_results(): void
    {
        $captured = null;
        $this->mock(RequestSender::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('send')->once()->andReturnUsing(
                function ($payload) use (&$captured) {
                    $captured = $payload;

                    return [
                        'status' => 200, 'status_text' => 'OK', 'time_ms' => 3, 'size' => 2,
                        'headers' => [], 'body' => '{}', 'content_type' => 'application/json',
                        'is_json' => true, 'error' => null, 'history_id' => null, 'request_echo' => [],
                    ];
                },
            );
        });

        $response = $this->postJson('/api/send', [
            'method' => 'GET',
            'url' => 'https://example.com/{{token}}',
            'environment_id' => $this->environment->id,
            'pre_request_script' => 'pm.environment.set("token", "tok-123");',
            'test_script' => 'pm.test("status is 200", () => { pm.expect(pm.response.code).to.equal(200); });'
                .'console.log("ran tests");',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame('tok-123', $captured['variables']['token']);
        $this->assertTrue($response->json('data.test_results.0.passed'));
        $this->assertSame(['ran tests'], $response->json('data.console_logs'));
        $this->assertNull($response->json('data.pre_request_error'));

        // The pre-request script's variable change was persisted.
        $variables = $this->environment->fresh()->variables;
        $this->assertSame('tok-123', collect($variables)->firstWhere('key', 'token')['value']);
    }

    public function test_collection_runner_runs_in_order_with_mocked_sender(): void
    {
        $first = ApiRequest::create([
            'collection_id' => $this->collection->id, 'user_id' => $this->user->id,
            'name' => 'First', 'method' => 'GET', 'url' => 'https://example.com/a',
            'sort_order' => 1,
            'scripts' => ['tests' => 'pm.environment.set("token", "T1"); pm.test("ok", () => { pm.expect(pm.response.code).to.equal(200); });'],
        ]);
        $second = ApiRequest::create([
            'collection_id' => $this->collection->id, 'user_id' => $this->user->id,
            'name' => 'Second', 'method' => 'GET', 'url' => 'https://example.com/b',
            'sort_order' => 2,
        ]);

        $run = Run::create([
            'user_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'collection_id' => $this->collection->id,
            'environment_id' => $this->environment->id,
            'iterations' => 1,
            'delay_ms' => 0,
            'status' => 'running',
        ]);

        $sender = new FakeRequestSender;
        $finished = (new CollectionRunner($sender, new ScriptRunner))->run(
            $run, $this->user, ['data' => [['row' => 'a'], ['row' => 'b']]],
        );

        // Data rows drive the iterations: 2 requests x 2 rows = 4 results.
        $this->assertSame('completed', $finished->status);
        $this->assertSame(4, $finished->summary['total']);
        $this->assertSame(4, $finished->summary['passed']);
        $this->assertSame(2, $finished->summary['iterations_completed']);
        $this->assertDatabaseCount('run_results', 4);

        $requestIds = array_map(fn ($call) => $call['request_id'], $sender->calls);
        $this->assertSame([$first->id, $second->id, $first->id, $second->id], $requestIds);

        // The first request's test script set `token`; the second request
        // in the same iteration already sees it.
        $this->assertSame('T1', $sender->calls[1]['variables']['token']);
        // Each iteration's data row is the highest-precedence scope.
        $this->assertSame('b', $sender->calls[2]['variables']['row']);
    }

    public function test_run_endpoint_returns_finished_run(): void
    {
        // Nothing listens on port 1, so both sends fail fast at connect —
        // no external network, but the endpoint + persistence are exercised.
        ApiRequest::create([
            'collection_id' => $this->collection->id, 'user_id' => $this->user->id,
            'name' => 'A', 'method' => 'GET', 'url' => 'http://127.0.0.1:1/a', 'sort_order' => 1,
        ]);
        ApiRequest::create([
            'collection_id' => $this->collection->id, 'user_id' => $this->user->id,
            'name' => 'B', 'method' => 'GET', 'url' => 'http://127.0.0.1:1/b', 'sort_order' => 2,
        ]);

        $response = $this->postJson('/api/collections/'.$this->collection->id.'/run', [
            'iterations' => 1,
            'delay' => 0,
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $data = $response->json('data');
        $this->assertSame('completed', $data['status']);
        $this->assertSame(2, $data['summary']['total']);
        $this->assertSame(2, $data['summary']['errored']);
        $this->assertCount(2, $data['results']);
        $this->assertSame('A', $data['results'][0]['name']);
        $this->assertSame('error', $data['results'][0]['outcome']);
        $this->assertDatabaseCount('runs', 1);
        $this->assertDatabaseCount('run_results', 2);

        // GET /runs/{id} cannot be reached in this test: routes registered
        // in setUp load after the web catch-all (Route::view('/{any}')),
        // so the SPA view wins. In routes/api.php the run routes load
        // before that catch-all, like every other API GET route. Exercise
        // show() directly instead.
        $shown = (new RunController)->show(Run::first());
        $this->assertSame(200, $shown->getStatusCode());
        $this->assertSame($data['id'], json_decode($shown->getContent(), true)['data']['id']);
    }
}
