<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\ApiRequestRepository;
use InvalidArgumentException;

/**
 * Parses cURL command strings into our normalised request arrays
 * (the same shape ApiRequest stores) and imports them into a collection.
 *
 * Supported: -X/--request, -H/--header, -d/--data/--data-raw/--data-binary/
 * --data-urlencode, --form/-F, -u/--user (basic auth), -A/--user-agent,
 * --compressed, --insecure, -G/--get, URLs with a query string, and
 * $'...' ANSI-C quoting plus single/double quotes. Multiple commands
 * separated by newlines are parsed one per command.
 */
class CurlImporter
{
    protected const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** Long options that take a value (also accepted as --opt=value). */
    protected const VALUE_OPTIONS = [
        'request', 'header', 'data', 'data-raw', 'data-binary', 'data-ascii',
        'data-urlencode', 'form', 'user', 'user-agent', 'url', 'cookie',
        'referer', 'output', 'max-time', 'connect-timeout', 'cert', 'key',
        'cacert', 'proxy', 'oauth2-bearer', 'aws-sigv4',
    ];

    /** Short options that take a value. */
    protected const VALUE_SHORT_OPTIONS = ['X', 'H', 'd', 'F', 'u', 'A', 'b', 'e', 'o', 'm', 'E'];

    /** Parse one cURL command into a normalised request array. */
    public function parse(string $command): array
    {
        $tokens = $this->tokenize($command);
        $tokens = $this->dropLeadingCurl($tokens);

        if ($tokens === []) {
            throw new InvalidArgumentException('No cURL command found.');
        }

        $method = null;
        $url = null;
        $headers = [];
        $dataParts = [];
        $urlencodeRows = [];
        $formRows = [];
        $basicAuth = null;
        $compressed = false;
        $moveDataToQuery = false;
        $isHead = false;

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '--') {
                // Everything after -- is a URL.
                $url ??= $tokens[$i + 1] ?? null;
                break;
            }

            if (str_starts_with($token, '--')) {
                [$name, $inlineValue] = $this->splitLongOption($token);

                if (in_array($name, self::VALUE_OPTIONS, true)) {
                    $value = $inlineValue ?? $tokens[++$i] ?? '';
                    $this->applyLongOption($name, $value, $method, $headers, $dataParts, $urlencodeRows, $formRows, $basicAuth, $url);

                    continue;
                }

                match ($name) {
                    'compressed' => $compressed = true,
                    'get' => $moveDataToQuery = true,
                    'head' => $isHead = true,
                    // --insecure, --location, --silent, --include and friends
                    // change transport behaviour only; they carry no request data.
                    default => null,
                };

                continue;
            }

            if (str_starts_with($token, '-') && $token !== '-') {
                $flags = substr($token, 1);

                for ($j = 0; $j < strlen($flags); $j++) {
                    $flag = $flags[$j];

                    if (in_array($flag, self::VALUE_SHORT_OPTIONS, true)) {
                        // Rest of the token is the value, else the next token.
                        $value = substr($flags, $j + 1) !== ''
                            ? substr($flags, $j + 1)
                            : ($tokens[++$i] ?? '');
                        $value = ltrim($value, '=');

                        $this->applyShortOption($flag, $value, $method, $headers, $dataParts, $urlencodeRows, $formRows, $basicAuth, $url);
                        break;
                    }

                    match ($flag) {
                        'G' => $moveDataToQuery = true,
                        'I' => $isHead = true,
                        // k (insecure), L (follow redirects), s (silent),
                        // i (include headers) and friends: transport only.
                        default => null,
                    };
                }

                continue;
            }

            // A bare token is the URL (curl takes exactly one).
            $url ??= $token;
        }

        if ($url === null || $url === '') {
            throw new InvalidArgumentException('cURL command has no URL.');
        }

        if ($compressed && ! $this->hasHeader($headers, 'Accept-Encoding')) {
            $headers[] = ['key' => 'Accept-Encoding', 'value' => 'gzip, deflate', 'enabled' => true];
        }

        // Authorization header wins over nothing; -u wins over the header.
        $auth = $this->authFromHeaders($headers);

        if ($basicAuth !== null) {
            $auth = ['type' => 'basic', 'data' => $basicAuth];
        }

        $params = $this->parseQueryString($url);

        if ($moveDataToQuery && $dataParts !== []) {
            $params = [...$params, ...$this->parseQueryString('?'.implode('&', $dataParts))];
            $dataParts = [];
        }

        $body = $this->buildBody($dataParts, $urlencodeRows, $formRows, $headers);

        $method ??= $isHead ? 'HEAD' : ($body['type'] !== 'none' ? 'POST' : 'GET');
        $method = strtoupper($method);

        if (! in_array($method, self::METHODS, true)) {
            $method = 'GET';
        }

        return [
            'name' => $method.' '.($this->pathOf($url) ?: $url),
            'method' => $method,
            'url' => $url,
            'params' => $params,
            'headers' => $headers,
            'auth_type' => $auth['type'],
            'auth' => $auth['data'],
            'body_type' => $body['type'],
            'body' => $body['data'],
            'description' => null,
        ];
    }

    /** Parse several commands separated by newlines, one request per command. */
    public function parseMany(string $input): array
    {
        $commands = $this->splitCommands($input);

        if ($commands === []) {
            throw new InvalidArgumentException('No cURL command found.');
        }

        return array_map(fn ($command) => $this->parse($command), $commands);
    }

    /**
     * Import cURL commands into a collection (created as "cURL Import"
     * when no collection is given). Returns the collection and requests.
     *
     * @return array{collection: Collection, requests: ApiRequest[]}
     */
    public function import(string $input, Workspace $workspace, User $user, ?Collection $collection = null): array
    {
        $parsed = $this->parseMany($input);

        $collection ??= Collection::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'name' => 'cURL Import',
        ]);

        $created = [];

        foreach ($parsed as $index => $request) {
            $created[] = ApiRequestRepository::create([
                ...$request,
                'collection_id' => $collection->id,
                'folder_id' => null,
                'user_id' => $user->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'sort_order' => $index,
            ]);
        }

        return ['collection' => $collection, 'requests' => $created];
    }

    /** Split pasted input into one command per line starting with curl. */
    protected function splitCommands(string $input): array
    {
        $input = str_replace(["\\\r\n", "\\\n"], ' ', $input);

        $parts = preg_split('/(?m)^(?=\s*curl(?:\s|$))/', $input, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            array_map('trim', $parts ?: []),
            fn ($part) => $part !== '' && str_starts_with(ltrim($part), 'curl'),
        ));
    }

    /** Shell-like tokenizer: single/double quotes, $'...' and backslashes. */
    protected function tokenize(string $command): array
    {
        $tokens = [];
        $current = '';
        $hasCurrent = false;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                if ($hasCurrent) {
                    $tokens[] = $current;
                    $current = '';
                    $hasCurrent = false;
                }

                continue;
            }

            $hasCurrent = true;

            if ($char === "'") {
                // Single quotes: literal until the next single quote.
                $i++;
                while ($i < $length && $command[$i] !== "'") {
                    $current .= $command[$i];
                    $i++;
                }

                continue;
            }

            if ($char === '$' && ($i + 1) < $length && $command[$i + 1] === "'") {
                // $'...' ANSI-C quoting: \n \t \\ \' \" and friends.
                $i += 2;
                while ($i < $length && $command[$i] !== "'") {
                    if ($command[$i] === '\\' && ($i + 1) < $length) {
                        $current .= $this->ansiEscape($command[$i + 1]);
                        $i += 2;
                    } else {
                        $current .= $command[$i];
                        $i++;
                    }
                }

                continue;
            }

            if ($char === '"') {
                $i++;
                while ($i < $length && $command[$i] !== '"') {
                    if ($command[$i] === '\\' && ($i + 1) < $length
                        && str_contains("$`\"\\\n", $command[$i + 1])) {
                        $current .= $command[$i + 1];
                        $i += 2;
                    } else {
                        $current .= $command[$i];
                        $i++;
                    }
                }

                continue;
            }

            if ($char === '\\' && ($i + 1) < $length) {
                // Backslash escapes the next char (a trailing newline is
                // a line continuation and disappears).
                if ($command[$i + 1] !== "\n") {
                    $current .= $command[$i + 1];
                }
                $i++;

                continue;
            }

            $current .= $char;
        }

        if ($hasCurrent) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    protected function ansiEscape(string $char): string
    {
        return match ($char) {
            'n' => "\n",
            't' => "\t",
            'r' => "\r",
            '0' => "\0",
            'a' => "\a",
            'e' => "\e",
            default => $char,
        };
    }

    protected function dropLeadingCurl(array $tokens): array
    {
        foreach ($tokens as $index => $token) {
            if ($token === 'curl' || str_ends_with($token, '/curl')) {
                return array_values(array_slice($tokens, $index + 1));
            }
        }

        return $tokens;
    }

    /** ['--header=Name: v'] -> ['header', 'Name: v']; ['--compressed'] -> ['compressed', null]. */
    protected function splitLongOption(string $token): array
    {
        $name = substr($token, 2);
        $pos = strpos($name, '=');

        if ($pos === false) {
            return [$name, null];
        }

        return [substr($name, 0, $pos), substr($name, $pos + 1)];
    }

    protected function applyLongOption(string $name, string $value, ?string &$method, array &$headers, array &$dataParts, array &$urlencodeRows, array &$formRows, ?array &$basicAuth, ?string &$url): void
    {
        match ($name) {
            'request' => $method = $value,
            'header' => $this->addHeader($headers, $value),
            'data', 'data-raw', 'data-binary', 'data-ascii' => $dataParts[] = $value,
            'data-urlencode' => $urlencodeRows[] = $this->parseUrlencodeArg($value),
            'form' => $formRows[] = $this->parseFormArg($value),
            'user' => $basicAuth = $this->parseUser($value),
            'user-agent' => $this->setHeader($headers, 'User-Agent', $value),
            'url' => $url ??= $value,
            'oauth2-bearer' => $this->setHeader($headers, 'Authorization', 'Bearer '.$value),
            default => null,
        };
    }

    protected function applyShortOption(string $flag, string $value, ?string &$method, array &$headers, array &$dataParts, array &$urlencodeRows, array &$formRows, ?array &$basicAuth, ?string &$url): void
    {
        match ($flag) {
            'X' => $method = $value,
            'H' => $this->addHeader($headers, $value),
            'd' => $dataParts[] = $value,
            'F' => $formRows[] = $this->parseFormArg($value),
            'u' => $basicAuth = $this->parseUser($value),
            'A' => $this->setHeader($headers, 'User-Agent', $value),
            'e' => $this->setHeader($headers, 'Referer', $value),
            'b' => $this->setHeader($headers, 'Cookie', $value),
            default => null,
        };
    }

    protected function addHeader(array &$headers, string $raw): void
    {
        $pos = strpos($raw, ':');

        if ($pos === false) {
            return;
        }

        $headers[] = [
            'key' => trim(substr($raw, 0, $pos)),
            'value' => trim(substr($raw, $pos + 1)),
            'enabled' => true,
        ];
    }

    protected function setHeader(array &$headers, string $key, string $value): void
    {
        $headers = array_values(array_filter(
            $headers,
            fn ($header) => strtolower($header['key']) !== strtolower($key),
        ));

        $headers[] = ['key' => $key, 'value' => $value, 'enabled' => true];
    }

    protected function hasHeader(array $headers, string $key): bool
    {
        foreach ($headers as $header) {
            if (strtolower($header['key']) === strtolower($key)) {
                return true;
            }
        }

        return false;
    }

    protected function headerValue(array $headers, string $key): ?string
    {
        foreach ($headers as $header) {
            if (strtolower($header['key']) === strtolower($key)) {
                return $header['value'];
            }
        }

        return null;
    }

    /** Authorization header -> bearer/basic auth (overridden by -u). */
    protected function authFromHeaders(array $headers): array
    {
        $authorization = $this->headerValue($headers, 'Authorization');

        if ($authorization === null) {
            return ['type' => 'none', 'data' => null];
        }

        if (str_starts_with($authorization, 'Bearer ')) {
            return ['type' => 'bearer', 'data' => ['token' => substr($authorization, 7)]];
        }

        if (str_starts_with($authorization, 'Basic ')) {
            $decoded = base64_decode(substr($authorization, 6), true) ?: '';
            $pos = strpos($decoded, ':');

            return ['type' => 'basic', 'data' => [
                'username' => $pos === false ? $decoded : substr($decoded, 0, $pos),
                'password' => $pos === false ? '' : substr($decoded, $pos + 1),
            ]];
        }

        return ['type' => 'none', 'data' => null];
    }

    protected function parseUser(string $value): array
    {
        $pos = strpos($value, ':');

        return [
            'username' => $pos === false ? $value : substr($value, 0, $pos),
            'password' => $pos === false ? '' : substr($value, $pos + 1),
        ];
    }

    /** --data-urlencode: key=value, =content, or key@file (file kept as-is). */
    protected function parseUrlencodeArg(string $value): array
    {
        if (str_starts_with($value, '=')) {
            return ['key' => '', 'value' => substr($value, 1), 'enabled' => true];
        }

        $pos = strpos($value, '=');

        if ($pos === false) {
            return ['key' => $value, 'value' => '', 'enabled' => true];
        }

        return [
            'key' => substr($value, 0, $pos),
            'value' => substr($value, $pos + 1),
            'enabled' => true,
        ];
    }

    /** -F: key=value, key=@file, key=<file, key=@file;type=mime. */
    protected function parseFormArg(string $value): array
    {
        $pos = strpos($value, '=');

        if ($pos === false) {
            return ['key' => $value, 'value' => '', 'type' => 'text', 'enabled' => true];
        }

        $key = substr($value, 0, $pos);
        $rest = substr($value, $pos + 1);

        if (str_starts_with($rest, '@') || str_starts_with($rest, '<')) {
            $filename = explode(';', substr($rest, 1))[0];

            return ['key' => $key, 'value' => $filename, 'type' => 'file', 'enabled' => true];
        }

        return ['key' => $key, 'value' => explode(';', $rest)[0], 'type' => 'text', 'enabled' => true];
    }

    protected function buildBody(array $dataParts, array $urlencodeRows, array $formRows, array $headers): array
    {
        if ($formRows !== []) {
            return ['type' => 'form-data', 'data' => $formRows];
        }

        if ($urlencodeRows !== []) {
            return ['type' => 'urlencoded', 'data' => $urlencodeRows];
        }

        if ($dataParts === []) {
            return ['type' => 'none', 'data' => null];
        }

        $raw = implode('&', $dataParts);
        $contentType = $this->headerValue($headers, 'Content-Type') ?? '';

        if (str_contains($contentType, 'json') || $this->looksLikeJson($raw)) {
            return ['type' => 'raw', 'data' => ['raw' => $raw, 'raw_language' => 'json']];
        }

        if (str_contains($contentType, 'x-www-form-urlencoded') || str_contains($raw, '=')) {
            return ['type' => 'urlencoded', 'data' => $this->parseQueryString('?'.$raw)];
        }

        return ['type' => 'raw', 'data' => ['raw' => $raw]];
    }

    protected function looksLikeJson(string $raw): bool
    {
        $trimmed = ltrim($raw);

        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return false;
        }

        json_decode($trimmed);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /** Query string -> [{key, value, enabled}] without parse_str mangling. */
    protected function parseQueryString(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! $query) {
            return [];
        }

        $params = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $pos = strpos($pair, '=');

            $params[] = [
                'key' => urldecode($pos === false ? $pair : substr($pair, 0, $pos)),
                'value' => $pos === false ? '' : urldecode(substr($pair, $pos + 1)),
                'enabled' => true,
            ];
        }

        return $params;
    }

    protected function pathOf(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return $path === null || $path === '' ? null : $path;
    }
}
