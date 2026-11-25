<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\History;
use App\Models\Media;
use App\Models\User;
use App\Repositories\CollectionRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends a raw HTTP request with Guzzle, applies auth, resolves
 * {{variables}}, measures timing, and records a History row.
 *
 * SSRF policy (config/postmen.php, ALLOW_PRIVATE_IPS, default true):
 * Postman lets you call local APIs, so by default this does too —
 * localhost, *.test / *.local hosts and private IPs are allowed, which
 * is what a local dev install needs (e.g. http://allsolution.test).
 * The cloud metadata endpoint (169.254.169.254, whole 169.254.0.0/16
 * range) is ALWAYS blocked. On a public deployment set
 * ALLOW_PRIVATE_IPS=false and the guard refuses private, loopback and
 * link-local addresses again.
 */
class RequestSender
{
    public const MAX_BODY_BYTES = 2 * 1024 * 1024;

    /**
     * Per-request settings with defaults. Keys: follow_redirects (bool),
     * timeout (seconds), verify_ssl (bool), max_redirects (int),
     * send_cookies (bool).
     */
    public static function normalizeSettings(?array $settings): array
    {
        $settings ??= [];

        return [
            'follow_redirects' => (bool) ($settings['follow_redirects'] ?? true),
            'timeout' => (int) ($settings['timeout'] ?? 30),
            'verify_ssl' => (bool) ($settings['verify_ssl'] ?? true),
            'max_redirects' => (int) ($settings['max_redirects'] ?? 5),
            'send_cookies' => (bool) ($settings['send_cookies'] ?? true),
        ];
    }

    /**
     * @param  array{method?: string, url: string, params?: array, headers?: array, auth_type?: string, auth?: array|null, body_type?: string, body?: mixed, body_file_media_id?: int|null, settings?: array, variables?: array, collection_id?: int|null, workspace_id?: int|null}  $payload
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

        $settings = self::normalizeSettings($payload['settings'] ?? []);
        $workspaceId = $this->resolveWorkspaceId($payload, $savedRequest, $user);

        try {
            $options = $this->buildOptions($payload, $variables, $user, $url, $savedRequest);

            // Cookie jar: send stored cookies, and persist Set-Cookie
            // afterwards — only when the request opts into cookies.
            if ($settings['send_cookies'] && $user !== null && $workspaceId !== null) {
                $options['cookies'] = CookieService::jarFor($user->id, $workspaceId);
            }

            $client = new Client([
                'timeout' => $settings['timeout'],
                'verify' => $settings['verify_ssl'],
                'http_errors' => false,
                'allow_redirects' => $settings['follow_redirects']
                    ? ['max' => $settings['max_redirects'], 'strict' => true]
                    : false,
            ]);

            $response = $client->request($method, $url, $options);

            if (isset($options['cookies']) && $user !== null && $workspaceId !== null) {
                CookieService::storeFromResponse($user->id, $workspaceId, $response);
            }

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
                if ($settings['send_cookies'] && $user !== null && $workspaceId !== null) {
                    CookieService::storeFromResponse($user->id, $workspaceId, $e->getResponse());
                }
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
        } elseif ($authType === 'digest') {
            $shown['Authorization'] = 'Digest •••';
        } elseif ($authType === 'oauth2') {
            $shown['Authorization'] = 'Bearer •••';
        } elseif ($authType === 'aws') {
            $shown['Authorization'] = 'AWS4-HMAC-SHA256 •••';
        } elseif ($authType === 'inherit') {
            $shown['Authorization'] = 'Inherited •••';
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
    protected function buildOptions(array $payload, array $variables, ?User $user = null, string $url = '', ?ApiRequest $savedRequest = null): array
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

        $options = [
            'query' => $query,
            'headers' => $requestHeaders,
        ];

        $this->applyAuth($payload['auth_type'] ?? 'none', $auth, $options['headers'], $options['query'], $options, $user, $savedRequest, $payload);

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

        // AWS SigV4 signs the final request (method, full URL with
        // query, headers, body), so it runs after everything else.
        if (($payload['auth_type'] ?? 'none') === 'aws') {
            $options['headers'] = AwsSigner::sign(
                strtoupper($payload['method'] ?? 'GET'),
                $this->urlWithQuery($url, $options['query'] ?? []),
                $options['headers'],
                $this->signingBody($options),
                (string) ($auth['access_key'] ?? ''),
                (string) ($auth['secret_key'] ?? ''),
                (string) ($auth['region'] ?? 'us-east-1'),
                (string) ($auth['service'] ?? 'execute-api'),
            );
        }

        return $options;
    }

    /** Full URL with the query params Guzzle would append. */
    protected function urlWithQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Body string for SigV4; UNSIGNED-PAYLOAD for streamed bodies. */
    protected function signingBody(array $options): string
    {
        if (isset($options['body']) && is_string($options['body'])) {
            return $options['body'];
        }
        if (isset($options['json'])) {
            return json_encode($options['json']) ?: '';
        }
        if (isset($options['form_params'])) {
            return http_build_query($options['form_params'], '', '&', PHP_QUERY_RFC3986);
        }

        return 'UNSIGNED-PAYLOAD';
    }

    /** Workspace for the cookie jar: payload, saved request, or collection. */
    protected function resolveWorkspaceId(array $payload, ?ApiRequest $savedRequest, ?User $user): ?int
    {
        if (! empty($payload['workspace_id'])) {
            return (int) $payload['workspace_id'];
        }

        $collectionId = $payload['collection_id'] ?? $savedRequest?->collection_id;
        if ($collectionId !== null && $user !== null) {
            $collection = CollectionRepository::findForUser((int) $collectionId, $user->id);

            return $collection?->workspace_id;
        }

        return $savedRequest?->collection?->workspace_id;
    }

    protected function applyAuth(string $type, array $auth, array &$headers, array &$query, array &$options, ?User $user = null, ?ApiRequest $savedRequest = null, array $payload = []): void
    {
        // 'inherit' resolves auth from the parent collection (a folder
        // has no auth of its own, so collections are the only parent).
        if ($type === 'inherit') {
            [$type, $auth] = $this->inheritedAuth($user, $savedRequest, $payload);
            if ($type === 'inherit' || $type === 'none') {
                return;
            }
        }

        match ($type) {
            'bearer' => $headers['Authorization'] = 'Bearer '.($auth['token'] ?? ''),
            'basic' => $headers['Authorization'] = 'Basic '.base64_encode(($auth['username'] ?? '').':'.($auth['password'] ?? '')),
            'api_key' => $this->applyApiKey($auth, $headers, $query),
            // Guzzle handles the Digest challenge/response itself.
            'digest' => $options['auth'] = [$auth['username'] ?? '', $auth['password'] ?? '', 'digest'],
            // Token stored on the request by OAuthController::exchange.
            'oauth2' => $this->applyOAuth2($auth, $headers),
            // AWS signs after the body is built; see buildOptions().
            'aws' => null,
            default => null,
        };
    }

    protected function applyOAuth2(array $auth, array &$headers): void
    {
        $token = $auth['access_token'] ?? '';
        if ($token !== '') {
            $headers['Authorization'] = ($auth['token_type'] ?? 'Bearer').' '.$token;
        }
    }

    /**
     * Collection auth for 'inherit'. The collection stores its auth as
     * {type, ...fields} (e.g. {type: 'bearer', token: '...'}), or flat
     * fields ({token: ...} implies bearer). Returns ['none', []] when
     * there is no usable parent auth.
     *
     * @return array{0: string, 1: array}
     */
    protected function inheritedAuth(?User $user, ?ApiRequest $savedRequest, array $payload): array
    {
        $collectionId = $payload['collection_id'] ?? $savedRequest?->collection_id;
        if ($collectionId === null || $user === null) {
            return ['none', []];
        }

        $collection = CollectionRepository::findForUser((int) $collectionId, $user->id);
        $auth = $collection?->auth ?? [];
        if (! is_array($auth) || $auth === []) {
            return ['none', []];
        }

        $type = $auth['type'] ?? $auth['auth_type']
            ?? (isset($auth['token']) || isset($auth['access_token']) ? 'bearer'
                : (isset($auth['username']) ? 'basic'
                    : (isset($auth['key']) ? 'api_key' : 'none')));
        unset($auth['type'], $auth['auth_type']);

        return [(string) $type, $auth];
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
     * Returns an error message when the URL must not be fetched — else null.
     *
     * Rules, in order:
     * 1. Cloud metadata (169.254.169.254, whole 169.254.0.0/16 link-local
     *    range) is ALWAYS blocked, even when private IPs are allowed.
     * 2. Local dev hosts — literally `localhost`, or ending `.localhost`,
     *    `.test` or `.local` — need no DNS check: when private IPs are
     *    allowed they pass straight through (so http://allsolution.test
     *    just works); when the toggle is off, `localhost` is refused
     *    outright and `.test` / `.local` fall through to the DNS check
     *    below, which refuses them when they resolve to loopback.
     * 3. Everything else is checked against the `postmen.allow_private_ips`
     *    toggle (ALLOW_PRIVATE_IPS, default true): when false, private,
     *    loopback and link-local addresses are refused again, so a public
     *    deployment cannot be used to probe its own network.
     */
    public function ssrfError(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return 'Invalid URL.';
        }

        $host = strtolower(trim($host, '[]'));

        // 1. Cloud metadata is never reachable.
        if ($this->isMetadataAddress($host)) {
            return 'Requests to cloud metadata endpoints are not allowed.';
        }

        $allowPrivate = (bool) config('postmen.allow_private_ips', true);
        $isLocalHost = $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test') || str_ends_with($host, '.local');

        // 2. Local dev hosts: no DNS check when private IPs are allowed.
        if ($isLocalHost && $allowPrivate) {
            return null;
        }

        if (($host === 'localhost' || str_ends_with($host, '.localhost')) && ! $allowPrivate) {
            return 'Requests to localhost are not allowed.';
        }

        // 3a. Direct IP literal.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! $allowPrivate && $this->isPrivateIp($host)
                ? 'Requests to private or reserved IP addresses are not allowed.'
                : null;
        }

        // 3b. Resolve the hostname and check every address it maps to.
        // (.test / .local hosts land here when the toggle is off.)
        $addresses = gethostbynamel($host);

        if ($addresses === false) {
            return 'Could not resolve host: '.$host;
        }

        foreach ($addresses as $address) {
            if ($this->isMetadataAddress($address)) {
                return 'Requests to cloud metadata endpoints are not allowed.';
            }
            if (! $allowPrivate && $this->isPrivateIp($address)) {
                return 'Requests to private or reserved IP addresses are not allowed.';
            }
        }

        return null;
    }

    /**
     * 169.254.0.0/16 — link-local; 169.254.169.254 is the cloud
     * instance-metadata service (AWS, GCP, Azure all answer there).
     */
    protected function isMetadataAddress(string $host): bool
    {
        if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return str_starts_with($host, '169.254.');
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
            foreach (['token', 'password', 'value', 'access_token', 'refresh_token', 'client_secret', 'secret_key', 'code'] as $secret) {
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
            'settings' => self::normalizeSettings($payload['settings'] ?? null),
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
