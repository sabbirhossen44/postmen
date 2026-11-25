<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\Folder;
use App\Models\User;
use App\Models\Workspace;
use InvalidArgumentException;

/** Parses a Postman Collection v2.1 JSON document into our tables. */
class CollectionImporter
{
    public function import(array $collectionJson, Workspace $workspace, User $user): Collection
    {
        $info = $collectionJson['info'] ?? null;

        if (! is_array($info) || empty($info['name'])) {
            throw new InvalidArgumentException('Invalid Postman collection: missing info.name.');
        }

        if (! isset($collectionJson['item']) || ! is_array($collectionJson['item'])) {
            throw new InvalidArgumentException('Invalid Postman collection: missing item list.');
        }

        $collection = Collection::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'name' => $info['name'],
            'description' => $info['description'] ?? null,
            'variables' => $this->parseVariables($collectionJson['variable'] ?? []),
        ]);

        $this->importItems($collectionJson['item'], $collection, $user);

        return $collection;
    }

    protected function importItems(array $items, Collection $collection, User $user, ?Folder $parent = null): void
    {
        foreach ($items as $index => $item) {
            // A folder has an "item" array; a request has a "request" key.
            if (isset($item['item']) && is_array($item['item'])) {
                $folder = Folder::create([
                    'collection_id' => $collection->id,
                    'parent_id' => $parent?->id,
                    'name' => $item['name'] ?? 'Untitled Folder',
                    'sort_order' => $index,
                ]);

                $this->importItems($item['item'], $collection, $user, $folder);

                continue;
            }

            if (isset($item['request'])) {
                $this->importRequest($item, $collection, $user, $parent, $index);
            }
        }
    }

    protected function importRequest(array $item, Collection $collection, User $user, ?Folder $parent, int $index): void
    {
        $request = $item['request'];

        if (! is_array($request)) {
            return;
        }

        $method = strtoupper($request['method'] ?? 'GET');
        $url = $this->parseUrl($request['url'] ?? '');
        $auth = $this->parseAuth($request['auth'] ?? null);
        $body = $this->parseBody($request['body'] ?? null);

        ApiRequest::create([
            'collection_id' => $collection->id,
            'folder_id' => $parent?->id,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'name' => $item['name'] ?? 'Untitled Request',
            'method' => in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $method : 'GET',
            'url' => $url['raw'],
            'params' => $url['params'],
            'headers' => $this->parseKeyValues($request['header'] ?? []),
            'auth_type' => $auth['type'],
            'auth' => $auth['data'],
            'body_type' => $body['type'],
            'body' => $body['data'],
            'description' => $item['description'] ?? null,
            'sort_order' => $index,
        ]);
    }

    /** URL may be a plain string or {raw, query: [{key, value}]}. */
    protected function parseUrl(mixed $url): array
    {
        if (is_string($url)) {
            return ['raw' => $url, 'params' => $this->parseQueryString($url)];
        }

        if (! is_array($url)) {
            return ['raw' => '', 'params' => []];
        }

        $raw = $url['raw'] ?? '';

        if ($raw === '' && isset($url['host'])) {
            $host = is_array($url['host']) ? implode('.', $url['host']) : $url['host'];
            $path = isset($url['path']) ? '/'.implode('/', (array) $url['path']) : '';
            $raw = ($url['protocol'] ?? 'https').'://'.$host.$path;
        }

        $params = $this->parseKeyValues($url['query'] ?? []);

        if ($params === []) {
            $params = $this->parseQueryString($raw);
        }

        return ['raw' => $raw, 'params' => $params];
    }

    protected function parseQueryString(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! $query) {
            return [];
        }

        parse_str($query, $parsed);

        return array_map(fn ($key, $value) => [
            'key' => $key,
            'value' => (string) $value,
            'enabled' => true,
        ], array_keys($parsed), array_values($parsed));
    }

    /** [{key, value, disabled}] -> [{key, value, enabled}]. */
    protected function parseKeyValues(array $items): array
    {
        $parsed = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['key'])) {
                continue;
            }

            $parsed[] = [
                'key' => $item['key'],
                'value' => $item['value'] ?? '',
                'enabled' => ! ($item['disabled'] ?? false),
                'description' => $item['description'] ?? null,
            ];
        }

        return $parsed;
    }

    protected function parseVariables(array $variables): array
    {
        return $this->parseKeyValues($variables);
    }

    protected function parseAuth(mixed $auth): array
    {
        if (! is_array($auth) || empty($auth['type'])) {
            return ['type' => 'none', 'data' => null];
        }

        $type = $auth['type'];
        $fields = [];

        foreach ($auth[$type] ?? [] as $field) {
            if (is_array($field) && isset($field['key'])) {
                $fields[$field['key']] = $field['value'] ?? '';
            }
        }

        return match ($type) {
            'bearer' => ['type' => 'bearer', 'data' => ['token' => $fields['token'] ?? '']],
            'basic' => ['type' => 'basic', 'data' => ['username' => $fields['username'] ?? '', 'password' => $fields['password'] ?? '']],
            'apikey' => ['type' => 'api_key', 'data' => ['key' => $fields['key'] ?? '', 'value' => $fields['value'] ?? '', 'in' => $fields['in'] ?? 'header']],
            default => ['type' => 'none', 'data' => null],
        };
    }

    protected function parseBody(mixed $body): array
    {
        if (! is_array($body) || empty($body['mode'])) {
            return ['type' => 'none', 'data' => null];
        }

        return match ($body['mode']) {
            'raw' => ['type' => 'raw', 'data' => ['raw' => $body['raw'] ?? '']],
            'formdata' => ['type' => 'form-data', 'data' => $this->parseKeyValues($body['formdata'] ?? [])],
            'urlencoded' => ['type' => 'urlencoded', 'data' => $this->parseKeyValues($body['urlencoded'] ?? [])],
            'file' => ['type' => 'binary', 'data' => null],
            default => ['type' => 'none', 'data' => null],
        };
    }
}
