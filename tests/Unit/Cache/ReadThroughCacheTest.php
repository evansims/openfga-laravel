<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Cache;

use Illuminate\Cache\ArrayStore;
use OpenFGA\Laravel\Cache\ReadThroughCache;
use OpenFGA\Laravel\Tests\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

uses(TestCase::class);

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
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: array_merge($this->defaultConfig, ['enabled' => false]),
            );

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

            $result = $this->cache->check('user:123', 'viewer', 'document:456');

            expect($result)->toBeTrue();
            expect($this->manager->getCheckCount())->toBe(1);
            expect($this->cacheRepository->getPutCount())->toBe(0);
        });

        it('bypasses cache with context', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

            $result = $this->cache->check('user:123', 'viewer', 'document:456', [], ['key' => 'value']);

            expect($result)->toBeTrue();
            expect($this->manager->getCheckCount())->toBe(1);

            // Verify nothing was cached
            expect($this->cacheRepository->getPutCount())->toBe(0);
        });

        it('bypasses cache with contextual tuples', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

            $result = $this->cache->check('user:123', 'viewer', 'document:456', ['tuple1']);

            expect($result)->toBeTrue();
            expect($this->manager->getCheckCount())->toBe(1);

            // Verify nothing was cached
            expect($this->cacheRepository->getPutCount())->toBe(0);
        });

        it('caches errors briefly', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

            $this->manager->setShouldThrow(new RuntimeException('OpenFGA error'));

            expect(fn () => $this->cache->check('user:123', 'viewer', 'document:456'))
                ->toThrow(RuntimeException::class, 'OpenFGA error');

            // Verify error was cached with short TTL
            $lastPut = $this->cacheRepository->getLastPut();
            expect($lastPut['ttl'])->toBe(10); // error_ttl
            expect($lastPut['value']['error'])->toBeTrue();
        });

        it('fetches from source on miss', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

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

            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: array_merge($this->defaultConfig, ['log_misses' => true]),
            );

            $this->manager->setCheckResult('user:123', 'viewer', 'document:456', true);

            $this->cache->check('user:123', 'viewer', 'document:456');

            expect($logger->hasLog('debug', 'OpenFGA cache miss'))->toBeTrue();
        });

        it('returns from cache on hit', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

            // Pre-populate cache
            $this->cacheRepository->put('test:check:user:123:viewer:document:456', ['value' => true, 'cached_at' => time()]);

            $result = $this->cache->check('user:123', 'viewer', 'document:456');

            expect($result)->toBeTrue();
            expect($this->manager->getCheckCount())->toBe(0);
        });

        it('uses negative ttl for false results', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

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
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

            $this->manager->setListObjectsResult('user:123', 'viewer', 'document', ['document:1', 'document:2']);

            $result = $this->cache->listObjects('user:123', 'viewer', 'document');

            expect($result)->toBe(['document:1', 'document:2']);
            expect($this->manager->getListObjectsCount())->toBe(1);
        });

        it('uses cache', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

            // Pre-populate cache
            $this->cacheRepository->put('test:list:user:123:viewer:document', ['value' => ['document:1', 'document:2'], 'cached_at' => time()]);

            $result = $this->cache->listObjects('user:123', 'viewer', 'document');

            expect($result)->toBe(['document:1', 'document:2']);
            expect($this->manager->getListObjectsCount())->toBe(0);
        });
    });

    describe('Statistics Operations', function (): void {
        it('gets stats', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

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
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

            $stats = $this->cache->getStats();

            expect($stats)->toBe([
                'hits' => 0,
                'misses' => 0,
                'hit_rate' => 0.0,
            ]);
        });

        it('resets stats', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

            $this->cacheRepository->put('test:stats:hits', 100);
            $this->cacheRepository->put('test:stats:misses', 50);

            $this->cache->resetStats();

            expect($this->cacheRepository->get('test:stats:hits'))->toBeNull();
            expect($this->cacheRepository->get('test:stats:misses'))->toBeNull();
        });
    });

    describe('Invalidation Operations', function (): void {
        it('returns zero when tags not enabled', function (): void {
            $this->cache = new ReadThroughCache(
                manager: $this->manager,
                config: $this->defaultConfig,
            );

            $invalidated = $this->cache->invalidate('user:123');

            expect($invalidated)->toBe(0);
        });
    });
});
