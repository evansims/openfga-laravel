<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

use function sprintf;

/**
 * Exception thrown when there are connection issues with OpenFGA.
 */
final class ConnectionException extends AbstractOpenFgaException
{
    public static function invalidConfiguration(string $message): self
    {
        return new self('Invalid connection configuration: ' . $message);
    }

    public static function timeout(string $url, int $timeout): self
    {
        return new self(sprintf("Connection to OpenFGA at '%s' timed out after %d seconds", $url, $timeout));
    }

    public static function unreachable(string $url): self
    {
        return new self(sprintf("Unable to connect to OpenFGA at '%s'", $url));
    }
}
