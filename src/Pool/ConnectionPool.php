<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Pool;

use Illuminate\Support\Collection;
use OpenFGA\Client;
use OpenFGA\ClientInterface;
use OpenFGA\Laravel\Exceptions\ConnectionPoolException;
use RuntimeException;

class ConnectionPool
{
    protected Collection $available;
    protected Collection $inUse;
    protected array $config;
    protected int $maxConnections;
    protected int $minConnections;
    protected int $maxIdleTime;
    protected int $connectionTimeout;
    protected array $stats = [
        'created' => 0,
        'destroyed' => 0,
        'acquired' => 0,
        'released' => 0,
        'timeouts' => 0,
        'errors' => 0,
    ];

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->maxConnections = $config['max_connections'] ?? 10;
        $this->minConnections = $config['min_connections'] ?? 2;
        $this->maxIdleTime = $config['max_idle_time'] ?? 300; // 5 minutes
        $this->connectionTimeout = $config['connection_timeout'] ?? 5;
        
        $this->available = new Collection();
        $this->inUse = new Collection();
        
        // Initialize minimum connections
        $this->initializePool();
    }

    /**
     * Initialize the connection pool with minimum connections
     */
    protected function initializePool(): void
    {
        for ($i = 0; $i < $this->minConnections; $i++) {
            try {
                $connection = $this->createConnection();
                $this->available->push($connection);
            } catch (\Exception $e) {
                throw new ConnectionPoolException(
                    "Failed to initialize connection pool: {$e->getMessage()}"
                );
            }
        }
    }

    /**
     * Acquire a connection from the pool
     */
    public function acquire(): PooledConnection
    {
        $this->stats['acquired']++;

        // Try to get an available connection
        $connection = $this->getAvailableConnection();
        
        if ($connection) {
            $this->inUse->push($connection);
            return $connection;
        }

        // No available connections, try to create a new one
        if ($this->getTotalConnections() < $this->maxConnections) {
            $connection = $this->createConnection();
            $this->inUse->push($connection);
            return $connection;
        }

        // Pool is at max capacity, wait for a connection
        return $this->waitForConnection();
    }

    /**
     * Release a connection back to the pool
     */
    public function release(PooledConnection $connection): void
    {
        $this->stats['released']++;

        // Remove from in-use
        $this->inUse = $this->inUse->reject(function ($conn) use ($connection) {
            return $conn === $connection;
        });

        // Check if connection is still healthy
        if ($connection->isHealthy()) {
            $connection->updateLastUsed();
            $this->available->push($connection);
        } else {
            // Destroy unhealthy connection
            $this->destroyConnection($connection);
        }

        // Clean up idle connections
        $this->cleanupIdleConnections();
    }

    /**
     * Get an available connection from the pool
     */
    protected function getAvailableConnection(): ?PooledConnection
    {
        while ($this->available->isNotEmpty()) {
            $connection = $this->available->shift();
            
            // Check if connection is still valid
            if ($connection->isHealthy() && ! $connection->isExpired($this->maxIdleTime)) {
                return $connection;
            }
            
            // Destroy expired or unhealthy connection
            $this->destroyConnection($connection);
        }

        return null;
    }

    /**
     * Wait for a connection to become available
     */
    protected function waitForConnection(): PooledConnection
    {
        $timeout = $this->connectionTimeout;
        $start = microtime(true);

        while ((microtime(true) - $start) < $timeout) {
            if ($this->available->isNotEmpty()) {
                return $this->acquire();
            }

            // Sleep for 10ms before checking again
            usleep(10000);
        }

        $this->stats['timeouts']++;
        throw new ConnectionPoolException(
            "Timeout waiting for available connection after {$timeout} seconds"
        );
    }

    /**
     * Create a new connection
     */
    protected function createConnection(): PooledConnection
    {
        try {
            $client = new Client([
                'api_url' => $this->config['url'] ?? 'http://localhost:8080',
                'store_id' => $this->config['store_id'] ?? null,
                'authorization_model_id' => $this->config['model_id'] ?? null,
                'credentials' => $this->config['credentials'] ?? [],
                'retries' => $this->config['retries'] ?? [],
                'http_options' => $this->config['http_options'] ?? [],
            ]);

            $this->stats['created']++;
            
            return new PooledConnection($client, uniqid('conn_'));
        } catch (\Exception $e) {
            $this->stats['errors']++;
            throw new ConnectionPoolException(
                "Failed to create connection: {$e->getMessage()}"
            );
        }
    }

    /**
     * Destroy a connection
     */
    protected function destroyConnection(PooledConnection $connection): void
    {
        $connection->close();
        $this->stats['destroyed']++;
    }

    /**
     * Clean up idle connections
     */
    protected function cleanupIdleConnections(): void
    {
        $totalConnections = $this->getTotalConnections();
        
        // Keep at least minimum connections
        if ($totalConnections <= $this->minConnections) {
            return;
        }

        $this->available = $this->available->reject(function ($connection) use (&$totalConnections) {
            if ($totalConnections > $this->minConnections && $connection->isExpired($this->maxIdleTime)) {
                $this->destroyConnection($connection);
                $totalConnections--;
                return true;
            }
            return false;
        })->values();
    }

    /**
     * Get total number of connections
     */
    public function getTotalConnections(): int
    {
        return $this->available->count() + $this->inUse->count();
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'available' => $this->available->count(),
            'in_use' => $this->inUse->count(),
            'total' => $this->getTotalConnections(),
            'utilization' => $this->getTotalConnections() > 0 
                ? round(($this->inUse->count() / $this->getTotalConnections()) * 100, 2) 
                : 0,
        ]);
    }

    /**
     * Health check all connections
     */
    public function healthCheck(): array
    {
        $healthy = 0;
        $unhealthy = 0;

        // Check available connections
        $this->available->each(function ($connection) use (&$healthy, &$unhealthy) {
            if ($connection->isHealthy()) {
                $healthy++;
            } else {
                $unhealthy++;
            }
        });

        // Check in-use connections
        $this->inUse->each(function ($connection) use (&$healthy, &$unhealthy) {
            if ($connection->isHealthy()) {
                $healthy++;
            } else {
                $unhealthy++;
            }
        });

        return [
            'healthy' => $healthy,
            'unhealthy' => $unhealthy,
            'total' => $healthy + $unhealthy,
        ];
    }

    /**
     * Close all connections and shut down the pool
     */
    public function shutdown(): void
    {
        // Close all available connections
        $this->available->each(function ($connection) {
            $this->destroyConnection($connection);
        });

        // Close all in-use connections
        $this->inUse->each(function ($connection) {
            $this->destroyConnection($connection);
        });

        $this->available = new Collection();
        $this->inUse = new Collection();
    }

    /**
     * Execute a callback with a pooled connection
     */
    public function execute(callable $callback)
    {
        $connection = $this->acquire();

        try {
            return $callback($connection->getClient());
        } finally {
            $this->release($connection);
        }
    }
}