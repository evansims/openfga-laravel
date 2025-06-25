<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;
use Exception;
use Illuminate\Support\Facades\Config;
use OpenFGA\{Client, ClientInterface};
use OpenFGA\Laravel\{OpenFgaManager, OpenFgaServiceProvider};
use Orchestra\Testbench\TestCase as BaseTestCase;
use RuntimeException;

use function sprintf;

/**
 * Base test case for OpenFGA integration tests.
 *
 * Provides helpers for testing against a real OpenFGA instance
 */
abstract class IntegrationTestCase extends BaseTestCase
{
    protected array $createdStores = [];

    protected ?ClientInterface $openFgaClient = null;

    protected ?OpenFgaManager $openFgaManager = null;

    protected ?string $testModelId = null;

    protected ?string $testStoreId = null;

    /**
     * Assert permission check with retries for eventual consistency.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param int    $maxRetries
     */
    protected function assertEventuallyAllowed(string $user, string $relation, string $object, int $maxRetries = 10): void
    {
        $allowed = false;
        $attempts = 0;

        while (! $allowed && $attempts < $maxRetries) {
            $allowed = $this->openFgaClient->check($user, $relation, $object);

            if (! $allowed) {
                $this->waitForConsistency(100);
                ++$attempts;
            }
        }

        $this->assertTrue($allowed, sprintf('Permission check failed after %d attempts: %s %s %s', $attempts, $user, $relation, $object));
    }

    /**
     * Assert permission check fails with retries for eventual consistency.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param int    $maxRetries
     */
    protected function assertEventuallyDenied(string $user, string $relation, string $object, int $maxRetries = 10): void
    {
        $denied = false;
        $attempts = 0;

        while (! $denied && $attempts < $maxRetries) {
            $allowed = $this->openFgaClient->check($user, $relation, $object);
            $denied = ! $allowed;

            if (! $denied) {
                $this->waitForConsistency(100);
                ++$attempts;
            }
        }

        $this->assertTrue($denied, sprintf('Permission check should have failed after %d attempts: %s %s %s', $attempts, $user, $relation, $object));
    }

    /**
     * Clear all tuples in the store.
     */
    protected function clearAllTuples(): void
    {
        $tuples = $this->listAllTuples();

        if ([] !== $tuples) {
            $this->openFgaClient->write([], $tuples);
        }
    }

    /**
     * Configure test connection.
     */
    protected function configureTestConnection(): void
    {
        Config::set('openfga.connections.integration_test', [
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
     * Create authorization model.
     *
     * @param array $model
     */
    protected function createAuthorizationModel(array $model): array
    {
        return $this->makeApiRequest('POST', sprintf('/stores/%s/authorization-models', $this->testStoreId), $model);
    }

    /**
     * Create a store.
     *
     * @param string $name
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
     * Delete a store.
     *
     * @param string $storeId
     */
    protected function deleteStore(string $storeId): void
    {
        $this->makeApiRequest('DELETE', '/stores/' . $storeId);
    }

    /**
     * Get OpenFGA client instance.
     */
    protected function getClient(): ClientInterface
    {
        return $this->openFgaClient;
    }

    /**
     * Get current model.
     */
    protected function getCurrentModel(): array
    {
        $response = $this->makeApiRequest('GET', sprintf('/stores/%s/authorization-models/%s', $this->testStoreId, $this->testModelId));

        return $response['authorization_model'] ?? [];
    }

    /**
     * Get OpenFGA manager instance.
     */
    protected function getManager(): OpenFgaManager
    {
        return $this->openFgaManager;
    }

    /**
     * Get package providers.
     *
     * @param mixed $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            OpenFgaServiceProvider::class,
        ];
    }

    /**
     * Get test authorization model.
     */
    protected function getTestAuthorizationModel(): array
    {
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
                            'this' => [],
                        ],
                        'member' => [
                            'union' => [
                                'child' => [
                                    ['this' => []],
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
                            'this' => [],
                        ],
                        'editor' => [
                            'union' => [
                                'child' => [
                                    ['this' => []],
                                    ['computedUserset' => ['relation' => 'owner']],
                                ],
                            ],
                        ],
                        'viewer' => [
                            'union' => [
                                'child' => [
                                    ['this' => []],
                                    ['computedUserset' => ['relation' => 'editor']],
                                    ['tupleToUserset' => [
                                        'tupleset' => ['relation' => 'organization'],
                                        'computedUserset' => ['relation' => 'member'],
                                    ]],
                                ],
                            ],
                        ],
                        'organization' => [
                            'this' => [],
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
     * Grant permission helper.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    protected function grantPermission(string $user, string $relation, string $object): void
    {
        $this->openFgaClient->write([
            ['user' => $user, 'relation' => $relation, 'object' => $object],
        ]);
    }

    /**
     * Batch grant permissions.
     *
     * @param array $tuples
     */
    protected function grantPermissions(array $tuples): void
    {
        $this->openFgaClient->write($tuples);
    }

    /**
     * List all tuples in the store.
     */
    protected function listAllTuples(): array
    {
        $response = $this->openFgaClient->read();

        return $response['tuples'] ?? [];
    }

    /**
     * Make direct API request.
     *
     * @param string $method
     * @param string $path
     * @param array  $data
     */
    protected function makeApiRequest(string $method, string $path, array $data = []): array
    {
        $url = rtrim((string) Config::get('openfga.connections.integration_test.url'), '/') . $path;

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

        return json_decode($response, true) ?: [];
    }

    /**
     * Measure operation time.
     *
     * @param Closure $operation
     */
    protected function measureTime(Closure $operation): array
    {
        $start = microtime(true);
        $result = $operation();
        $duration = (microtime(true) - $start) * 1000;

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
     */
    protected function revokePermission(string $user, string $relation, string $object): void
    {
        $this->openFgaClient->write([], [
            ['user' => $user, 'relation' => $relation, 'object' => $object],
        ]);
    }

    /**
     * Run a test with a clean store.
     *
     * @param Closure $test
     */
    protected function runWithCleanStore(Closure $test): void
    {
        $this->clearAllTuples();
        $test();
        $this->clearAllTuples();
    }

    /**
     * Set up integration test environment.
     */
    protected function setUpIntegrationTest(): void
    {
        // Skip if not in integration test mode
        if (! $this->shouldRunIntegrationTests()) {
            $this->markTestSkipped('Integration tests are not enabled. Set OPENFGA_RUN_INTEGRATION_TESTS=true to run.');
        }

        // Configure test connection
        $this->configureTestConnection();

        // Initialize OpenFGA manager
        $this->openFgaManager = app(OpenFgaManager::class);
        $this->openFgaClient = $this->openFgaManager->connection('integration_test');

        // Create test store and model
        $this->setUpTestStore();
    }

    /**
     * Set up test store and model.
     */
    protected function setUpTestStore(): void
    {
        // Create a new store for testing
        $storeName = 'test_' . uniqid();
        $store = $this->createStore($storeName);

        $this->testStoreId = $store['id'];
        $this->createdStores[] = $this->testStoreId;

        // Update connection with store ID
        Config::set('openfga.connections.integration_test.store_id', $this->testStoreId);

        // Create authorization model
        $model = $this->createAuthorizationModel($this->getTestAuthorizationModel());
        $this->testModelId = $model['id'];

        // Update connection with model ID
        Config::set('openfga.connections.integration_test.model_id', $this->testModelId);

        // Reinitialize client with updated config
        $this->openFgaManager->purge('integration_test');
        $this->openFgaClient = $this->openFgaManager->connection('integration_test');
    }

    /**
     * Check if integration tests should run.
     */
    protected function shouldRunIntegrationTests(): bool
    {
        return true === env('OPENFGA_RUN_INTEGRATION_TESTS', false);
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
        usleep($milliseconds * 1000);
    }
}
