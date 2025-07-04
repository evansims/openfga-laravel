<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Abstracts;

use Exception;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\{BindingResolutionException, Container};
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OpenFGA\Authentication\{AuthenticationInterface, ClientCredentialAuthentication, TokenAuthentication};
use OpenFGA\{Client, ClientInterface};
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Cache\{ReadThroughCache, TaggedCache};
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Exceptions\{ConnectionException, InvalidTupleException, ModelNotFoundException, OpenFgaException, StoreNotFoundException};
use OpenFGA\Laravel\Query\AuthorizationQuery;
use OpenFGA\Laravel\Traits\ManagerOperations;
use OpenFGA\Models\{BatchCheckItem, TupleKey, UserTypeFilter};
use OpenFGA\Models\Collections\{BatchCheckItems, TupleKeys, TupleKeysInterface, UserTypeFilters};
use OpenFGA\Results\{FailureInterface, SuccessInterface};
use Override;
use Psr\Http\Message\{RequestFactoryInterface, ResponseFactoryInterface, StreamFactoryInterface};
use ReflectionException;
use RuntimeException;
use Throwable;

use function count;
use function gettype;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Abstract base class for OpenFGA manager operations.
 *
 * This abstract class contains the core logic for OpenFGA operations,
 * allowing for easier testing and extension. It provides all the base
 * functionality while keeping the concrete implementation details
 * separate.
 *
 * @api
 */
abstract class AbstractOpenFgaManager implements ManagerInterface
{
    use ManagerOperations;

    /**
     * The active connection instances.
     *
     * @var array<string, ClientInterface>
     */
    protected array $connections = [];

    /**
     * Read-through cache instance.
     */
    protected ?ReadThroughCache $readThroughCache = null;

    /**
     * Tagged cache instance.
     */
    protected ?TaggedCache $taggedCache = null;

    /**
     * Whether to throw exceptions instead of returning Result pattern.
     */
    protected bool $throwExceptions = false;

    /**
     * Create a new OpenFGA manager instance.
     *
     * @param array{default?: string, connections?: array<string, array<string, mixed>>, cache?: array<string, mixed>, queue?: array<string, mixed>, logging?: array<string, mixed>} $config
     * @param Container                                                                                                                                                              $container
     */
    public function __construct(
        protected readonly Container $container,
        protected array $config,
    ) {
        $this->initializeCaching();
    }

    /**
     * Dynamically pass methods to the default connection.
     *
     * @param string       $method
     * @param array<mixed> $parameters
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        /** @var mixed */
        return $this->connection()->{$method}(...$parameters);
    }

    /**
     * Create a new query builder instance.
     *
     * @param string|null $connection Optional connection name
     */
    abstract public function query(?string $connection = null): AuthorizationQuery;

    /**
     * Batch check multiple permissions at once.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $checks
     * @param string|null                                                       $connection Optional connection name
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception                  If throwExceptions is true and an error occurs
     * @throws InvalidArgumentException
     *
     * @return array<string, bool> Keyed by "user:relation:object"
     */
    #[Override]
    public function batchCheck(array $checks, ?string $connection = null): array
    {
        $batchItems = [];
        $results = [];
        $keyMap = [];

        foreach ($checks as $index => $check) {
            $user = $this->resolveUserId($check['user']);
            $key = sprintf('%s:%s:%s', $user, $check['relation'], $check['object']);

            // Check cache first
            if ($this->cacheEnabled()) {
                if ($this->taggedCacheEnabled()) {
                    $cached = $this->getTaggedCache()->getPermission($user, $check['relation'], $check['object']);

                    if (null !== $cached) {
                        $results[$key] = $cached;

                        continue;
                    }
                } else {
                    $cacheKey = $this->getCacheKey('check', $user, $check['relation'], $check['object']);

                    /** @var mixed $cached */
                    $cached = $this->getCache()->get($cacheKey);

                    if (null !== $cached) {
                        $results[$key] = (bool) $cached;

                        continue;
                    }
                }
            }

            $correlationId = 'check-' . $index;
            $keyMap[$correlationId] = $key;

            $batchItems[] = new BatchCheckItem(
                tupleKey: new TupleKey(
                    user: $user,
                    relation: $check['relation'],
                    object: $check['object'],
                ),
                correlationId: $correlationId,
            );
        }

        if ([] !== $batchItems) {
            $connectionConfig = $this->configuration($connection ?? $this->getDefaultConnection());

            if (null === $connectionConfig) {
                throw new InvalidArgumentException('Connection configuration not found');
            }

            $storeId = $connectionConfig['store_id'] ?? null;
            $modelId = $connectionConfig['model_id'] ?? null;

            if (! is_string($storeId)) {
                throw new InvalidArgumentException('store_id not configured');
            }

            if (! is_string($modelId)) {
                throw ModelNotFoundException::noModelSpecified();
            }

            $result = $this->connection($connection)->batchCheck(
                store: $storeId,
                model: $modelId,
                checks: new BatchCheckItems($batchItems),
            );

            $batchResults = $this->handleResult($result, static function ($success): array {
                /** @var mixed $response */
                $response = $success->val();

                if (is_object($response) && method_exists($response, 'getResult')) {
                    /** @var array<mixed> */
                    $rawResult = $response->getResult();

                    // Filter to ensure we only have objects
                    return array_filter($rawResult, static fn ($item): bool => is_object($item));
                }

                return [];
            });

            if (! is_array($batchResults)) {
                $batchResults = [];
            }

            foreach ($batchResults as $batchResult) {
                if (method_exists($batchResult, 'getCorrelationId') && method_exists($batchResult, 'getAllowed')) {
                    /** @var string $correlationId */
                    $correlationId = $batchResult->getCorrelationId();

                    /** @var bool $allowed */
                    $allowed = $batchResult->getAllowed();

                    if (isset($keyMap[$correlationId])) {
                        $key = $keyMap[$correlationId];
                        $results[$key] = $allowed;

                        // Cache the result
                        if ($this->cacheEnabled()) {
                            $parts = explode(':', $key, 3);

                            if (3 === count($parts)) {
                                [$user, $relation, $object] = $parts;
                                $cacheKey = $this->getCacheKey('check', $user, $relation, $object);
                                $this->getCache()->put($cacheKey, $allowed, $this->getCacheTtl());
                            }
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check if a user has a specific permission.
     *
     * @param string                                                                $user             User identifier (supports @me for current user)
     * @param string                                                                $relation         The relation to check
     * @param string                                                                $object           The object to check against
     * @param array<array{user: string, relation: string, object: string}|TupleKey> $contextualTuples Optional contextual tuples
     * @param array<string, mixed>                                                  $context          Optional context
     * @param string|null                                                           $connection       Optional connection name
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws ConnectionException                       If connection configuration is invalid
     * @throws Exception                                 If general errors occur
     * @throws InvalidArgumentException                  If arguments are invalid
     * @throws InvalidTupleException                     If user, relation, or object is invalid
     * @throws ModelNotFoundException                    If authorization model is not found
     * @throws OpenFgaException                          If OpenFGA operation fails
     * @throws ReflectionException                       If reflection operations fail
     * @throws StoreNotFoundException                    If store is not configured
     */
    #[Override]
    public function check(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): bool {
        // Auto-resolve user from auth if needed
        $user = $this->resolveUserId($user);

        // Get connection configuration first to include store ID in cache key
        $connectionName = $connection ?? $this->getDefaultConnection();
        $connectionConfig = $this->configuration($connectionName);

        if (null === $connectionConfig) {
            throw new InvalidArgumentException('Connection configuration not found');
        }

        $storeId = $connectionConfig['store_id'] ?? null;

        if (! is_string($storeId)) {
            throw StoreNotFoundException::noStoreSpecified();
        }

        // Check cache first if enabled (skip cache if contextual tuples are provided)
        if ($this->cacheEnabled() && [] === $contextualTuples) {
            // Try tagged cache first if available
            if ($this->taggedCacheEnabled()) {
                $cached = $this->getTaggedCache()->getPermission($user, $relation, $object, $storeId);

                if (null !== $cached) {
                    $this->logCacheHit('check', sprintf('tagged:%s:%s:%s:%s', $storeId, $user, $relation, $object));

                    return $cached;
                }
            } else {
                // Fallback to regular cache
                $cacheKey = $this->getCacheKey('check', $storeId, $user, $relation, $object);

                /** @var mixed $cached */
                $cached = $this->getCache()->get($cacheKey);

                if (null !== $cached) {
                    $this->logCacheHit('check', $cacheKey);

                    return (bool) $cached;
                }
            }
        }

        // Already have connection config from cache check above

        // Build contextual tuples if provided
        $contextualTuplesCollection = null;

        if ([] !== $contextualTuples) {
            $tuples = [];

            foreach ($contextualTuples as $contextualTuple) {
                if ($contextualTuple instanceof TupleKey) {
                    $tuples[] = $contextualTuple;
                } else {
                    // Support legacy array format
                    $tuples[] = new TupleKey(
                        user: $this->resolveUserId($contextualTuple['user']),
                        relation: $contextualTuple['relation'],
                        object: $contextualTuple['object'],
                    );
                }
            }
            $contextualTuplesCollection = new TupleKeys($tuples);
        }

        // Create the tuple to check
        $tupleKey = new TupleKey(
            user: $user,
            relation: $relation,
            object: $object,
        );

        // Get model ID
        $modelId = $connectionConfig['model_id'] ?? null;

        if (! is_string($modelId)) {
            throw ModelNotFoundException::noModelSpecified();
        }

        // Convert context array to object if needed
        $contextObject = null;

        if ([] !== $context) {
            $contextObject = (object) $context;
        }

        // Perform check
        $result = $this->connection($connection)->check(
            store: $storeId,
            model: $modelId,
            tuple: $tupleKey,
            trace: null,
            context: $contextObject,
            contextualTuples: $contextualTuplesCollection,
        );

        // Handle result
        /** @var bool|null $allowed */
        $allowed = $this->handleResult($result, static function ($success) {
            // The $success parameter is the Success result object
            // We need to get the actual response value from it
            /** @var mixed $response */
            $response = $success->val();

            if (is_object($response) && method_exists($response, 'getAllowed')) {
                return $response->getAllowed();
            }

            return null;
        });

        // Cache the result if enabled (skip cache if contextual tuples are provided)
        if ($this->cacheEnabled() && null !== $allowed && [] === $contextualTuples) {
            if ($this->taggedCacheEnabled()) {
                $this->getTaggedCache()->putPermission($user, $relation, $object, $allowed, $this->getCacheTtl(), $storeId);
            } else {
                $cacheKey = $this->getCacheKey('check', $storeId, $user, $relation, $object);
                $this->getCache()->put($cacheKey, $allowed, $this->getCacheTtl());
            }
        }

        return true === $allowed;
    }

    /**
     * Get an OpenFGA connection instance.
     *
     * @param string|null $name
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function connection(?string $name = null): ClientInterface
    {
        $name ??= $this->getDefaultConnection();

        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->makeConnection($name);
        }

        return $this->connections[$name];
    }

    /**
     * Disconnect from the given connection.
     *
     * @param ?string $name
     */
    public function disconnect(?string $name = null): void
    {
        $name ??= $this->getDefaultConnection();

        unset($this->connections[$name]);
    }

    /**
     * Disconnect from all connections.
     */
    public function disconnectAll(): void
    {
        $this->connections = [];
    }

    /**
     * Expand a relation to see all users who have it.
     *
     * @param string      $relation   The relation to expand
     * @param string      $object     The object identifier
     * @param string|null $connection Optional connection name
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception                  If throwExceptions is true and an error occurs
     * @throws InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public function expand(string $relation, string $object, ?string $connection = null): array
    {
        $connectionConfig = $this->configuration($connection ?? $this->getDefaultConnection());

        if (null === $connectionConfig) {
            throw new InvalidArgumentException('Connection configuration not found');
        }

        $storeId = $connectionConfig['store_id'] ?? null;
        $modelId = $connectionConfig['model_id'] ?? null;

        if (! is_string($storeId)) {
            throw StoreNotFoundException::noStoreSpecified();
        }

        if (! is_string($modelId)) {
            throw ModelNotFoundException::noModelSpecified();
        }

        // Create TupleKey for the SDK
        $tupleKey = new TupleKey(
            user: '',  // Empty user for expand operation
            relation: $relation,
            object: $object,
        );

        $result = $this->connection($connection)->expand(
            store: $storeId,
            model: $modelId,
            tuple: $tupleKey,
        );

        /** @var array<string, mixed> */
        return $this->handleResult($result, static function ($success): array {
            /** @var mixed $response */
            $response = $success->val();

            if (is_object($response) && method_exists($response, 'getTree')) {
                /** @var array<string, mixed> */
                $tree = $response->getTree();

                return ['tree' => $tree];
            }

            return [];
        });
    }

    /**
     * Get all of the created connections.
     *
     * @return array<string, ClientInterface>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string
    {
        return $this->config['default'] ?? 'main';
    }

    /**
     * Get the read-through cache instance.
     *
     * @throws RuntimeException
     */
    public function getReadThroughCache(): ReadThroughCache
    {
        $cacheConfig = $this->config['cache'] ?? [];
        $readThroughEnabled = true;

        if (isset($cacheConfig['read_through'])) {
            $readThroughEnabled = (bool) $cacheConfig['read_through'];
        }

        if (! $this->readThroughCache instanceof ReadThroughCache && $readThroughEnabled) {
            $this->readThroughCache = new ReadThroughCache($this, $cacheConfig);
        }

        if (! $this->readThroughCache instanceof ReadThroughCache) {
            throw new RuntimeException('Read-through cache is not enabled');
        }

        return $this->readThroughCache;
    }

    /**
     * Grant permission(s) to user(s).
     *
     * @param array<string>|string $users      User identifier(s)
     * @param string               $relation   The relation to grant
     * @param string               $object     The object to grant on
     * @param string|null          $connection Optional connection name
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception                  If throwExceptions is true and an error occurs
     * @throws InvalidArgumentException
     */
    #[Override]
    public function grant(
        string | array $users,
        string $relation,
        string $object,
        ?string $connection = null,
    ): bool {
        $users = is_array($users) ? $users : [$users];
        $tuples = [];

        foreach ($users as $user) {
            $tuples[] = new TupleKey(
                user: $this->resolveUserId($user),
                relation: $relation,
                object: $object,
            );
        }

        $writes = new TupleKeys($tuples);

        return $this->write($writes, null, $connection);
    }

    /**
     * Check the health of a connection.
     *
     * @param string|null $name
     */
    public function healthCheck(?string $name = null): bool
    {
        try {
            $connectionConfig = $this->configuration($name ?? $this->getDefaultConnection());
            $connection = $this->connection($name);

            if (null === $connectionConfig) {
                throw new InvalidArgumentException('Connection configuration not found');
            }

            $storeId = $connectionConfig['store_id'] ?? null;

            if (! is_string($storeId)) {
                throw new InvalidArgumentException('store_id not configured');
            }

            // Try to read a single tuple to check if the connection works
            $result = $connection->readTuples(
                store: $storeId,
                pageSize: 1,
            );

            // If we get a result (success or failure), the connection is working
            return $result instanceof SuccessInterface;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check the health of all configured connections.
     *
     * @return array<string, bool>
     */
    public function healthCheckAll(): array
    {
        $results = [];

        foreach (array_keys($this->config['connections'] ?? []) as $name) {
            $results[$name] = $this->healthCheck($name);
        }

        return $results;
    }

    /**
     * List all objects a user has a specific relation with.
     *
     * @param string                                                                $user             User identifier
     * @param string                                                                $relation         The relation to check
     * @param string                                                                $type             The object type
     * @param array<array{user: string, relation: string, object: string}|TupleKey> $contextualTuples Optional contextual tuples
     * @param array<string, mixed>                                                  $context          Optional context
     * @param string|null                                                           $connection       Optional connection name
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception                  If throwExceptions is true and an error occurs
     * @throws InvalidArgumentException
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
        $user = $this->resolveUserId($user);
        $connectionConfig = $this->configuration($connection ?? $this->getDefaultConnection());

        if (null === $connectionConfig) {
            throw new InvalidArgumentException('Connection configuration not found');
        }

        // Build contextual tuples if provided
        $contextualTuplesCollection = null;

        if ([] !== $contextualTuples) {
            $tuples = [];

            foreach ($contextualTuples as $contextualTuple) {
                if ($contextualTuple instanceof TupleKey) {
                    $tuples[] = $contextualTuple;
                } else {
                    // Support legacy array format
                    $tuples[] = new TupleKey(
                        user: $this->resolveUserId($contextualTuple['user']),
                        relation: $contextualTuple['relation'],
                        object: $contextualTuple['object'],
                    );
                }
            }
            $contextualTuplesCollection = new TupleKeys($tuples);
        }

        $storeId = $connectionConfig['store_id'] ?? null;
        $modelId = $connectionConfig['model_id'] ?? null;

        if (! is_string($storeId)) {
            throw StoreNotFoundException::noStoreSpecified();
        }

        if (! is_string($modelId)) {
            throw ModelNotFoundException::noModelSpecified();
        }

        // Convert context array to object if needed
        $contextObject = null;

        if ([] !== $context) {
            $contextObject = (object) $context;
        }

        $result = $this->connection($connection)->listObjects(
            store: $storeId,
            model: $modelId,
            type: $type,
            relation: $relation,
            user: $user,
            context: $contextObject,
            contextualTuples: $contextualTuplesCollection,
        );

        $objects = $this->handleResult($result, static function ($success) {
            /** @var mixed $response */
            $response = $success->val();

            if (is_object($response) && method_exists($response, 'getObjects')) {
                /** @var array<string> */
                return $response->getObjects();
            }

            return null;
        });

        return $objects ?? [];
    }

    /**
     * List all relations a user has with an object.
     *
     * @param string                                                                $user             User identifier
     * @param string                                                                $object           The object
     * @param array<string>                                                         $relations        Optional relation filters
     * @param array<array{user: string, relation: string, object: string}|TupleKey> $contextualTuples Optional contextual tuples
     * @param array<string, mixed>                                                  $context          Optional context
     * @param string|null                                                           $connection       Optional connection name
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception                  If throwExceptions is true and an error occurs
     * @throws InvalidArgumentException
     *
     * @return array<string, bool> Relations mapped to whether the user has them
     */
    #[Override]
    public function listRelations(
        string $user,
        string $object,
        array $relations = [],
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        $user = $this->resolveUserId($user);

        // If no specific relations provided, we need to check all possible relations
        // In a real implementation, you might want to get these from the authorization model
        if ([] === $relations) {
            throw new InvalidArgumentException('Relations array cannot be empty');
        }

        $results = [];

        // Check each relation
        foreach ($relations as $relation) {
            $results[$relation] = $this->check($user, $relation, $object, $contextualTuples, $context, $connection);
        }

        return $results;
    }

    /**
     * List all users who have a specific relation with an object.
     *
     * @param string                                                                $object           The object
     * @param string                                                                $relation         The relation to check
     * @param array<string>                                                         $userTypes        Optional user type filters
     * @param array<array{user: string, relation: string, object: string}|TupleKey> $contextualTuples Optional contextual tuples
     * @param array<string, mixed>                                                  $context          Optional context
     * @param string|null                                                           $connection       Optional connection name
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception                  If throwExceptions is true and an error occurs
     * @throws InvalidArgumentException
     *
     * @return array<mixed>
     */
    #[Override]
    public function listUsers(
        string $object,
        string $relation,
        array $userTypes = [],
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        $connectionConfig = $this->configuration($connection ?? $this->getDefaultConnection());

        // Build user type filters from the provided array
        $filters = [];

        foreach ($userTypes as $userType) {
            $filters[] = new UserTypeFilter($userType);
        }
        $userFilters = new UserTypeFilters($filters);

        // Build contextual tuples if provided
        $contextualTuplesCollection = null;

        if ([] !== $contextualTuples) {
            $tuples = [];

            foreach ($contextualTuples as $contextualTuple) {
                if ($contextualTuple instanceof TupleKey) {
                    $tuples[] = $contextualTuple;
                } else {
                    // Support legacy array format
                    $tuples[] = new TupleKey(
                        user: $this->resolveUserId($contextualTuple['user']),
                        relation: $contextualTuple['relation'],
                        object: $contextualTuple['object'],
                    );
                }
            }
            $contextualTuplesCollection = new TupleKeys($tuples);
        }

        $storeId = $connectionConfig['store_id'] ?? null;
        $modelId = $connectionConfig['model_id'] ?? null;

        if (! is_string($storeId)) {
            throw StoreNotFoundException::noStoreSpecified();
        }

        if (! is_string($modelId)) {
            throw ModelNotFoundException::noModelSpecified();
        }

        // Convert context array to object if needed
        $contextObject = null;

        if ([] !== $context) {
            $contextObject = (object) $context;
        }

        $result = $this->connection($connection)->listUsers(
            store: $storeId,
            model: $modelId,
            object: $object,
            relation: $relation,
            userFilters: $userFilters,
            context: $contextObject,
            contextualTuples: $contextualTuplesCollection,
        );

        $users = $this->handleResult($result, static function ($success) {
            /** @var mixed $response */
            $response = $success->val();

            if (is_object($response) && method_exists($response, 'getUsers')) {
                /** @var array<mixed> */
                return $response->getUsers();
            }

            return null;
        });

        return $users ?? [];
    }

    /**
     * Revoke permission(s) from user(s).
     *
     * @param array<string>|string $users      User identifier(s)
     * @param string               $relation   The relation to revoke
     * @param string               $object     The object to revoke from
     * @param string|null          $connection Optional connection name
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception                  If throwExceptions is true and an error occurs
     * @throws InvalidArgumentException
     */
    #[Override]
    public function revoke(
        string | array $users,
        string $relation,
        string $object,
        ?string $connection = null,
    ): bool {
        $users = is_array($users) ? $users : [$users];
        $tuples = [];

        foreach ($users as $user) {
            $tuples[] = new TupleKey(
                user: $this->resolveUserId($user),
                relation: $relation,
                object: $object,
            );
        }

        $deletes = new TupleKeys($tuples);

        return $this->write(null, $deletes, $connection);
    }

    /**
     * Set the connection to use for subsequent operations.
     *
     * This is an alias for setDefaultConnection for better developer experience.
     *
     * @param  string $name The connection name
     * @return $this  For method chaining
     */
    public function setConnection(string $name): self
    {
        $this->setDefaultConnection($name);

        return $this;
    }

    /**
     * Set the default connection name.
     *
     * @param string $name
     */
    public function setDefaultConnection(string $name): void
    {
        $this->config['default'] = $name;
    }

    /**
     * Set whether to throw exceptions instead of returning Result pattern.
     *
     * @param  bool  $throw
     * @return $this
     */
    public function throwExceptions(bool $throw = true): static
    {
        $this->throwExceptions = $throw;

        return $this;
    }

    /**
     * Update the configuration for testing purposes.
     *
     * @param array{default?: string, connections?: array<string, array<string, mixed>>, cache?: array<string, mixed>, queue?: array<string, mixed>, logging?: array<string, mixed>} $config
     *
     * @internal This method is for testing only
     */
    public function updateConfig(array $config): void
    {
        $this->config = $config;
        $this->disconnectAll();
    }

    /**
     * Write tuples (grant and/or revoke permissions).
     *
     * @param TupleKeysInterface|null $writes     Tuples to write
     * @param TupleKeysInterface|null $deletes    Tuples to delete
     * @param string|null             $connection Optional connection name
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception                  If throwExceptions is true and an error occurs
     * @throws InvalidArgumentException
     */
    #[Override]
    public function write(
        ?TupleKeysInterface $writes = null,
        ?TupleKeysInterface $deletes = null,
        ?string $connection = null,
    ): bool {
        // Invalidate cache for affected tuples
        if ($this->cacheEnabled()) {
            $this->invalidateCache($writes, $deletes);
        }

        $connectionConfig = $this->configuration($connection ?? $this->getDefaultConnection());

        if (null === $connectionConfig) {
            throw new InvalidArgumentException('Connection configuration not found');
        }

        $storeId = $connectionConfig['store_id'] ?? null;
        $modelId = $connectionConfig['model_id'] ?? null;

        if (! is_string($storeId)) {
            throw StoreNotFoundException::noStoreSpecified();
        }

        if (! is_string($modelId)) {
            throw ModelNotFoundException::noModelSpecified();
        }

        $result = $this->connection($connection)->writeTuples(
            store: $storeId,
            model: $modelId,
            writes: $writes,
            deletes: $deletes,
        );

        /** @var bool|null $success */
        $success = $this->handleResult($result, static fn (): true => true);

        return true === $success;
    }

    /**
     * Write batch operations for arrays of writes and deletes.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $writes
     * @param array<int, array{user: string, relation: string, object: string}> $deletes
     * @param string|null                                                       $connection
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function writeBatch(
        array $writes = [],
        array $deletes = [],
        ?string $connection = null,
    ): bool {
        // Convert arrays to TupleKeys
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

        return $this->write($writeTuples, $deleteTuples, $connection);
    }

    /**
     * Build credentials array from configuration.
     *
     * @param  array<string, mixed>      $config
     * @return array<string, mixed>|null
     */
    protected function buildCredentials(array $config): ?array
    {
        if (! isset($config['method']) || 'none' === $config['method']) {
            return null;
        }

        return match ($config['method']) {
            'api_token' => isset($config['token']) && '' !== $config['token'] ? [
                'api_token' => $config['token'],
            ] : null,
            'client_credentials' => [
                'method' => 'client_credentials',
                'client_id' => $config['client_id'] ?? null,
                'client_secret' => $config['client_secret'] ?? null,
                'api_token_issuer' => $config['api_token_issuer'] ?? null,
                'api_audience' => $config['api_audience'] ?? null,
                'scopes' => $config['scopes'] ?? [],
            ],
            default => null,
        };
    }

    /**
     * Check if caching is enabled.
     */
    protected function cacheEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = $this->config['cache']['enabled'] ?? false;

        return true === $enabled;
    }

    /**
     * Get the configuration for a connection.
     *
     * @param  string                    $name
     * @return array<string, mixed>|null
     */
    protected function configuration(string $name): ?array
    {
        $connections = $this->config['connections'] ?? null;

        if (null === $connections) {
            return null;
        }

        return $connections[$name] ?? null;
    }

    /**
     * Create authentication instance from credentials.
     *
     * @param array<string, mixed> $credentials
     */
    protected function createAuthentication(array $credentials): ?AuthenticationInterface
    {
        if (isset($credentials['api_token']) && is_string($credentials['api_token'])) {
            return new TokenAuthentication($credentials['api_token']);
        }

        if (isset($credentials['method']) && 'client_credentials' === $credentials['method']) {
            $clientId = isset($credentials['client_id']) && is_string($credentials['client_id']) ? $credentials['client_id'] : '';
            $clientSecret = isset($credentials['client_secret']) && is_string($credentials['client_secret']) ? $credentials['client_secret'] : '';
            $issuer = isset($credentials['api_token_issuer']) && is_string($credentials['api_token_issuer']) ? $credentials['api_token_issuer'] : '';
            $audience = isset($credentials['api_audience']) && is_string($credentials['api_audience']) ? $credentials['api_audience'] : '';

            return new ClientCredentialAuthentication(
                $clientId,
                $clientSecret,
                $issuer,
                $audience,
            );
        }

        return null;
    }

    /**
     * Create a new connection instance.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    protected function createConnection(array $config): ClientInterface
    {
        // Build authentication if configured
        $authentication = null;

        /** @var array<string, mixed> $credentialsConfig */
        $credentialsConfig = (isset($config['credentials']) && is_array($config['credentials'])) ? $config['credentials'] : [];
        $credentials = $this->buildCredentials($credentialsConfig);

        if (null !== $credentials) {
            $authentication = $this->createAuthentication($credentials);
        }

        // Get retry configuration
        /** @var positive-int $maxRetries */
        $maxRetries = 3;

        if (isset($config['retries']) && is_array($config['retries']) && isset($config['retries']['max_retries'])) {
            /** @var mixed $retryValue */
            $retryValue = $config['retries']['max_retries'];

            if (is_int($retryValue) && 0 < $retryValue) {
                $maxRetries = $retryValue;
            }
        }

        // Create client with detected PSR implementations
        $url = 'http://localhost:8080';

        if (isset($config['url']) && is_string($config['url'])) {
            $url = $config['url'];
        }

        return new Client(
            url: $url,
            authentication: $authentication,
            httpMaxRetries: $maxRetries,
            httpClient: $this->detectHttpClient(),
            httpResponseFactory: $this->detectResponseFactory(),
            httpStreamFactory: $this->detectStreamFactory(),
            httpRequestFactory: $this->detectRequestFactory(),
        );

        // Store and model IDs are passed per-request in the new SDK
    }

    /**
     * Detect available PSR-18 HTTP client.
     */
    protected function detectHttpClient(): ?\Psr\Http\Client\ClientInterface
    {
        // Priority order of HTTP clients to check
        $clients = [
            '\Http\Discovery\Psr18ClientDiscovery',
            '\GuzzleHttp\Client',
            '\Symfony\Component\HttpClient\Psr18Client',
        ];

        foreach ($clients as $client) {
            if (class_exists($client)) {
                try {
                    // Use discovery for discovery classes
                    if (str_contains($client, 'Discovery')) {
                        if (method_exists($client, 'find')) {
                            /** @var callable(): \Psr\Http\Client\ClientInterface $callable */
                            $callable = [$client, 'find'];

                            return $callable();
                        }
                    } else {
                        // Direct instantiation for concrete classes
                        /** @var class-string<\Psr\Http\Client\ClientInterface> $client */
                        return new $client;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Detect available PSR-17 request factory.
     */
    protected function detectRequestFactory(): ?RequestFactoryInterface
    {
        $factories = [
            '\Http\Discovery\Psr17FactoryDiscovery',
            '\Nyholm\Psr7\Factory\Psr17Factory',
            '\GuzzleHttp\Psr7\HttpFactory',
            '\Laminas\Diactoros\RequestFactory',
        ];

        foreach ($factories as $factory) {
            if (class_exists($factory)) {
                try {
                    // Use discovery for discovery classes
                    if (str_contains($factory, 'Discovery')) {
                        if (method_exists($factory, 'findRequestFactory')) {
                            /** @var callable(): RequestFactoryInterface $callable */
                            $callable = [$factory, 'findRequestFactory'];

                            return $callable();
                        }
                    } else {
                        // Direct instantiation for concrete classes
                        /** @var class-string<RequestFactoryInterface> $factory */
                        return new $factory;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Detect available PSR-17 response factory.
     */
    protected function detectResponseFactory(): ?ResponseFactoryInterface
    {
        $factories = [
            '\Http\Discovery\Psr17FactoryDiscovery',
            '\Nyholm\Psr7\Factory\Psr17Factory',
            '\GuzzleHttp\Psr7\HttpFactory',
            '\Laminas\Diactoros\ResponseFactory',
        ];

        foreach ($factories as $factory) {
            if (class_exists($factory)) {
                try {
                    // Use discovery for discovery classes
                    if (str_contains($factory, 'Discovery')) {
                        if (method_exists($factory, 'findResponseFactory')) {
                            /** @var callable(): ResponseFactoryInterface $callable */
                            $callable = [$factory, 'findResponseFactory'];

                            return $callable();
                        }
                    } else {
                        // Direct instantiation for concrete classes
                        /** @var class-string<ResponseFactoryInterface> $factory */
                        return new $factory;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Detect available PSR-17 stream factory.
     */
    protected function detectStreamFactory(): ?StreamFactoryInterface
    {
        $factories = [
            '\Http\Discovery\Psr17FactoryDiscovery',
            '\Nyholm\Psr7\Factory\Psr17Factory',
            '\GuzzleHttp\Psr7\HttpFactory',
            '\Laminas\Diactoros\StreamFactory',
        ];

        foreach ($factories as $factory) {
            if (class_exists($factory)) {
                try {
                    // Use discovery for discovery classes
                    if (str_contains($factory, 'Discovery')) {
                        if (method_exists($factory, 'findStreamFactory')) {
                            /** @var callable(): StreamFactoryInterface $callable */
                            $callable = [$factory, 'findStreamFactory'];

                            return $callable();
                        }
                    } else {
                        // Direct instantiation for concrete classes
                        /** @var class-string<StreamFactoryInterface> $factory */
                        return new $factory;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Get the cache repository.
     *
     * @throws BindingResolutionException
     */
    protected function getCache(): CacheRepository
    {
        /** @var mixed $store */
        $store = $this->config['cache']['store'] ?? null;

        if (! is_string($store) && null !== $store) {
            $store = null;
        }

        /** @var CacheManager $cacheManager */
        $cacheManager = $this->container->make('cache');

        return $cacheManager->store($store);
    }

    /**
     * Generate cache key.
     *
     * @param string $operation
     * @param string ...$parts
     */
    protected function getCacheKey(string $operation, string ...$parts): string
    {
        /** @var mixed $prefix */
        $prefix = $this->config['cache']['prefix'] ?? 'openfga';

        if (! is_string($prefix)) {
            $prefix = 'openfga';
        }

        return $prefix . ':' . $operation . ':' . md5(implode(':', $parts));
    }

    /**
     * Get cache TTL in seconds.
     */
    protected function getCacheTtl(): int
    {
        /** @var mixed $ttl */
        $ttl = $this->config['cache']['ttl'] ?? 300;

        if (is_int($ttl)) {
            return $ttl;
        }

        return 300;
    }

    /**
     * Get the tagged cache instance.
     */
    protected function getTaggedCache(): TaggedCache
    {
        if (! $this->taggedCache instanceof TaggedCache) {
            $this->taggedCache = new TaggedCache($this->config['cache'] ?? []);
        }

        return $this->taggedCache;
    }

    /**
     * Handle result from SDK, converting between Result pattern and exceptions.
     *
     * @template T
     *
     * @param FailureInterface|SuccessInterface $result
     * @param callable(SuccessInterface): T     $successHandler
     *
     * @throws ClientThrowable
     * @throws Exception
     *
     * @return T|null
     */
    protected function handleResult(FailureInterface | SuccessInterface $result, callable $successHandler)
    {
        if ($result instanceof FailureInterface) {
            if ($this->throwExceptions) {
                // Get the error from the failure using err() method
                $error = $result->err();

                // The error from err() is always a Throwable
                throw new OpenFgaException('OpenFGA operation failed: ' . $error->getMessage(), 0, $error);
            }

            $this->logError($result);

            return null;
        }

        return $successHandler($result);
    }

    /**
     * Initialize caching components.
     */
    protected function initializeCaching(): void
    {
        $cacheConfig = $this->config['cache'] ?? [];

        // Initialize read-through cache if enabled
        $readThroughEnabled = true;

        if (isset($cacheConfig['read_through'])) {
            $readThroughEnabled = (bool) $cacheConfig['read_through'];
        }

        if ($readThroughEnabled) {
            $this->readThroughCache = new ReadThroughCache($this, $cacheConfig);
        }

        // Initialize tagged cache if tags are enabled
        $tagsEnabled = true;

        if (isset($cacheConfig['tags']) && is_array($cacheConfig['tags']) && isset($cacheConfig['tags']['enabled'])) {
            $tagsEnabled = (bool) $cacheConfig['tags']['enabled'];
        }

        if ($tagsEnabled) {
            $this->taggedCache = new TaggedCache($cacheConfig);
        }
    }

    /**
     * Invalidate cache entries for the given tuples.
     *
     * @param ?TupleKeysInterface $writes
     * @param ?TupleKeysInterface $deletes
     *
     * @throws BindingResolutionException
     */
    protected function invalidateCache(?TupleKeysInterface $writes, ?TupleKeysInterface $deletes): void
    {
        if (! $this->cacheEnabled()) {
            return;
        }

        $tuplesToInvalidate = [];

        if ($writes instanceof TupleKeysInterface) {
            foreach ($writes as $write) {
                if ($write instanceof TupleKey) {
                    $tuplesToInvalidate[] = $write;
                }
            }
        }

        if ($deletes instanceof TupleKeysInterface) {
            foreach ($deletes as $delete) {
                if ($delete instanceof TupleKey) {
                    $tuplesToInvalidate[] = $delete;
                }
            }
        }

        if ($this->taggedCacheEnabled()) {
            $taggedCache = $this->getTaggedCache();

            foreach ($tuplesToInvalidate as $tupleToInvalidate) {
                // Invalidate by user and object to clear all relations
                $taggedCache->invalidateUser($tupleToInvalidate->getUser());
                $taggedCache->invalidateObject($tupleToInvalidate->getObject());
            }
        } else {
            // Invalidate regular cache entries
            $cache = $this->getCache();

            foreach ($tuplesToInvalidate as $tupleToInvalidate) {
                $cacheKey = $this->getCacheKey('check', $tupleToInvalidate->getUser(), $tupleToInvalidate->getRelation(), $tupleToInvalidate->getObject());
                $cache->forget($cacheKey);
            }
        }
    }

    /**
     * Log cache hit.
     *
     * @param string $operation
     * @param string $key
     */
    protected function logCacheHit(string $operation, string $key): void
    {
        /** @var bool $loggingEnabled */
        $loggingEnabled = $this->config['logging']['enabled'] ?? false;

        if (true === $loggingEnabled) {
            /** @var string $channel */
            $channel = $this->config['logging']['channel'] ?? 'default';

            Log::channel($channel)
                ->debug('OpenFGA cache hit for ' . $operation, ['key' => $key]);
        }
    }

    /**
     * Log error from failed result.
     *
     * @param FailureInterface $result
     *
     * @throws ClientThrowable
     */
    protected function logError(FailureInterface $result): void
    {
        /** @var bool $loggingEnabled */
        $loggingEnabled = $this->config['logging']['enabled'] ?? false;

        if (true === $loggingEnabled) {
            /** @var mixed $error */
            $error = $result->val();

            /** @var string $channel */
            $channel = $this->config['logging']['channel'] ?? 'default';

            Log::channel($channel)
                ->error('OpenFGA operation failed', [
                    'error' => $error instanceof Throwable ? $error->getMessage() : 'Unknown error',
                    'trace' => $error instanceof Throwable ? $error->getTraceAsString() : null,
                ]);
        }
    }

    /**
     * Make the OpenFGA connection instance.
     *
     * @param string $name
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    protected function makeConnection(string $name): ClientInterface
    {
        $config = $this->configuration($name);

        if (null === $config) {
            throw ConnectionException::invalidConfiguration(sprintf('OpenFGA connection [%s] not configured.', $name));
        }

        return $this->createConnection($config);
    }

    /**
     * Resolve user ID from various formats.
     *
     * @param string $user
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws InvalidTupleException
     */
    protected function resolveUserId(string $user): string
    {
        // If user is @me, resolve from auth
        if ('@me' === $user) {
            $auth = $this->container->make(AuthFactory::class);
            $authUser = $auth->guard()->user();

            if (null === $authUser) {
                throw InvalidTupleException::missingUser();
            }

            $identifier = $authUser->getAuthIdentifier();

            if (! is_string($identifier) && ! is_int($identifier)) {
                $identifierType = gettype($identifier);

                throw InvalidTupleException::invalidFormat('user', 'type: ' . $identifierType);
            }

            return 'user:' . $identifier;
        }

        // If user doesn't have a prefix, add user: prefix
        if (! str_contains($user, ':')) {
            return 'user:' . $user;
        }

        return $user;
    }

    /**
     * Check if tagged cache is enabled.
     */
    protected function taggedCacheEnabled(): bool
    {
        if (! $this->cacheEnabled()) {
            return false;
        }

        $cacheConfig = $this->config['cache'] ?? [];

        /** @var array<string, mixed> $tagsConfig */
        $tagsConfig = $cacheConfig['tags'] ?? [];

        /** @var mixed $enabled */
        $enabled = $tagsConfig['enabled'] ?? false;

        return true === $enabled;
    }
}
