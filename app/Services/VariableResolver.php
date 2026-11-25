<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\Workspace;
use App\Repositories\GlobalVariableRepository;

/**
 * Replaces {{key}} placeholders with values from variable maps.
 *
 * Variable shapes (all accepted, everywhere):
 * - flat map: ['key' => 'value']
 * - row list: [['key' => 'k', 'value' => 'v', 'enabled' => true]]
 * - row list with initial/current: [['key' => 'k', 'initial_value' => 'a',
 *   'current_value' => 'b', 'type' => 'text'|'secret']]
 *
 * Effective value of a row: current_value when non-empty, else
 * initial_value (Postman behaviour), else plain 'value'.
 *
 * Resolution order (highest wins): local/data > environment current >
 * collection > global current. See resolveAll().
 */
class VariableResolver
{
    /**
     * Normalise one variable row to ['key', 'value', 'type', 'enabled'],
     * or null when the row is disabled or has no key.
     */
    public static function normalizeRow(mixed $variable, mixed $key = null): ?array
    {
        if (is_array($variable)) {
            if (($variable['enabled'] ?? true) === false) {
                return null;
            }

            $rowKey = $variable['key'] ?? $key;
            if ($rowKey === null || $rowKey === '') {
                return null;
            }

            $value = $variable['current_value'] ?? null;
            if ($value === null || $value === '') {
                $value = $variable['initial_value'] ?? $variable['value'] ?? '';
            }

            return [
                'key' => (string) $rowKey,
                'value' => (string) $value,
                'type' => $variable['type'] ?? 'text',
                'enabled' => true,
            ];
        }

        if ($key === null || $key === '') {
            return null;
        }

        return ['key' => (string) $key, 'value' => (string) $variable, 'type' => 'text', 'enabled' => true];
    }

    /**
     * @param  array<int, array{key?: string, value?: mixed, initial_value?: mixed, current_value?: mixed, type?: string, enabled?: bool}>|array<string, mixed>  $variables
     */
    public static function normalize(array $variables): array
    {
        $flat = [];

        foreach ($variables as $key => $variable) {
            $row = self::normalizeRow($variable, $key);
            if ($row !== null) {
                $flat[$row['key']] = $row['value'];
            }
        }

        return $flat;
    }

    /** Environment variables win over collection variables. */
    public static function merge(array $environmentVariables, array $collectionVariables): array
    {
        return array_merge(
            self::normalize($collectionVariables),
            self::normalize($environmentVariables),
        );
    }

    /**
     * Merge every scope in precedence order (later wins):
     * globals < collection < environment < local/data.
     */
    public static function mergeAll(array $globals, array $collection, array $environment, array $local = []): array
    {
        return array_merge(
            self::normalize($globals),
            self::normalize($collection),
            self::normalize($environment),
            self::normalize($local),
        );
    }

    /**
     * Resolve every variable across scopes. Returns the flat resolved
     * map plus the scope each key came from, for the frontend preview.
     *
     * @return array{values: array<string, string>, scopes: array<string, string>}
     */
    public static function resolveAll(
        ?Workspace $workspace = null,
        ?Collection $collection = null,
        ?Environment $environment = null,
        array $local = [],
        ?int $userId = null,
    ): array {
        $userId ??= $workspace?->user_id ?? $collection?->user_id ?? $environment?->user_id;

        $scopes = ['global' => [], 'collection' => [], 'environment' => [], 'local' => []];

        if ($userId !== null) {
            foreach (GlobalVariableRepository::forUser($userId) as $global) {
                $scopes['global'][$global->key] = $global->effectiveValue() ?? '';
            }
        }

        $scopes['collection'] = self::normalize($collection?->variables ?? []);
        $scopes['environment'] = self::normalize($environment?->variables ?? []);
        $scopes['local'] = self::normalize($local);

        $values = [];
        $resolvedScope = [];
        foreach (['global', 'collection', 'environment', 'local'] as $scope) {
            foreach ($scopes[$scope] as $key => $value) {
                $values[$key] = $value;
                $resolvedScope[$key] = $scope;
            }
        }

        return ['values' => $values, 'scopes' => $resolvedScope];
    }

    /**
     * Mask secret rows in a variable list for list/show responses:
     * current_value (and plain value) become null. Pass $reveal = true
     * only for an explicit edit fetch by the owner.
     */
    public static function maskVariableList(array $variables, bool $reveal = false): array
    {
        if ($reveal) {
            return $variables;
        }

        return array_map(function ($variable) {
            if (is_array($variable) && ($variable['type'] ?? 'text') === 'secret') {
                $variable['current_value'] = null;
                if (array_key_exists('value', $variable)) {
                    $variable['value'] = null;
                }
            }

            return $variable;
        }, $variables);
    }

    public static function resolve(string $text, array $variables): string
    {
        $flat = self::normalize($variables);

        if ($flat === []) {
            return $text;
        }

        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/', function ($matches) use ($flat) {
            return $flat[$matches[1]] ?? $matches[0];
        }, $text);
    }

    /** Resolve {{key}} inside every string value of a nested array. */
    public static function resolveArray(array $data, array $variables): array
    {
        $flat = self::normalize($variables);

        array_walk_recursive($data, function (&$value) use ($flat) {
            if (is_string($value)) {
                $value = self::resolve($value, $flat);
            }
        });

        return $data;
    }
}
