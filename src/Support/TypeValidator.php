<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Support;

use InvalidArgumentException;

use function gettype;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;

/**
 * Type validation utilities for the OpenFGA Laravel package.
 *
 * Provides common type checking and validation to reduce
 * code duplication across the codebase.
 */
final class TypeValidator
{
    /**
     * Validate that a value is within a numeric range.
     *
     * @param float|int $value
     * @param float|int $min
     * @param float|int $max
     * @param string    $name
     *
     * @throws InvalidArgumentException
     */
    public static function ensureInRange(
        int | float $value,
        int | float $min,
        int | float $max,
        string $name = 'value',
    ): int | float {
        if ($value < $min) {
            throw new InvalidArgumentException(sprintf('%s must be at least %s', ucfirst($name), $min));
        }

        if ($value > $max) {
            throw new InvalidArgumentException(sprintf('%s must be at most %s', ucfirst($name), $max));
        }

        return $value;
    }

    /**
     * Validate that a value is an integer or string.
     *
     * @param mixed  $value
     * @param string $name
     *
     * @throws InvalidArgumentException
     */
    public static function ensureIntOrString(mixed $value, string $name = 'value'): int | string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf('%s must be int or string, got: %s', ucfirst($name), gettype($value)));
    }

    /**
     * Validate that a string is not blank (empty after trimming).
     *
     * @param string $value
     * @param string $name
     *
     * @throws InvalidArgumentException
     */
    public static function ensureNotBlank(string $value, string $name = 'value'): string
    {
        if ('' === trim($value)) {
            throw new InvalidArgumentException(sprintf('%s cannot be blank', ucfirst($name)));
        }

        return $value;
    }

    /**
     * Validate that a value is not empty.
     *
     * @param mixed  $value
     * @param string $name
     *
     * @throws InvalidArgumentException
     */
    public static function ensureNotEmpty(mixed $value, string $name = 'value'): mixed
    {
        if (null === $value || false === $value || '' === $value || [] === $value) {
            throw new InvalidArgumentException(sprintf('%s cannot be empty', ucfirst($name)));
        }

        return $value;
    }

    /**
     * Validate that a value is a string or numeric and return it as a string.
     *
     * @param mixed  $value
     * @param string $name
     *
     * @throws InvalidArgumentException
     */
    public static function ensureStringable(mixed $value, string $name = 'value'): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf('%s must be string or numeric, got: %s', ucfirst($name), gettype($value)));
    }

    /**
     * Check if a value is stringable (string or numeric).
     *
     * @param mixed $value
     */
    public static function isStringable(mixed $value): bool
    {
        return is_string($value) || is_numeric($value);
    }
}
