<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Support;

use function implode;
use function is_array;
use function is_object;
use function is_scalar;
use function sprintf;

/**
 * Message formatting utilities for the OpenFGA Laravel package.
 *
 * Provides consistent message formatting across the codebase.
 */
final class MessageFormatter
{
    /**
     * Format a batch operation result message.
     *
     * @param int $total
     * @param int $successful
     * @param int $failed
     */
    public static function formatBatchResult(
        int $total,
        int $successful,
        int $failed,
    ): string {
        return sprintf(
            'Batch operation: %d total, %d successful, %d failed',
            $total,
            $successful,
            $failed,
        );
    }

    /**
     * Format a count assertion message.
     *
     * @param string $description
     * @param int    $expected
     * @param int    $actual
     */
    public static function formatCountAssertion(
        string $description,
        int $expected,
        int $actual,
    ): string {
        return sprintf(
            'Failed asserting that [%d] %s. Actual: [%d]',
            $expected,
            $description,
            $actual,
        );
    }

    /**
     * Format a missing item assertion message.
     *
     * @param string       $itemType
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     */
    public static function formatMissingItemsAssertion(
        string $itemType,
        array $expected,
        array $actual,
    ): string {
        $missing = array_diff($expected, $actual);
        $missingStrings = array_map(
            static fn ($v): string => is_scalar($v) || (is_object($v) && method_exists($v, '__toString')) ? (string) $v : '',
            $missing,
        );

        return sprintf(
            'Failed asserting that %s contains all expected items. Missing: [%s]',
            $itemType,
            implode(', ', $missingStrings),
        );
    }

    /**
     * Format a permission error message.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param bool   $expected
     */
    public static function formatPermissionAssertion(
        string $user,
        string $relation,
        string $object,
        bool $expected,
    ): string {
        return sprintf(
            'Failed asserting that user [%s] %s permission [%s] on object [%s]',
            $user,
            $expected ? 'has' : 'does not have',
            $relation,
            $object,
        );
    }

    /**
     * Format a type validation error message.
     *
     * @param string               $fieldName
     * @param array<string>|string $expectedType
     * @param string               $actualType
     */
    public static function formatTypeError(
        string $fieldName,
        string | array $expectedType,
        string $actualType,
    ): string {
        $expected = is_array($expectedType) ? implode(' or ', $expectedType) : $expectedType;

        return sprintf(
            '%s must be %s, got: %s',
            ucfirst($fieldName),
            $expected,
            $actualType,
        );
    }

    /**
     * Format an unexpected item assertion message.
     *
     * @param string       $itemType
     * @param array<mixed> $unexpected
     */
    public static function formatUnexpectedItemsAssertion(
        string $itemType,
        array $unexpected,
    ): string {
        $unexpectedStrings = array_map(
            static fn ($v): string => is_scalar($v) || (is_object($v) && method_exists($v, '__toString')) ? (string) $v : '',
            $unexpected,
        );

        return sprintf(
            'Failed asserting that %s does not contain unexpected items. Found: [%s]',
            $itemType,
            implode(', ', $unexpectedStrings),
        );
    }

    /**
     * Format a validation error message.
     *
     * @param string $fieldName
     * @param string $constraint
     */
    public static function formatValidationError(
        string $fieldName,
        string $constraint,
    ): string {
        return sprintf('%s %s', ucfirst($fieldName), $constraint);
    }
}
