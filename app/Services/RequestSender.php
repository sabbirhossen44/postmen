<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\History;
use App\Models\Media;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends a raw HTTP request with Guzzle, applies auth, resolves
 * {{variables}}, measures timing, and records a History row.
 *
 * SSRF policy (deliberate, kept as-is — see audit §2.9): requests to
 * localhost and to private / loopback / link-local addresses are refused.
 * That blocks the common local-dev case (http://localhost:3000), but
 * opening it up is a security decision for Sabbir, not something to
 * change silently in a UI-parity task. If local dev APIs ever need to
 * work through /send, add an explicit opt-in (e.g. a local-mode
 * allowlist in config) instead of weakening this guard.
 */
class RequestSender
{
    public const MAX_BODY_BYTES = 2 * 1024 * 1024;

    /**
     * @param  array{method?: string, url: string, params?: array, headers?: array, auth_type?: string, auth?: array|null, body_type?: string, body?: mixed, body_file_media_id?: int|null, settings?: array, variables?: array}  $payload
     */
    public function send(array $payload, ?User $user = null, ?ApiRequest $savedRequest = null): array
    {
        $method = strtoupper($payload['method'] ?? 'GET');
        $variables = $payload['variables'] ?? [];
        $url = VariableResolver::resolve((string) ($payload['url'] ?? ''), $variables);

        // Path variables (:id in the URL) are substituted from params rows
        // flagged path / in=path, and are not sent as query params.
        foreach ($payload['params'] ?? [] as $param) {
            if (is_array($param) && ($param['enabled'] ?? true) && isset($param['key'])
                && (($param['path'] ?? false) || ($param['in'] ?? '') === 'path')) {
                $value = VariableResolver::resolve((string) ($param['value'] ?? ''), $variables);
                $url = str_replace(':'.$param['key'], rawurlencode($value), $url);
            }
        }

        $startedAt = microtime(true);
        $result = [
            'status' => null,
            'status_text' => null,
            'time_ms' => 0,
            'size' => 0,
            'headers' => [],
            'body' => '',
            'content_type' => null,
            'is_json' => false,
            'error' => null,
            'history_id' => null,
            'request_echo' => $this->requestEcho($payload, $variables, $method, $url),
        ];

        $ssrfError = $this->ssrfError($url);

        if ($ssrfError !== null) {
            $result['error'] = $ssrfError;
            $result['time_ms'] = (int) ((microtime(true) - $startedAt) * 1000);
            $result['history_id'] = $this->recordHistory($user, $savedRequest, $method, $url, $result, $payload);

            return $result;
        }

        try {
            $options = $this->buildOptions($payload, $variables, $user);
            $settings = $payload['settings'] ?? [];
            $client = new Client([
                'timeout' => (int) ($settings['timeout'] ?? 30),
                'http_errors' => false,
                'allow_redirects' => ($settings['follow_redirects'] ?? true)
                    ? ['max' => (int) ($settings['max_redirects'] ?? 5), 'strict' => true]
                    : false,
            ]);

            $response = $client->request($method, $url, $options);

            $body = (string) $response->getBody();
            $headers = [];

            foreach ($response->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }

            $contentType = $response->getHeaderLine('Content-Type') ?: null;

            $result = array_merge($result, [
                'status' => $response->getStatusCode(),
                'status_text' => $response->getReasonPhrase(),
                'time_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'size' => strlen($body),
                'headers' => $headers,
                'body' => Str::limit($body, self::MAX_BODY_BYTES, ''),
                'content_type' => $contentType,
                'is_json' => $contentType !== null && str_contains($contentType, 'json'),
            ]);
        } catch (RequestException $e) {
            $result['error'] = $e->getMessage();
            $result['time_ms'] = (int) ((microtime(true) - $startedAt) * 1000);

            if ($e->hasResponse()) {
                $result['status'] = $e->getResponse()->getStatusCode();
                $result['body'] = Str::limit((string) $e->getResponse()->getBody(), self::MAX_BODY_BYTES, '');
            }
        } catch (GuzzleException $e) {
            $result['error'] = $e->getMessage();
            $result['time_ms'] = (int) ((microtime(true) - $startedAt) * 1000);
        }

        $result['history_id'] = $this->recordHistory($user, $savedRequest, $method, $url, $result, $payload);

        return $result;
    }

    /** What was actually sent (auth values redacted) — feeds the console drawer. */
    protected function requestEcho(array $payload, array $variables, string $method, string $url): array
    {
        $headers = VariableResolver::resolveArray($payload['headers'] ?? [], $variables);
        $shown = [];
        foreach ($headers as $header) {
            if (is_array($header) && ($header['enabled'] ?? true) && isset($header['key'])) {
                $shown[$header['key']] = $header['value'] ?? '';
            }
        }
        $authType = $payload['auth_type'] ?? 'none';
        if ($authType === 'bearer') {
            $shown['Authorization'] = 'Bearer •••';
        } elseif ($authType === 'basic') {
            $shown['Authorization'] = 'Basic •••';
        } elseif ($authType === 'api_key') {
            $auth = $payload['auth'] ?? [];
            $in = $auth['in'] ?? $auth['add_to'] ?? 'header';
            if ($in !== 'query' && ! empty($auth['key'])) {
                $shown[$auth['key']] = '•••';
            }
        }

        return [
            'method' => $method,
            'url' => $url,
            'headers' => $shown,
            'body_type' => $payload['body_type'] ?? 'none',
        ];
    }

    /** Build Guzzle options: query, headers, auth, body. */
    protected function buildOptions(array $payload, array $variables, ?User $user = null): array
    {
        $params = VariableResolver::resolveArray($payload['params'] ?? [], $variables);
        $headers = VariableResolver::resolveArray($payload['headers'] ?? [], $variables);
        $auth = VariableResolver::resolveArray($payload['auth'] ?? [], $variables);

        $query = [];
        $requestHeaders = [];

        foreach ($params as $param) {
            if (is_array($param) && ($param['enabled'] ?? true) && isset($param['key'])
                && ! ($param['path'] ?? false) && ($param['in'] ?? '') !== 'path') {
                $query[$param['key']] = $param['value'] ?? '';
            }
        }

        foreach ($headers as $header) {
            if (is_array($header) && ($header['enabled'] ?? true) && isset($header['key'])) {
                $requestHeaders[$header['key']] = $header['value'] ?? '';
            }
        }

        $this->applyAuth($payload['auth_type'] ?? 'none', $auth, $requestHeaders, $query);

        $options = [
            'query' => $query,
            'headers' => $requestHeaders,
        ];

        $bodyType = $payload['body_type'] ?? 'none';
        $body = $payload['body'] ?? null;

        if ($bodyType === 'raw' && $body !== null) {
            // Body may be a plain string, or { raw, raw_language }.
            if (is_array($body) && array_key_exists('raw', $body)) {
                $raw = VariableResolver::resolve((string) ($body['raw'] ?? ''), $variables);
                $language = $body['raw_language'] ?? null;
            } else {
                $raw = is_string($body)
                    ? VariableResolver::resolve($body, $variables)
                    : VariableResolver::resolve(json_encode($body) ?: '', $variables);
                $language = null;
            }
            $options['body'] = $raw;
            // Auto Content-Type for JSON raw bodies, unless the user set one.
            if (! isset($requestHeaders['Content-Type']) && $language === 'json') {
                $options['headers']['Content-Type'] = 'application/json';
            }
        } elseif ($bodyType === 'urlencoded' && is_array($body)) {
            // Saved bodies nest rows under the 'urlencoded' key.
            $rows = $body['urlencoded'] ?? $body;
            $form = [];
            foreach (VariableResolver::resolveArray($rows, $variables) as $item) {
                if (is_array($item) && ($item['enabled'] ?? true) && isset($item['key'])) {
                    $form[$item['key']] = $item['value'] ?? '';
                }
            }
            $options['form_params'] = $form;
        } elseif ($bodyType === 'form-data' && is_array($body)) {
            // Saved bodies nest rows under the 'formdata' key.
            $rows = $body['formdata'] ?? $body;
            $multipart = [];
            foreach (VariableResolver::resolveArray($rows, $variables) as $item) {
                if (! is_array($item) || ! ($item['enabled'] ?? true) || ! isset($item['key'])) {
                    continue;
                }
                // File rows send real bytes: the frontend uploads the file
                // to /media first and passes its media_id here. A plain
                // string value is never sent in place of the file.
                if (($item['type'] ?? 'text') === 'file' && ! empty($item['media_id'])) {
                    $media = $this->ownedMedia((int) $item['media_id'], $user);
                    if ($media) {
                        $multipart[] = [
                            'name' => $item['key'],
                            'contents' => Storage::disk($media->disk)->readStream($media->path),
                            'filename' => $media->original_name ?: basename($media->path),
                        ];
                        continue;
                    }
                }
                $multipart[] = ['name' => $item['key'], 'contents' => $item['value'] ?? ''];
            }
            $options['multipart'] = $multipart;
        } elseif ($bodyType === 'binary') {
            // Binary was validated but dropped before — now it is sent.
            // Source, in order: the saved request's media file, an inline
            // base64 body, or a plain raw string.
            $media = null;
            if (! empty($payload['body_file_media_id'])) {
                $media = $this->ownedMedia((int) $payload['body_file_media_id'], $user);
            }
            if ($media) {
                $options['body'] = Storage::disk($media->disk)->readStream($media->path);
                if (! isset($requestHeaders['Content-Type']) && $media->mime_type) {
                    $options['headers']['Content-Type'] = $media->mime_type;
                }
            } elseif (is_array($body) && ! empty($body['binary'])) {
                $options['body'] = base64_decode((string) $body['binary'], true) ?: '';
                if (! isset($requestHeaders['Content-Type']) && ! empty($body['binary_content_type'])) {
                    $options['headers']['Content-Type'] = $body['binary_content_type'];
                }
            } elseif (is_string($body)) {
                $options['body'] = VariableResolver::resolve($body, $variables);
            }
        } elseif ($bodyType === 'graphql' && is_array($body)) {
            // GraphQL is a JSON envelope: { query, variables }.
            $graphql = $body['graphql'] ?? $body;
            $variablesJson = $graphql['variables'] ?? '';
            $decoded = is_string($variablesJson) && $variablesJson !== ''
                ? (json_decode($variablesJson, true) ?? new \stdClass)
                : (is_array($variablesJson) ? $variablesJson : new \stdClass);
            $options['json'] = [
                'query' => VariableResolver::resolve((string) ($graphql['query'] ?? ''), $variables),
                'variables' => $decoded,
            ];
            if (! isset($requestHeaders['Content-Type'])) {
                $options['headers']['Content-Type'] = 'application/json';
            }
        }

        return $options;
    }

    protected function applyAuth(string $type, array $auth, array &$headers, array &$query): void
    {
        match ($type) {
            'bearer' => $headers['Authorization'] = 'Bearer '.($auth['token'] ?? ''),
            'basic' => $headers['Authorization'] = 'Basic '.base64_encode(($auth['username'] ?? '').':'.($auth['password'] ?? '')),
            'api_key' => $this->applyApiKey($auth, $headers, $query),
            default => null,
        };
    }

    protected function applyApiKey(array $auth, array &$headers, array &$query): void
    {
        $key = $auth['key'] ?? '';
        $value = $auth['value'] ?? '';

        // Canonical key is `in` ('header' | 'query'), matching the
        // importer/exporter. `add_to` is accepted as a legacy alias so
        // older saved requests still work.
        $in = $auth['in'] ?? $auth['add_to'] ?? 'header';

        if ($in === 'query') {
            $query[$key] = $value;
        } else {
            $headers[$key] = $value;
        }
    }

    /** A media file only if it belongs to the sending user. */
    protected function ownedMedia(int $mediaId, ?User $user): ?Media
    {
        if ($user === null) {
            return null;
        }

        return Media::where('id', $mediaId)->where('user_id', $user->id)->first();
    }

    /**
     * Returns an error message when the URL targets a private,
     * loopback or link-local address, or cannot be resolved — else null.
     */
    public function ssrfError(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return 'Invalid URL.';
        }

        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return 'Requests to localhost are not allowed.';
        }

        // Direct IP literal.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPrivateIp($host)
                ? 'Requests to private or reserved IP addresses are not allowed.'
                : null;
        }

        // Resolve the hostname and check every address it maps to.
        $addresses = gethostbynamel($host);

        if ($addresses === false) {
            return 'Could not resolve host: '.$host;
        }

        foreach ($addresses as $address) {
            if ($this->isPrivateIp($address)) {
                return 'Requests to private or reserved IP addresses are not allowed.';
            }
        }

        return null;
    }

    protected function isPrivateIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE rejects private,
        // reserved, loopback and link-local ranges.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** Snapshot of the sent request, so History can restore it in full. */
    protected function requestSnapshot(array $payload): array
    {
        $auth = $payload['auth'] ?? [];
        if (is_array($auth)) {
            // Secrets are never stored in the snapshot.
            foreach (['token', 'password', 'value'] as $secret) {
                if (isset($auth[$secret])) {
                    $auth[$secret] = '';
                }
            }
        }

        $body = $payload['body'] ?? null;
        if (is_array($body) && ($payload['body_type'] ?? null) === 'form-data') {
            // File rows keep key/type/filename only — never bytes or paths.
            $body = array_map(function ($row) {
                if (is_array($row) && ($row['type'] ?? 'text') === 'file') {
                    return ['key' => $row['key'] ?? '', 'type' => 'file', 'value' => $row['value'] ?? '', 'enabled' => $row['enabled'] ?? true];
                }

                return $row;
            }, $body);
        }
        if (is_array($body) && ($payload['body_type'] ?? null) === 'binary') {
            $body = ['binary' => null, 'binary_content_type' => $body['binary_content_type'] ?? null];
        }

        return [
            'method' => strtoupper($payload['method'] ?? 'GET'),
            'url' => $payload['url'] ?? '',
            'params' => $payload['params'] ?? [],
            'headers' => $payload['headers'] ?? [],
            'auth_type' => $payload['auth_type'] ?? 'none',
            'auth' => $auth,
            'body_type' => $payload['body_type'] ?? 'none',
            'body' => $body,
            'collection_id' => $payload['collection_id'] ?? null,
            'folder_id' => $payload['folder_id'] ?? null,
        ];
    }

    protected function recordHistory(?User $user, ?ApiRequest $savedRequest, string $method, string $url, array $result, array $payload = []): ?int
    {
        if ($user === null) {
            return null;
        }

        $history = History::create([
            'user_id' => $user->id,
            'request_id' => $savedRequest?->id,
            'method' => $method,
            'url' => $url,
            'status' => $result['status'],
            'time_ms' => $result['time_ms'],
            'size' => $result['size'],
            'response_headers' => $result['headers'],
            'response_body' => $result['body'],
            'error' => $result['error'],
            'request_snapshot' => $payload === [] ? null : $this->requestSnapshot($payload),
        ]);

        return $history->id;
    }
}
