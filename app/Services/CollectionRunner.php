<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\Folder;
use App\Models\Run;
use App\Models\User;
use App\Repositories\CollectionRepository;
use App\Repositories\EnvironmentRepository;
use App\Repositories\GlobalVariableRepository;
use App\Repositories\RunRepository;
use App\Repositories\RunResultRepository;

/**
 * Runs a collection (or one folder of it) request by request, in
 * sort_order, for N iterations, reusing RequestSender + ScriptRunner for
 * every request — so a run behaves exactly like sending each request by
 * hand, scripts included. History rows are written by RequestSender as
 * normal sends do.
 *
 * Variable scopes per iteration, highest wins: data row > environment >
 * collection > globals. A pre-request script runs before each send (its
 * variable updates apply to that send), a test script runs after each
 * response (its updates persist for the next request).
 */
class CollectionRunner
{
    public function __construct(
        protected RequestSender $sender,
        protected ScriptRunner $scripts,
    ) {}

    /**
     * @param  array{stop_on_error?: bool, data?: array<int, array>}  $options
     */
    public function run(Run $run, User $user, array $options = []): Run
    {
        $collection = CollectionRepository::findForUser($run->collection_id, $user->id);
        abort_unless($collection, 404, 'Collection not found.');

        $environment = null;
        if ($run->environment_id !== null) {
            $environment = EnvironmentRepository::findOrFail($run->environment_id);
            abort_unless($environment->user_id === $user->id, 403, 'You do not own this environment.');
        }

        $folder = null;
        if ($run->folder_id !== null) {
            $folder = Folder::where('id', $run->folder_id)
                ->where('collection_id', $collection->id)->first();
            abort_unless($folder, 404, 'Folder not found.');
        }

        $requests = $this->requestsFor($collection, $folder);
        $dataRows = array_values($options['data'] ?? []);
        $iterations = $dataRows !== [] ? count($dataRows) : max(1, $run->iterations);
        $stopOnError = (bool) ($options['stop_on_error'] ?? false);

        // Scopes live in memory across the run and stay in sync with the
        // database because ScriptRunner::persistVariables refreshes the
        // models after every write.
        $scopes = [
            'environment' => ScriptRunner::flatMap($environment?->variables ?? []),
            'collectionVariables' => ScriptRunner::flatMap($collection->variables ?? []),
            'globals' => $this->globalsFlat($user->id),
            'data' => [],
        ];

        $summary = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'errored' => 0,
            'iterations_completed' => 0,
            'total_time_ms' => 0,
        ];

        $startedAt = microtime(true);
        $stopped = false;
        $first = true;

        try {
            for ($iteration = 0; $iteration < $iterations; $iteration++) {
                $scopes['data'] = ScriptRunner::flatMap($dataRows[$iteration] ?? []);

                foreach ($requests as $apiRequest) {
                    if (! $first && $run->delay_ms > 0) {
                        usleep($run->delay_ms * 1000);
                    }
                    $first = false;

                    $outcome = $this->runOne(
                        $apiRequest, $collection, $environment, $user,
                        $scopes, $iteration,
                    );

                    $summary['total']++;
                    $summary[$outcome['status'] === 'passed' ? 'passed' : ($outcome['status'] === 'failed' ? 'failed' : 'errored')]++;
                    $summary['total_time_ms'] += $outcome['time_ms'];

                    RunResultRepository::create([
                        'run_id' => $run->id,
                        'request_id' => $apiRequest->id,
                        'iteration' => $iteration,
                        'status' => $outcome['status'],
                        'status_code' => $outcome['status_code'],
                        'time_ms' => $outcome['time_ms'],
                        'size' => $outcome['size'],
                        'test_results' => $outcome['test_results'],
                        'error' => $outcome['error'],
                    ]);

                    if ($stopOnError && ($outcome['send_failed'] ?? false)) {
                        $stopped = true;
                        break 2;
                    }
                }

                $summary['iterations_completed'] = $iteration + 1;
            }
        } catch (\Throwable $e) {
            RunRepository::update($run, [
                'status' => 'failed',
                'summary' => array_merge($summary, ['error' => $e->getMessage()]),
            ]);

            throw $e;
        }

        RunRepository::update($run, [
            'status' => $stopped ? 'stopped' : 'completed',
            'summary' => array_merge($summary, [
                'total_time_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]),
        ]);

        return $run->fresh();
    }

    /**
     * Send one request with its scripts. $scopes is updated in place with
     * whatever the scripts changed.
     *
     * @return array{status: string, status_code: int|null, time_ms: int, size: int, test_results: array, error: string|null, send_failed: bool}
     */
    protected function runOne(
        ApiRequest $apiRequest,
        Collection $collection,
        ?Environment $environment,
        User $user,
        array &$scopes,
        int $iteration,
    ): array {
        $scripts = ScriptRunner::scriptsFromPayload([], $apiRequest);

        $requestEcho = [
            'method' => $apiRequest->method,
            'url' => $apiRequest->url,
            'headers' => $this->headerMap($apiRequest->headers ?? []),
            'body' => $apiRequest->body,
        ];

        // Pre-request script: variable updates apply to this send.
        $before = $this->persistableScopes($scopes);
        $pre = $this->scripts->run($scripts['pre_request'], [
            'variables' => $scopes,
            'request' => $requestEcho,
            'response' => null,
        ]);
        $this->applyScriptVariables($scopes, $pre['variables']);
        ScriptRunner::persistVariables($environment, $collection, $before, $pre['variables'], $user->id);

        $result = $this->sender->send(
            $this->payloadFor($apiRequest, $scopes),
            $user,
            $apiRequest,
        );

        // Test script: runs against the response; its variable updates
        // (e.g. saving a token) persist for the next request.
        $before = $this->persistableScopes($scopes);
        $test = $this->scripts->run($scripts['tests'], [
            'variables' => $scopes,
            'request' => $requestEcho,
            'response' => [
                'code' => $result['status'],
                'status' => $result['status_text'],
                'headers' => $result['headers'],
                'body' => $result['body'],
            ],
        ]);
        $this->applyScriptVariables($scopes, $test['variables']);
        ScriptRunner::persistVariables($environment, $collection, $before, $test['variables'], $user->id);

        $testResults = $test['tests'];

        // A test script that throws (syntax error, bad pm.response.json())
        // is a failed test, not a crashed run.
        if ($test['error'] !== null) {
            $testResults[] = [
                'name' => 'Test script',
                'passed' => false,
                'error' => $test['error'],
            ];
        }

        // A pre-request script error does not fail the send — the request
        // still goes out — but it is recorded so the run shows it.
        $error = $result['error'];
        if ($error === null && $pre['error'] !== null) {
            $error = 'Pre-request script error: '.$pre['error'];
        }

        if ($result['error'] !== null || $result['status'] === null) {
            $status = 'error';
        } elseif (collect($testResults)->contains(fn ($t) => ! ($t['passed'] ?? true))) {
            $status = 'failed';
        } else {
            $status = 'passed';
        }

        return [
            'status' => $status,
            'status_code' => $result['status'],
            'time_ms' => $result['time_ms'],
            'size' => $result['size'],
            'test_results' => $testResults,
            'error' => $error,
            // stop_on_error only stops on a send failure, not on a
            // pre-request script error or a failed test.
            'send_failed' => $status === 'error',
        ];
    }

    protected function payloadFor(ApiRequest $apiRequest, array $scopes): array
    {
        return [
            'method' => $apiRequest->method,
            'url' => $apiRequest->url,
            'params' => $apiRequest->params ?? [],
            'headers' => $apiRequest->headers ?? [],
            'auth_type' => $apiRequest->auth_type,
            'auth' => $apiRequest->auth ?? [],
            'body_type' => $apiRequest->body_type,
            'body' => $apiRequest->body,
            'body_file_media_id' => $apiRequest->body_file_media_id,
            'settings' => $apiRequest->settings ?? [],
            'collection_id' => $apiRequest->collection_id,
            'folder_id' => $apiRequest->folder_id,
            'request_id' => $apiRequest->id,
            'variables' => VariableResolver::mergeAll(
                $scopes['globals'],
                $scopes['collectionVariables'],
                $scopes['environment'],
                $scopes['data'],
            ),
        ];
    }

    /** Scopes a script run returns, keyed for persistVariables(). */
    protected function persistableScopes(array $scopes): array
    {
        return [
            'environment' => $scopes['environment'],
            'collectionVariables' => $scopes['collectionVariables'],
            'globals' => $scopes['globals'],
        ];
    }

    protected function applyScriptVariables(array &$scopes, array $updated): void
    {
        $scopes['environment'] = $updated['environment'] ?? $scopes['environment'];
        $scopes['collectionVariables'] = $updated['collectionVariables'] ?? $scopes['collectionVariables'];
        $scopes['globals'] = $updated['globals'] ?? $scopes['globals'];
    }

    protected function globalsFlat(int $userId): array
    {
        $flat = [];

        foreach (GlobalVariableRepository::forUser($userId) as $global) {
            $flat[$global->key] = $global->effectiveValue() ?? '';
        }

        return $flat;
    }

    /** Requests in run order: root requests, then each top folder in turn. */
    protected function requestsFor(Collection $collection, ?Folder $folder): array
    {
        if ($folder !== null) {
            return $this->requestsInFolder($folder);
        }

        $requests = ApiRequest::where('collection_id', $collection->id)
            ->whereNull('folder_id')
            ->orderBy('sort_order')->orderBy('name')->get()->all();

        foreach ($collection->folders()->whereNull('parent_id')->orderBy('sort_order')->orderBy('name')->get() as $top) {
            array_push($requests, ...$this->requestsInFolder($top));
        }

        return $requests;
    }

    /** One folder's requests, then its child folders depth-first. */
    protected function requestsInFolder(Folder $folder): array
    {
        $requests = $folder->requests()->orderBy('sort_order')->orderBy('name')->get()->all();

        foreach ($folder->children()->orderBy('sort_order')->orderBy('name')->get() as $child) {
            array_push($requests, ...$this->requestsInFolder($child));
        }

        return $requests;
    }

    protected function headerMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $header) {
            if (is_array($header) && ($header['enabled'] ?? true) && isset($header['key'])) {
                $map[$header['key']] = $header['value'] ?? '';
            }
        }

        return $map;
    }
}
