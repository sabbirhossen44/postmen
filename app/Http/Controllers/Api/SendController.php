<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendRequestRequest;
use App\Repositories\ApiRequestRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\EnvironmentRepository;
use App\Repositories\GlobalVariableRepository;
use App\Services\RequestSender;
use App\Services\ScriptRunner;
use App\Services\VariableResolver;
use Illuminate\Http\JsonResponse;

class SendController extends Controller
{
    public function send(SendRequestRequest $request, RequestSender $sender): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $savedRequest = null;
        if (! empty($data['request_id'])) {
            // Any role may send a saved request in a workspace they can see.
            $savedRequest = ApiRequestRepository::findForUser((int) $data['request_id'], $user->id);
            abort_unless($savedRequest, 404, 'Request not found.');
        }

        // Variables, resolved across every scope in precedence order:
        // local/data > environment > collection > globals.
        $environment = null;
        $collection = null;
        $workspace = null;

        if (! empty($data['environment_id'])) {
            $environment = EnvironmentRepository::findForUser((int) $data['environment_id'], $user->id);
            abort_unless($environment, 404, 'Environment not found.');
            $workspace = $environment->workspace;
        }

        if (! empty($data['collection_id'])) {
            $collection = CollectionRepository::findForUser((int) $data['collection_id'], $user->id);
            $workspace ??= $collection?->workspace;
        }

        if ($environment === null && $collection !== null) {
            $environment = EnvironmentRepository::activeForWorkspace($collection->workspace_id);
        }

        $resolved = VariableResolver::resolveAll(
            $workspace,
            $collection,
            $environment,
            $data['variables'] ?? [],
            $user->id,
        );
        $variables = $resolved['values'];

        // Script scopes, one flat map per scope (data/local is what the
        // caller passed; globals come from the GlobalVariable rows).
        $scopes = [
            'environment' => ScriptRunner::flatMap($environment?->variables ?? []),
            'collectionVariables' => ScriptRunner::flatMap($collection?->variables ?? []),
            'globals' => $this->globalsFlat($user->id),
            'data' => ScriptRunner::flatMap($data['variables'] ?? []),
        ];

        $scripts = ScriptRunner::scriptsFromPayload($data, $savedRequest);
        $scriptRunner = new ScriptRunner;
        $requestEcho = [
            'method' => $data['method'],
            'url' => VariableResolver::resolve((string) $data['url'], $variables),
            'headers' => $this->headerMap($data['headers'] ?? []),
            'body' => $data['body'] ?? null,
        ];

        // Pre-request script: its variable updates apply to this send.
        // A script error never blocks the send — it is reported instead.
        $before = $this->persistableScopes($scopes);
        $pre = $scriptRunner->run($scripts['pre_request'], [
            'variables' => $scopes,
            'request' => $requestEcho,
            'response' => null,
        ]);
        $this->applyScriptVariables($scopes, $pre['variables']);
        ScriptRunner::persistVariables($environment, $collection, $before, $pre['variables'], $user->id);
        $variables = VariableResolver::mergeAll(
            $scopes['globals'],
            $scopes['collectionVariables'],
            $scopes['environment'],
            $scopes['data'],
        );

        $result = $sender->send([
            ...$data,
            'variables' => $variables,
        ], ($data['save_history'] ?? true) ? $user : null, $savedRequest);

        // Test script: runs against the response. Its variable updates
        // (e.g. saving a token from the response) are persisted too.
        $before = $this->persistableScopes($scopes);
        $test = $scriptRunner->run($scripts['tests'], [
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
        if ($test['error'] !== null) {
            $testResults[] = ['name' => 'Test script', 'passed' => false, 'error' => $test['error']];
        }

        $result['test_results'] = $testResults;
        $result['console_logs'] = [...$pre['logs'], ...$test['logs']];
        $result['pre_request_logs'] = $pre['logs'];
        $result['pre_request_error'] = $pre['error'];
        $result['test_script_error'] = $test['error'];

        $status = $result['error'] !== null && $result['status'] === null ? 422 : 200;

        return response()->json([
            'success' => $result['error'] === null,
            'message' => $result['error'] ?? 'Request sent.',
            'data' => $result,
        ], $status);
    }

    protected function globalsFlat(int $userId): array
    {
        $flat = [];

        foreach (GlobalVariableRepository::forAccessibleUser($userId) as $global) {
            $flat[$global->key] = $global->effectiveValue() ?? '';
        }

        return $flat;
    }

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
