<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Feature;

use OpenFGA\Laravel\Testing\{FakesOpenFga, MeasuresPerformance};
use OpenFGA\Laravel\Tests\FeatureTestCase;

final class PerformanceTestingTest extends FeatureTestCase
{
    use FakesOpenFga;

    use MeasuresPerformance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeOpenFga();
        $this->setUpPerformanceTesting();
    }

    protected function tearDown(): void
    {
        $this->tearDownPerformanceTesting();
        parent::tearDown();
    }

    public function test_assert_faster_than(): void
    {
        $fake = $this->getFakeOpenFga();

        // Direct check should be faster than checking with non-existent permission
        $this->assertFasterThan(
            function () use ($fake): void {
                // This returns false immediately as permission doesn't exist
                $fake->check('user:1', 'admin', 'system:main');
            },
            function () use ($fake): void {
                // Add many permissions then check
                for ($i = 1; 100 >= $i; $i++) {
                    $fake->grant("user:{$i}", 'viewer', "doc:{$i}");
                }
                $fake->check('user:50', 'viewer', 'doc:50');
            },
            'Checking non-existent permission should be faster',
            10,
        );

        // Add a simple assertion to avoid risky test warning
        $this->assertTrue(true);
    }

    public function test_batch_operations_performance(): void
    {
        $fake = $this->getFakeOpenFga();

        // Benchmark batch writes
        $results = $this->benchmark('batch-write-100', function () use ($fake): void {
            $writes = [];

            for ($i = 1; 100 >= $i; $i++) {
                $writes[] = [
                    'user' => "user:{$i}",
                    'relation' => 'viewer',
                    'object' => "document:{$i}",
                ];
            }
            $fake->writeBatch($writes);
        }, 10);

        $this->assertArrayHasKey('mean', $results);
        $this->assertArrayHasKey('median', $results);
        $this->assertArrayHasKey('p95', $results);

        // Mean time should be reasonable for 100 operations
        $this->assertLessThan(50, $results['mean']);
    }

    public function test_comparing_single_vs_batch_checks(): void
    {
        $fake = $this->getFakeOpenFga();

        // Set up permissions
        for ($i = 1; 10 >= $i; $i++) {
            $fake->grant('user:1', 'viewer', "document:{$i}");
        }

        // Compare single checks vs batch check
        $comparison = $this->comparePerformance(
            'single-checks',
            function () use ($fake): void {
                for ($i = 1; 10 >= $i; $i++) {
                    $fake->check('user:1', 'viewer', "document:{$i}");
                }
            },
            'batch-check',
            function () use ($fake): void {
                $checks = [];

                for ($i = 1; 10 >= $i; $i++) {
                    $checks[] = [
                        'user' => 'user:1',
                        'relation' => 'viewer',
                        'object' => "document:{$i}",
                    ];
                }

                // Simulate batch check by checking all at once
                foreach ($checks as $check) {
                    $fake->check($check['user'], $check['relation'], $check['object']);
                }
            },
            20,
        );

        $this->assertArrayHasKey('conclusion', $comparison);
        $this->assertArrayHasKey('ratio', $comparison);
    }

    public function test_detailed_performance_report(): void
    {
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

        $perf->benchmark('bulk-checks', function () use ($fake): void {
            for ($i = 1; 5 >= $i; $i++) {
                $fake->check("user:{$i}", 'member', 'organization:acme');
            }
        }, 20);

        // Get the report
        $report = $this->getPerformanceReport();

        $this->assertStringContainsString('Performance Test Report', $report);
        $this->assertStringContainsString('Summary:', $report);
        $this->assertStringContainsString('Detailed Metrics:', $report);
        $this->assertStringContainsString('Admin check', $report);
        $this->assertStringContainsString('bulk-checks', $report);
    }

    public function test_memory_usage_assertion(): void
    {
        $fake = $this->getFakeOpenFga();

        // Assert memory usage stays below 1MB
        $this->assertMemoryUsageBelow(1024 * 1024, function () use ($fake): void {
            // Create 1000 permissions
            for ($i = 1; 1000 >= $i; $i++) {
                $fake->grant("user:{$i}", 'viewer', "document:{$i}");
            }
        });

        // Add a simple assertion to avoid risky test warning
        $this->assertTrue(true);
    }

    public function test_performance_metrics_collection(): void
    {
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

        $this->assertArrayHasKey('total_operations', $summary);
        $this->assertArrayHasKey('operations', $summary);
        $this->assertEquals(2, $summary['operations']['count']);
        $this->assertGreaterThan(0, $summary['operations']['total_time']);
        $this->assertGreaterThan(0, $summary['operations']['average_time']);
    }

    public function test_performance_scaling_with_data_size(): void
    {
        $fake = $this->getFakeOpenFga();
        $results = [];

        // Test with different data sizes
        foreach ([10, 50, 100, 500, 1000] as $size) {
            // Add permissions
            for ($i = 1; $i <= $size; $i++) {
                $fake->grant('user:1', 'viewer', "document:{$i}");
            }

            // Measure check performance
            $metrics = $this->measure("check-with-{$size}-permissions", fn () => $fake->check('user:1', 'viewer', 'document:1'));

            $results[$size] = $metrics['duration'];
        }

        // Performance shouldn't degrade significantly with more data
        // In a real implementation, this would test actual scaling
        $this->assertLessThan(
            $results[10] * 2,
            $results[1000],
            'Performance degraded more than 2x with 100x more data',
        );
    }

    public function test_performance_within_baseline(): void
    {
        $fake = $this->getFakeOpenFga();

        // Set up some initial data
        for ($i = 1; 10 >= $i; $i++) {
            $fake->grant("user:{$i}", 'editor', "post:{$i}");
        }

        // Assert that checking with more data is within 100% of baseline (allow 2x slower)
        $this->assertPerformanceWithin(
            100, // percentage
            function () use ($fake): void {
                // Baseline: check with current data
                $fake->check('user:1', 'editor', 'post:1');
            },
            function () use ($fake): void {
                // Add more data then check
                for ($i = 11; 20 >= $i; $i++) {
                    $fake->grant("user:{$i}", 'editor', "post:{$i}");
                }
                $fake->check('user:1', 'editor', 'post:1');
            },
            'Performance degraded more than 50% with additional data',
        );
    }

    public function test_permission_check_completes_within_time_limit(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant('user:1', 'viewer', 'post:1');

        // Assert operation completes within 10ms
        $this->assertCompletesWithin(10, function () use ($fake): void {
            $fake->check('user:1', 'viewer', 'post:1');
        });

        // Add a simple assertion to avoid risky test warning
        $this->assertTrue(true);
    }

    public function test_single_permission_check_performance(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant('user:1', 'editor', 'document:1');

        // Measure a single check
        $metrics = $this->measure('single-check', fn () => $fake->check('user:1', 'editor', 'document:1'));

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('duration', $metrics);
        $this->assertArrayHasKey('memory_used', $metrics);

        // Assert it completes within reasonable time (5ms for fake implementation)
        $this->assertLessThan(5, $metrics['duration']);
    }
}
