<?php

declare(strict_types=1);

use OpenFGA\Laravel\Testing\{FakesOpenFga, MeasuresPerformance};
use OpenFGA\Laravel\Tests\Support\FeatureTestCase;

uses(FeatureTestCase::class, FakesOpenFga::class, MeasuresPerformance::class);

describe('Performance Testing', function (): void {
    beforeEach(function (): void {
        $this->fakeOpenFga();
        $this->setUpPerformanceTesting();
    });

    afterEach(function (): void {
        $this->tearDownPerformanceTesting();
    });

    it('assert faster than', function (): void {
        $fake = $this->getFakeOpenFga();

        // Direct check should be faster than checking with non-existent permission
        $this->assertFasterThan(
            static function () use ($fake): void {
                // This returns false immediately as permission doesn't exist
                $fake->check('user:1', 'admin', 'system:main');
            },
            static function () use ($fake): void {
                // Add many permissions then check
                for ($i = 1; 100 >= $i; ++$i) {
                    $fake->grant('user:' . $i, 'viewer', 'doc:' . $i);
                }
                $fake->check('user:50', 'viewer', 'doc:50');
            },
            'Checking non-existent permission should be faster',
            10,
        );

        // Add a simple assertion to avoid risky test warning
        expect(true)->toBeTrue();
    });

    it('batch operations performance', function (): void {
        $fake = $this->getFakeOpenFga();

        // Benchmark batch writes
        $results = $this->benchmark('batch-write-100', static function () use ($fake): void {
            $writes = [];

            for ($i = 1; 100 >= $i; ++$i) {
                $writes[] = [
                    'user' => 'user:' . $i,
                    'relation' => 'viewer',
                    'object' => 'document:' . $i,
                ];
            }
            $fake->writeBatch($writes);
        }, 10);

        expect($results)->toHaveKey('mean');
        expect($results)->toHaveKey('median');
        expect($results)->toHaveKey('p95');

        // Mean time should be reasonable for 100 operations
        expect($results['mean'])->toBeLessThan(50);
    });

    it('comparing single vs batch checks', function (): void {
        $fake = $this->getFakeOpenFga();

        // Set up permissions
        for ($i = 1; 10 >= $i; ++$i) {
            $fake->grant('user:1', 'viewer', 'document:' . $i);
        }

        // Compare single checks vs batch check
        $comparison = $this->comparePerformance(
            'single-checks',
            static function () use ($fake): void {
                for ($i = 1; 10 >= $i; ++$i) {
                    $fake->check('user:1', 'viewer', 'document:' . $i);
                }
            },
            'batch-check',
            static function () use ($fake): void {
                $checks = [];

                for ($i = 1; 10 >= $i; ++$i) {
                    $checks[] = [
                        'user' => 'user:1',
                        'relation' => 'viewer',
                        'object' => 'document:' . $i,
                    ];
                }

                // Simulate batch check by checking all at once
                foreach ($checks as $check) {
                    $fake->check($check['user'], $check['relation'], $check['object']);
                }
            },
            20,
        );

        expect($comparison)->toHaveKey('conclusion');
        expect($comparison)->toHaveKey('ratio');
    });

    it('detailed performance report', function (): void {
        $perf = $this->performance()->enableDetailed();

        $fake = $this->getFakeOpenFga();

        // Perform various operations
        $perf->measureCheck('user:1', 'admin', 'organization:acme', 'Admin check');

        $fake->grant('user:1', 'admin', 'organization:acme');
        $perf->measureCheck('user:1', 'admin', 'organization:acme', 'Admin check (granted)');

        $perf->measureWrite(
            [['user' => 'user:2', 'relation' => 'member', 'object' => 'team:engineering']],
            [],
            'Add team member',
        );

        $perf->benchmark('bulk-checks', static function () use ($fake): void {
            for ($i = 1; 5 >= $i; ++$i) {
                $fake->check('user:' . $i, 'member', 'organization:acme');
            }
        }, 20);

        // Get the report
        $report = $this->getPerformanceReport();

        expect($report)->toContain('Performance Test Report');
        expect($report)->toContain('Summary:');
        expect($report)->toContain('Detailed Metrics:');
        expect($report)->toContain('Admin check');
        expect($report)->toContain('bulk-checks');
    });

    it('memory usage assertion', function (): void {
        $fake = $this->getFakeOpenFga();

        // Assert memory usage stays below 1MB
        $this->assertMemoryUsageBelow(1024 * 1024, static function () use ($fake): void {
            // Create 1000 permissions
            for ($i = 1; 1000 >= $i; ++$i) {
                $fake->grant('user:' . $i, 'viewer', 'document:' . $i);
            }
        });

        // Add a simple assertion to avoid risky test warning
        expect(true)->toBeTrue();
    });

    it('performance metrics collection', function (): void {
        $fake = $this->getFakeOpenFga();
        $perf = $this->performance();

        // Collect various metrics
        $perf->measureCheck('user:1', 'viewer', 'doc:1');
        $perf->measureBatchCheck([
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'doc:1'],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'doc:2'],
        ]);

        // Get summary
        $summary = $perf->getSummary();

        expect($summary)->toHaveKey('total_operations');
        expect($summary)->toHaveKey('operations');
        expect($summary['operations']['count'])->toBe(2);
        expect($summary['operations']['total_time'])->toBeGreaterThan(0);
        expect($summary['operations']['average_time'])->toBeGreaterThan(0);
    });

    it('performance scaling with data size', function (): void {
        $fake = $this->getFakeOpenFga();
        $results = [];

        // Test with different data sizes
        foreach ([10, 50, 100, 500, 1000] as $size) {
            // Add permissions
            for ($i = 1; $i <= $size; ++$i) {
                $fake->grant('user:1', 'viewer', 'document:' . $i);
            }

            // Measure check performance
            $metrics = $this->measure(sprintf('check-with-%s-permissions', $size), static fn () => $fake->check('user:1', 'viewer', 'document:1'));

            $results[$size] = $metrics['duration'];
        }

        // Performance shouldn't degrade significantly with more data
        // In a real implementation, this would test actual scaling
        // Add small tolerance for timing variations
        expect($results[1000])->toBeLessThanOrEqual(
            $results[10] * 2.1, // Allow 5% tolerance
        );
    });

    it('performance within baseline', function (): void {
        $fake = $this->getFakeOpenFga();

        // Set up some initial data
        for ($i = 1; 10 >= $i; ++$i) {
            $fake->grant('user:' . $i, 'editor', 'post:' . $i);
        }

        // Measure baseline performance
        $baselineCallable = static function () use ($fake): void {
            $fake->check('user:1', 'editor', 'post:1');
        };

        // Add more data before measuring
        for ($i = 11; 20 >= $i; ++$i) {
            $fake->grant('user:' . $i, 'editor', 'post:' . $i);
        }

        // Measure performance with more data
        $operationCallable = static function () use ($fake): void {
            $fake->check('user:1', 'editor', 'post:1');
        };

        // Assert that checking with more data is within 200% of baseline (allow 3x slower)
        $this->assertPerformanceWithin(
            200, // percentage
            $baselineCallable,
            $operationCallable,
            'Performance degraded more than 200% with additional data',
        );

        // Add explicit assertion to satisfy PHPUnit
        expect(true)->toBeTrue();
    });

    it('permission check completes within time limit', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant('user:1', 'viewer', 'post:1');

        // Assert operation completes within 10ms
        $this->assertCompletesWithin(10, static function () use ($fake): void {
            $fake->check('user:1', 'viewer', 'post:1');
        });

        // Add a simple assertion to avoid risky test warning
        expect(true)->toBeTrue();
    });

    it('single permission check performance', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant('user:1', 'editor', 'document:1');

        // Measure a single check
        $metrics = $this->measure('single-check', static fn () => $fake->check('user:1', 'editor', 'document:1'));

        expect($metrics)->toBeArray();
        expect($metrics)->toHaveKey('duration');
        expect($metrics)->toHaveKey('memory_used');

        // Assert it completes within reasonable time (5ms for fake implementation)
        expect($metrics['duration'])->toBeLessThan(5);
    });
});
