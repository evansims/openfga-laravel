<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Exception;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\Queue;
use Mockery;
use Mockery\MockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

use function count;

/**
 * Pre-built mock scenarios for common test cases.
 */
final class MockScenarios
{
    /**
     * Create a cache mock that always hits.
     *
     * @param mixed $value
     */
    public static function cacheAlwaysHits(mixed $value = true): MockInterface
    {
        $cache = Mockery::mock(Repository::class);

        $cache->shouldReceive('get')->andReturn($value);
        $cache->shouldReceive('has')->andReturn(true);
        $cache->shouldReceive('remember')->andReturn($value);

        return $cache;
    }

    /**
     * Create a cache mock that always misses.
     */
    public static function cacheAlwaysMisses(): MockInterface
    {
        $cache = Mockery::mock(Repository::class);

        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('has')->andReturn(false);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

        return $cache;
    }

    /**
     * Create a cache mock with standard behavior.
     */
    public static function cacheWithStandardBehavior(): MockInterface
    {
        $cache = Mockery::mock(Repository::class);

        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('forget')->andReturn(true);
        $cache->shouldReceive('flush')->andReturn(true);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

        return $cache;
    }

    /**
     * Create a client mock that simulates slow responses.
     *
     * @param int $delayMs
     */
    public static function clientWithSlowResponses(int $delayMs = 100): MockInterface
    {
        $client = TestFactories::createMockClient();

        $client->shouldReceive('check')->andReturnUsing(function () use ($delayMs) {
            usleep($delayMs * 1000);

            return true;
        });

        $client->shouldReceive('batchCheck')->andReturnUsing(function () use ($delayMs) {
            usleep($delayMs * 1000);

            return [true, true, true];
        });

        return $client;
    }

    /**
     * Create a client mock that returns standard responses.
     */
    public static function clientWithStandardResponses(): MockInterface
    {
        $client = TestFactories::createMockClient();

        $client->shouldReceive('check')->andReturn(true);
        $client->shouldReceive('batchCheck')->andReturn([true, true, true]);
        $client->shouldReceive('expand')->andReturn([
            'tree' => [
                'root' => [
                    'leaf' => [
                        'users' => ['user:1', 'user:2'],
                    ],
                ],
            ],
        ]);
        $client->shouldReceive('listObjects')->andReturn(['document:1', 'document:2']);
        $client->shouldReceive('listUsers')->andReturn(['user:1', 'user:2']);
        $client->shouldReceive('listRelations')->andReturn(['viewer', 'editor']);
        $client->shouldReceive('write')->andReturn(true);
        $client->shouldReceive('writeBatch')->andReturn(true);

        return $client;
    }

    /**
     * Create an HTTP client mock with standard responses.
     *
     * @param array $responses
     */
    public static function httpClientWithResponses(array $responses = []): MockInterface
    {
        $client = Mockery::mock(\GuzzleHttp\ClientInterface::class);

        $defaultResponse = Mockery::mock(ResponseInterface::class);
        $defaultResponse->shouldReceive('getStatusCode')->andReturn(200);
        $defaultResponse->shouldReceive('getBody')->andReturn(json_encode(['status' => 'ok']));

        if (empty($responses)) {
            $client->shouldReceive('request')->andReturn($defaultResponse);
        } else {
            foreach ($responses as $response) {
                $mockResponse = Mockery::mock(ResponseInterface::class);
                $mockResponse->shouldReceive('getStatusCode')->andReturn($response['status'] ?? 200);
                $mockResponse->shouldReceive('getBody')->andReturn($response['body'] ?? '{}');

                $client->shouldReceive('request')->andReturn($mockResponse);
            }
        }

        return $client;
    }

    /**
     * Create a logger mock that captures log messages.
     */
    public static function loggerCapturingMessages(): MockInterface
    {
        $logger = Mockery::mock(LoggerInterface::class);

        $logger->shouldReceive('debug')->andReturnUsing(function ($message, $context = []): void {
            // Store for later verification if needed
        });

        $logger->shouldReceive('info')->andReturnUsing(function ($message, $context = []): void {
            // Store for later verification if needed
        });

        $logger->shouldReceive('warning')->andReturnUsing(function ($message, $context = []): void {
            // Store for later verification if needed
        });

        $logger->shouldReceive('error')->andReturnUsing(function ($message, $context = []): void {
            // Store for later verification if needed
        });

        return $logger;
    }

    /**
     * Create a manager mock that always allows permissions.
     */
    public static function managerAlwaysAllows(): MockInterface
    {
        $manager = TestFactories::createMockManager();
        $manager->shouldReceive('check')->andReturn(true);
        $manager->shouldReceive('batchCheck')->andReturn([true, true, true]);
        $manager->shouldReceive('grant')->andReturn(true);
        $manager->shouldReceive('revoke')->andReturn(true);
        $manager->shouldReceive('writeBatch')->andReturn(true);

        return $manager;
    }

    /**
     * Create a manager mock that always denies permissions.
     */
    public static function managerAlwaysDenies(): MockInterface
    {
        $manager = TestFactories::createMockManager();
        $manager->shouldReceive('check')->andReturn(false);
        $manager->shouldReceive('batchCheck')->andReturn([false, false, false]);
        $manager->shouldReceive('grant')->andReturn(false);
        $manager->shouldReceive('revoke')->andReturn(false);
        $manager->shouldReceive('writeBatch')->andReturn(false);

        return $manager;
    }

    /**
     * Create a manager mock that expects specific method calls.
     *
     * @param array $expectedCalls
     */
    public static function managerExpectingCalls(array $expectedCalls): MockInterface
    {
        $manager = TestFactories::createMockManager();

        foreach ($expectedCalls as $method => $expectations) {
            $mockMethod = $manager->shouldReceive($method);

            if (isset($expectations['times'])) {
                $mockMethod->times($expectations['times']);
            }

            if (isset($expectations['with'])) {
                $mockMethod->with(...$expectations['with']);
            }

            if (isset($expectations['andReturn'])) {
                $mockMethod->andReturn($expectations['andReturn']);
            }

            if (isset($expectations['andThrow'])) {
                $mockMethod->andThrow($expectations['andThrow']);
            }
        }

        return $manager;
    }

    /**
     * Create a manager mock that fails with exceptions.
     *
     * @param string $exceptionClass
     * @param string $message
     */
    public static function managerThrowsExceptions(string $exceptionClass = Exception::class, string $message = 'Test exception'): MockInterface
    {
        $manager = TestFactories::createMockManager();
        $exception = new $exceptionClass($message);

        $manager->shouldReceive('check')->andThrow($exception);
        $manager->shouldReceive('batchCheck')->andThrow($exception);
        $manager->shouldReceive('grant')->andThrow($exception);
        $manager->shouldReceive('revoke')->andThrow($exception);
        $manager->shouldReceive('writeBatch')->andThrow($exception);

        return $manager;
    }

    /**
     * Create a manager mock with mixed results.
     */
    public static function managerWithMixedResults(): MockInterface
    {
        $manager = TestFactories::createMockManager();
        $manager->shouldReceive('check')->andReturn(true, false, true);
        $manager->shouldReceive('batchCheck')->andReturn([true, false, true]);
        $manager->shouldReceive('grant')->andReturn(true);
        $manager->shouldReceive('revoke')->andReturn(true);
        $manager->shouldReceive('writeBatch')->andReturn(true);

        return $manager;
    }

    /**
     * Create a manager with realistic performance characteristics.
     */
    public static function managerWithRealisticPerformance(): MockInterface
    {
        $manager = TestFactories::createMockManager();

        // Simulate realistic response times
        $manager->shouldReceive('check')->andReturnUsing(function () {
            usleep(random_int(10, 50) * 1000); // 10-50ms

            return 1 === random_int(0, 1); // Random true/false
        });

        $manager->shouldReceive('batchCheck')->andReturnUsing(function ($requests) {
            usleep(count($requests) * random_int(5, 15) * 1000); // 5-15ms per request

            return array_map(fn () => 1 === random_int(0, 1), $requests);
        });

        return $manager;
    }

    /**
     * Create a queue mock that tracks dispatched jobs.
     */
    public static function queueTrackingJobs(): MockInterface
    {
        $queue = Mockery::mock(Queue::class);

        $queue->shouldReceive('push')->andReturn('job-id-123');
        $queue->shouldReceive('later')->andReturn('job-id-456');
        $queue->shouldReceive('bulk')->andReturn(['job-id-789']);

        return $queue;
    }
}
