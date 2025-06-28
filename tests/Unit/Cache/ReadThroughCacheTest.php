<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Cache;

use Exception;
use Illuminate\Cache\{ArrayStore, Repository};
use OpenFGA\Laravel\Cache\ReadThroughCache;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function expect;

describe('ReadThroughCache', function (): void {
    beforeEach(function (): void {
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
    });

    describe('Check Operations', function (): void {
        it('bypasses cache when disabled', function (): void {
            $this->cache = new ReadThroughCache($this->manager, array_merge($this->defaultConfig, [
                'enabled' => false,
            ]));

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

            $result = $this->cache->check('user:123', 'viewer', 'document:456');

            expect($result)->toBeTrue();
            expect($this->manager->getCheckCount())->toBe(1);
            expect($this->cacheRepository->getPutCount())->toBe(0);
        });

        it('bypasses cache with context', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

            $result = $this->cache->check('user:123', 'viewer', 'document:456', [], ['key' => 'value']);

            expect($result)->toBeTrue();
            expect($this->manager->getCheckCount())->toBe(1);

            // Verify nothing was cached
            expect($this->cacheRepository->getPutCount())->toBe(0);
        });

        it('bypasses cache with contextual tuples', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

            $result = $this->cache->check('user:123', 'viewer', 'document:456', ['tuple1']);

            expect($result)->toBeTrue();
            expect($this->manager->getCheckCount())->toBe(1);

            // Verify nothing was cached
            expect($this->cacheRepository->getPutCount())->toBe(0);
        });

        it('caches errors briefly', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $this->manager->setShouldThrow(new RuntimeException('OpenFGA error'));

            expect(fn () => $this->cache->check('user:123', 'viewer', 'document:456'))
                ->toThrow(RuntimeException::class, 'OpenFGA error');

            // Verify error was cached with short TTL
            $lastPut = $this->cacheRepository->getLastPut();
            expect($lastPut['ttl'])->toBe(10); // error_ttl
            expect($lastPut['value']['error'])->toBeTrue();
        });

        it('fetches from source on miss', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

            $result = $this->cache->check('user:123', 'viewer', 'document:456');

            expect($result)->toBeTrue();
            expect($this->manager->getCheckCount())->toBe(1);

            // Verify it was cached
            $cached = $this->cacheRepository->get('test:check:user:123:viewer:document:456');
            expect($cached)->not->toBeNull();
            expect($cached['value'])->toBeTrue();
        });

        it('logs misses when enabled', function (): void {
            $logger = new TestLogger;
            $this->app->singleton(LoggerInterface::class, fn () => $logger);

            $this->cache = new ReadThroughCache($this->manager, array_merge($this->defaultConfig, [
                'log_misses' => true,
            ]));

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

            $this->cache->check('user:123', 'viewer', 'document:456');

            expect($logger->logs)->toHaveCount(1);
            expect($logger->logs[0]['level'])->toBe('debug');
            expect($logger->logs[0]['message'])->toBe('OpenFGA cache miss');
        });

        it('returns from cache on hit', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            // Pre-populate cache
            $this->cacheRepository->put('test:check:user:123:viewer:document:456', ['value' => true, 'cached_at' => time()]);

            $result = $this->cache->check('user:123', 'viewer', 'document:456');

            expect($result)->toBeTrue();
            expect($this->manager->getCheckCount())->toBe(0);
        });

        it('uses negative ttl for false results', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', false);

            $result = $this->cache->check('user:123', 'viewer', 'document:456');

            expect($result)->toBeFalse();

            // Verify TTL was set correctly (we'll check the last put call)
            $lastPut = $this->cacheRepository->getLastPut();
            expect($lastPut['ttl'])->toBe(60); // negative_ttl
        });
    });

    describe('List Objects Operations', function (): void {
        it('fetches on miss', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $this->manager->setListObjectsResult('user:123', 'viewer', 'document', ['document:1', 'document:2']);

            $result = $this->cache->listObjects('user:123', 'viewer', 'document');

            expect($result)->toBe(['document:1', 'document:2']);
            expect($this->manager->getListObjectsCount())->toBe(1);
        });

        it('uses cache', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            // Pre-populate cache
            $this->cacheRepository->put('test:list:user:123:viewer:document', ['value' => ['document:1', 'document:2'], 'cached_at' => time()]);

            $result = $this->cache->listObjects('user:123', 'viewer', 'document');

            expect($result)->toBe(['document:1', 'document:2']);
            expect($this->manager->getListObjectsCount())->toBe(0);
        });
    });

    describe('Statistics Operations', function (): void {
        it('gets stats', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $this->cacheRepository->put('test:stats:hits', 75);
            $this->cacheRepository->put('test:stats:misses', 25);

            $stats = $this->cache->getStats();

            expect($stats)->toBe([
                'hits' => 75,
                'misses' => 25,
                'hit_rate' => 75.0,
            ]);
        });

        it('handles zero total in stats', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $stats = $this->cache->getStats();

            expect($stats)->toBe([
                'hits' => 0,
                'misses' => 0,
                'hit_rate' => 0.0,
            ]);
        });

        it('resets stats', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $this->cacheRepository->put('test:stats:hits', 100);
            $this->cacheRepository->put('test:stats:misses', 50);

            $this->cache->resetStats();

            expect($this->cacheRepository->get('test:stats:hits'))->toBeNull();
            expect($this->cacheRepository->get('test:stats:misses'))->toBeNull();
        });
    });

    describe('Invalidation Operations', function (): void {
        it('returns zero when tags not enabled', function (): void {
            $this->cache = new ReadThroughCache($this->manager, $this->defaultConfig);

            $invalidated = $this->cache->invalidate('user:123');

            expect($invalidated)->toBe(0);
        });
    });
});

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
