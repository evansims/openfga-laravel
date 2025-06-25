<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Pool;

use OpenFGA\ClientInterface;

class PooledConnection
{
    protected ClientInterface $client;
    protected string $id;
    protected float $createdAt;
    protected float $lastUsedAt;
    protected bool $healthy = true;
    protected int $useCount = 0;

    public function __construct(ClientInterface $client, string $id)
    {
        $this->client = $client;
        $this->id = $id;
        $this->createdAt = microtime(true);
        $this->lastUsedAt = microtime(true);
    }

    /**
     * Get the underlying client
     */
    public function getClient(): ClientInterface
    {
        $this->useCount++;
        $this->lastUsedAt = microtime(true);
        return $this->client;
    }

    /**
     * Get connection ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Check if connection is healthy
     */
    public function isHealthy(): bool
    {
        if (! $this->healthy) {
            return false;
        }

        // Perform a lightweight health check
        try {
            // This would ideally be a ping or lightweight operation
            // For now, we'll assume the connection is healthy
            return true;
        } catch (\Exception $e) {
            $this->healthy = false;
            return false;
        }
    }

    /**
     * Check if connection has expired based on idle time
     */
    public function isExpired(int $maxIdleTime): bool
    {
        return (microtime(true) - $this->lastUsedAt) > $maxIdleTime;
    }

    /**
     * Update last used timestamp
     */
    public function updateLastUsed(): void
    {
        $this->lastUsedAt = microtime(true);
    }

    /**
     * Get connection age in seconds
     */
    public function getAge(): float
    {
        return microtime(true) - $this->createdAt;
    }

    /**
     * Get idle time in seconds
     */
    public function getIdleTime(): float
    {
        return microtime(true) - $this->lastUsedAt;
    }

    /**
     * Get use count
     */
    public function getUseCount(): int
    {
        return $this->useCount;
    }

    /**
     * Mark connection as unhealthy
     */
    public function markUnhealthy(): void
    {
        $this->healthy = false;
    }

    /**
     * Close the connection
     */
    public function close(): void
    {
        // Clean up any resources
        $this->healthy = false;
    }

    /**
     * Get connection statistics
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
}