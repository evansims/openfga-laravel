<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use AssertionError;
use DB;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\{Cache, Queue};
use Mockery;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\{OpenFgaManager, OpenFgaServiceProvider};

use function is_array;
use function sprintf;

/**
 * Helper for common test setup patterns.
 */
final class TestSetup
{
    /**
     * Assert that a closure throws a specific exception.
     *
     * @param string   $expectedClass
     * @param callable $closure
     * @param string   $expectedMessage
     */
    public static function assertThrows(string $expectedClass, callable $closure, string $expectedMessage = ''): void
    {
        try {
            $closure();

            throw new AssertionError(sprintf('Expected %s to be thrown', $expectedClass));
        } catch (Exception $exception) {
            if (! $exception instanceof $expectedClass) {
                throw new AssertionError(sprintf('Expected %s, got ', $expectedClass) . $exception::class, $exception->getCode(), $exception);
            }

            if ($expectedMessage && ! str_contains($exception->getMessage(), $expectedMessage)) {
                throw new AssertionError(sprintf("Expected message to contain '%s', got: %s", $expectedMessage, $exception->getMessage()), $exception->getCode(), $exception);
            }
        }
    }

    /**
     * Clean up test environment.
     */
    public static function cleanupTestEnvironment(): void
    {
        // Clear caches
        if (app()->bound('cache')) {
            Cache::flush();
        }

        // Clear queue
        if (app()->bound('queue')) {
            Queue::purge();
        }

        // Reset configuration
        config()->set('openfga', null);

        // Close Mockery
        Mockery::close();
    }

    /**
     * Set up authentication for testing.
     *
     * @param object|null $user
     */
    public static function configureAuth(?object $user = null): void
    {
        if (null === $user) {
            $user = TestFactories::createTestUser();
        }

        auth()->login($user);
    }

    /**
     * Set up cache configuration for testing.
     *
     * @param string $driver
     * @param array  $config
     */
    public static function configureCache(string $driver = 'array', array $config = []): void
    {
        config(['cache.default' => $driver]);

        if ([] !== $config) {
            config(['cache.stores.' . $driver => $config]);
        }

        Cache::flush();
    }

    /**
     * Set up multiple OpenFGA connections for testing.
     *
     * @param array $connections
     */
    public static function configureMultipleConnections(array $connections): void
    {
        $configArray = ['connections' => []];

        foreach ($connections as $name => $config) {
            $configArray['connections'][$name] = array_merge(
                TestConfigBuilder::create()->build(),
                $config,
            );
        }

        if ([] !== $connections) {
            $configArray['default'] = array_key_first($connections);
        }

        config(['openfga' => $configArray]);
    }

    /**
     * Set up OpenFGA configuration for testing.
     *
     * @param array $config
     */
    public static function configureOpenFga(array $config = []): void
    {
        $defaultConfig = TestConfigBuilder::create()->build();
        $mergedConfig = array_merge($defaultConfig, $config);

        config(['openfga' => [
            'default' => 'test',
            'connections' => [
                'test' => $mergedConfig,
            ],
        ]]);
    }

    /**
     * Set up queue configuration for testing.
     *
     * @param string $driver
     */
    public static function configureQueue(string $driver = 'sync'): void
    {
        config(['queue.default' => $driver]);
        Queue::fake();
    }

    /**
     * Create test data for batch operations.
     *
     * @param int $size
     */
    public static function createBatchTestData(int $size = 100): array
    {
        $data = [];

        for ($i = 1; $i <= $size; ++$i) {
            $data[] = [
                'user' => 'user:' . $i,
                'relation' => 'viewer',
                'object' => 'document:' . $i,
            ];
        }

        return $data;
    }

    /**
     * Create a test container with minimal services.
     */
    public static function createTestContainer(): Container
    {
        $container = new Container;

        // Register config repository
        $container->singleton('config', static fn (): Repository => new Repository);

        return $container;
    }

    /**
     * Mock the OpenFGA manager in the container.
     *
     * @param Container  $app
     * @param mixed|null $mock
     */
    public static function mockManagerInContainer(Container $app, $mock = null): void
    {
        if (null === $mock) {
            $mock = MockScenarios::managerAlwaysAllows();
        }

        $app->singleton(ManagerInterface::class, static fn () => $mock);
        $app->singleton(OpenFgaManager::class, static fn () => $mock);
    }

    /**
     * Register OpenFGA service provider and services.
     *
     * @param Container|null $app
     */
    public static function registerOpenFgaServices(?Container $app = null): void
    {
        $app ??= app();

        $provider = new OpenFgaServiceProvider($app);
        $provider->register();
        $provider->boot();
    }

    /**
     * Set up integration testing environment.
     */
    public static function setupIntegrationTesting(): array
    {
        // Check if OpenFGA is available
        $url = env('OPENFGA_TEST_URL', TestConstants::DEFAULT_API_URL);

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'method' => 'GET',
                ],
            ]);

            $response = @file_get_contents($url . '/healthz', false, $context);

            if (false === $response) {
                throw new Exception('OpenFGA server not available');
            }

            $health = json_decode($response, true);

            if (! is_array($health) || ($health['status'] ?? '') !== 'SERVING') {
                throw new Exception('OpenFGA server not ready');
            }
        } catch (Exception $exception) {
            test()->markTestSkipped('OpenFGA server is not available: ' . $exception->getMessage());
        }

        // Configure for integration testing
        self::configureOpenFga([
            'url' => $url,
            'credentials' => [
                'method' => env('OPENFGA_TEST_AUTH_METHOD', 'none'),
                'token' => env('OPENFGA_TEST_API_TOKEN'),
            ],
            'retries' => [
                'max_retries' => 0, // No retries for tests
            ],
        ]);

        return ['url' => $url];
    }

    /**
     * Set up performance testing environment.
     */
    public static function setupPerformanceTesting(): void
    {
        // Disable query logging to reduce overhead
        if (config('database.default')) {
            DB::disableQueryLog();
        }

        // Set higher memory limit
        ini_set('memory_limit', '512M');

        // Configure cache for performance testing
        config(['cache.default' => 'array']);

        // Use sync queue to avoid job overhead
        config(['queue.default' => 'sync']);
    }

    /**
     * Set up a test route with parameters.
     *
     * @param Request $request
     * @param string  $path
     * @param array   $parameters
     */
    public static function setupRoute(
        Request $request,
        string $path = '/test/{document}',
        array $parameters = ['document' => '123'],
    ): void {
        $route = new Route(['GET'], $path, []);
        $route->bind($request);

        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(static fn (): Route => $route);
    }

    /**
     * Skip test if OpenFGA is not available.
     */
    public static function skipIfOpenFgaUnavailable(): void
    {
        try {
            $url = env('OPENFGA_TEST_URL', TestConstants::DEFAULT_API_URL);
            $context = stream_context_create(['http' => ['timeout' => 1]]);

            if (false === @file_get_contents($url . '/stores', false, $context)) {
                test()->markTestSkipped('OpenFGA server is not available');
            }
        } catch (Exception $exception) {
            test()->markTestSkipped('OpenFGA server is not available: ' . $exception->getMessage());
        }
    }

    /**
     * Skip test if running in CI environment.
     *
     * @param string $reason
     */
    public static function skipOnCI(string $reason = 'Skipped on CI'): void
    {
        if (env('CI', false)) {
            test()->markTestSkipped($reason);
        }
    }

    /**
     * Wait for eventual consistency in tests.
     *
     * @param int $milliseconds
     */
    public static function waitForConsistency(int $milliseconds = 100): void
    {
        usleep(max($milliseconds, 50) * 1000);
    }
}
