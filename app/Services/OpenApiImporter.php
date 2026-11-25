<?php

namespace App\Services;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\Folder;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\ApiRequestRepository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Parses an OpenAPI 3.x document (JSON, or YAML when symfony/yaml is
 * installed) into a collection: one folder per tag (or per first path
 * segment), one request per operation, servers[0] as {{baseUrl}}.
 *
 * Invalid documents throw InvalidArgumentException — never a 500.
 */
class OpenApiImporter
{
    protected const METHODS = ['get', 'put', 'post', 'delete', 'patch', 'options', 'head'];

    /** Import a decoded spec array, or raw JSON/YAML text. */
    public function import(array|string $spec, Workspace $workspace, User $user): Collection
    {
        $spec = is_string($spec) ? $this->parseContent($spec) : $spec;

        $this->validate($spec);

        return DB::transaction(function () use ($spec, $workspace, $user) {
            $baseUrl = $this->baseUrl($spec);
            $variables = [];

            if ($baseUrl !== null) {
                $variables[] = ['key' => 'baseUrl', 'value' => $baseUrl, 'enabled' => true];
            }

            $collection = Collection::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'name' => $spec['info']['title'],
                'description' => $spec['info']['description'] ?? null,
                'variables' => $variables,
            ]);

            $folders = [];
            $sortOrder = 0;

            foreach ($spec['paths'] as $path => $pathItem) {
                if (! is_array($pathItem)) {
                    continue;
                }

                foreach (self::METHODS as $method) {
                    $operation = $pathItem[$method] ?? null;

                    if (! is_array($operation)) {
                        continue;
                    }

                    $folder = $this->folderFor($spec, $operation, $path, $collection, $folders);
                    $request = $this->buildRequest($spec, $operation, $method, $path, $baseUrl);

                    ApiRequestRepository::create([
                        ...$request,
                        'collection_id' => $collection->id,
                        'folder_id' => $folder?->id,
                        'user_id' => $user->id,
                        'sort_order' => $sortOrder++,
                    ]);
                }
            }

            return $collection;
        });
    }

    /** Decode raw spec text: JSON always, YAML only with symfony/yaml. */
    public function parseContent(string $content): array
    {
        $content = trim($content);

        if ($content === '') {
            throw new InvalidArgumentException('Empty OpenAPI document.');
        }

        if (str_starts_with($content, '{')) {
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException('Invalid OpenAPI JSON: '.json_last_error_msg().'.');
            }

            return $decoded;
        }

        if (! class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            throw new InvalidArgumentException(
                'YAML OpenAPI import needs the symfony/yaml package, which is not installed. '.
                'Paste the spec as JSON instead, or run: composer require symfony/yaml.'
            );
        }

        try {
            $decoded = \Symfony\Component\Yaml\Yaml::parse($content);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Invalid OpenAPI YAML: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Invalid OpenAPI YAML: expected a mapping at the top level.');
        }

        return $decoded;
    }

    protected function validate(array $spec): void
    {
        $version = $spec['openapi'] ?? null;

        if (! is_string($version) || ! str_starts_with($version, '3.')) {
            throw new InvalidArgumentException('Only OpenAPI 3.x documents are supported.');
        }

        if (empty($spec['info']['title']) || ! is_string($spec['info']['title'])) {
            throw new InvalidArgumentException('Invalid OpenAPI document: missing info.title.');
        }

        if (! isset($spec['paths']) || ! is_array($spec['paths']) || $spec['paths'] === []) {
            throw new InvalidArgumentException('Invalid OpenAPI document: missing paths.');
        }
    }

    /** servers[0] with {var} turned into {{var}}; null when no servers. */
    protected function baseUrl(array $spec): ?string
    {
        $server = $spec['servers'][0] ?? null;

        if (! is_array($server) || empty($server['url'])) {
            return null;
        }

        $url = rtrim((string) $server['url'], '/');

        return preg_replace_callback('/\{([^}]+)\}/', function ($matches) use ($server) {
            $default = $server['variables'][$matches[1]]['default'] ?? null;

            return $default !== null ? (string) $default : '{{'.$matches[1].'}}';
        }, $url);
    }

    /** First tag wins; otherwise the first path segment; else no folder. */
    protected function folderFor(array $spec, array $operation, string $path, Collection $collection, array &$folders): ?Folder
    {
        $name = $operation['tags'][0] ?? null;

        if (! is_string($name) || $name === '') {
            $segments = array_values(array_filter(explode('/', $path)));
            $name = $segments[0] ?? null;
        }

        if ($name === null) {
            return null;
        }

        if (! isset($folders[$name])) {
            $folders[$name] = Folder::create([
                'collection_id' => $collection->id,
                'parent_id' => null,
                'name' => $name,
                'sort_order' => count($folders),
            ]);
        }

        return $folders[$name];
    }

    /** One operation -> one normalised request array. */
    protected function buildRequest(array $spec, array $operation, string $method, string $path, ?string $baseUrl): array
    {
        $params = [];
        $headers = [];

        foreach ([...($spec['paths'][$path]['parameters'] ?? []), ...($operation['parameters'] ?? [])] as $parameter) {
            if (! is_array($parameter) || empty($parameter['name'])) {
                continue;
            }

            $row = [
                'key' => $parameter['name'],
                'value' => $this->parameterExample($parameter),
                'enabled' => true,
                'description' => $parameter['description'] ?? null,
            ];

            match ($parameter['in'] ?? 'query') {
                'path' => $params[] = [...$row, 'in' => 'path'],
                'header' => $headers[] = $row,
                default => $params[] = $row,
            };
        }

        $url = ($baseUrl !== null ? '{{baseUrl}}' : '').$this->pathToPostmanStyle($path);
        $body = $this->buildBody($spec, $operation);
        $auth = $this->buildAuth($spec, $operation);

        return [
            'name' => $operation['summary'] ?? $operation['operationId'] ?? strtoupper($method).' '.$path,
            'method' => strtoupper($method),
            'url' => $url,
            'params' => $params,
            'headers' => $headers,
            'auth_type' => $auth['type'],
            'auth' => $auth['data'],
            'body_type' => $body['type'],
            'body' => $body['data'],
            'description' => $operation['description'] ?? $operation['summary'] ?? null,
        ];
    }

    /** {id} -> :id, so RequestSender substitutes it from path params. */
    protected function pathToPostmanStyle(string $path): string
    {
        return preg_replace('/\{([^}]+)\}/', ':$1', $path);
    }

    protected function parameterExample(array $parameter): string
    {
        $schema = $parameter['schema'] ?? [];

        if (isset($parameter['example'])) {
            return (string) $parameter['example'];
        }

        $example = $this->exampleFromSchema(is_array($schema) ? $schema : [], []);

        return is_scalar($example) ? (string) $example : '';
    }

    protected function buildBody(array $spec, array $operation): array
    {
        $requestBody = $operation['requestBody'] ?? null;

        if (! is_array($requestBody) || ! is_array($requestBody['content'] ?? null)) {
            return ['type' => 'none', 'data' => null];
        }

        $content = $requestBody['content'];

        if (isset($content['application/json'])) {
            $example = $this->exampleFromSchema($content['application/json']['schema'] ?? [], $spec['components']['schemas'] ?? []);

            return ['type' => 'raw', 'data' => [
                'raw' => json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                'raw_language' => 'json',
            ]];
        }

        if (isset($content['application/x-www-form-urlencoded'])) {
            return ['type' => 'urlencoded', 'data' => $this->schemaRows($content['application/x-www-form-urlencoded']['schema'] ?? [], $spec)];
        }

        if (isset($content['multipart/form-data'])) {
            return ['type' => 'form-data', 'data' => $this->schemaRows($content['multipart/form-data']['schema'] ?? [], $spec)];
        }

        if (isset($content['text/plain'])) {
            return ['type' => 'raw', 'data' => ['raw' => '']];
        }

        return ['type' => 'none', 'data' => null];
    }

    /** Object schema properties -> key/value rows with example values. */
    protected function schemaRows(mixed $schema, array $spec): array
    {
        $schema = $this->resolveRef($schema, $spec);

        if (! is_array($schema) || ! is_array($schema['properties'] ?? null)) {
            return [];
        }

        $rows = [];

        foreach ($schema['properties'] as $key => $property) {
            $example = $this->exampleFromSchema(is_array($property) ? $property : [], $spec['components']['schemas'] ?? []);

            $rows[] = [
                'key' => $key,
                'value' => is_scalar($example) ? (string) $example : json_encode($example),
                'enabled' => true,
            ];
        }

        return $rows;
    }

    /** Operation security (else root security) -> our auth shape. */
    protected function buildAuth(array $spec, array $operation): array
    {
        $security = $operation['security'] ?? $spec['security'] ?? null;
        $schemes = $spec['components']['securitySchemes'] ?? [];

        if (! is_array($security) || $security === [] || ! is_array($schemes)) {
            return ['type' => 'none', 'data' => null];
        }

        foreach ($security as $requirement) {
            foreach ((array) $requirement as $name => $scopes) {
                $scheme = $schemes[$name] ?? null;

                if (! is_array($scheme)) {
                    continue;
                }

                return match ($scheme['type'] ?? null) {
                    'http' => match (strtolower($scheme['scheme'] ?? '')) {
                        'bearer' => ['type' => 'bearer', 'data' => ['token' => '']],
                        'basic' => ['type' => 'basic', 'data' => ['username' => '', 'password' => '']],
                        default => ['type' => 'none', 'data' => null],
                    },
                    'apiKey' => ['type' => 'api_key', 'data' => [
                        'key' => $scheme['name'] ?? '',
                        'value' => '',
                        'in' => $scheme['in'] ?? 'header',
                    ]],
                    default => ['type' => 'none', 'data' => null],
                };
            }
        }

        return ['type' => 'none', 'data' => null];
    }

    /** Build an example value from a schema (resolves local $refs). */
    protected function exampleFromSchema(mixed $schema, array $components, int $depth = 0): mixed
    {
        $schema = $this->resolveRef($schema, ['components' => ['schemas' => $components]]);

        if (! is_array($schema) || $depth > 5) {
            return null;
        }

        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }

        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        if (isset($schema['enum'][0])) {
            return $schema['enum'][0];
        }

        return match ($schema['type'] ?? null) {
            'object' => array_map(
                fn ($property) => $this->exampleFromSchema($property, $components, $depth + 1),
                $schema['properties'] ?? [],
            ),
            'array' => isset($schema['items'])
                ? [$this->exampleFromSchema($schema['items'], $components, $depth + 1)]
                : [],
            'integer' => 0,
            'number' => 0,
            'boolean' => false,
            'string' => match ($schema['format'] ?? null) {
                'date-time' => '2026-01-01T00:00:00Z',
                'date' => '2026-01-01',
                'email' => 'user@example.com',
                'uuid' => '00000000-0000-0000-0000-000000000000',
                default => 'string',
            },
            default => null,
        };
    }

    /** Resolve a local #/components/schemas/... $ref, else return as-is. */
    protected function resolveRef(mixed $schema, array $spec): mixed
    {
        if (! is_array($schema) || ! isset($schema['$ref'])) {
            return $schema;
        }

        if (! str_starts_with($schema['$ref'], '#/components/schemas/')) {
            return $schema;
        }

        $name = substr($schema['$ref'], strlen('#/components/schemas/'));
        $resolved = $spec['components']['schemas'][$name] ?? null;

        return is_array($resolved) ? $resolved : $schema;
    }
}
