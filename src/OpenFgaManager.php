<?php

declare(strict_types=1);

namespace OpenFGA\Laravel;

use Exception;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OpenFGA\Authentication\{AuthenticationInterface, ClientCredentialAuthentication, TokenAuthentication};
use OpenFGA\{Client, ClientInterface};
use OpenFGA\Models\{BatchCheckItem, TupleKey, UserTypeFilter};
use OpenFGA\Models\Collections\{BatchCheckItems, TupleKeys, TupleKeysInterface, UserTypeFilters};
use OpenFGA\Results\{FailureInterface, SuccessInterface};
use Psr\Http\Message\{RequestFactoryInterface, ResponseFactoryInterface, StreamFactoryInterface};
use RuntimeException;
use Throwable;

use function count;
use function is_array;
use function is_int;
use function is_object;
use function is_string;

/**
 * Manages multiple OpenFGA connections and provides a fluent API
 * for interacting with OpenFGA services.
 */
class OpenFgaManager
{
    /**
     * The active connection instances.
     *
     * @var array<string, ClientInterface>
     */
    private array $connections = [];

    /**
     * Whether to throw exceptions instead of returning Result pattern.
     */
    private bool $throwExceptions = false;

    /**
     * Create a new OpenFGA manager instance.
     *
     * @param array{default?: string, connections?: array<string, array<string, mixed>>, cache?: array<string, mixed>, queue?: array<string, mixed>, logging?: array<string, mixed>} $config
     * @param Container                                                                                                                                                              $container
     */
    public function __construct(
        protected Container $container,
        protected array $config,
    ) {
    }

    /**
     * Dynamically pass methods to the default connection.
     *
     * @param string       $method
     * @param array<mixed> $parameters
     *
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
     * Batch check multiple permissions at once.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $checks
     * @param string|null                                                       $connection Optional connection name
     *
     * @throws Exception If throwExceptions is true and an error occurs
     *
     * @return array<string, bool> Keyed by "user:relation:object"
     */
    public function batchCheck(array $checks, ?string $connection = null): array
    {
        $batchItems = [];
        $results = [];
        $keyMap = [];

        foreach ($checks as $index => $check) {
            $user = $this->resolveUserId($check['user']);
            $key = "{$user}:{$check['relation']}:{$check['object']}";

            // Check cache first
            if ($this->cacheEnabled()) {
                $cacheKey = $this->getCacheKey('check', $user, $check['relation'], $check['object']);
                $cached = $this->getCache()->get($cacheKey);

                if (null !== $cached) {
                    $results[$key] = (bool) $cached;

                    continue;
                }
            }

            $correlationId = "check-{$index}";
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

        if (0 < count($batchItems)) {
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
                throw new InvalidArgumentException('model_id not configured');
            }

            $result = $this->connection($connection)->batchCheck(
                store: $storeId,
                model: $modelId,
                checks: new BatchCheckItems($batchItems),
            );

            /** @var array<mixed> $batchResults */
            $batchResults = $this->handleResult($result, function ($success) {
                if (method_exists($success, 'getResults')) {
                    /** @var array<mixed> */
                    return $success->getResults();
                }

                return null;
            });

            if (! is_array($batchResults)) {
                $batchResults = [];
            }

            foreach ($batchResults as $response) {
                if (is_object($response) && method_exists($response, 'getCorrelationId') && method_exists($response, 'getAllowed')) {
                    /** @var string $correlationId */
                    $correlationId = $response->getCorrelationId();

                    /** @var bool $allowed */
                    $allowed = $response->getAllowed();

                    if (isset($keyMap[$correlationId])) {
                        $key = $keyMap[$correlationId];
                        $results[$key] = $allowed;
                    }

                    // Cache the result
                    if ($this->cacheEnabled() && isset($key)) {
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

        return $results;
    }

    /**
     * Check if a user has a specific permission.
     *
     * @param string                                                            $user             User identifier (supports @me for current user)
     * @param string                                                            $relation         The relation to check
     * @param string                                                            $object           The object to check against
     * @param array<int, array{user: string, relation: string, object: string}> $contextualTuples Optional contextual tuples
     * @param object|null                                                       $context          Optional context
     * @param string|null                                                       $connection       Optional connection name
     *
     * @throws Exception If throwExceptions is true and an error occurs
     *
     * @return bool
     */
    public function check(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        ?object $context = null,
        ?string $connection = null,
    ): bool {
        // Auto-resolve user from auth if needed
        $user = $this->resolveUserId($user);

        // Check cache first if enabled
        if ($this->cacheEnabled()) {
            $cacheKey = $this->getCacheKey('check', $user, $relation, $object);
            $cached = $this->getCache()->get($cacheKey);

            if (null !== $cached) {
                $this->logCacheHit('check', $cacheKey);

                return (bool) $cached;
            }
        }

        // Get connection configuration for store/model IDs
        $connectionConfig = $this->configuration($connection ?? $this->getDefaultConnection());

        if (null === $connectionConfig) {
            throw new InvalidArgumentException('Connection configuration not found');
        }

        // Build contextual tuples if provided
        $contextualTuplesCollection = null;

        if (0 < count($contextualTuples)) {
            $tuples = [];

            foreach ($contextualTuples as $tuple) {
                $tuples[] = new TupleKey(
                    user: $this->resolveUserId($tuple['user']),
                    relation: $tuple['relation'],
                    object: $tuple['object'],
                );
            }
            $contextualTuplesCollection = new TupleKeys($tuples);
        }

        // Create the tuple to check
        $tupleKey = new TupleKey(
            user: $user,
            relation: $relation,
            object: $object,
        );

        // Get store and model IDs
        $storeId = $connectionConfig['store_id'] ?? null;
        $modelId = $connectionConfig['model_id'] ?? null;

        if (! is_string($storeId)) {
            throw new InvalidArgumentException('store_id not configured');
        }

        if (! is_string($modelId)) {
            throw new InvalidArgumentException('model_id not configured');
        }

        // Perform check
        $result = $this->connection($connection)->check(
            store: $storeId,
            model: $modelId,
            tuple: $tupleKey,
            context: $context,
            contextualTuples: $contextualTuplesCollection,
        );

        // Handle result
        /** @var bool|null $allowed */
        $allowed = $this->handleResult($result, function ($success) {
            if (method_exists($success, 'getAllowed')) {
                return $success->getAllowed();
            }

            return null;
        });

        // Cache the result if enabled
        if ($this->cacheEnabled() && null !== $allowed) {
            $this->getCache()->put($cacheKey, $allowed, $this->getCacheTtl());
        }

        return true === $allowed;
    }

    /**
     * Get an OpenFGA connection instance.
     *
     * @param string|null $name
     *
     * @throws InvalidArgumentException
     *
     * @return ClientInterface
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
     * Grant permission(s) to user(s).
     *
     * @param array<string>|string $users      User identifier(s)
     * @param string               $relation   The relation to grant
     * @param string               $object     The object to grant on
     * @param string|null          $connection Optional connection name
     *
     * @throws Exception If throwExceptions is true and an error occurs
     *
     * @return bool
     */
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
     * @param  string|null $name
     * @return bool
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
     * @param string                                                            $user             User identifier
     * @param string                                                            $relation         The relation to check
     * @param string                                                            $type             The object type
     * @param array<int, array{user: string, relation: string, object: string}> $contextualTuples Optional contextual tuples
     * @param object|null                                                       $context          Optional context
     * @param string|null                                                       $connection       Optional connection name
     *
     * @throws Exception If throwExceptions is true and an error occurs
     *
     * @return array<string>
     */
    public function listObjects(
        string $user,
        string $relation,
        string $type,
        array $contextualTuples = [],
        ?object $context = null,
        ?string $connection = null,
    ): array {
        $user = $this->resolveUserId($user);
        $connectionConfig = $this->configuration($connection ?? $this->getDefaultConnection());

        if (null === $connectionConfig) {
            throw new InvalidArgumentException('Connection configuration not found');
        }

        // Build contextual tuples if provided
        $contextualTuplesCollection = null;

        if (0 < count($contextualTuples)) {
            $tuples = [];

            foreach ($contextualTuples as $tuple) {
                $tuples[] = new TupleKey(
                    user: $this->resolveUserId($tuple['user']),
                    relation: $tuple['relation'],
                    object: $tuple['object'],
                );
            }
            $contextualTuplesCollection = new TupleKeys($tuples);
        }

        $storeId = $connectionConfig['store_id'] ?? null;
        $modelId = $connectionConfig['model_id'] ?? null;

        if (! is_string($storeId)) {
            throw new InvalidArgumentException('store_id not configured');
        }

        if (! is_string($modelId)) {
            throw new InvalidArgumentException('model_id not configured');
        }

        $result = $this->connection($connection)->listObjects(
            store: $storeId,
            model: $modelId,
            type: $type,
            relation: $relation,
            user: $user,
            context: $context,
            contextualTuples: $contextualTuplesCollection,
        );

        /** @var array<string>|null $objects */
        $objects = $this->handleResult($result, function ($success) {
            if (method_exists($success, 'getObjects')) {
                /** @var array<string> */
                return $success->getObjects();
            }

            return null;
        });

        return $objects ?? [];
    }

    /**
     * List all users who have a specific relation with an object.
     *
     * @param string                                                            $object           The object
     * @param string                                                            $relation         The relation to check
     * @param array<string>                                                     $userTypes        Optional user type filters
     * @param array<int, array{user: string, relation: string, object: string}> $contextualTuples Optional contextual tuples
     * @param object|null                                                       $context          Optional context
     * @param string|null                                                       $connection       Optional connection name
     *
     * @throws Exception If throwExceptions is true and an error occurs
     *
     * @return array<mixed>
     */
    public function listUsers(
        string $object,
        string $relation,
        array $userTypes = [],
        array $contextualTuples = [],
        ?object $context = null,
        ?string $connection = null,
    ): array {
        $connectionConfig = $this->configuration($connection ?? $this->getDefaultConnection());

        // Build user type filters from the provided array
        $filters = [];

        if (0 < count($userTypes)) {
            foreach ($userTypes as $type) {
                if (is_string($type)) {
                    $filters[] = new UserTypeFilter($type);
                }
            }
        }
        $userFilters = new UserTypeFilters($filters);

        // Build contextual tuples if provided
        $contextualTuplesCollection = null;

        if (0 < count($contextualTuples)) {
            $tuples = [];

            foreach ($contextualTuples as $tuple) {
                $tuples[] = new TupleKey(
                    user: $this->resolveUserId($tuple['user']),
                    relation: $tuple['relation'],
                    object: $tuple['object'],
                );
            }
            $contextualTuplesCollection = new TupleKeys($tuples);
        }

        $storeId = $connectionConfig['store_id'] ?? null;
        $modelId = $connectionConfig['model_id'] ?? null;

        if (! is_string($storeId)) {
            throw new InvalidArgumentException('store_id not configured');
        }

        if (! is_string($modelId)) {
            throw new InvalidArgumentException('model_id not configured');
        }

        $result = $this->connection($connection)->listUsers(
            store: $storeId,
            model: $modelId,
            object: $object,
            relation: $relation,
            userFilters: $userFilters,
            context: $context,
            contextualTuples: $contextualTuplesCollection,
        );

        /** @var array<mixed>|null $users */
        $users = $this->handleResult($result, function ($success) {
            if (method_exists($success, 'getUsers')) {
                /** @var array<mixed> */
                return $success->getUsers();
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
     * @throws Exception If throwExceptions is true and an error occurs
     *
     * @return bool
     */
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
     * Write tuples (grant and/or revoke permissions).
     *
     * @param TupleKeysInterface|null $writes     Tuples to write
     * @param TupleKeysInterface|null $deletes    Tuples to delete
     * @param string|null             $connection Optional connection name
     *
     * @throws Exception If throwExceptions is true and an error occurs
     *
     * @return bool
     */
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
            throw new InvalidArgumentException('store_id not configured');
        }

        if (! is_string($modelId)) {
            throw new InvalidArgumentException('model_id not configured');
        }

        $result = $this->connection($connection)->writeTuples(
            store: $storeId,
            model: $modelId,
            writes: $writes,
            deletes: $deletes,
        );

        /** @var bool|null $success */
        $success = $this->handleResult($result, fn () => true);

        return true === $success;
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
    private function cacheEnabled(): bool
    {
        $enabled = $this->config['cache']['enabled'] ?? false;

        return true === $enabled;
    }

    /**
     * Get the configuration for a connection.
     *
     * @param  string                    $name
     * @return array<string, mixed>|null
     */
    private function configuration(string $name): ?array
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
     * @param  array<string, mixed>         $credentials
     * @return AuthenticationInterface|null
     */
    private function createAuthentication(array $credentials): ?AuthenticationInterface
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
     * @param  array<string, mixed> $config
     * @return ClientInterface
     */
    private function createConnection(array $config): ClientInterface
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
     *
     * @return \Psr\Http\Client\ClientInterface|null
     */
    protected function detectHttpClient(): ?\Psr\Http\Client\ClientInterface
    {
        // Priority order of HTTP clients to check
        $clients = [
            '\Http\Discovery\Psr18ClientDiscovery',
            '\GuzzleHttp\Client',
            '\Symfony\Component\HttpClient\Psr18Client',
        ];

        foreach ($clients as $class) {
            if (class_exists($class)) {
                try {
                    // Use discovery for discovery classes
                    if (str_contains($class, 'Discovery')) {
                        if (method_exists($class, 'find')) {
                            /** @var \Psr\Http\Client\ClientInterface */
                            return $class::find();
                        }
                    } else {
                        // Direct instantiation for concrete classes
                        /** @var class-string<\Psr\Http\Client\ClientInterface> $class */
                        return new $class;
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
     *
     * @return RequestFactoryInterface|null
     */
    private function detectRequestFactory(): ?RequestFactoryInterface
    {
        $factories = [
            '\Http\Discovery\Psr17FactoryDiscovery',
            '\Nyholm\Psr7\Factory\Psr17Factory',
            '\GuzzleHttp\Psr7\HttpFactory',
            '\Laminas\Diactoros\RequestFactory',
        ];

        foreach ($factories as $class) {
            if (class_exists($class)) {
                try {
                    // Use discovery for discovery classes
                    if (str_contains($class, 'Discovery')) {
                        if (method_exists($class, 'findRequestFactory')) {
                            /** @var RequestFactoryInterface */
                            return $class::findRequestFactory();
                        }
                    } else {
                        // Direct instantiation for concrete classes
                        /** @var class-string<RequestFactoryInterface> $class */
                        return new $class;
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
     *
     * @return ResponseFactoryInterface|null
     */
    private function detectResponseFactory(): ?ResponseFactoryInterface
    {
        $factories = [
            '\Http\Discovery\Psr17FactoryDiscovery',
            '\Nyholm\Psr7\Factory\Psr17Factory',
            '\GuzzleHttp\Psr7\HttpFactory',
            '\Laminas\Diactoros\ResponseFactory',
        ];

        foreach ($factories as $class) {
            if (class_exists($class)) {
                try {
                    // Use discovery for discovery classes
                    if (str_contains($class, 'Discovery')) {
                        if (method_exists($class, 'findResponseFactory')) {
                            /** @var ResponseFactoryInterface */
                            return $class::findResponseFactory();
                        }
                    } else {
                        // Direct instantiation for concrete classes
                        /** @var class-string<ResponseFactoryInterface> $class */
                        return new $class;
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
     *
     * @return StreamFactoryInterface|null
     */
    private function detectStreamFactory(): ?StreamFactoryInterface
    {
        $factories = [
            '\Http\Discovery\Psr17FactoryDiscovery',
            '\Nyholm\Psr7\Factory\Psr17Factory',
            '\GuzzleHttp\Psr7\HttpFactory',
            '\Laminas\Diactoros\StreamFactory',
        ];

        foreach ($factories as $class) {
            if (class_exists($class)) {
                try {
                    // Use discovery for discovery classes
                    if (str_contains($class, 'Discovery')) {
                        if (method_exists($class, 'findStreamFactory')) {
                            /** @var StreamFactoryInterface */
                            return $class::findStreamFactory();
                        }
                    } else {
                        // Direct instantiation for concrete classes
                        /** @var class-string<StreamFactoryInterface> $class */
                        return new $class;
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
     */
    private function getCache(): CacheRepository
    {
        $store = $this->config['cache']['store'] ?? null;

        if (! is_string($store) && null !== $store) {
            $store = null;
        }

        /** @var CacheManager $cacheManager */
        $cacheManager = $this->container->make('cache');

        /** @var CacheRepository $repository */
        return $cacheManager->store($store);
    }

    /**
     * Generate cache key.
     *
     * @param string   $operation
     * @param string[] $parts
     */
    private function getCacheKey(string $operation, string ...$parts): string
    {
        $prefix = $this->config['cache']['prefix'] ?? 'openfga';

        if (! is_string($prefix)) {
            $prefix = 'openfga';
        }

        return $prefix . ':' . $operation . ':' . md5(implode(':', $parts));
    }

    /**
     * Get cache TTL in seconds.
     */
    private function getCacheTtl(): int
    {
        $ttl = $this->config['cache']['ttl'] ?? 300;

        if (is_int($ttl)) {
            return $ttl;
        }

        return 300;
    }

    /**
     * Handle result from SDK, converting between Result pattern and exceptions.
     *
     * @template T
     *
     * @param FailureInterface|SuccessInterface $result
     * @param callable(SuccessInterface): T     $successHandler
     *
     * @throws Exception
     *
     * @return T|null
     */
    private function handleResult($result, callable $successHandler)
    {
        if ($result instanceof FailureInterface) {
            if ($this->throwExceptions) {
                $error = $result->val();

                // All errors from the Result pattern should be Throwable
                if ($error instanceof Throwable) {
                    throw new RuntimeException('OpenFGA operation failed: ' . $error->getMessage(), 0, $error);
                }

                throw new RuntimeException('OpenFGA operation failed: Unknown error');
            }

            $this->logError($result);

            return null;
        }

        return $successHandler($result);
    }

    /**
     * Invalidate cache for affected tuples.
     *
     * @param TupleKeysInterface|null $writes
     * @param TupleKeysInterface|null $deletes
     */
    private function invalidateCache(?TupleKeysInterface $writes, ?TupleKeysInterface $deletes): void
    {
        $cache = $this->getCache();

        if (null !== $writes) {
            foreach ($writes as $tuple) {
                $cacheKey = $this->getCacheKey('check', $tuple->getUser(), $tuple->getRelation(), $tuple->getObject());
                $cache->forget($cacheKey);
            }
        }

        if (null !== $deletes) {
            foreach ($deletes as $tuple) {
                $cacheKey = $this->getCacheKey('check', $tuple->getUser(), $tuple->getRelation(), $tuple->getObject());
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
    private function logCacheHit(string $operation, string $key): void
    {
        $loggingEnabled = $this->config['logging']['enabled'] ?? false;

        if (true === $loggingEnabled) {
            $channel = $this->config['logging']['channel'] ?? 'default';

            if (is_string($channel)) {
                Log::channel($channel)
                    ->debug("OpenFGA cache hit for {$operation}", ['key' => $key]);
            } else {
                Log::channel('default')
                    ->debug("OpenFGA cache hit for {$operation}", ['key' => $key]);
            }
        }
    }

    /**
     * Log error from failed result.
     *
     * @param FailureInterface $result
     */
    private function logError(FailureInterface $result): void
    {
        $loggingEnabled = $this->config['logging']['enabled'] ?? false;

        if (true === $loggingEnabled) {
            $error = $result->val();
            $channel = $this->config['logging']['channel'] ?? 'default';

            if (is_string($channel)) {
                Log::channel($channel)
                    ->error('OpenFGA operation failed', [
                        'error' => $error instanceof Throwable ? $error->getMessage() : 'Unknown error',
                        'trace' => $error instanceof Throwable ? $error->getTraceAsString() : null,
                    ]);
            } else {
                Log::channel('default')
                    ->error('OpenFGA operation failed', [
                        'error' => $error instanceof Throwable ? $error->getMessage() : 'Unknown error',
                        'trace' => $error instanceof Throwable ? $error->getTraceAsString() : null,
                    ]);
            }
        }
    }

    /**
     * Make the OpenFGA connection instance.
     *
     * @param string $name
     *
     * @throws InvalidArgumentException
     */
    private function makeConnection(string $name): ClientInterface
    {
        $config = $this->configuration($name);

        if (null === $config) {
            throw new InvalidArgumentException("OpenFGA connection [{$name}] not configured.");
        }

        return $this->createConnection($config);
    }

    /**
     * Resolve user ID from various formats.
     *
     * @param  string $user
     * @return string
     */
    private function resolveUserId(string $user): string
    {
        // If user is @me, resolve from auth
        if ('@me' === $user) {
            $auth = $this->container->make(AuthFactory::class);
            $authUser = $auth->guard()->user();

            if (null === $authUser) {
                throw new InvalidArgumentException('Cannot resolve @me without authenticated user');
            }

            $identifier = $authUser->getAuthIdentifier();

            if (! is_string($identifier) && ! is_int($identifier)) {
                throw new InvalidArgumentException('User identifier must be string or integer');
            }

            return 'user:' . $identifier;
        }

        // If user doesn't have a prefix, add user: prefix
        if (! str_contains($user, ':')) {
            return 'user:' . $user;
        }

        return $user;
    }
}
