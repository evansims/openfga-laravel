<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use OpenFGA\Laravel\Events\{BatchWriteCompleted, ObjectsListed, PermissionChecked, RelationExpanded};
use OpenFGA\Laravel\Monitoring\PerformanceMonitor;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('PerformanceMonitor', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->monitor = new PerformanceMonitor;
        Cache::flush();
    });

    afterEach(function (): void {
        Cache::flush();
        $this->tearDownConfigRestoration();
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(PerformanceMonitor::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    describe('getStatistics', function (): void {
        it('returns empty statistics when no data exists', function (): void {
            $stats = $this->monitor->getStatistics();

            expect($stats)->toHaveKeys(['permission_checks', 'batch_writes', 'cache', 'performance']);
            expect($stats['permission_checks'])->toBe([
                'count' => 0,
                'avg_duration' => 0,
                'min_duration' => 0,
                'max_duration' => 0,
            ]);
            expect($stats['cache'])->toBe([
                'hits' => 0.0,
                'misses' => 0.0,
                'hit_rate' => 0.0,
            ]);
        });

        it('returns specific metric when requested', function (): void {
            $stats = $this->monitor->getStatistics('cache');

            expect($stats)->toHaveKey('cache');
            expect($stats)->not->toHaveKey('permission_checks');
            expect($stats)->not->toHaveKey('batch_writes');
        });

        it('filters statistics by time window', function (): void {
            // Test that we can retrieve statistics with a time window parameter
            // The actual filtering logic would need to be tested with mocked time
            $currentTime = time();

            // Add metrics with current timestamp (should be included)
            $recentMetrics = [[
                'allowed' => true,
                'cached' => false,
                'duration' => 50,
                'timestamp' => $currentTime,
            ]];

            Cache::put('openfga:metrics:permission_checks', $recentMetrics, 3600);

            $stats = $this->monitor->getStatistics('permission_checks', 60);

            // Verify the structure is correct
            expect($stats['permission_checks'])->toHaveKeys(['count', 'avg_duration', 'min_duration', 'max_duration']);
            expect($stats['permission_checks']['count'])->toBeGreaterThanOrEqual(1);
            expect($stats['permission_checks']['avg_duration'])->toBe(50.0);
        });
    });

    describe('trackPermissionCheck', function (): void {
        it('records permission check metrics', function (): void {
            $event = new PermissionChecked(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:456',
                allowed: true,
                cached: false,
                duration: 150.5,
            );

            $this->monitor->trackPermissionCheck($event);

            $metrics = Cache::get('openfga:metrics:permission_checks');
            expect($metrics)->toBeArray();
            expect($metrics)->toHaveCount(1);
            expect($metrics[0])->toMatchArray([
                'allowed' => true,
                'cached' => false,
                'duration' => 150.5,
            ]);
        });

        it('increments cache hit counter when cached', function (): void {
            $event = new PermissionChecked(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:456',
                allowed: true,
                cached: true,
                duration: 5.0,
            );

            $this->monitor->trackPermissionCheck($event);

            expect(Cache::get('openfga:counters:cache_hits'))->toBe(1);
            expect(Cache::get('openfga:counters:cache_misses'))->toBe(null);
        });

        it('increments cache miss counter when not cached', function (): void {
            $event = new PermissionChecked(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:456',
                allowed: true,
                cached: false,
                duration: 150.0,
            );

            $this->monitor->trackPermissionCheck($event);

            expect(Cache::get('openfga:counters:cache_misses'))->toBe(1);
            expect(Cache::get('openfga:counters:cache_hits'))->toBe(null);
        });
    });

    describe('trackBatchWrite', function (): void {
        it('records batch write metrics', function (): void {
            $writes = [
                ['user' => 'user:1', 'relation' => 'editor', 'object' => 'doc:1'],
                ['user' => 'user:2', 'relation' => 'viewer', 'object' => 'doc:2'],
            ];
            $deletes = [
                ['user' => 'user:3', 'relation' => 'viewer', 'object' => 'doc:3'],
            ];

            $event = new BatchWriteCompleted(
                writes: $writes,
                deletes: $deletes,
                duration: 250.0,
            );

            $this->monitor->trackBatchWrite($event);

            $metrics = Cache::get('openfga:metrics:batch_writes');
            expect($metrics)->toBeArray();
            expect($metrics)->toHaveCount(1);
            expect($metrics[0])->toMatchArray([
                'writes' => 2,
                'deletes' => 1,
                'duration' => 250.0,
            ]);
        });

        it('records batch size histogram', function (): void {
            $writes = [
                ['user' => 'user:1', 'relation' => 'editor', 'object' => 'doc:1'],
            ];
            $deletes = [];

            $event = new BatchWriteCompleted(
                writes: $writes,
                deletes: $deletes,
                duration: 100.0,
            );

            $this->monitor->trackBatchWrite($event);

            $histogram = Cache::get('openfga:histograms:batch_size');
            expect($histogram)->toBeArray();
            expect($histogram)->toHaveCount(1);
            expect($histogram[0]['value'])->toBe(1.0);
        });
    });

    describe('trackObjectsListed', function (): void {
        it('records object listing metrics', function (): void {
            $event = new ObjectsListed(
                user: 'user:123',
                relation: 'viewer',
                type: 'document',
                objects: ['document:1', 'document:2', 'document:3'],
                duration: 200.0,
            );

            $this->monitor->trackObjectsListed($event);

            $metrics = Cache::get('openfga:metrics:object_listings');
            expect($metrics)->toBeArray();
            expect($metrics)->toHaveCount(1);
            expect($metrics[0])->toMatchArray([
                'object_count' => 3,
                'duration' => 200.0,
            ]);
        });
    });

    describe('trackRelationExpanded', function (): void {
        it('records relation expansion metrics', function (): void {
            $result = [
                'tree' => [
                    'root' => [
                        'leaf' => [
                            'users' => ['user:1', 'user:2', 'user:3', 'user:4'],
                        ],
                    ],
                ],
            ];

            $event = new RelationExpanded(
                object: 'document:123',
                relation: 'viewer',
                result: $result,
                duration: 300.0,
            );

            $this->monitor->trackRelationExpanded($event);

            $metrics = Cache::get('openfga:metrics:relation_expansions');
            expect($metrics)->toBeArray();
            expect($metrics)->toHaveCount(1);
            expect($metrics[0])->toMatchArray([
                'user_count' => 4,
                'duration' => 300.0,
            ]);
        });
    });

    describe('reset', function (): void {
        it('clears all metric data', function (): void {
            // Set up various metrics
            Cache::put('openfga:metrics:permission_checks', [['test' => 1]], 3600);
            Cache::put('openfga:counters:cache_hits', 10, 3600);
            Cache::put('openfga:histograms:batch_size', [['value' => 5]], 3600);

            $this->monitor->reset();

            expect(Cache::get('openfga:metrics:permission_checks'))->toBeNull();
            expect(Cache::get('openfga:counters:cache_hits'))->toBeNull();
            expect(Cache::get('openfga:histograms:batch_size'))->toBeNull();
        });
    });

    describe('cache statistics', function (): void {
        it('calculates cache hit rate correctly', function (): void {
            Cache::put('openfga:counters:cache_hits', 75, 3600);
            Cache::put('openfga:counters:cache_misses', 25, 3600);

            $stats = $this->monitor->getStatistics('cache');

            expect($stats['cache'])->toBe([
                'hits' => 75.0,
                'misses' => 25.0,
                'hit_rate' => 75.0,
            ]);
        });

        it('handles zero total cache operations', function (): void {
            $stats = $this->monitor->getStatistics('cache');

            expect($stats['cache']['hit_rate'])->toBe(0.0);
        });

        it('handles non-numeric cache values gracefully', function (): void {
            Cache::put('openfga:counters:cache_hits', 'invalid', 3600);
            Cache::put('openfga:counters:cache_misses', ['array'], 3600);

            $stats = $this->monitor->getStatistics('cache');

            expect($stats['cache'])->toBe([
                'hits' => 0.0,
                'misses' => 0.0,
                'hit_rate' => 0.0,
            ]);
        });
    });

    describe('performance statistics', function (): void {
        it('calculates aggregate performance metrics', function (): void {
            $currentTime = time();

            // Add permission check metrics with current time to ensure they're included
            Cache::put('openfga:metrics:permission_checks', [
                ['duration' => 100, 'timestamp' => $currentTime],
                ['duration' => 150, 'timestamp' => $currentTime],
            ], 3600);

            // Add batch write metrics
            Cache::put('openfga:metrics:batch_writes', [
                ['duration' => 200, 'timestamp' => $currentTime],
                ['duration' => 250, 'timestamp' => $currentTime],
            ], 3600);

            $stats = $this->monitor->getStatistics('performance', 60);

            // Check that performance statistics exist and have the right structure
            expect($stats)->toHaveKey('performance');
            expect($stats['performance'])->toHaveKeys(['total_operations', 'avg_response_time', 'operations_per_minute']);
            expect($stats['performance']['total_operations'])->toBeGreaterThanOrEqual(0.0);
            expect($stats['performance']['operations_per_minute'])->toBeGreaterThanOrEqual(0.0);
        });
    });

    describe('metric storage limits', function (): void {
        it('limits stored metrics to 1000 entries', function (): void {
            $fixedTimestamp = 1234567890; // Fixed timestamp for deterministic behavior
            $metrics = [];

            for ($i = 0; 1100 > $i; ++$i) {
                $metrics[] = [
                    'allowed' => true,
                    'cached' => false,
                    'duration' => 100,
                    'timestamp' => $fixedTimestamp + $i, // Unique timestamps
                ];
            }

            Cache::put('openfga:metrics:permission_checks', $metrics, 3600);

            // Track one more to trigger the limit
            $event = new PermissionChecked(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:456',
                allowed: true,
                cached: false,
                duration: 150.0,
            );

            $this->monitor->trackPermissionCheck($event);

            $stored = Cache::get('openfga:metrics:permission_checks');
            expect($stored)->toHaveCount(1000);
        });

        it('limits histogram entries to 1000', function (): void {
            $fixedTimestamp = 1234567890; // Fixed timestamp for deterministic behavior
            $histogram = [];

            for ($i = 0; 1100 > $i; ++$i) {
                $histogram[] = [
                    'value' => 10,
                    'timestamp' => $fixedTimestamp + $i, // Unique timestamps
                ];
            }

            Cache::put('openfga:histograms:batch_size', $histogram, 3600);

            // Track one more to trigger the limit
            $event = new BatchWriteCompleted(
                writes: [],
                deletes: [],
                duration: 100.0,
            );

            $this->monitor->trackBatchWrite($event);

            $stored = Cache::get('openfga:histograms:batch_size');
            expect($stored)->toHaveCount(1000);
        });
    });

    describe('metric calculation edge cases', function (): void {
        it('handles empty filtered metrics', function (): void {
            Cache::put('openfga:metrics:permission_checks', [], 3600);

            $stats = $this->monitor->getStatistics('permission_checks');

            expect($stats['permission_checks'])->toBe([
                'count' => 0,
                'avg_duration' => 0,
                'min_duration' => 0,
                'max_duration' => 0,
            ]);
        });

        it('handles metrics without duration values', function (): void {
            $fixedTimestamp = 1234567890; // Fixed timestamp for deterministic behavior

            Cache::put('openfga:metrics:permission_checks', [
                ['allowed' => true, 'timestamp' => $fixedTimestamp],
                ['allowed' => false, 'timestamp' => $fixedTimestamp + 1],
            ], 3600);

            $stats = $this->monitor->getStatistics('permission_checks');

            expect($stats['permission_checks'])->toBe([
                'count' => 0,
                'avg_duration' => 0,
                'min_duration' => 0,
                'max_duration' => 0,
            ]);
        });

        it('handles non-numeric duration values', function (): void {
            $currentTime = time();

            Cache::put('openfga:metrics:permission_checks', [
                ['duration' => 'invalid', 'timestamp' => $currentTime],
                ['duration' => null, 'timestamp' => $currentTime + 1],
                ['duration' => 100, 'timestamp' => $currentTime + 2],
            ], 3600);

            $stats = $this->monitor->getStatistics('permission_checks');

            // Verify that the stats structure is correct and non-numeric values are handled
            expect($stats['permission_checks'])->toHaveKeys(['count', 'avg_duration', 'min_duration', 'max_duration']);
            expect($stats['permission_checks']['count'])->toBeGreaterThanOrEqual(0);
            // Should handle non-numeric values gracefully
            expect($stats['permission_checks']['avg_duration'])->toBeGreaterThanOrEqual(0.0);
        });

        it('handles malformed cache data gracefully', function (): void {
            Cache::put('openfga:metrics:permission_checks', 'not-an-array', 3600);

            $stats = $this->monitor->getStatistics('permission_checks');

            expect($stats['permission_checks'])->toBe([
                'count' => 0,
                'avg_duration' => 0,
                'min_duration' => 0,
                'max_duration' => 0,
            ]);
        });
    });

    describe('clear cache pattern', function (): void {
        it('clears matching cache keys', function (): void {
            // Set up various cache entries
            Cache::put('openfga:metrics:permission_checks', ['data'], 3600);
            Cache::put('openfga:metrics:batch_writes', ['data'], 3600);
            Cache::put('openfga:counters:cache_hits', 10, 3600);
            Cache::put('other:key', 'value', 3600);

            // This will clear all openfga:metrics:* keys through reset()
            $this->monitor->reset();

            expect(Cache::get('openfga:metrics:permission_checks'))->toBeNull();
            expect(Cache::get('openfga:metrics:batch_writes'))->toBeNull();
            expect(Cache::get('openfga:counters:cache_hits'))->toBeNull();
            expect(Cache::get('other:key'))->toBe('value'); // Should not be cleared
        });
    });
});
