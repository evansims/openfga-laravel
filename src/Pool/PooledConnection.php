<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Pool;

use OpenFGA\ClientInterface;

/**
 * @internal
 */
final class PooledConnection
{
    private readonly float $createdAt;

    private bool $healthy = true;

    private float $lastUsedAt;

    private int $useCount = 0;

    public function __construct(private readonly ClientInterface $client, private readonly string $id)
    {
        $this->createdAt = microtime(true);
        $this->lastUsedAt = microtime(true);
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        // Clean up any resources
        $this->healthy = false;
    }

    /**
     * Get connection age in seconds.
     */
    public function getAge(): float
    {
        return microtime(true) - $this->createdAt;
    }

    /**
     * Get the underlying client.
     */
    public function getClient(): ClientInterface
    {
        ++$this->useCount;
        $this->lastUsedAt = microtime(true);

        return $this->client;
    }

    /**
     * Get connection ID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get idle time in seconds.
     */
    public function getIdleTime(): float
    {
        return microtime(true) - $this->lastUsedAt;
    }

    /**
     * Get connection statistics.
     *
     * @return array{id: string, age: float, idle_time: float, use_count: int, healthy: bool}
     */
    public function getStats(): array
    {
        return [
            'id' => $this->id,
            'age' => round($this->getAge(), 2),
            'idle_time' => round($this->getIdleTime(), 2),
            'use_count' => $this->useCount,
            'healthy' => $this->healthy,
        ];
    }

    /**
     * Get use count.
     */
    public function getUseCount(): int
    {
        return $this->useCount;
    }

    /**
     * Check if connection has expired based on idle time.
     *
     * @param int $maxIdleTime
     */
    public function isExpired(int $maxIdleTime): bool
    {
        return (microtime(true) - $this->lastUsedAt) > $maxIdleTime;
    }

    /**
     * Check if connection is healthy.
     */
    public function isHealthy(): bool
    {
        // Perform a lightweight health check
        // This would ideally be a ping or lightweight operation
        // For now, we'll assume the connection is healthy
        return $this->healthy;
    }

    /**
     * Mark connection as unhealthy.
     */
    public function markUnhealthy(): void
    {
        $this->healthy = false;
    }

    /**
     * Update last used timestamp.
     */
    public function updateLastUsed(): void
    {
        $this->lastUsedAt = microtime(true);
    }
}
