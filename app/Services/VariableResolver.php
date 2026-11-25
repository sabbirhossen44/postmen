<?php

namespace App\Services;

/**
 * Replaces {{key}} placeholders with values from a flat
 * key => value variable map.
 */
class VariableResolver
{
    /**
     * @param  array<int, array{key?: string, value?: mixed, enabled?: bool}>|array<string, mixed>  $variables
     */
    public static function normalize(array $variables): array
    {
        $flat = [];

        foreach ($variables as $key => $variable) {
            if (is_array($variable)) {
                if (($variable['enabled'] ?? true) === false) {
                    continue;
                }

                if (isset($variable['key'])) {
                    $flat[(string) $variable['key']] = (string) ($variable['value'] ?? '');
                }
            } else {
                $flat[(string) $key] = (string) $variable;
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
