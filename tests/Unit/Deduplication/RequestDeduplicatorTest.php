<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as Cache;
use OpenFGA\Laravel\Deduplication\RequestDeduplicator;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('RequestDeduplicator', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->cache = mock(Cache::class);
        $this->deduplicator = new RequestDeduplicator($this->cache);
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(RequestDeduplicator::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('accepts cache instance and config in constructor', function (): void {
        $config = [
            'enabled' => false,
            'ttl' => 120,
            'in_flight_ttl' => 10,
            'prefix' => 'custom_dedup',
        ];

        $deduplicator = new RequestDeduplicator($this->cache, $config);
        expect($deduplicator)->toBeInstanceOf(RequestDeduplicator::class);
    });

    describe('execute method', function (): void {
        it('executes callback directly when deduplication is disabled', function (): void {
            $config = ['enabled' => false];
            $deduplicator = new RequestDeduplicator($this->cache, $config);

            $result = $deduplicator->execute('operation', ['param' => 'value'], static fn (): string => 'direct_result');

            expect($result)->toBe('direct_result');
        });

        it('returns cached result when available', function (): void {
            $this->cache->shouldReceive('get')
                ->once()
                ->andReturn(serialize('cached_result'));

            $callbackExecuted = false;
            $result = $this->deduplicator->execute(
                'operation',
                ['param' => 'value'],
                function () use (&$callbackExecuted): string {
                    $callbackExecuted = true;

                    return 'new_result';
                },
            );

            expect($result)->toBe('cached_result');
            expect($callbackExecuted)->toBeFalse();
        });

        it('executes callback and caches result when not cached', function (): void {
            $this->cache->shouldReceive('get')
                ->once()
                ->andReturn(null);

            $this->cache->shouldReceive('has')
                ->with(Mockery::pattern('/:inflight$/'))
                ->once()
                ->andReturn(false);

            $this->cache->shouldReceive('put')
                ->with(Mockery::pattern('/:inflight$/'), Mockery::any(), 5)
                ->once();

            $this->cache->shouldReceive('put')
                ->with(Mockery::not(Mockery::pattern('/:inflight$/')), serialize('new_result'), 60)
                ->once();

            $this->cache->shouldReceive('forget')
                ->with(Mockery::pattern('/:inflight$/'))
                ->once();

            $result = $this->deduplicator->execute('operation', ['param' => 'value'], static fn (): string => 'new_result');

            expect($result)->toBe('new_result');
        });

        it('deduplicates concurrent requests', function (): void {
            // First check returns null (not cached)
            $this->cache->shouldReceive('get')
                ->once()
                ->andReturn(null);

            // Check if in-flight
            $this->cache->shouldReceive('has')
                ->with(Mockery::pattern('/:inflight$/'))
                ->andReturn(true, false); // First call: in-flight, second: not in-flight

            // When waiting for in-flight, it checks cache again
            $this->cache->shouldReceive('get')
                ->andReturn(null, serialize('completed_result')); // First: still processing, second: completed

            $result = $this->deduplicator->execute('operation', ['param' => 'value'], static fn (): string => 'should_not_execute');

            expect($result)->toBe('completed_result');
        });

        it('handles exceptions by removing in-flight marker', function (): void {
            $this->cache->shouldReceive('get')
                ->once()
                ->andReturn(null);

            $this->cache->shouldReceive('has')
                ->with(Mockery::pattern('/:inflight$/'))
                ->once()
                ->andReturn(false);

            $this->cache->shouldReceive('put')
                ->with(Mockery::pattern('/:inflight$/'), Mockery::any(), 5)
                ->once();

            $this->cache->shouldReceive('forget')
                ->with(Mockery::pattern('/:inflight$/'))
                ->once();

            expect(fn () => $this->deduplicator->execute(
                'operation',
                ['param' => 'value'],
                static fn () => throw new Exception('Test exception'),
            ))->toThrow(Exception::class, 'Test exception');
        });
    });

    describe('key generation', function (): void {
        it('generates consistent keys for same parameters', function (): void {
            $reflection = new ReflectionClass($this->deduplicator);
            $method = $reflection->getMethod('generateKey');
            $method->setAccessible(true);

            $params1 = ['b' => 2, 'a' => 1, 'c' => 3];
            $params2 = ['a' => 1, 'c' => 3, 'b' => 2];

            $key1 = $method->invoke($this->deduplicator, 'operation', $params1);
            $key2 = $method->invoke($this->deduplicator, 'operation', $params2);

            expect($key1)->toBe($key2);
            expect($key1)->toStartWith('openfga_dedup:operation:');
        });

        it('generates different keys for different operations', function (): void {
            $reflection = new ReflectionClass($this->deduplicator);
            $method = $reflection->getMethod('generateKey');
            $method->setAccessible(true);

            $params = ['param' => 'value'];

            $key1 = $method->invoke($this->deduplicator, 'operation1', $params);
            $key2 = $method->invoke($this->deduplicator, 'operation2', $params);

            expect($key1)->not->toBe($key2);
        });

        it('generates different keys for different parameters', function (): void {
            $reflection = new ReflectionClass($this->deduplicator);
            $method = $reflection->getMethod('generateKey');
            $method->setAccessible(true);

            $key1 = $method->invoke($this->deduplicator, 'operation', ['param' => 'value1']);
            $key2 = $method->invoke($this->deduplicator, 'operation', ['param' => 'value2']);

            expect($key1)->not->toBe($key2);
        });
    });

    describe('statistics', function (): void {
        it('tracks total requests', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);
            $this->cache->shouldReceive('has')->andReturn(false);
            $this->cache->shouldReceive('put')->andReturnNull();
            $this->cache->shouldReceive('forget')->andReturnNull();

            $this->deduplicator->execute('op1', [], static fn (): string => 'result1');
            $this->deduplicator->execute('op2', [], static fn (): string => 'result2');

            $stats = $this->deduplicator->getStats();
            expect($stats['total_requests'])->toBe(2);
        });

        it('tracks cache hits', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(serialize('cached'), serialize('cached'));

            $this->deduplicator->execute('op', [], static fn (): string => 'new');
            $this->deduplicator->execute('op', [], static fn (): string => 'new');

            $stats = $this->deduplicator->getStats();
            expect($stats['cache_hits'])->toBe(2);
            expect($stats['cache_misses'])->toBe(0);
        });

        it('tracks cache misses', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);
            $this->cache->shouldReceive('has')->andReturn(false);
            $this->cache->shouldReceive('put')->andReturnNull();
            $this->cache->shouldReceive('forget')->andReturnNull();

            $this->deduplicator->execute('op', [], static fn (): string => 'result');

            $stats = $this->deduplicator->getStats();
            expect($stats['cache_misses'])->toBe(1);
        });

        it('tracks deduplicated requests', function (): void {
            // Set up expectations for a deduplicated request scenario
            // Initial cache check - not cached
            $this->cache->shouldReceive('get')
                ->with(Mockery::pattern('/^openfga_dedup:op:/'))
                ->once()
                ->andReturn(null);

            // Check if in-flight - yes
            $this->cache->shouldReceive('has')
                ->with(Mockery::pattern('/:inflight$/'))
                ->once()
                ->andReturn(true);

            // During waitForInFlight, it will check cache and in-flight status multiple times
            // Set up a sequence of responses that eventually returns a cached result
            $this->cache->shouldReceive('get')
                ->with(Mockery::pattern('/^openfga_dedup:op:/'))
                ->andReturn(null, serialize('result')); // First check: still processing, second: found result

            $this->cache->shouldReceive('has')
                ->with(Mockery::pattern('/:inflight$/'))
                ->andReturn(true); // Still in-flight during first loop

            $result = $this->deduplicator->execute('op', [], static fn (): string => 'should_not_execute');

            expect($result)->toBe('result');

            $stats = $this->deduplicator->getStats();
            expect($stats['deduplicated'])->toBe(1);
        });

        it('calculates hit rate correctly', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(serialize('cached'), null, serialize('cached'));
            $this->cache->shouldReceive('has')->andReturn(false);
            $this->cache->shouldReceive('put')->andReturnNull();
            $this->cache->shouldReceive('forget')->andReturnNull();

            // 2 hits, 1 miss
            $this->deduplicator->execute('op1', [], static fn (): string => 'result');
            $this->deduplicator->execute('op2', [], static fn (): string => 'result');
            $this->deduplicator->execute('op3', [], static fn (): string => 'result');

            $stats = $this->deduplicator->getStats();
            expect($stats['hit_rate'])->toBe(66.67);
        });

        it('calculates deduplication rate correctly', function (): void {
            // First request - cache hit
            $this->cache->shouldReceive('get')
                ->with(Mockery::pattern('/^openfga_dedup:op1:/'))
                ->once()
                ->andReturn(serialize('cached_result'));

            $this->deduplicator->execute('op1', [], static fn (): string => 'result');

            // Second request - deduplicated (in-flight)
            $this->cache->shouldReceive('get')
                ->with(Mockery::pattern('/^openfga_dedup:op2:/'))
                ->once()
                ->andReturn(null);

            $this->cache->shouldReceive('has')
                ->with(Mockery::pattern('/:inflight$/'))
                ->once()
                ->andReturn(true);

            // During wait, it finds the result in cache
            $this->cache->shouldReceive('get')
                ->with(Mockery::pattern('/^openfga_dedup:op2:/'))
                ->andReturn(serialize('result'));

            $this->cache->shouldReceive('has')
                ->with(Mockery::pattern('/:inflight$/'))
                ->andReturn(true);

            $this->deduplicator->execute('op2', [], static fn (): string => 'result');

            $stats = $this->deduplicator->getStats();
            expect($stats['total_requests'])->toBe(2);
            expect($stats['deduplicated'])->toBe(1);
            expect($stats['deduplication_rate'])->toBe(50.0);
        });

        it('handles zero requests gracefully', function (): void {
            $stats = $this->deduplicator->getStats();

            expect($stats['total_requests'])->toBe(0);
            expect($stats['hit_rate'])->toBe(0.0);
            expect($stats['deduplication_rate'])->toBe(0.0);
        });
    });

    describe('resetStats method', function (): void {
        it('resets all statistics to zero', function (): void {
            // Generate some stats
            $this->cache->shouldReceive('get')->andReturn(serialize('cached'));
            $this->deduplicator->execute('op', [], static fn (): string => 'result');

            // Verify stats exist
            $stats = $this->deduplicator->getStats();
            expect($stats['total_requests'])->toBe(1);
            expect($stats['cache_hits'])->toBe(1);

            // Reset stats
            $this->deduplicator->resetStats();

            // Verify reset
            $stats = $this->deduplicator->getStats();
            expect($stats['total_requests'])->toBe(0);
            expect($stats['cache_hits'])->toBe(0);
            expect($stats['cache_misses'])->toBe(0);
            expect($stats['deduplicated'])->toBe(0);
        });
    });

    describe('clear method', function (): void {
        it('clears in-flight tracking', function (): void {
            // The clear method should clear internal state
            $this->deduplicator->clear();

            // We can't directly test private property, but we can verify behavior
            expect($this->deduplicator)->toBeInstanceOf(RequestDeduplicator::class);
        });
    });

    describe('custom configuration', function (): void {
        it('uses custom TTL values', function (): void {
            $config = [
                'ttl' => 120,
                'in_flight_ttl' => 10,
            ];

            $deduplicator = new RequestDeduplicator($this->cache, $config);

            $this->cache->shouldReceive('get')->andReturn(null);
            $this->cache->shouldReceive('has')->andReturn(false);

            // Should use custom TTL values
            $this->cache->shouldReceive('put')
                ->with(Mockery::pattern('/:inflight$/'), Mockery::any(), 10)
                ->once();

            $this->cache->shouldReceive('put')
                ->with(Mockery::not(Mockery::pattern('/:inflight$/')), Mockery::any(), 120)
                ->once();

            $this->cache->shouldReceive('forget')->once();

            $deduplicator->execute('op', [], static fn (): string => 'result');
        });

        it('uses custom prefix', function (): void {
            $config = ['prefix' => 'custom_prefix'];
            $deduplicator = new RequestDeduplicator($this->cache, $config);

            $reflection = new ReflectionClass($deduplicator);
            $method = $reflection->getMethod('generateKey');
            $method->setAccessible(true);

            $key = $method->invoke($deduplicator, 'operation', ['param' => 'value']);

            expect($key)->toStartWith('custom_prefix:operation:');
        });
    });

    describe('timeout handling', function (): void {
        it('throws exception when waiting for in-flight request times out', function (): void {
            $config = ['in_flight_ttl' => 0.001]; // Very short timeout
            $deduplicator = new RequestDeduplicator($this->cache, $config);

            $this->cache->shouldReceive('get')->andReturn(null);
            $this->cache->shouldReceive('has')
                ->with(Mockery::pattern('/:inflight$/'))
                ->andReturn(true); // Always in-flight

            expect(static fn () => $deduplicator->execute('op', [], static fn (): string => 'result'))
                ->toThrow(RuntimeException::class, 'Timeout waiting for in-flight request');
        });

        it('throws exception when in-flight request fails', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);
            $this->cache->shouldReceive('has')
                ->with(Mockery::pattern('/:inflight$/'))
                ->andReturn(true, false); // Was in-flight, then not

            // After in-flight is gone, cache check still returns null
            $this->cache->shouldReceive('get')->andReturn(null);

            expect(fn () => $this->deduplicator->execute('op', [], static fn (): string => 'result'))
                ->toThrow(RuntimeException::class, 'In-flight request failed or timed out');
        });
    });
});
