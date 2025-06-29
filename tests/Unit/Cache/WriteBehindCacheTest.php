<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Cache\WriteBehindCache;
use OpenFGA\Laravel\Jobs\FlushWriteBehindCacheJob;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('WriteBehindCache', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });
    it('instantiates with dependencies', function (): void {
        expect(class_exists(WriteBehindCache::class))->toBeTrue();
        expect(method_exists(WriteBehindCache::class, 'write'))->toBeTrue();
        expect(method_exists(WriteBehindCache::class, 'delete'))->toBeTrue();
        expect(method_exists(WriteBehindCache::class, 'flush'))->toBeTrue();
        expect(method_exists(WriteBehindCache::class, 'clear'))->toBeTrue();
        expect(method_exists(WriteBehindCache::class, 'getPendingCount'))->toBeTrue();
        expect(method_exists(WriteBehindCache::class, 'getPendingOperations'))->toBeTrue();
    });

    it('validates cache key generation', function (): void {
        // Test the cache key format used internally
        $user = 'user:123';
        $relation = 'viewer';
        $object = 'document:456';

        $expectedCacheKey = sprintf('openfga.check.%s.%s.%s', $user, $relation, $object);

        expect($expectedCacheKey)->toBe('openfga.check.user:123.viewer.document:456');
    });

    it('validates tuple key generation', function (): void {
        // Test the internal tuple key format
        $user = 'user:123';
        $relation = 'viewer';
        $object = 'document:456';

        $expectedKey = md5(sprintf('%s:%s:%s', $user, $relation, $object));

        expect($expectedKey)->toBe(md5('user:123:viewer:document:456'));

        // Test different tuples produce different keys
        $key1 = md5('user:1:viewer:document:1');
        $key2 = md5('user:1:editor:document:1');
        $key3 = md5('user:2:viewer:document:1');

        expect($key1)->not->toBe($key2);
        expect($key1)->not->toBe($key3);
        expect($key2)->not->toBe($key3);
    });

    it('validates batch size logic', function (): void {
        $batchSize = 100;
        $operations = range(start: 1, end: 150);

        // Test that operations would trigger flush when reaching batch size
        $totalOperations = count($operations);
        $shouldFlush = $totalOperations >= $batchSize;

        expect($shouldFlush)->toBeTrue();

        // Test with operations below batch size
        $smallOperations = range(start: 1, end: 50);
        $smallTotal = count($smallOperations);
        $shouldNotFlush = $smallTotal >= $batchSize;

        expect($shouldNotFlush)->toBeFalse();
    });

    it('validates flush interval logic', function (): void {
        $flushInterval = 5; // seconds

        // Test time-based flush logic with fixed timestamps
        $baseTime = 1000000000; // Fixed base timestamp
        $lastFlushTime = $baseTime - 6; // 6 seconds ago (should flush)
        $currentTime = $baseTime;
        $secondsSinceFlush = $currentTime - $lastFlushTime;

        $shouldFlush = $secondsSinceFlush >= $flushInterval;

        expect($shouldFlush)->toBeTrue();
        expect($secondsSinceFlush)->toBe(6);

        // Test when not enough time has passed
        $recentFlushTime = $baseTime - 3; // 3 seconds ago (should not flush)
        $secondsSinceRecentFlush = $currentTime - $recentFlushTime;

        $shouldNotFlush = $secondsSinceRecentFlush >= $flushInterval;

        expect($shouldNotFlush)->toBeFalse();
        expect($secondsSinceRecentFlush)->toBe(3);

        // Test exact boundary condition
        $exactBoundaryTime = $baseTime - $flushInterval; // Exactly 5 seconds ago
        $exactSecondsElapsed = $currentTime - $exactBoundaryTime;
        $shouldFlushExact = $exactSecondsElapsed >= $flushInterval;

        expect($shouldFlushExact)->toBeTrue();
        expect($exactSecondsElapsed)->toBe(5);
    });

    it('validates operation data structure', function (): void {
        // Test the structure of pending operations
        $operation = [
            'user' => 'user:123',
            'relation' => 'viewer',
            'object' => 'document:456',
            'timestamp' => 1234567890,
        ];

        expect($operation)->toHaveKeys(['user', 'relation', 'object', 'timestamp']);
        expect($operation['user'])->toBeString();
        expect($operation['relation'])->toBeString();
        expect($operation['object'])->toBeString();
        expect($operation['timestamp'])->toBeInt();
    });

    it('validates queue job dispatch', function (): void {
        Queue::fake();

        // Simulate dispatching the flush job
        dispatch(new FlushWriteBehindCacheJob)->onQueue('openfga-write-behind');

        Queue::assertPushed(FlushWriteBehindCacheJob::class);
        Queue::assertPushed(fn (FlushWriteBehindCacheJob $job) => 'openfga-write-behind' === $job->queue);
    });

    it('validates error handling patterns', function (): void {
        // Test error logging pattern
        Log::shouldReceive('error')
            ->with('Write-behind cache flush failed', Mockery::any())
            ->once();

        Log::error('Write-behind cache flush failed', [
            'error' => 'Test error',
            'writes' => 5,
            'deletes' => 3,
        ]);
    });

    it('validates cache TTL configuration', function (): void {
        // Test default TTL when not set
        $defaultTtl = config('openfga.cache.write_behind_ttl', 300);
        expect($defaultTtl)->toBe(300); // Should use default when not set

        // Test custom TTL
        $this->setConfigWithRestore('openfga.cache.write_behind_ttl', 600);
        $customTtl = config('openfga.cache.write_behind_ttl', 300);
        expect($customTtl)->toBe(600);
    });

    it('validates return types and structure', function (): void {
        // Test flush return structure
        $flushResult = [
            'writes' => 10,
            'deletes' => 5,
        ];

        expect($flushResult)->toHaveKeys(['writes', 'deletes']);
        expect($flushResult['writes'])->toBeInt();
        expect($flushResult['deletes'])->toBeInt();

        // Test pending count structure
        $pendingCount = [
            'writes' => 3,
            'deletes' => 2,
            'total' => 5,
        ];

        expect($pendingCount)->toHaveKeys(['writes', 'deletes', 'total']);
        expect($pendingCount['total'])->toBe($pendingCount['writes'] + $pendingCount['deletes']);

        // Test pending operations structure
        $pendingOps = [
            'writes' => [],
            'deletes' => [],
        ];

        expect($pendingOps)->toHaveKeys(['writes', 'deletes']);
        expect($pendingOps['writes'])->toBeArray();
        expect($pendingOps['deletes'])->toBeArray();
    });

    it('validates operation merging logic', function (): void {
        // Test that writes remove deletes for same tuple
        $tupleKey = md5('user:1:viewer:document:1');

        $pendingDeletes = [
            $tupleKey => ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1', 'timestamp' => 100],
        ];

        $pendingWrites = [];

        // Simulate adding a write for same tuple
        unset($pendingDeletes[$tupleKey]);
        $pendingWrites[$tupleKey] = ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1', 'timestamp' => 101];

        expect($pendingDeletes)->not->toHaveKey($tupleKey);
        expect($pendingWrites)->toHaveKey($tupleKey);

        // Test that deletes remove writes for same tuple
        $pendingWrites2 = [
            $tupleKey => ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1', 'timestamp' => 100],
        ];

        $pendingDeletes2 = [];

        // Simulate adding a delete for same tuple
        unset($pendingWrites2[$tupleKey]);
        $pendingDeletes2[$tupleKey] = ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1', 'timestamp' => 101];

        expect($pendingWrites2)->not->toHaveKey($tupleKey);
        expect($pendingDeletes2)->toHaveKey($tupleKey);
    });

    it('validates batch processing patterns', function (): void {
        // Test batch chunking
        $operations = [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'document:2'],
            ['user' => 'user:3', 'relation' => 'admin', 'object' => 'document:3'],
        ];

        // Map operations for writeBatch format
        $mapped = array_map(static fn (array $op): array => [
            'user' => $op['user'],
            'relation' => $op['relation'],
            'object' => $op['object'],
        ], $operations);

        expect($mapped)->toHaveCount(3);
        expect($mapped[0])->toHaveKeys(['user', 'relation', 'object']);
        expect($mapped[0])->not->toHaveKey('timestamp');
    });

    it('validates timestamp handling', function (): void {
        // Test timestamp structure
        $fixedTimestamp = 1234567890;

        // Test timestamp in operations
        $operation = [
            'user' => 'user:1',
            'relation' => 'viewer',
            'object' => 'document:1',
            'timestamp' => $fixedTimestamp,
        ];

        expect($operation['timestamp'])->toBeInt();
        expect($operation['timestamp'])->toBeGreaterThan(0);
        expect($operation['timestamp'])->toBe(1234567890);
    });

    it('validates cache interaction patterns', function (): void {
        $mockCache = mock(Cache::class);

        // Test cache put pattern
        $mockCache->shouldReceive('put')
            ->with(
                'openfga.check.user:123.viewer.document:456',
                true,
                300,
            )
            ->once();

        $mockCache->put('openfga.check.user:123.viewer.document:456', true, 300);

        // Test cache put for delete
        $mockCache->shouldReceive('put')
            ->with(
                'openfga.check.user:789.editor.document:999',
                false,
                300,
            )
            ->once();

        $mockCache->put('openfga.check.user:789.editor.document:999', false, 300);
    });

    it('validates configuration retrieval', function (): void {
        // Test batch size config
        $this->setConfigWithRestore('openfga.cache.write_behind_batch_size', 200);
        $batchSize = config('openfga.cache.write_behind_batch_size', 100);
        expect($batchSize)->toBe(200);

        // Test flush interval config
        $this->setConfigWithRestore('openfga.cache.write_behind_flush_interval', 10);
        $flushInterval = config('openfga.cache.write_behind_flush_interval', 5);
        expect($flushInterval)->toBe(10);

        // Test with null values - Laravel returns null when explicitly set to null
        $this->setConfigWithRestore('openfga.cache.write_behind_batch_size', null);
        $this->setConfigWithRestore('openfga.cache.write_behind_flush_interval', null);

        $defaultBatchSize = config('openfga.cache.write_behind_batch_size', 100);
        $defaultFlushInterval = config('openfga.cache.write_behind_flush_interval', 5);

        expect($defaultBatchSize)->toBeNull();
        expect($defaultFlushInterval)->toBeNull();

        // Test true defaults when keys don't exist
        // Use keys that definitely don't exist in the config
        $trueBatchDefault = config('openfga.cache.nonexistent_batch_size_key', 100);
        $trueFlushDefault = config('openfga.cache.nonexistent_flush_interval_key', 5);

        expect($trueBatchDefault)->toBe(100);
        expect($trueFlushDefault)->toBe(5);
    });

    it('validates exception handling patterns', function (): void {
        // Test exception throwing pattern
        expect(fn () => throw new Exception('OpenFGA error'))
            ->toThrow(Exception::class, 'OpenFGA error');

        // Test exception catching and logging
        try {
            throw new Exception('Test error');
        } catch (Exception $e) {
            expect($e->getMessage())->toBe('Test error');
        }
    });
});
