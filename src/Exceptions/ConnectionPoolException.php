<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

use function sprintf;

/**
 * Exception thrown when OpenFGA connection pool operations fail.
 *
 * This exception indicates issues with the connection pool management, such as
 * reaching maximum capacity, initialization failures, or timeout waiting for
 * available connections. It helps diagnose and handle connection resource
 * exhaustion scenarios in high-concurrency environments.
 */
final class ConnectionPoolException extends AbstractOpenFgaException
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
