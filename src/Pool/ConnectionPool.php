<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Pool;

use Exception;
use Illuminate\Support\Collection;
use OpenFGA\Authentication\{ClientCredentialAuthentication, TokenAuthentication};
use OpenFGA\{Client, ClientInterface};
use OpenFGA\Language;
use OpenFGA\Laravel\Exceptions\ConnectionPoolException;

use function is_int;
use function is_string;
use function sprintf;

/**
 * @internal
 */
final class ConnectionPool
{
    private readonly int $connectionTimeout;

    private readonly int $maxConnections;

    private readonly int $maxIdleTime;

    private readonly int $minConnections;

    /**
     * @var Collection<int, PooledConnection>
     */
    private Collection $available;

    /**
     * @var Collection<int, PooledConnection>
     */
    private Collection $inUse;

    /**
     * @var array{created: int, destroyed: int, acquired: int, released: int, timeouts: int, errors: int}
     */
    private array $stats = [
        'created' => 0,
        'destroyed' => 0,
        'acquired' => 0,
        'released' => 0,
        'timeouts' => 0,
        'errors' => 0,
    ];

    /**
     * @param array{max_connections?: int, min_connections?: int, max_idle_time?: int, connection_timeout?: int, url?: string, store_id?: string|null, model_id?: string|null, credentials?: array<string, mixed>, retries?: array<string, mixed>, http_options?: array<string, mixed>} $config
     */
    public function __construct(private array $config = [])
    {
        $this->maxConnections = $this->config['max_connections'] ?? 10;
        $this->minConnections = $this->config['min_connections'] ?? 2;
        $this->maxIdleTime = $this->config['max_idle_time'] ?? 300; // 5 minutes
        $this->connectionTimeout = $this->config['connection_timeout'] ?? 5;

        $this->available = new Collection;
        $this->inUse = new Collection;

        // Initialize minimum connections
        $this->initializePool();
    }

    /**
     * Acquire a connection from the pool.
     *
     * @throws ConnectionPoolException
     */
    public function acquire(): PooledConnection
    {
        ++$this->stats['acquired'];

        // Try to get an available connection
        $connection = $this->getAvailableConnection();

        if ($connection instanceof PooledConnection) {
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
     * Execute a callback with a pooled connection.
     *
     * @template T
     *
     * @param callable(ClientInterface): T $callback
     *
     * @throws ConnectionPoolException
     *
     * @return T
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

    /**
     * Get pool statistics.
     *
     * @return array{created: int, destroyed: int, acquired: int, released: int, timeouts: int, errors: int, available: int, in_use: int, total: int, utilization: float}
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'available' => $this->available->count(),
            'in_use' => $this->inUse->count(),
            'total' => $this->getTotalConnections(),
            'utilization' => 0 < $this->getTotalConnections()
                ? round(((float) $this->inUse->count() / (float) $this->getTotalConnections()) * 100.0, 2)
                : 0.0,
        ]);
    }

    /**
     * Get total number of connections.
     */
    public function getTotalConnections(): int
    {
        return $this->available->count() + $this->inUse->count();
    }

    /**
     * Health check all connections.
     *
     * @return array{healthy: int, unhealthy: int, total: int}
     */
    public function healthCheck(): array
    {
        $healthy = 0;
        $unhealthy = 0;

        // Check available connections
        $this->available->each(static function (PooledConnection $connection) use (&$healthy, &$unhealthy): void {
            if ($connection->isHealthy()) {
                ++$healthy;
            } else {
                ++$unhealthy;
            }
        });

        // Check in-use connections
        $this->inUse->each(static function (PooledConnection $connection) use (&$healthy, &$unhealthy): void {
            if ($connection->isHealthy()) {
                ++$healthy;
            } else {
                ++$unhealthy;
            }
        });

        return [
            'healthy' => $healthy,
            'unhealthy' => $unhealthy,
            'total' => $healthy + $unhealthy,
        ];
    }

    /**
     * Release a connection back to the pool.
     *
     * @param PooledConnection $connection
     */
    public function release(PooledConnection $connection): void
    {
        ++$this->stats['released'];

        // Remove from in-use
        $this->inUse = $this->inUse->reject(static fn (PooledConnection $conn): bool => $conn === $connection);

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
     * Close all connections and shut down the pool.
     */
    public function shutdown(): void
    {
        // Close all available connections
        $this->available->each(function (PooledConnection $connection): void {
            $this->destroyConnection($connection);
        });

        // Close all in-use connections
        $this->inUse->each(function (PooledConnection $connection): void {
            $this->destroyConnection($connection);
        });

        $this->available = new Collection;
        $this->inUse = new Collection;
    }

    /**
     * Clean up idle connections.
     */
    private function cleanupIdleConnections(): void
    {
        $totalConnections = $this->getTotalConnections();

        // Keep at least minimum connections
        if ($totalConnections <= $this->minConnections) {
            return;
        }

        $this->available = $this->available->reject(function (PooledConnection $connection) use (&$totalConnections): bool {
            if ($totalConnections > $this->minConnections && $connection->isExpired($this->maxIdleTime)) {
                $this->destroyConnection($connection);
                --$totalConnections;

                return true;
            }

            return false;
        })->values();
    }

    /**
     * Create a new connection.
     *
     * @throws ConnectionPoolException
     */
    private function createConnection(): PooledConnection
    {
        try {
            // Create authentication if credentials are provided
            $authentication = null;

            if (isset($this->config['credentials']['client_id'], $this->config['credentials']['client_secret'])
                && is_string($this->config['credentials']['client_id']) && is_string($this->config['credentials']['client_secret'])) {
                $audience = isset($this->config['credentials']['audience']) && is_string($this->config['credentials']['audience'])
                    ? $this->config['credentials']['audience'] : '';
                $issuer = isset($this->config['credentials']['issuer']) && is_string($this->config['credentials']['issuer'])
                    ? $this->config['credentials']['issuer'] : '';

                $authentication = new ClientCredentialAuthentication(
                    $this->config['credentials']['client_id'],
                    $this->config['credentials']['client_secret'],
                    $issuer,
                    $audience,
                );
            } elseif (isset($this->config['credentials']['api_token']) && is_string($this->config['credentials']['api_token'])) {
                $authentication = new TokenAuthentication($this->config['credentials']['api_token']);
            }

            $maxRetries = isset($this->config['retries']['max']) && is_int($this->config['retries']['max']) && 0 < $this->config['retries']['max']
                ? $this->config['retries']['max'] : 3;

            $client = new Client(
                $this->config['url'] ?? 'http://localhost:8080',
                $authentication,
                Language::English,
                $maxRetries,
            );

            ++$this->stats['created'];

            return new PooledConnection($client, uniqid('conn_'));
        } catch (Exception $exception) {
            ++$this->stats['errors'];

            throw new ConnectionPoolException('Failed to create connection: ' . $exception->getMessage());
        }
    }

    /**
     * Destroy a connection.
     *
     * @param PooledConnection $connection
     */
    private function destroyConnection(PooledConnection $connection): void
    {
        $connection->close();
        ++$this->stats['destroyed'];
    }

    /**
     * Get an available connection from the pool.
     */
    private function getAvailableConnection(): ?PooledConnection
    {
        while ($this->available->isNotEmpty()) {
            /** @var PooledConnection $connection */
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
     * Initialize the connection pool with minimum connections.
     */
    private function initializePool(): void
    {
        for ($i = 0; $i < $this->minConnections; ++$i) {
            try {
                $connection = $this->createConnection();
                $this->available->push($connection);
            } catch (Exception $e) {
                throw new ConnectionPoolException('Failed to initialize connection pool: ' . $e->getMessage());
            }
        }
    }

    /**
     * Wait for a connection to become available.
     *
     * @throws ConnectionPoolException
     */
    private function waitForConnection(): PooledConnection
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

        ++$this->stats['timeouts'];

        throw new ConnectionPoolException(sprintf('Timeout waiting for available connection after %d seconds', $timeout));
    }
}
