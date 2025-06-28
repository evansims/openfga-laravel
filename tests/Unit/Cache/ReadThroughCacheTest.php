<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Cache;

use Exception;
use Illuminate\Cache\{ArrayStore, Repository};
use OpenFGA\Laravel\Cache\ReadThroughCache;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Tests\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ReadThroughCacheTest extends TestCase
{
    private ReadThroughCache $cache;

    private TestCacheRepository $cacheRepository;

    private array $defaultConfig;

    private TestableOpenFgaManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConfig = [
            'enabled' => true,
            'store' => 'array',
            'ttl' => 300,
            'prefix' => 'test',
            'tags_enabled' => false,
            'negative_ttl' => 60,
            'error_ttl' => 10,
            'log_misses' => false,
            'metrics_enabled' => true,
        ];

        // Create a test cache repository
        $this->cacheRepository = new TestCacheRepository(new ArrayStore);

        // Override the cache store resolution
        $this->app->singleton('cache', function () {
            return new class($this->cacheRepository) {
                private $store;

                public function __construct($store)
                {
                    $this->store = $store;
                }

                public function store($name = null)
                {
                    return $this->store;
                }
            };
        });

        $this->manager = new TestableOpenFgaManager;
    }

    public function test_check_bypasses_cache_when_disabled(): void
    {
        $this->cache = new ReadThroughCache($this->manager, array_merge($this->defaultConfig, [
            'enabled' => false,
        ]));

        $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

        $result = $this->cache->check('user:123', 'viewer', 'document:456');

        $this->assertTrue($result);
        $this->assertEquals(1, $this->manager->getCheckCount());
        $this->assertEquals(0, $this->cacheRepository->getPutCount());
    }

    public function test_check_bypasses_cache_with_context(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

        $result = $this->cache->check('user:123', 'viewer', 'document:456', [], ['key' => 'value']);

        $this->assertTrue($result);
        $this->assertEquals(1, $this->manager->getCheckCount());

        // Verify nothing was cached
        $this->assertEquals(0, $this->cacheRepository->getPutCount());
    }

    public function test_check_bypasses_cache_with_contextual_tuples(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

        $result = $this->cache->check('user:123', 'viewer', 'document:456', ['tuple1']);

        $this->assertTrue($result);
        $this->assertEquals(1, $this->manager->getCheckCount());

        // Verify nothing was cached
        $this->assertEquals(0, $this->cacheRepository->getPutCount());
    }

    public function test_check_caches_errors_briefly(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $this->manager->setShouldThrow(new RuntimeException('OpenFGA error'));

        try {
            $this->cache->check('user:123', 'viewer', 'document:456');
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertEquals('OpenFGA error', $e->getMessage());
        }

        // Verify error was cached with short TTL
        $lastPut = $this->cacheRepository->getLastPut();
        $this->assertEquals(10, $lastPut['ttl']); // error_ttl
        $this->assertTrue($lastPut['value']['error']);
    }

    public function test_check_fetches_from_source_on_miss(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

        $result = $this->cache->check('user:123', 'viewer', 'document:456');

        $this->assertTrue($result);
        $this->assertEquals(1, $this->manager->getCheckCount());

        // Verify it was cached
        $cached = $this->cacheRepository->get('test:check:user:123:viewer:document:456');
        $this->assertNotNull($cached);
        $this->assertTrue($cached['value']);
    }

    public function test_check_logs_misses_when_enabled(): void
    {
        $logger = new TestLogger;
        $this->app->singleton(LoggerInterface::class, fn () => $logger);

        $this->cache = new ReadThroughCache($this->manager, array_merge($this->defaultConfig, [
            'log_misses' => true,
        ]));

        $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

        $this->cache->check('user:123', 'viewer', 'document:456');

        $this->assertCount(1, $logger->logs);
        $this->assertEquals('debug', $logger->logs[0]['level']);
        $this->assertEquals('OpenFGA cache miss', $logger->logs[0]['message']);
    }

    public function test_check_returns_from_cache_on_hit(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        // Pre-populate cache
        $this->cacheRepository->put('test:check:user:123:viewer:document:456', ['value' => true, 'cached_at' => time()]);

        $result = $this->cache->check('user:123', 'viewer', 'document:456');

        $this->assertTrue($result);
        $this->assertEquals(0, $this->manager->getCheckCount());
    }

    public function test_check_uses_negative_ttl_for_false_results(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $this->manager->setCheckResult('user:123', 'viewer', 'document:456', false);

        $result = $this->cache->check('user:123', 'viewer', 'document:456');

        $this->assertFalse($result);

        // Verify TTL was set correctly (we'll check the last put call)
        $lastPut = $this->cacheRepository->getLastPut();
        $this->assertEquals(60, $lastPut['ttl']); // negative_ttl
    }

    public function test_get_stats(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $this->cacheRepository->put('test:stats:hits', 75);
        $this->cacheRepository->put('test:stats:misses', 25);

        $stats = $this->cache->getStats();

        $this->assertEquals([
            'hits' => 75,
            'misses' => 25,
            'hit_rate' => 75.0,
        ], $stats);
    }

    public function test_get_stats_handles_zero_total(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $stats = $this->cache->getStats();

        $this->assertEquals([
            'hits' => 0,
            'misses' => 0,
            'hit_rate' => 0.0,
        ], $stats);
    }

    public function test_invalidate_without_tags_returns_zero(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $invalidated = $this->cache->invalidate('user:123');

        $this->assertEquals(0, $invalidated);
    }

    public function test_list_objects_fetches_on_miss(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $this->manager->setListObjectsResult('user:123', 'viewer', 'document', ['document:1', 'document:2']);

        $result = $this->cache->listObjects('user:123', 'viewer', 'document');

        $this->assertEquals(['document:1', 'document:2'], $result);
        $this->assertEquals(1, $this->manager->getListObjectsCount());
    }

    public function test_list_objects_uses_cache(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        // Pre-populate cache
        $this->cacheRepository->put('test:list:user:123:viewer:document', ['value' => ['document:1', 'document:2'], 'cached_at' => time()]);

        $result = $this->cache->listObjects('user:123', 'viewer', 'document');

        $this->assertEquals(['document:1', 'document:2'], $result);
        $this->assertEquals(0, $this->manager->getListObjectsCount());
    }

    public function test_reset_stats(): void
    {
        $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

        $this->cacheRepository->put('test:stats:hits', 100);
        $this->cacheRepository->put('test:stats:misses', 50);

        $this->cache->resetStats();

        $this->assertNull($this->cacheRepository->get('test:stats:hits'));
        $this->assertNull($this->cacheRepository->get('test:stats:misses'));
    }
}

/**
 * Testable version of manager interface.
 */
final class TestableOpenFgaManager implements ManagerInterface
{
    private int $checkCount = 0;

    private array $checkResults = [];

    private int $listObjectsCount = 0;

    private array $listResults = [];

    private ?Exception $shouldThrow = null;

    public function batchCheck(array $checks, ?string $connection = null): array
    {
        $results = [];

        foreach ($checks as $index => $check) {
            [$user, $relation, $object] = $check;
            $key = "{$user}:{$relation}:{$object}";
            $results[$index] = $this->checkResults[$key] ?? false;
        }

        return $results;
    }

    public function check(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): bool {
        $this->checkCount++;

        if ($this->shouldThrow) {
            throw $this->shouldThrow;
        }

        $key = "{$user}:{$relation}:{$object}";

        return $this->checkResults[$key] ?? false;
    }

    public function getCheckCount(): int
    {
        return $this->checkCount;
    }

    public function getListObjectsCount(): int
    {
        return $this->listObjectsCount;
    }

    public function listObjects(
        string $user,
        string $relation,
        string $type,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        $this->listObjectsCount++;

        if ($this->shouldThrow) {
            throw $this->shouldThrow;
        }

        $key = "{$user}:{$relation}:{$type}";

        return $this->listResults[$key] ?? [];
    }

    public function setCheckResult(string $user, string $relation, string $object, bool $result): void
    {
        $this->checkResults["{$user}:{$relation}:{$object}"] = $result;
    }

    public function setListObjectsResult(string $user, string $relation, string $type, array $result): void
    {
        $this->listResults["{$user}:{$relation}:{$type}"] = $result;
    }

    public function setShouldThrow(?Exception $exception): void
    {
        $this->shouldThrow = $exception;
    }
}

/**
 * Test cache repository that tracks operations.
 */
final class TestCacheRepository extends Repository
{
    private array $lastPut = [];

    private int $putCount = 0;

    public function getLastPut(): array
    {
        return $this->lastPut;
    }

    public function getPutCount(): int
    {
        return $this->putCount;
    }

    public function increment($key, $value = 1)
    {
        $current = $this->get($key, 0);
        $this->put($key, $current + $value);

        return $current + $value;
    }

    public function put($key, $value, $ttl = null): bool
    {
        $this->putCount++;
        $this->lastPut = ['key' => $key, 'value' => $value, 'ttl' => $ttl];

        return parent::put($key, $value, $ttl);
    }
}

/**
 * Simple test logger.
 */
final class TestLogger implements LoggerInterface
{
    public array $logs = [];

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }
}
