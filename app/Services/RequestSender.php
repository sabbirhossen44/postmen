<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\History;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;

/**
 * Sends a raw HTTP request with Guzzle, applies auth, resolves
 * {{variables}}, measures timing, and records a History row.
 * Refuses to call private / loopback / link-local addresses (SSRF guard).
 */
class RequestSender
{
    public const MAX_BODY_BYTES = 2 * 1024 * 1024;

    /**
     * @param  array{method?: string, url: string, params?: array, headers?: array, auth_type?: string, auth?: array|null, body_type?: string, body?: mixed, variables?: array}  $payload
     */
    public function send(array $payload, ?User $user = null, ?ApiRequest $savedRequest = null): array
    {
        $method = strtoupper($payload['method'] ?? 'GET');
        $variables = $payload['variables'] ?? [];
        $url = VariableResolver::resolve((string) ($payload['url'] ?? ''), $variables);

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
        ];

        $ssrfError = $this->ssrfError($url);

        if ($ssrfError !== null) {
            $result['error'] = $ssrfError;
            $result['time_ms'] = (int) ((microtime(true) - $startedAt) * 1000);
            $result['history_id'] = $this->recordHistory($user, $savedRequest, $method, $url, $result);

            return $result;
        }

        try {
            $options = $this->buildOptions($payload, $variables);
            $client = new Client([
                'timeout' => 30,
                'http_errors' => false,
                'allow_redirects' => ['max' => 5, 'strict' => true],
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

        $result['history_id'] = $this->recordHistory($user, $savedRequest, $method, $url, $result);

        return $result;
    }

    /** Build Guzzle options: query, headers, auth, body. */
    protected function buildOptions(array $payload, array $variables): array
    {
        $params = VariableResolver::resolveArray($payload['params'] ?? [], $variables);
        $headers = VariableResolver::resolveArray($payload['headers'] ?? [], $variables);
        $auth = VariableResolver::resolveArray($payload['auth'] ?? [], $variables);

        $query = [];
        $requestHeaders = [];

        foreach ($params as $param) {
            if (is_array($param) && ($param['enabled'] ?? true) && isset($param['key'])) {
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
            $options['body'] = is_string($body)
                ? VariableResolver::resolve($body, $variables)
                : VariableResolver::resolve(json_encode($body) ?: '', $variables);
        } elseif ($bodyType === 'urlencoded' && is_array($body)) {
            $form = [];
            foreach (VariableResolver::resolveArray($body, $variables) as $item) {
                if (is_array($item) && ($item['enabled'] ?? true) && isset($item['key'])) {
                    $form[$item['key']] = $item['value'] ?? '';
                }
            }
            $options['form_params'] = $form;
        } elseif ($bodyType === 'form-data' && is_array($body)) {
            $multipart = [];
            foreach (VariableResolver::resolveArray($body, $variables) as $item) {
                if (is_array($item) && ($item['enabled'] ?? true) && isset($item['key'])) {
                    $multipart[] = ['name' => $item['key'], 'contents' => $item['value'] ?? ''];
                }
            }
            $options['multipart'] = $multipart;
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

        if (($auth['in'] ?? 'header') === 'query') {
            $query[$key] = $value;
        } else {
            $headers[$key] = $value;
        }
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

    protected function recordHistory(?User $user, ?ApiRequest $savedRequest, string $method, string $url, array $result): ?int
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
        ]);

        return $history->id;
    }
}
