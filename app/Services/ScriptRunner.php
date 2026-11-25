<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\GlobalVariable;
use App\Repositories\CollectionRepository;
use App\Repositories\EnvironmentRepository;

/**
 * Runs a user's pm.* script (pre-request or test) in the Node sandbox.
 *
 * User JS is NEVER eval()'d in PHP and NEVER runs in the Laravel process.
 * It is handed to resources/js/sandbox/pm-runner.js as JSON on stdin of a
 * short-lived `node` subprocess (strict 5s timeout), and the result comes
 * back as JSON on stdout. A syntax error, a runtime error or a timeout
 * never crashes the send — it comes back as `error` in the result.
 *
 * Variable scopes follow Postman precedence for reading
 * (data > environment > collection > globals); writes go to the scope the
 * script named (pm.environment / pm.collectionVariables / pm.globals).
 */
class ScriptRunner
{
    /** Hard cap on one script run, including process start-up. */
    public const TIMEOUT_SECONDS = 5;

    /** Scripts longer than this are refused without spawning Node. */
    public const MAX_SCRIPT_BYTES = 200_000;

    /**
     * Run a script.
     *
     * @param  array{
     *     variables?: array{environment?: array, collectionVariables?: array, globals?: array, data?: array},
     *     request?: array{method?: string, url?: string, headers?: array, body?: mixed},
     *     response?: array{code?: int|null, status?: string|null, headers?: array, body?: string|null}|null
     * }  $context
     * @return array{tests: array, logs: array, variables: array{environment: array, collectionVariables: array, globals: array}, error: string|null}
     */
    public function run(?string $script, array $context = []): array
    {
        $variables = [
            'environment' => self::flatMap($context['variables']['environment'] ?? []),
            'collectionVariables' => self::flatMap($context['variables']['collectionVariables'] ?? []),
            'globals' => self::flatMap($context['variables']['globals'] ?? []),
            'data' => self::flatMap($context['variables']['data'] ?? []),
        ];

        if ($script === null || trim($script) === '') {
            return self::emptyResult($variables);
        }

        if (strlen($script) > self::MAX_SCRIPT_BYTES) {
            $result = self::emptyResult($variables);
            $result['error'] = 'Script is too long (max '.self::MAX_SCRIPT_BYTES.' bytes).';

            return $result;
        }

        $runner = base_path('resources/js/sandbox/pm-runner.js');

        if (! is_file($runner)) {
            $result = self::emptyResult($variables);
            $result['error'] = 'Script runner is missing (resources/js/sandbox/pm-runner.js).';

            return $result;
        }

        $input = json_encode([
            'script' => $script,
            'variables' => $variables,
            'request' => [
                'method' => $context['request']['method'] ?? 'GET',
                'url' => $context['request']['url'] ?? '',
                'headers' => $context['request']['headers'] ?? [],
                'body' => $context['request']['body'] ?? null,
            ],
            'response' => $context['response'] ?? null,
        ]);

        $raw = $this->runNode($runner, $input ?: '');

        if ($raw['error'] !== null) {
            $result = self::emptyResult($variables);
            $result['error'] = $raw['error'];

            return $result;
        }

        $decoded = json_decode($raw['stdout'], true);

        if (! is_array($decoded)) {
            $result = self::emptyResult($variables);
            $result['error'] = 'Script runner returned invalid JSON.';

            return $result;
        }

        return $this->normalizeResult($decoded, $variables);
    }

    /**
     * Spawn `node <runner>`, feed $input on stdin, read JSON from stdout.
     * Returns ['stdout' => string, 'error' => string|null].
     */
    protected function runNode(string $runner, string $input): array
    {
        $node = getenv('PM_NODE_BINARY') ?: 'node';
        $command = escapeshellarg($node).' '.escapeshellarg($runner);

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            return ['stdout' => '', 'error' => 'Could not start the Node.js runtime.'];
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                break;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return ['stdout' => '', 'error' => 'Script timed out after '.self::TIMEOUT_SECONDS.' seconds.'];
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    $chunk = stream_get_contents($pipe);
                    if ($chunk !== false && $chunk !== '') {
                        if ($pipe === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 && trim($stdout) === '') {
            $detail = trim($stderr) !== '' ? mb_substr(trim($stderr), 0, 500) : 'exit code '.$exitCode;

            return ['stdout' => '', 'error' => 'Script runner failed: '.$detail];
        }

        return ['stdout' => $stdout, 'error' => null];
    }

    /** Guarantee the result shape, falling back to the input variables. */
    protected function normalizeResult(array $decoded, array $inputVariables): array
    {
        $variables = $decoded['variables'] ?? [];

        return [
            'tests' => is_array($decoded['tests'] ?? null) ? $decoded['tests'] : [],
            'logs' => is_array($decoded['logs'] ?? null) ? $decoded['logs'] : [],
            'variables' => [
                'environment' => is_array($variables['environment'] ?? null)
                    ? $variables['environment']
                    : $inputVariables['environment'],
                'collectionVariables' => is_array($variables['collectionVariables'] ?? null)
                    ? $variables['collectionVariables']
                    : $inputVariables['collectionVariables'],
                'globals' => is_array($variables['globals'] ?? null)
                    ? $variables['globals']
                    : $inputVariables['globals'],
            ],
            'error' => isset($decoded['error']) && is_string($decoded['error'])
                ? $decoded['error']
                : null,
        ];
    }

    public static function emptyResult(array $variables = []): array
    {
        return [
            'tests' => [],
            'logs' => [],
            'variables' => [
                'environment' => $variables['environment'] ?? [],
                'collectionVariables' => $variables['collectionVariables'] ?? [],
                'globals' => $variables['globals'] ?? [],
            ],
            'error' => null,
        ];
    }

    /**
     * Resolve the pre-request / test scripts for a send.
     *
     * Precedence: flat fields on the payload (`pre_request_script`,
     * `test_script`) win over `scripts: { pre_request, tests }`, which win
     * over the saved request's stored scripts.
     *
     * @return array{pre_request: string|null, tests: string|null}
     */
    public static function scriptsFromPayload(array $payload, ?ApiRequest $savedRequest = null): array
    {
        $stored = $savedRequest?->scripts ?? [];
        $inline = is_array($payload['scripts'] ?? null) ? $payload['scripts'] : [];

        return [
            'pre_request' => self::scriptValue(
                $payload['pre_request_script'] ?? null,
                $inline['pre_request'] ?? null,
                $stored['pre_request'] ?? null,
            ),
            'tests' => self::scriptValue(
                $payload['test_script'] ?? null,
                $inline['tests'] ?? null,
                $stored['tests'] ?? null,
            ),
        ];
    }

    protected static function scriptValue(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Persist variable changes a script made.
     *
     * $before / $after are the flat maps that went into the sandbox and came
     * back out. Only keys the script could see are touched: a key present
     * before but missing after was unset, a changed value is updated, a new
     * key is appended. Disabled rows and keys the script never saw are left
     * alone. Globals are stored as GlobalVariable rows (current_value wins,
     * like Postman); unsetting a global deletes its row.
     *
     * @param  array{environment?: array, collectionVariables?: array, globals?: array}  $before
     * @param  array{environment?: array, collectionVariables?: array, globals?: array}  $after
     */
    public static function persistVariables(
        ?Environment $environment,
        ?Collection $collection,
        array $before,
        array $after,
        ?int $userId = null,
    ): void {
        if ($environment !== null && isset($after['environment'])) {
            $rows = self::applyMapDiff(
                $environment->variables ?? [],
                self::flatMap($before['environment'] ?? []),
                self::flatMap($after['environment']),
            );
            EnvironmentRepository::update($environment, ['variables' => $rows]);
            $environment->refresh();
        }

        if ($collection !== null && isset($after['collectionVariables'])) {
            $rows = self::applyMapDiff(
                $collection->variables ?? [],
                self::flatMap($before['collectionVariables'] ?? []),
                self::flatMap($after['collectionVariables']),
            );
            CollectionRepository::update($collection, ['variables' => $rows]);
            $collection->refresh();
        }

        if ($userId !== null && isset($after['globals'])) {
            self::persistGlobals(
                $userId,
                self::flatMap($before['globals'] ?? []),
                self::flatMap($after['globals']),
            );
        }
    }

    protected static function persistGlobals(int $userId, array $before, array $after): void
    {
        foreach ($before as $key => $value) {
            if (! array_key_exists($key, $after)) {
                GlobalVariable::query()
                    ->where('user_id', $userId)->where('key', $key)->delete();
            }
        }

        foreach ($after as $key => $value) {
            GlobalVariable::updateOrCreate(
                ['user_id' => $userId, 'key' => (string) $key],
                ['current_value' => (string) $value],
            );
        }
    }

    /**
     * Apply a flat-map diff onto the stored [{key, value, enabled}] rows.
     * Rows the script never saw (e.g. disabled ones) are kept as-is.
     * A script write lands on the CURRENT value: rows stored with
     * initial_value/current_value get current_value updated, plain
     * value rows get value updated.
     */
    public static function applyMapDiff(array $currentRows, array $before, array $after): array
    {
        $rows = is_array($currentRows) ? array_values($currentRows) : [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! isset($row['key'])) {
                continue;
            }

            $key = (string) $row['key'];
            $seen[$key] = true;

            if (array_key_exists($key, $before) && ! array_key_exists($key, $after)) {
                unset($rows[$index]); // pm.*.unset()
            } elseif (array_key_exists($key, $after)) {
                if (array_key_exists('current_value', $row) || array_key_exists('initial_value', $row)) {
                    $rows[$index]['current_value'] = $after[$key];
                } else {
                    $rows[$index]['value'] = $after[$key];
                }
            }
        }

        $rows = array_values($rows);

        foreach ($after as $key => $value) {
            if (! isset($seen[$key])) {
                $rows[] = ['key' => (string) $key, 'value' => (string) $value, 'enabled' => true];
            }
        }

        return $rows;
    }

    /**
     * Normalise variables to a flat key => string map. Accepts every shape
     * the app stores: a flat map, [{key, value, enabled}] rows, or rows
     * with initial_value/current_value (current wins, Postman behaviour).
     * Disabled rows are skipped.
     */
    public static function flatMap(array $variables): array
    {
        return VariableResolver::normalize($variables);
    }

    /** Flat map back to the stored row shape. */
    public static function toRows(array $flat): array
    {
        $rows = [];

        foreach ($flat as $key => $value) {
            $rows[] = ['key' => (string) $key, 'value' => self::stringValue($value), 'enabled' => true];
        }

        return $rows;
    }

    protected static function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value) ?: '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
