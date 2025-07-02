<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

use function sprintf;

/**
 * Exception thrown when authorization operations fail.
 */
final class AuthorizationException extends AbstractOpenFgaException
{
    public static function checkFailed(string $user, string $relation, string $object): self
    {
        return new self(sprintf("Authorization check failed for user '%s' with relation '%s' on object '%s'", $user, $relation, $object));
    }

    public static function deleteFailed(string $message): self
    {
        return new self('Failed to delete authorization data: ' . $message);
    }

    public static function writeFailed(string $message): self
    {
        return new self('Failed to write authorization data: ' . $message);
    }
}
