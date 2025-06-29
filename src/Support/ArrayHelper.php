<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Support;

use function array_key_exists;
use function count;
use function in_array;
use function is_array;

/**
 * Array manipulation utilities for the OpenFGA Laravel package.
 *
 * Provides common array operations to reduce code duplication
 * across the codebase.
 */
final class ArrayHelper
{
    /**
     * Check if a value exists in a deeply nested array.
     *
     * @param array<mixed> $array
     * @param string       $path
     * @param mixed        $value
     */
    public static function deepContains(array $array, string $path, mixed $value): bool
    {
        $items = self::deepGet($array, $path, []);

        if (! is_array($items)) {
            return false;
        }

        return in_array($value, $items, true);
    }

    /**
     * Count items in a deeply nested array path.
     *
     * @param array<mixed> $array
     * @param string       $path
     */
    public static function deepCount(array $array, string $path): int
    {
        $items = self::deepGet($array, $path, []);

        if (! is_array($items)) {
            return 0;
        }

        return count($items);
    }

    /**
     * Get a value from a deeply nested array using dot notation.
     *
     * @param array<mixed> $array
     * @param string       $path
     * @param mixed        $default
     */
    public static function deepGet(array $array, string $path, mixed $default = null): mixed
    {
        $keys = explode('.', $path);

        $current = $array;

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return $default;
            }

            /** @var mixed $current */
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Filter an array by a callback and return the count.
     *
     * @param array<mixed>          $array
     * @param callable(mixed): bool $callback
     */
    public static function filterCount(array $array, callable $callback): int
    {
        return count(array_filter($array, $callback));
    }

    /**
     * Get users from an expansion tree structure.
     *
     * @param  array<string, mixed> $expansion
     * @return array<mixed>
     */
    public static function getExpansionUsers(array $expansion): array
    {
        /** @var mixed $users */
        $users = self::deepGet($expansion, 'tree.root.leaf.users', []);

        if (is_array($users)) {
            return $users;
        }

        return [];
    }

    /**
     * Check if a deeply nested array path exists and contains an array.
     *
     * @param array<mixed> $array
     * @param string       $path
     */
    public static function hasArrayAt(array $array, string $path): bool
    {
        /** @var mixed $value */
        $value = self::deepGet($array, $path);

        return is_array($value);
    }
}
