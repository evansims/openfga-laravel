<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

/**
 * Exception thrown when authorization operations fail
 */
final class AuthorizationException extends OpenFgaException
{
    public static function checkFailed(string $user, string $relation, string $object): self
    {
        return new self("Authorization check failed for user '{$user}' with relation '{$relation}' on object '{$object}'");
    }

    public static function writeFailed(string $message): self
    {
        return new self("Failed to write authorization data: {$message}");
    }

    public static function deleteFailed(string $message): self
    {
        return new self("Failed to delete authorization data: {$message}");
    }
}