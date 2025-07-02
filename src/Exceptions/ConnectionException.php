<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

/**
 * Exception thrown when there are connection issues with OpenFGA
 */
final class ConnectionException extends OpenFgaException
{
    public static function unreachable(string $url): self
    {
        return new self("Unable to connect to OpenFGA at '{$url}'");
    }

    public static function timeout(string $url, int $timeout): self
    {
        return new self("Connection to OpenFGA at '{$url}' timed out after {$timeout} seconds");
    }

    public static function invalidConfiguration(string $message): self
    {
        return new self("Invalid connection configuration: {$message}");
    }
}