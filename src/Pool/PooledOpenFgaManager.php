<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Pool;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OpenFGA\ClientInterface;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Models\Collections\{TupleKeys, TupleKeysInterface};
use OpenFGA\Models\{TupleKey, TupleKeyInterface};
use Override;
use ReflectionException;

use function is_array;
use function is_bool;
use function is_int;
use function is_object;
use function is_string;

/**
 * @internal
 */
final class PooledOpenFgaManager implements ManagerInterface
{
    private ?ConnectionPool $pool = null;

    public function __construct(private readonly OpenFgaManager $manager)
    {
    }

    /**
     * Destructor to ensure pool is shut down.
     */
    public function __destruct()
    {
        $this->shutdownPool();
    }

    /**
     * Batch check multiple permissions at once.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $checks
     * @param ?string                                                           $connection
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException
     *
     * @return array<string, bool>
     */
    #[Override]
    public function batchCheck(array $checks, ?string $connection = null): array
    {
        return $this->manager->batchCheck($checks, $connection);
    }

    /**
     * Check if a user has a specific permission.
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException|\Psr\SimpleCache\InvalidArgumentException
     */
    #[Override]
    public function check(string $user, string $relation, string $object, array $contextualTuples = [], array $context = [], ?string $connection = null): bool
    {
        $poolEnabled = config('openfga.pool.enabled', false);

        if (! is_bool($poolEnabled) || ! $poolEnabled) {
            return $this->manager->check($user, $relation, $object, $contextualTuples, $context, $connection);
        }

        return $this->getPool()->execute(function (ClientInterface $client) use ($user, $relation, $object, $contextualTuples, $context): bool {
            $connectionName = $this->manager->getDefaultConnection();

            /** @var mixed $storeId */
            $storeId = config('openfga.connections.' . $connectionName . '.store_id');

            /** @var mixed $modelId */
            $modelId = config('openfga.connections.' . $connectionName . '.authorization_model_id');

            // Create TupleKey
            $tupleKey = new TupleKey(
                user: $user,
                relation: $relation,
                object: $object,
            );

            // Convert contextual tuples if provided
            $contextualTuplesCollection = null;

            if ([] !== $contextualTuples) {
                $contextualTuplesCollection = new TupleKeys;

                foreach ($contextualTuples as $contextualTuple) {
                    if (is_array($contextualTuple) && isset($contextualTuple['user'], $contextualTuple['relation'], $contextualTuple['object'])) {
                        $contextualTuplesCollection->add(new TupleKey(
                            user: $contextualTuple['user'],
                            relation: $contextualTuple['relation'],
                            object: $contextualTuple['object'],
                        ));
                    } elseif ($contextualTuple instanceof TupleKeyInterface) {
                        $contextualTuplesCollection->add($contextualTuple);
                    }
                }
            }

            // Convert context to object if provided
            $contextObject = null;

            if ([] !== $context) {
                $contextObject = (object) $context;
            }

            $result = $client->check(
                store: is_string($storeId) ? $storeId : '',
                model: is_string($modelId) ? $modelId : '',
                tuple: $tupleKey,
                context: $contextObject,
                contextualTuples: $contextualTuplesCollection,
            );

            /** @var mixed $throwExceptions */
            $throwExceptions = config('openfga.throw_exceptions', false);

            if (is_bool($throwExceptions) && $throwExceptions) {
                /** @var mixed $success */
                $success = $result->unwrap();

                if (is_object($success) && method_exists($success, 'getAllowed')) {
                    /** @var bool */
                    return $success->getAllowed();
                }

                return false;
            }

            /** @var mixed $val */
            $val = $result->val();

            if (null !== $val && is_object($val) && method_exists($val, 'getAllowed')) {
                /** @var bool */
                return $val->getAllowed();
            }

            return false;
        });
    }

    /**
     * Get pool statistics.
     *
     * @return array{enabled: bool, message?: string, stats?: mixed, health?: mixed}
     */
    public function getPoolStats(): array
    {
        $pool = $this->pool;

        if (! $pool instanceof ConnectionPool) {
            return [
                'enabled' => false,
                'message' => 'Connection pool not initialized',
            ];
        }

        return [
            'enabled' => true,
            'stats' => $pool->getStats(),
            'health' => $pool->healthCheck(),
        ];
    }

    /**
     * List objects that a user has a specific relation to.
     *
     * @param string               $user
     * @param string               $relation
     * @param string               $type
     * @param array<TupleKey>      $contextualTuples
     * @param array<string, mixed> $context
     * @param ?string              $connection
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException
     *
     * @return array<string>
     */
    #[Override]
    public function listObjects(
        string $user,
        string $relation,
        string $type,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        return $this->manager->listObjects($user, $relation, $type, $contextualTuples, $context, $connection);
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
     * Write tuples to OpenFGA.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $writes
     * @param array<int, array{user: string, relation: string, object: string}> $deletes
     * @param ?string                                                           $connection
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException|ReflectionException
     */
    public function write(array $writes = [], array $deletes = [], ?string $connection = null): bool
    {
        $poolEnabled = config('openfga.pool.enabled', false);

        if (! is_bool($poolEnabled) || ! $poolEnabled) {
            // Convert arrays to TupleKeysInterface
            $writeTuples = null;
            $deleteTuples = null;

            if ([] !== $writes) {
                $writeTuples = new TupleKeys;

                foreach ($writes as $write) {
                    $writeTuples->add(new TupleKey(
                        user: $write['user'],
                        relation: $write['relation'],
                        object: $write['object'],
                    ));
                }
            }

            if ([] !== $deletes) {
                $deleteTuples = new TupleKeys;

                foreach ($deletes as $delete) {
                    $deleteTuples->add(new TupleKey(
                        user: $delete['user'],
                        relation: $delete['relation'],
                        object: $delete['object'],
                    ));
                }
            }

            return $this->manager->write($writeTuples, $deleteTuples, $connection);
        }

        return $this->getPool()->execute(function (ClientInterface $client) use ($writes, $deletes): bool {
            $connectionName = $this->manager->getDefaultConnection();

            /** @var mixed $storeId */
            $storeId = config('openfga.connections.' . $connectionName . '.store_id');

            /** @var mixed $modelId */
            $modelId = config('openfga.connections.' . $connectionName . '.authorization_model_id');

            // Convert arrays to TupleKeys for the client
            $writeTuples = null;

            if ([] !== $writes) {
                $writeTuples = new TupleKeys;

                foreach ($writes as $write) {
                    $writeTuples->add(new TupleKey(
                        user: $write['user'],
                        relation: $write['relation'],
                        object: $write['object'],
                    ));
                }
            }

            $deleteTuples = null;

            if ([] !== $deletes) {
                $deleteTuples = new TupleKeys;

                foreach ($deletes as $delete) {
                    $deleteTuples->add(new TupleKey(
                        user: $delete['user'],
                        relation: $delete['relation'],
                        object: $delete['object'],
                    ));
                }
            }

            $result = $client->writeTuples(
                store: is_string($storeId) ? $storeId : '',
                model: is_string($modelId) ? $modelId : '',
                writes: $writeTuples,
                deletes: $deleteTuples,
            );

            /** @var mixed $throwExceptions */
            $throwExceptions = config('openfga.throw_exceptions', false);

            if (is_bool($throwExceptions) && $throwExceptions) {
                $result->unwrap();
            }

            return true;
        });
    }

    /**
     * Get or create the connection pool.
     */
    private function getPool(): ConnectionPool
    {
        if (! $this->pool instanceof ConnectionPool) {
            $connectionName = $this->manager->getDefaultConnection();

            /** @var mixed $config */
            $config = config('openfga.connections.' . $connectionName, []);

            if (! is_array($config)) {
                $config = [];
            }

            /** @var mixed $maxConn */
            $maxConn = config('openfga.pool.max_connections', 10);

            /** @var mixed $minConn */
            $minConn = config('openfga.pool.min_connections', 2);

            /** @var mixed $maxIdle */
            $maxIdle = config('openfga.pool.max_idle_time', 300);

            /** @var mixed $connTimeout */
            $connTimeout = config('openfga.pool.connection_timeout', 5);

            /** @var array{max_connections?: int, min_connections?: int, max_idle_time?: int, connection_timeout?: int, url?: string, store_id?: string|null, model_id?: string|null, credentials?: array<string, mixed>, retries?: array<string, mixed>, http_options?: array<string, mixed>} $poolConfig */
            $poolConfig = array_merge($config, [
                'max_connections' => is_int($maxConn) ? $maxConn : 10,
                'min_connections' => is_int($minConn) ? $minConn : 2,
                'max_idle_time' => is_int($maxIdle) ? $maxIdle : 300,
                'connection_timeout' => is_int($connTimeout) ? $connTimeout : 5,
            ]);

            $this->pool = new ConnectionPool($poolConfig);
        }

        return $this->pool;
    }
}
