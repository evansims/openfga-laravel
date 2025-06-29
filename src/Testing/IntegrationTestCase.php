<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use OpenFGA\ClientInterface;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager;
use OpenFGA\Laravel\{OpenFgaManager, OpenFgaServiceProvider};
use Orchestra\Testbench\TestCase as BaseTestCase;
use Override;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;
use stdClass;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Base test case for OpenFGA integration tests.
 *
 * Provides helpers for testing against a real OpenFGA instance
 *
 * @api
 */
abstract class IntegrationTestCase extends BaseTestCase
{
    /**
     * @var array<int, string>
     */
    protected array $createdStores = [];

    protected ClientInterface | null $openFgaClient = null;

    protected ?AbstractOpenFgaManager $openFgaManager = null;

    protected ?string $testModelId = null;

    protected ?string $testStoreId = null;

    /**
     * Assert permission check with retries for eventual consistency.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param int    $maxRetries
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function assertEventuallyAllowed(string $user, string $relation, string $object, int $maxRetries = 20): void
    {
        if (! $this->openFgaClient instanceof ClientInterface) {
            throw new RuntimeException('OpenFGA client is not initialized');
        }

        $allowed = false;
        $attempts = 0;

        while (false === $allowed && $attempts < $maxRetries) {
            if (null === $this->testStoreId || null === $this->testModelId) {
                throw new RuntimeException('Store ID or Model ID is not set');
            }

            if (! $this->openFgaManager instanceof AbstractOpenFgaManager) {
                throw new RuntimeException('OpenFGA manager is not initialized');
            }

            $result = $this->openFgaManager->check(
                user: $user,
                relation: $relation,
                object: $object,
                connection: 'integration_test',
            );

            $allowed = $result;

            if (false === $allowed) {
                // Use exponential backoff to reduce flakiness
                $waitTime = min(100 + ($attempts * 50), 500); // 100ms, 150ms, 200ms, ... up to 500ms
                $this->waitForConsistency($waitTime);
                ++$attempts;

                // Add debugging for failed attempts
                if (1 === $attempts || 5 === $attempts || 10 === $attempts || $attempts === $maxRetries) {
                    error_log(sprintf(
                        'Permission check attempt %d/%d (waited %dms) - User: %s, Relation: %s, Object: %s, Store: %s, Model: %s',
                        $attempts,
                        $maxRetries,
                        $waitTime,
                        $user,
                        $relation,
                        $object,
                        $this->testStoreId ?? 'null',
                        $this->testModelId ?? 'null',
                    ));
                }
            }
        }

        self::assertTrue($allowed, sprintf('Permission check failed after %d attempts: %s %s %s', $attempts, $user, $relation, $object));
    }

    /**
     * Assert permission check fails with retries for eventual consistency.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param int    $maxRetries
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function assertEventuallyDenied(string $user, string $relation, string $object, int $maxRetries = 20): void
    {
        if (! $this->openFgaClient instanceof ClientInterface) {
            throw new RuntimeException('OpenFGA client is not initialized');
        }

        $denied = false;
        $attempts = 0;

        while (! $denied && $attempts < $maxRetries) {
            if (null === $this->testStoreId || null === $this->testModelId) {
                throw new RuntimeException('Store ID or Model ID is not set');
            }

            if (! $this->openFgaManager instanceof AbstractOpenFgaManager) {
                throw new RuntimeException('OpenFGA manager is not initialized');
            }

            $result = $this->openFgaManager->check(
                user: $user,
                relation: $relation,
                object: $object,
                connection: 'integration_test',
            );

            $allowed = $result;
            $denied = ! $allowed;

            if (! $denied) {
                // Use exponential backoff to reduce flakiness
                $waitTime = min(100 + ($attempts * 50), 500); // 100ms, 150ms, 200ms, ... up to 500ms
                $this->waitForConsistency($waitTime);
                ++$attempts;

                // Add debugging for failed attempts
                if (1 === $attempts || 5 === $attempts || 10 === $attempts || $attempts === $maxRetries) {
                    error_log(sprintf(
                        'Permission denial check attempt %d/%d (waited %dms) - User: %s, Relation: %s, Object: %s, Store: %s, Model: %s',
                        $attempts,
                        $maxRetries,
                        $waitTime,
                        $user,
                        $relation,
                        $object,
                        $this->testStoreId ?? 'null',
                        $this->testModelId ?? 'null',
                    ));
                }
            }
        }

        self::assertTrue($denied, sprintf('Permission check should have failed after %d attempts: %s %s %s', $attempts, $user, $relation, $object));
    }

    /**
     * Clear all tuples in the store.
     */
    protected function clearAllTuples(): void
    {
        // Since listAllTuples returns empty array for now,
        // we don't need to do anything here
    }

    /**
     * Configure test connection.
     *
     * @deprecated Configuration is now handled in defineEnvironment()
     */
    protected function configureTestConnection(): void
    {
        // Configuration is now handled in defineEnvironment()
        // This method is kept for backward compatibility
    }

    /**
     * Create authorization model.
     *
     * @param array<string, mixed> $model
     *
     * @throws RuntimeException
     *
     * @return array<string, mixed>
     */
    protected function createAuthorizationModel(array $model): array
    {
        if (null === $this->testStoreId) {
            throw new RuntimeException('Test store ID is not set');
        }

        // The API expects the model directly, not wrapped
        return $this->makeApiRequest('POST', sprintf('/stores/%s/authorization-models', $this->testStoreId), $model);
    }

    /**
     * Create a store.
     *
     * @param string $name
     *
     * @throws RuntimeException
     *
     * @return array<string, mixed>
     */
    protected function createStore(string $name): array
    {
        return $this->makeApiRequest('POST', '/stores', [
            'name' => $name,
        ]);
    }

    protected function createTestDocument(string $id): string
    {
        return 'document:' . $id;
    }

    protected function createTestOrganization(string $id): string
    {
        return 'organization:' . $id;
    }

    /**
     * Create test data helpers.
     *
     * @param string $id
     */
    protected function createTestUser(string $id): string
    {
        return 'user:' . $id;
    }

    /**
     * Define environment setup.
     *
     * @param mixed $app
     *
     * @throws BindingResolutionException
     * @throws RuntimeException
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        // Set default connection to integration_test for tests
        if (! $app instanceof Application) {
            throw new RuntimeException('Expected Application instance');
        }

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('openfga.default', 'integration_test');

        // Configure test connection
        $config->set('openfga.connections.integration_test', [
            'url' => env('OPENFGA_TEST_URL', 'http://localhost:8080'),
            'store_id' => null, // Will be set dynamically
            'model_id' => null, // Will be set dynamically
            'credentials' => [
                'method' => env('OPENFGA_TEST_AUTH_METHOD', 'none'),
                'token' => env('OPENFGA_TEST_API_TOKEN'),
            ],
            'retries' => [
                'max_retries' => 0, // No retries for tests
            ],
            'http_options' => [
                'timeout' => 10,
                'connect_timeout' => 5,
            ],
        ]);
    }

    /**
     * Delete a store.
     *
     * @param string $storeId
     *
     * @throws RuntimeException
     */
    protected function deleteStore(string $storeId): void
    {
        $this->makeApiRequest('DELETE', '/stores/' . $storeId);
    }

    /**
     * Get OpenFGA client instance.
     *
     * @throws RuntimeException
     */
    protected function getClient(): ClientInterface
    {
        if (! $this->openFgaClient instanceof ClientInterface) {
            throw new RuntimeException('OpenFGA client is not initialized');
        }

        return $this->openFgaClient;
    }

    /**
     * Get current model.
     *
     * @throws RuntimeException
     *
     * @return array<string, mixed>
     */
    protected function getCurrentModel(): array
    {
        if (null === $this->testStoreId || null === $this->testModelId) {
            throw new RuntimeException('Test store ID or model ID is not set');
        }

        $response = $this->makeApiRequest('GET', sprintf('/stores/%s/authorization-models/%s', $this->testStoreId, $this->testModelId));

        if (isset($response['authorization_model']) && is_array($response['authorization_model'])) {
            /** @var array<string, mixed> */
            return $response['authorization_model'];
        }

        return [];
    }

    /**
     * Get OpenFGA manager instance.
     *
     * @throws RuntimeException
     */
    protected function getManager(): AbstractOpenFgaManager
    {
        if (! $this->openFgaManager instanceof AbstractOpenFgaManager) {
            throw new RuntimeException('OpenFGA manager is not initialized');
        }

        return $this->openFgaManager;
    }

    /**
     * Get package providers.
     *
     * @param  mixed                    $app
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            OpenFgaServiceProvider::class,
        ];
    }

    /**
     * Get test authorization model.
     *
     * @return array<string, mixed>
     */
    protected function getTestAuthorizationModel(): array
    {
        // Use stdClass for empty objects as required by OpenFGA API
        $emptyObject = new stdClass;

        return [
            'schema_version' => '1.1',
            'type_definitions' => [
                [
                    'type' => 'user',
                ],
                [
                    'type' => 'organization',
                    'relations' => [
                        'admin' => [
                            'this' => $emptyObject,
                        ],
                        'member' => [
                            'union' => [
                                'child' => [
                                    ['this' => $emptyObject],
                                    ['computedUserset' => ['relation' => 'admin']],
                                ],
                            ],
                        ],
                    ],
                    'metadata' => [
                        'relations' => [
                            'admin' => ['directly_related_user_types' => [['type' => 'user']]],
                            'member' => ['directly_related_user_types' => [['type' => 'user']]],
                        ],
                    ],
                ],
                [
                    'type' => 'document',
                    'relations' => [
                        'owner' => [
                            'this' => $emptyObject,
                        ],
                        'editor' => [
                            'union' => [
                                'child' => [
                                    ['this' => $emptyObject],
                                    ['computedUserset' => ['relation' => 'owner']],
                                ],
                            ],
                        ],
                        'viewer' => [
                            'union' => [
                                'child' => [
                                    ['this' => $emptyObject],
                                    ['computedUserset' => ['relation' => 'editor']],
                                    ['tupleToUserset' => [
                                        'tupleset' => ['relation' => 'organization'],
                                        'computedUserset' => ['relation' => 'member'],
                                    ]],
                                ],
                            ],
                        ],
                        'organization' => [
                            'this' => $emptyObject,
                        ],
                    ],
                    'metadata' => [
                        'relations' => [
                            'owner' => ['directly_related_user_types' => [['type' => 'user']]],
                            'editor' => ['directly_related_user_types' => [['type' => 'user']]],
                            'viewer' => ['directly_related_user_types' => [['type' => 'user']]],
                            'organization' => ['directly_related_user_types' => [['type' => 'organization']]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get test authorization model in DSL format.
     */
    protected function getTestAuthorizationModelDSL(): string
    {
        return <<<'DSL'
            model
              schema 1.1

            type user

            type organization
              relations
                define admin: [user]
                define member: [user] or admin

            type document
              relations
                define organization: [organization]
                define owner: [user]
                define editor: [user] or owner
                define viewer: [user] or editor or member from organization
            DSL;
    }

    /**
     * Grant permission helper.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function grantPermission(string $user, string $relation, string $object): void
    {
        if (! $this->openFgaManager instanceof AbstractOpenFgaManager) {
            throw new RuntimeException('OpenFGA manager is not initialized');
        }

        if (null === $this->testStoreId || null === $this->testModelId) {
            throw new RuntimeException('Store ID or Model ID is not set');
        }

        $manager = $this->openFgaManager;

        $result = $manager->grant(
            users: $user,
            relation: $relation,
            object: $object,
            connection: 'integration_test',
        );

        // Debug: Check if grant succeeded
        if (! $result) {
            error_log(sprintf('Grant failed for %s %s %s', $user, $relation, $object));
        }
    }

    /**
     * Batch grant permissions.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $tuples
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function grantPermissions(array $tuples): void
    {
        if (! $this->openFgaManager instanceof AbstractOpenFgaManager) {
            throw new RuntimeException('OpenFGA manager is not initialized');
        }

        if (null === $this->testStoreId || null === $this->testModelId) {
            throw new RuntimeException('Store ID or Model ID is not set');
        }

        $manager = $this->openFgaManager;

        $manager->writeBatch(
            writes: $tuples,
            deletes: [],
            connection: 'integration_test',
        );
    }

    /**
     * Check if OpenFGA server is available.
     */
    protected function isOpenFgaAvailable(): bool
    {
        /** @var mixed $envUrl */
        $envUrl = env('OPENFGA_TEST_URL', 'http://localhost:8080');
        $url = is_string($envUrl) ? $envUrl : 'http://localhost:8080';

        // In Docker environment, retry multiple times as services may still be starting
        /** @var mixed $maxRetriesEnv */
        $maxRetriesEnv = env('OPENFGA_AVAILABILITY_RETRIES', 10);
        $maxRetries = is_numeric($maxRetriesEnv) ? (int) $maxRetriesEnv : 10;

        /** @var mixed $retryDelayEnv */
        $retryDelayEnv = env('OPENFGA_AVAILABILITY_DELAY', 3);
        $retryDelay = is_numeric($retryDelayEnv) ? (int) $retryDelayEnv : 3;

        for ($i = 0; $i < $maxRetries; ++$i) {
            try {
                // First check health endpoint
                $healthContext = stream_context_create([
                    'http' => [
                        'timeout' => 2,
                        'method' => 'GET',
                    ],
                ]);

                $healthCheck = @file_get_contents($url . '/healthz', false, $healthContext);

                if (false === $healthCheck) {
                    error_log(sprintf('OpenFGA health check failed at: %s/healthz', $url));

                    throw new Exception('Health check failed');
                }

                // Parse the health check response to check for SERVING status
                $healthData = json_decode($healthCheck, true);

                if (! is_array($healthData) || ! isset($healthData['status']) || 'SERVING' !== $healthData['status']) {
                    error_log('OpenFGA health check not SERVING. Response: ' . $healthCheck);

                    throw new Exception('Health check not SERVING');
                }

                // Then check stores endpoint
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 2,
                        'method' => 'GET',
                    ],
                ]);

                $result = @file_get_contents($url . '/stores', false, $context);

                if (false !== $result) {
                    return true;
                }

                error_log(sprintf('OpenFGA not ready at: %s/stores (attempt %d/%d)', $url, $i + 1, $maxRetries));
            } catch (Exception) {
                // Continue to next retry
            }

            // Sleep before next retry (except on last attempt)
            if ($i < $maxRetries - 1) {
                sleep($retryDelay);
            }
        }

        return false;
    }

    /**
     * List all tuples in the store.
     *
     * @throws RuntimeException
     *
     * @return array<int, array{user: string, relation: string, object: string}>
     */
    protected function listAllTuples(): array
    {
        if (! $this->openFgaClient instanceof ClientInterface) {
            throw new RuntimeException('OpenFGA client is not initialized');
        }

        if (null === $this->testStoreId) {
            throw new RuntimeException('Store ID is not set');
        }

        // For now, return empty array as the Laravel SDK doesn't expose readTuples directly
        // This would need to be implemented using the raw client if needed
        return [];
    }

    /**
     * Make direct API request.
     *
     * @param string               $method
     * @param string               $path
     * @param array<string, mixed> $data
     *
     * @throws RuntimeException
     *
     * @return array<string, mixed>
     */
    protected function makeApiRequest(string $method, string $path, array $data = []): array
    {
        /** @var mixed $configUrl */
        $configUrl = Config::get('openfga.connections.integration_test.url');
        $url = (is_string($configUrl) ? rtrim($configUrl, '/') : '') . $path;

        $options = [
            'http' => [
                'method' => $method,
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                'content' => json_encode($data),
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if (false === $response) {
            throw new RuntimeException('Failed to make API request to ' . $url);
        }

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            // Return the raw response for debugging if decode fails
            return ['error' => 'Failed to decode response', 'raw' => $response];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Measure operation time.
     *
     * @param  Closure(): mixed                         $operation
     * @return array{result: mixed, duration_ms: float}
     */
    protected function measureTime(Closure $operation): array
    {
        $start = microtime(true);

        /** @var mixed $result */
        $result = $operation();
        $duration = (microtime(true) - $start) * 1000.0;

        return [
            'result' => $result,
            'duration_ms' => $duration,
        ];
    }

    /**
     * Revoke permission helper.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function revokePermission(string $user, string $relation, string $object): void
    {
        if (! $this->openFgaManager instanceof AbstractOpenFgaManager) {
            throw new RuntimeException('OpenFGA manager is not initialized');
        }

        if (null === $this->testStoreId || null === $this->testModelId) {
            throw new RuntimeException('Store ID or Model ID is not set');
        }

        $manager = $this->openFgaManager;
        $manager->revoke(
            users: $user,
            relation: $relation,
            object: $object,
            connection: 'integration_test',
        );
    }

    /**
     * Run a test with a clean store.
     *
     * @param Closure(): void $test
     */
    protected function runWithCleanStore(Closure $test): void
    {
        $this->clearAllTuples();
        $test();
        $this->clearAllTuples();
    }

    /**
     * Set up integration test environment.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function setUpIntegrationTest(): void
    {
        // Initialize OpenFGA manager
        $this->openFgaManager = app(OpenFgaManager::class);
        $this->openFgaClient = $this->openFgaManager->connection('integration_test');

        // Create test store and model
        $this->setUpTestStore();
    }

    /**
     * Set up test store and model.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function setUpTestStore(): void
    {
        // Create a new store for testing
        $storeName = 'test_' . uniqid();
        $store = $this->createStore($storeName);

        if (isset($store['id']) && is_string($store['id'])) {
            $this->testStoreId = $store['id'];
            $this->createdStores[] = $this->testStoreId;
        } else {
            throw new RuntimeException('Failed to create test store');
        }

        // Update connection with store ID
        Config::set('openfga.connections.integration_test.store_id', $this->testStoreId);

        // Create authorization model
        $model = $this->createAuthorizationModel($this->getTestAuthorizationModel());

        if (isset($model['authorization_model_id']) && is_string($model['authorization_model_id'])) {
            $this->testModelId = $model['authorization_model_id'];
        } else {
            $encoded = json_encode($model);
            $errorMsg = 'Failed to create authorization model. Response: ' . (false !== $encoded ? $encoded : 'null');

            throw new RuntimeException($errorMsg);
        }

        // Update connection with model ID
        Config::set('openfga.connections.integration_test.model_id', $this->testModelId);

        // Reinitialize client with updated config
        if (! $this->openFgaManager instanceof AbstractOpenFgaManager) {
            throw new RuntimeException('OpenFGA manager is not initialized');
        }

        $manager = $this->openFgaManager;

        // Update the manager's internal config to reflect the new store and model IDs
        /** @var Repository $configRepository */
        $configRepository = app('config');

        /** @var array{default?: string, connections?: array<string, array<string, mixed>>, cache?: array<string, mixed>, queue?: array<string, mixed>, logging?: array<string, mixed>} $updatedConfig */
        $updatedConfig = $configRepository->get('openfga', []);
        $manager->updateConfig($updatedConfig);

        // Enable exception throwing to see errors during tests
        $manager->throwExceptions(true);

        $this->openFgaClient = $manager->connection('integration_test');
    }

    /**
     * Tear down integration test environment.
     */
    protected function tearDownIntegrationTest(): void
    {
        // Clean up created stores
        foreach ($this->createdStores as $createdStore) {
            try {
                $this->deleteStore($createdStore);
            } catch (Exception) {
                // Ignore cleanup errors
            }
        }

        $this->openFgaManager = null;
        $this->openFgaClient = null;
        $this->testStoreId = null;
        $this->testModelId = null;
        $this->createdStores = [];
    }

    /**
     * Wait for eventual consistency.
     *
     * @param int $milliseconds
     */
    protected function waitForConsistency(int $milliseconds = 100): void
    {
        // Ensure minimum wait time to avoid timing issues
        $minWait = max($milliseconds, 50);
        usleep($minWait * 1000);
    }
}
