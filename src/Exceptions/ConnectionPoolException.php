<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

use RuntimeException;

class ConnectionPoolException extends RuntimeException
{
    /**
     * Create a new connection pool exception
     */
    public static function maxConnectionsReached(int $max): self
    {
        return new self("Connection pool has reached maximum capacity of {$max} connections");
    }

    /**
     * Create a timeout exception
     */
    public static function timeout(int $seconds): self
    {
        return new self("Timeout waiting for available connection after {$seconds} seconds");
    }

    /**
     * Create an initialization exception
     */
    public static function initializationFailed(string $reason): self
    {
        return new self("Failed to initialize connection pool: {$reason}");
    }
}