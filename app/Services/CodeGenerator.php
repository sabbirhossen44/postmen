<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Generates code snippets from a normalised request array
 * (method, url, params, headers, auth_type, auth, body_type, body).
 * {{variables}} in the URL and values are left as-is.
 */
class CodeGenerator
{
    public const LANGUAGES = [
        'curl',
        'javascript-fetch',
        'javascript-axios',
        'php-guzzle',
        'php-curl',
        'python-requests',
        'go',
        'java-okhttp',
    ];

    public function supportedLanguages(): array
    {
        return self::LANGUAGES;
    }

    public function generate(array $request, string $language): string
    {
        if (! in_array($language, self::LANGUAGES, true)) {
            throw new InvalidArgumentException(
                'Unsupported language: '.$language.'. Supported: '.implode(', ', self::LANGUAGES).'.'
            );
        }

        $normalised = $this->normalize($request);

        return match ($language) {
            'curl' => $this->toCurl($normalised),
            'javascript-fetch' => $this->toFetch($normalised),
            'javascript-axios' => $this->toAxios($normalised),
            'php-guzzle' => $this->toPhpGuzzle($normalised),
            'php-curl' => $this->toPhpCurl($normalised),
            'python-requests' => $this->toPython($normalised),
            'go' => $this->toGo($normalised),
            'java-okhttp' => $this->toJavaOkhttp($normalised),
        };
    }

    /**
     * @return array{method: string, url: string, headers: array<string,string>, body: string|null, body_type: string, form_rows: array}
     */
    protected function normalize(array $request): array
    {
        $method = strtoupper($request['method'] ?? 'GET');
        $headers = [];

        foreach ($request['headers'] ?? [] as $header) {
            if (is_array($header) && ($header['enabled'] ?? true) && isset($header['key'])) {
                $headers[$header['key']] = (string) ($header['value'] ?? '');
            }
        }

        $query = [];

        foreach ($request['params'] ?? [] as $param) {
            if (! is_array($param) || ! ($param['enabled'] ?? true) || ! isset($param['key'])) {
                continue;
            }

            // Path params (:id) live in the URL already; they are not query.
            if (($param['in'] ?? '') === 'path' || ($param['path'] ?? false)) {
                continue;
            }

            $query[$param['key']] = (string) ($param['value'] ?? '');
        }

        $this->applyAuth($request['auth_type'] ?? 'none', $request['auth'] ?? [], $headers, $query);

        $url = $this->appendQuery((string) ($request['url'] ?? ''), $query);
        $bodyType = $request['body_type'] ?? 'none';
        $body = $request['body'] ?? null;

        $formRows = [];

        if ($bodyType === 'form-data' && is_array($body)) {
            $formRows = array_values(array_filter(
                $body['formdata'] ?? $body,
                fn ($row) => is_array($row) && ($row['enabled'] ?? true) && isset($row['key']),
            ));
        }

        return [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $this->rawBody($bodyType, $body),
            'body_type' => $bodyType,
            'form_rows' => $formRows,
        ];
    }

    protected function applyAuth(string $type, mixed $auth, array &$headers, array &$query): void
    {
        $auth = is_array($auth) ? $auth : [];

        match ($type) {
            'bearer' => $headers['Authorization'] = 'Bearer '.($auth['token'] ?? ''),
            'basic' => $headers['Authorization'] = 'Basic '.base64_encode(($auth['username'] ?? '').':'.($auth['password'] ?? '')),
            'api_key' => ($auth['in'] ?? 'header') === 'query'
                ? $query[$auth['key'] ?? ''] = $auth['value'] ?? ''
                : $headers[$auth['key'] ?? ''] = $auth['value'] ?? '',
            default => null,
        };
    }

    /** Append query pairs; values holding {{vars}} are left unencoded. */
    protected function appendQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $pairs = [];

        foreach ($query as $key => $value) {
            $pairs[] = rawurlencode((string) $key).'='.
                (str_contains((string) $value, '{{') ? $value : rawurlencode((string) $value));
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.implode('&', $pairs);
    }

    /** Raw / urlencoded body as a plain string; form-data stays as rows. */
    protected function rawBody(string $bodyType, mixed $body): ?string
    {
        if ($bodyType === 'raw') {
            if (is_array($body) && array_key_exists('raw', $body)) {
                return (string) $body['raw'];
            }

            return is_string($body) ? $body : json_encode($body);
        }

        if ($bodyType === 'urlencoded' && is_array($body)) {
            $rows = $body['urlencoded'] ?? $body;
            $pairs = [];

            foreach ($rows as $row) {
                if (is_array($row) && ($row['enabled'] ?? true) && isset($row['key'])) {
                    $pairs[] = rawurlencode($row['key']).'='.rawurlencode((string) ($row['value'] ?? ''));
                }
            }

            return implode('&', $pairs);
        }

        return null;
    }

    // -- Escaping helpers -------------------------------------------------

    /** Shell single-quote escaping: ' becomes '\'' . */
    protected function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /** Double-quoted string literal, valid in JS, Python, Go and Java. */
    protected function quoted(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** PHP string literal. */
    protected function phpString(string $value): string
    {
        return var_export($value, true);
    }

    /** Headers as a JS/Python-style object literal, one entry per line. */
    protected function objectLiteral(array $headers, string $indent = '  '): string
    {
        if ($headers === []) {
            return '{}';
        }

        $lines = [];

        foreach ($headers as $key => $value) {
            $lines[] = $indent.$this->quoted($key).': '.$this->quoted($value).',';
        }

        return "{\n".implode("\n", $lines)."\n".substr($indent, 0, -2).'}';
    }

    protected function hasJsonBody(array $r): bool
    {
        if ($r['body_type'] !== 'raw' || $r['body'] === null) {
            return false;
        }

        $trimmed = ltrim($r['body']);

        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }

    // -- Generators --------------------------------------------------------

    protected function toCurl(array $r): string
    {
        $parts = ['curl --request '.$r['method'].' '.$this->shellQuote($r['url'])];

        foreach ($r['headers'] as $key => $value) {
            $parts[] = '  --header '.$this->shellQuote($key.': '.$value);
        }

        if ($r['body_type'] === 'form-data') {
            foreach ($r['form_rows'] as $row) {
                $value = ($row['type'] ?? 'text') === 'file'
                    ? '@'.($row['value'] ?? '')
                    : ($row['value'] ?? '');
                $parts[] = '  --form '.$this->shellQuote($row['key'].'='.$value);
            }
        } elseif ($r['body'] !== null && $r['body'] !== '') {
            $parts[] = '  --data '.$this->shellQuote($r['body']);
        }

        return implode(" \\\n", $parts);
    }

    protected function toFetch(array $r): string
    {
        $lines = ['fetch('.$this->quoted($r['url']).', {'];
        $lines[] = '  method: '.$this->quoted($r['method']).',';
        $lines[] = '  headers: '.$this->objectLiteral($r['headers']).',';

        if ($r['body_type'] === 'form-data') {
            $lines[] = '  body: (() => {';
            $lines[] = '    const formData = new FormData();';
            foreach ($r['form_rows'] as $row) {
                $lines[] = '    formData.append('.$this->quoted($row['key']).', '.$this->quoted((string) ($row['value'] ?? '')).');';
            }
            $lines[] = '    return formData;';
            $lines[] = '  })(),';
        } elseif ($r['body'] !== null && $r['body'] !== '') {
            $lines[] = $this->hasJsonBody($r)
                ? '  body: JSON.stringify('.json_encode(json_decode($r['body'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'),'
                : '  body: '.$this->quoted($r['body']).',';
        }

        $lines[] = '});';

        return implode("\n", $lines);
    }

    protected function toAxios(array $r): string
    {
        $lines = ['axios({'];
        $lines[] = '  method: '.$this->quoted(strtolower($r['method'])).',';
        $lines[] = '  url: '.$this->quoted($r['url']).',';
        $lines[] = '  headers: '.$this->objectLiteral($r['headers']).',';

        if ($r['body_type'] === 'form-data') {
            $lines[] = '  data: (() => {';
            $lines[] = '    const formData = new FormData();';
            foreach ($r['form_rows'] as $row) {
                $lines[] = '    formData.append('.$this->quoted($row['key']).', '.$this->quoted((string) ($row['value'] ?? '')).');';
            }
            $lines[] = '    return formData;';
            $lines[] = '  })(),';
        } elseif ($r['body'] !== null && $r['body'] !== '') {
            $lines[] = $this->hasJsonBody($r)
                ? '  data: '.json_encode(json_decode($r['body'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).','
                : '  data: '.$this->quoted($r['body']).',';
        }

        $lines[] = '});';

        return implode("\n", $lines);
    }

    protected function toPhpGuzzle(array $r): string
    {
        $lines = ['$client = new Client();', ''];
        $lines[] = '$response = $client->request('.$this->phpString($r['method']).', '.$this->phpString($r['url']).', [';

        if ($r['headers'] !== []) {
            $lines[] = "    'headers' => [";
            foreach ($r['headers'] as $key => $value) {
                $lines[] = '        '.$this->phpString($key).' => '.$this->phpString($value).',';
            }
            $lines[] = '    ],';
        }

        if ($r['body_type'] === 'form-data') {
            $lines[] = "    'multipart' => [";
            foreach ($r['form_rows'] as $row) {
                $lines[] = '        [';
                $lines[] = "            'name' => ".$this->phpString($row['key']).',';
                $lines[] = "            'contents' => ".$this->phpString((string) ($row['value'] ?? '')).',';
                $lines[] = '        ],';
            }
            $lines[] = '    ],';
        } elseif ($r['body'] !== null && $r['body'] !== '') {
            if ($this->hasJsonBody($r)) {
                $lines[] = "    'json' => ".var_export(json_decode($r['body'], true), true).',';
            } elseif ($r['body_type'] === 'urlencoded') {
                $lines[] = "    'form_params' => ".var_export($this->queryToArray($r['body']), true).',';
            } else {
                $lines[] = "    'body' => ".$this->phpString($r['body']).',';
            }
        }

        $lines[] = ']);';

        return implode("\n", $lines);
    }

    protected function toPhpCurl(array $r): string
    {
        $lines = ['$curl = curl_init();', ''];
        $lines[] = 'curl_setopt($curl, CURLOPT_URL, '.$this->phpString($r['url']).');';
        $lines[] = 'curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);';
        $lines[] = 'curl_setopt($curl, CURLOPT_CUSTOMREQUEST, '.$this->phpString($r['method']).');';

        if ($r['headers'] !== []) {
            $lines[] = 'curl_setopt($curl, CURLOPT_HTTPHEADER, [';
            foreach ($r['headers'] as $key => $value) {
                $lines[] = '    '.$this->phpString($key.': '.$value).',';
            }
            $lines[] = ']);';
        }

        if ($r['body_type'] === 'form-data') {
            $lines[] = 'curl_setopt($curl, CURLOPT_POSTFIELDS, [';
            foreach ($r['form_rows'] as $row) {
                $value = ($row['type'] ?? 'text') === 'file'
                    ? 'new CURLFile('.$this->phpString((string) ($row['value'] ?? '')).')'
                    : $this->phpString((string) ($row['value'] ?? ''));
                $lines[] = '    '.$this->phpString($row['key']).' => '.$value.',';
            }
            $lines[] = ']);';
        } elseif ($r['body'] !== null && $r['body'] !== '') {
            $lines[] = 'curl_setopt($curl, CURLOPT_POSTFIELDS, '.$this->phpString($r['body']).');';
        }

        $lines[] = '';
        $lines[] = '$response = curl_exec($curl);';
        $lines[] = 'curl_close($curl);';

        return implode("\n", $lines);
    }

    protected function toPython(array $r): string
    {
        $lines = ['import requests', ''];
        $lines[] = 'url = '.$this->quoted($r['url']);
        $lines[] = 'headers = '.$this->pythonObject($r['headers']);
        $lines[] = '';

        $args = ['"'.$r['method'].'"', 'url', 'headers=headers'];

        if ($r['body_type'] === 'form-data') {
            $data = [];
            $files = [];
            foreach ($r['form_rows'] as $row) {
                if (($row['type'] ?? 'text') === 'file') {
                    $files[$row['key']] = "open(".$this->quoted((string) ($row['value'] ?? '')).", 'rb')";
                } else {
                    $data[$row['key']] = $row['value'] ?? '';
                }
            }
            if ($data !== []) {
                $lines[] = 'data = '.$this->pythonObject($data);
                $args[] = 'data=data';
            }
            if ($files !== []) {
                $lines[] = 'files = {';
                foreach ($files as $key => $value) {
                    $lines[] = '    '.$this->quoted($key).': '.$value.',';
                }
                $lines[] = '}';
                $args[] = 'files=files';
            }
        } elseif ($r['body'] !== null && $r['body'] !== '') {
            if ($this->hasJsonBody($r)) {
                $lines[] = 'payload = '.json_encode(json_decode($r['body'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $args[] = 'json=payload';
            } elseif ($r['body_type'] === 'urlencoded') {
                $lines[] = 'data = '.$this->pythonObject($this->queryToArray($r['body']));
                $args[] = 'data=data';
            } else {
                $lines[] = 'data = '.$this->quoted($r['body']);
                $args[] = 'data=data';
            }
        }

        $lines[] = 'response = requests.request('.implode(', ', $args).')';

        return implode("\n", $lines);
    }

    protected function toGo(array $r): string
    {
        $hasBody = ($r['body'] !== null && $r['body'] !== '') || $r['body_type'] === 'form-data';

        $lines = ['package main', '', 'import ('];

        if ($hasBody) {
            $lines[] = '    "bytes"';
        }

        $lines[] = '    "net/http"';
        $lines[] = ')';
        $lines[] = '';
        $lines[] = 'func main() {';

        if ($hasBody) {
            $body = $r['body_type'] === 'form-data'
                ? $this->formRowsToQuery($r['form_rows'])
                : ($r['body'] ?? '');
            $lines[] = '    payload := bytes.NewBufferString('.$this->quoted($body).')';
            $lines[] = '    req, _ := http.NewRequest('.$this->quoted($r['method']).', '.$this->quoted($r['url']).', payload)';
        } else {
            $lines[] = '    req, _ := http.NewRequest('.$this->quoted($r['method']).', '.$this->quoted($r['url']).', nil)';
        }

        foreach ($r['headers'] as $key => $value) {
            $lines[] = '    req.Header.Set('.$this->quoted($key).', '.$this->quoted($value).')';
        }

        $lines[] = '    client := &http.Client{}';
        $lines[] = '    resp, _ := client.Do(req)';
        $lines[] = '    defer resp.Body.Close()';
        $lines[] = '}';

        return implode("\n", $lines);
    }

    protected function toJavaOkhttp(array $r): string
    {
        $lines = ['OkHttpClient client = new OkHttpClient();', ''];

        if ($r['body_type'] === 'form-data') {
            $lines[] = 'MultipartBody.Builder formBuilder = new MultipartBody.Builder().setType(MultipartBody.FORM);';
            foreach ($r['form_rows'] as $row) {
                $lines[] = 'formBuilder.addFormDataPart('.$this->quoted($row['key']).', '.$this->quoted((string) ($row['value'] ?? '')).');';
            }
            $lines[] = 'RequestBody body = formBuilder.build();';
        } elseif ($r['body'] !== null && $r['body'] !== '') {
            $mediaType = $this->hasJsonBody($r) ? 'application/json' : 'text/plain';
            $lines[] = 'RequestBody body = RequestBody.create('.$this->quoted($r['body']).', MediaType.parse('.$this->quoted($mediaType).'));';
        } else {
            $lines[] = 'RequestBody body = null;';
        }

        $lines[] = '';
        $lines[] = 'Request request = new Request.Builder()';
        $lines[] = '    .url('.$this->quoted($r['url']).')';

        foreach ($r['headers'] as $key => $value) {
            $lines[] = '    .addHeader('.$this->quoted($key).', '.$this->quoted($value).')';
        }

        $lines[] = '    .method('.$this->quoted($r['method']).', body)';
        $lines[] = '    .build();';
        $lines[] = '';
        $lines[] = 'Response response = client.newCall(request).execute();';

        return implode("\n", $lines);
    }

    /** Python dict literal from a string map. */
    protected function pythonObject(array $map): string
    {
        if ($map === []) {
            return '{}';
        }

        $lines = ['{'];

        foreach ($map as $key => $value) {
            $lines[] = '    '.$this->quoted((string) $key).': '.$this->quoted((string) $value).',';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    protected function queryToArray(string $query): array
    {
        $out = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $pos = strpos($pair, '=');
            $out[urldecode($pos === false ? $pair : substr($pair, 0, $pos))] =
                $pos === false ? '' : urldecode(substr($pair, $pos + 1));
        }

        return $out;
    }

    protected function formRowsToQuery(array $rows): string
    {
        $pairs = [];

        foreach ($rows as $row) {
            $pairs[] = rawurlencode($row['key']).'='.rawurlencode((string) ($row['value'] ?? ''));
        }

        return implode('&', $pairs);
    }
}
