<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\Folder;

/** Serializes one of our collections back to Postman Collection v2.1 JSON. */
class CollectionExporter
{
    public function export(Collection $collection): array
    {
        $collection->loadMissing(['folders', 'requests']);

        return [
            'info' => [
                'name' => $collection->name,
                'description' => $collection->description,
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'variable' => $this->toPostmanKeyValues($collection->variables ?? []),
            'item' => $this->exportItems($collection),
        ];
    }

    protected function exportItems(Collection $collection): array
    {
        $items = [];

        $rootFolders = $collection->folders->whereNull('parent_id')->sortBy('sort_order');
        $rootRequests = $collection->requests->whereNull('folder_id')->sortBy('sort_order');

        foreach ($rootFolders as $folder) {
            $items[] = $this->exportFolder($folder);
        }

        foreach ($rootRequests as $request) {
            $items[] = $this->exportRequest($request);
        }

        return $items;
    }

    protected function exportFolder(Folder $folder): array
    {
        $folder->loadMissing(['children', 'requests']);

        $items = [];

        foreach ($folder->children->sortBy('sort_order') as $child) {
            $items[] = $this->exportFolder($child);
        }

        foreach ($folder->requests->sortBy('sort_order') as $request) {
            $items[] = $this->exportRequest($request);
        }

        return [
            'name' => $folder->name,
            'item' => $items,
        ];
    }

    protected function exportRequest(ApiRequest $request): array
    {
        $url = $request->url;
        $query = $this->toPostmanKeyValues($request->params ?? []);

        return [
            'name' => $request->name,
            'description' => $request->description,
            'request' => [
                'method' => $request->method,
                'header' => $this->toPostmanKeyValues($request->headers ?? []),
                'url' => [
                    'raw' => $url,
                    'query' => $query,
                ],
                'auth' => $this->exportAuth($request),
                'body' => $this->exportBody($request),
            ],
        ];
    }

    protected function exportAuth(ApiRequest $request): ?array
    {
        $auth = $request->auth ?? [];

        return match ($request->auth_type) {
            'bearer' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => $auth['token'] ?? '', 'type' => 'string']]],
            'basic' => ['type' => 'basic', 'basic' => [
                ['key' => 'username', 'value' => $auth['username'] ?? '', 'type' => 'string'],
                ['key' => 'password', 'value' => $auth['password'] ?? '', 'type' => 'string'],
            ]],
            'api_key' => ['type' => 'apikey', 'apikey' => [
                ['key' => 'key', 'value' => $auth['key'] ?? '', 'type' => 'string'],
                ['key' => 'value', 'value' => $auth['value'] ?? '', 'type' => 'string'],
                ['key' => 'in', 'value' => $auth['in'] ?? 'header', 'type' => 'string'],
            ]],
            default => null,
        };
    }

    protected function exportBody(ApiRequest $request): ?array
    {
        $body = $request->body;

        return match ($request->body_type) {
            'raw' => ['mode' => 'raw', 'raw' => is_array($body) ? ($body['raw'] ?? '') : (string) $body],
            'form-data' => ['mode' => 'formdata', 'formdata' => $this->toPostmanKeyValues(is_array($body) ? $body : [])],
            'urlencoded' => ['mode' => 'urlencoded', 'urlencoded' => $this->toPostmanKeyValues(is_array($body) ? $body : [])],
            'binary' => ['mode' => 'file', 'file' => new \stdClass],
            default => null,
        };
    }

    /** [{key, value, enabled}] -> [{key, value, disabled}]. */
    protected function toPostmanKeyValues(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['key'])) {
                continue;
            }

            $entry = [
                'key' => $item['key'],
                'value' => $item['value'] ?? '',
            ];

            if (($item['enabled'] ?? true) === false) {
                $entry['disabled'] = true;
            }

            $out[] = $entry;
        }

        return $out;
    }
}
