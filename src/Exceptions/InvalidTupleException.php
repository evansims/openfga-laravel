<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

use function sprintf;

/**
 * Exception thrown when a tuple is invalid or malformed.
 */
final class InvalidTupleException extends AbstractOpenFgaException
{
    public static function invalidFormat(string $field, string $value): self
    {
        return new self(sprintf("Invalid format for %s: '%s'", $field, $value));
    }

    public static function missingObject(): self
    {
        return new self('Tuple is missing required object field');
    }

    public static function missingRelation(): self
    {
        return new self('Tuple is missing required relation field');
    }

    public static function missingUser(): self
    {
        return new self('Tuple is missing required user field');
    }
}
