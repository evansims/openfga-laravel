<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

use RuntimeException;

use function sprintf;

final class ConnectionPoolException extends RuntimeException
{
    /**
     * Create an initialization exception.
     *
     * @param string $reason
     */
    public static function initializationFailed(string $reason): self
    {
        return new self('Failed to initialize connection pool: ' . $reason);
    }

    /**
     * Create a new connection pool exception.
     *
     * @param int $max
     */
    public static function maxConnectionsReached(int $max): self
    {
        return new self(sprintf('Connection pool has reached maximum capacity of %d connections', $max));
    }

    /**
     * Create a timeout exception.
     *
     * @param int $seconds
     */
    public static function timeout(int $seconds): self
    {
        return new self(sprintf('Timeout waiting for available connection after %d seconds', $seconds));
    }
}
