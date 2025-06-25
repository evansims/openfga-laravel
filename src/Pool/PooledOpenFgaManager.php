<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Pool;

use OpenFGA\Laravel\OpenFgaManager;
use Override;

final class PooledOpenFgaManager extends OpenFgaManager
{
    private ?ConnectionPool $pool = null;

    /**
     * Destructor to ensure pool is shut down.
     */
    public function __destruct()
    {
        $this->shutdownPool();
    }

    /**
     * Override check method to use pooled connection.
     */
    #[Override]
    public function check(string $user, string $relation, string $object, array $contextualTuples = [], array $context = [], ?string $connection = null): bool
    {
        if (! config('openfga.pool.enabled', false)) {
            return parent::check($user, $relation, $object);
        }

        return $this->getPool()->execute(function ($client) use ($user, $relation, $object) {
            $result = $client->check(
                user: $user,
                relation: $relation,
                object: $object,
                authorizationModelId: $this->getModelId(),
            );

            if ($this->shouldThrowExceptions()) {
                return $result->unwrap()->getAllowed();
            }

            return $result->val()?->getAllowed() ?? false;
        });
    }

    /**
     * Get pool statistics.
     */
    public function getPoolStats(): array
    {
        if (! $this->pool instanceof ConnectionPool) {
            return [
                'enabled' => false,
                'message' => 'Connection pool not initialized',
            ];
        }

        return [
            'enabled' => true,
            'stats' => $this->pool->getStats(),
            'health' => $this->pool->healthCheck(),
        ];
    }

    /**
     * Shutdown the connection pool.
     */
    public function shutdownPool(): void
    {
        if ($this->pool instanceof ConnectionPool) {
            $this->pool->shutdown();
            $this->pool = null;
        }
    }

    /**
     * Override write method to use pooled connection.
     */
    #[Override]
    public function write(array $writes = [], array $deletes = [], ?string $connection = null): void
    {
        if (! config('openfga.pool.enabled', false)) {
            parent::write($writes, $deletes);

            return;
        }

        $this->getPool()->execute(function ($client) use ($writes, $deletes): void {
            $result = $client->write(
                writes: $writes,
                deletes: $deletes,
                authorizationModelId: $this->getModelId(),
            );

            if ($this->shouldThrowExceptions()) {
                $result->unwrap();
            }
        });
    }

    /**
     * Get or create the connection pool.
     */
    private function getPool(): ConnectionPool
    {
        if (! $this->pool instanceof ConnectionPool) {
            $config = $this->getConnectionConfig($this->getDefaultConnection());

            $poolConfig = array_merge($config, [
                'max_connections' => config('openfga.pool.max_connections', 10),
                'min_connections' => config('openfga.pool.min_connections', 2),
                'max_idle_time' => config('openfga.pool.max_idle_time', 300),
                'connection_timeout' => config('openfga.pool.connection_timeout', 5),
            ]);

            $this->pool = new ConnectionPool($poolConfig);
        }

        return $this->pool;
    }
}
