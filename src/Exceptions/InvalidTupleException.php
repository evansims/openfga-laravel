<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

/**
 * Exception thrown when a tuple is invalid or malformed
 */
final class InvalidTupleException extends OpenFgaException
{
    public static function missingUser(): self
    {
        return new self('Tuple is missing required user field');
    }

    public static function missingRelation(): self
    {
        return new self('Tuple is missing required relation field');
    }

    public static function missingObject(): self
    {
        return new self('Tuple is missing required object field');
    }

    public static function invalidFormat(string $field, string $value): self
    {
        return new self("Invalid format for {$field}: '{$value}'");
    }
}