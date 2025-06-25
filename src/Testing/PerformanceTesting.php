<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;
use OpenFGA\Laravel\Facades\OpenFga;
use PHPUnit\Framework\AssertionFailedError;

use function count;
use function sprintf;

/**
 * Performance testing utilities for OpenFGA operations.
 *
 * Provides tools to measure and assert performance characteristics
 * of authorization operations.
 */
final class PerformanceTesting
{
    /**
     * Whether to enable detailed timing.
     */
    private bool $detailed = false;

    /**
     * Performance metrics collected during tests.
     */
    private array $metrics = [];

    /**
     * Assert that an operation completes within a time limit.
     *
     * @param int     $milliseconds
     * @param Closure $operation
     * @param ?string $message
     */
    public function assertCompletesWithin(int $milliseconds, Closure $operation, ?string $message = null): void
    {
        $metrics = $this->measure('assertion', $operation);

        if ($metrics['duration'] > $milliseconds) {
            $message ??= sprintf(
                'Operation took %.2fms, expected less than %dms',
                $metrics['duration'],
                $milliseconds,
            );

            throw new AssertionFailedError($message);
        }
    }

    /**
     * Assert that memory usage stays below a threshold.
     *
     * @param int     $bytes
     * @param Closure $operation
     * @param ?string $message
     */
    public function assertMemoryUsageBelow(int $bytes, Closure $operation, ?string $message = null): void
    {
        $startMemory = memory_get_usage();
        $operation();
        $memoryUsed = memory_get_usage() - $startMemory;

        if ($memoryUsed > $bytes) {
            $message ??= sprintf(
                'Operation used %s of memory, expected less than %s',
                $this->formatBytes($memoryUsed),
                $this->formatBytes($bytes),
            );

            throw new AssertionFailedError($message);
        }
    }

    /**
     * Benchmark multiple operations.
     *
     * @param string  $name
     * @param Closure $operation
     * @param int     $iterations
     */
    public function benchmark(string $name, Closure $operation, int $iterations = 100): array
    {
        $results = [];

        // Warm up (run once without measuring)
        $operation();

        for ($i = 0; $i < $iterations; ++$i) {
            $startTime = microtime(true);
            $operation();
            $duration = (microtime(true) - $startTime) * 1000;
            $results[] = $duration;
        }

        $stats = $this->calculateStats($results);
        $stats['name'] = $name;
        $stats['iterations'] = $iterations;

        $this->metrics[] = [
            'type' => 'benchmark',
            'stats' => $stats,
            'timestamp' => now()->toIso8601String(),
        ];

        return $stats;
    }

    /**
     * Compare performance of two operations.
     *
     * @param string  $name1
     * @param Closure $operation1
     * @param string  $name2
     * @param Closure $operation2
     * @param int     $iterations
     */
    public function compare(string $name1, Closure $operation1, string $name2, Closure $operation2, int $iterations = 50): array
    {
        $results1 = $this->benchmark($name1, $operation1, $iterations);
        $results2 = $this->benchmark($name2, $operation2, $iterations);

        return [
            'test1' => $results1,
            'test2' => $results2,
            'difference' => [
                'mean' => $results2['mean'] - $results1['mean'],
                'median' => $results2['median'] - $results1['median'],
                'min' => $results2['min'] - $results1['min'],
                'max' => $results2['max'] - $results1['max'],
            ],
            'ratio' => [
                'mean' => $results2['mean'] / max($results1['mean'], 0.001),
                'median' => $results2['median'] / max($results1['median'], 0.001),
            ],
            'conclusion' => $results1['mean'] < $results2['mean'] ?
                $name1 . ' is faster by ' . round((1 - $results1['mean'] / $results2['mean']) * 100, 2) . '%' :
                $name2 . ' is faster by ' . round((1 - $results2['mean'] / $results1['mean']) * 100, 2) . '%',
        ];
    }

    /**
     * Enable detailed timing.
     */
    public function enableDetailed(): self
    {
        $this->detailed = true;

        return $this;
    }

    /**
     * Generate performance report.
     */
    public function generateReport(): string
    {
        $summary = $this->getSummary();

        $report = "Performance Test Report\n";
        $report .= "======================\n\n";

        $report .= "Summary:\n";
        $report .= sprintf("- Total Operations: %d\n", $summary['total_operations']);
        $report .= sprintf("- Total Time: %.2fms\n", $summary['operations']['total_time'] ?? 0);
        $report .= sprintf("- Average Time: %.2fms\n", $summary['operations']['average_time'] ?? 0);
        $report .= sprintf("- Min Time: %.2fms\n", $summary['operations']['min_time'] ?? 0);
        $report .= sprintf("- Max Time: %.2fms\n\n", $summary['operations']['max_time'] ?? 0);

        if (! empty($summary['benchmarks'])) {
            $report .= "Benchmarks:\n";

            foreach ($summary['benchmarks'] as $benchmark) {
                $report .= sprintf(
                    "- %s: %.2fms (avg over %d iterations)\n",
                    $benchmark['name'],
                    $benchmark['mean'],
                    $benchmark['iterations'],
                );
            }
            $report .= "\n";
        }

        if ($this->detailed) {
            $report .= "Detailed Metrics:\n";

            foreach ($this->metrics as $metric) {
                if (isset($metric['duration'])) {
                    $report .= sprintf(
                        "- %s: %.2fms, Memory: %s\n",
                        $metric['name'],
                        $metric['duration'],
                        $this->formatBytes($metric['memory_used'] ?? 0),
                    );
                }
            }
        }

        return $report;
    }

    /**
     * Get all collected metrics.
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Get metrics summary.
     */
    public function getSummary(): array
    {
        $operations = collect($this->metrics)
            ->where('type', '!=', 'benchmark')
            ->pluck('duration');

        $benchmarks = collect($this->metrics)
            ->where('type', 'benchmark')
            ->pluck('stats');

        return [
            'total_operations' => $operations->count() + $benchmarks->count(),
            'operations' => [
                'count' => $operations->count(),
                'total_time' => $operations->sum(),
                'average_time' => $operations->average(),
                'min_time' => $operations->min(),
                'max_time' => $operations->max(),
            ],
            'benchmarks' => $benchmarks->map(static fn ($b): array => [
                'name' => $b['name'],
                'mean' => $b['mean'],
                'iterations' => $b['iterations'],
            ])->toArray(),
        ];
    }

    /**
     * Measure the performance of a single operation.
     *
     * @param string  $name
     * @param Closure $operation
     */
    public function measure(string $name, Closure $operation): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        $startPeakMemory = memory_get_peak_usage();

        // Execute the operation
        $operation();

        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        $endPeakMemory = memory_get_peak_usage();

        $metrics = [
            'name' => $name,
            'duration' => ($endTime - $startTime) * 1000, // Convert to milliseconds
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => $endPeakMemory - $startPeakMemory,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->metrics[] = $metrics;

        return $metrics;
    }

    /**
     * Measure batch check performance.
     *
     * @param array   $checks
     * @param ?string $name
     */
    public function measureBatchCheck(array $checks, ?string $name = null): array
    {
        $name ??= sprintf('batchCheck(%d checks)', count($checks));

        return $this->measure($name, static fn () => OpenFga::batchCheck($checks));
    }

    /**
     * Measure permission check performance.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param ?string $name
     */
    public function measureCheck(string $user, string $relation, string $object, ?string $name = null): array
    {
        $name ??= sprintf('check(%s, %s, %s)', $user, $relation, $object);

        return $this->measure($name, static fn () => OpenFga::check($user, $relation, $object));
    }

    /**
     * Measure expand performance.
     *
     * @param string  $object
     * @param string  $relation
     * @param ?string $name
     */
    public function measureExpand(string $object, string $relation, ?string $name = null): array
    {
        $name ??= sprintf('expand(%s, %s)', $object, $relation);

        return $this->measure($name, static fn () => OpenFga::expand($object, $relation));
    }

    /**
     * Measure list objects performance.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $objectType
     * @param ?string $name
     */
    public function measureListObjects(string $user, string $relation, string $objectType, ?string $name = null): array
    {
        $name ??= sprintf('listObjects(%s, %s, %s)', $user, $relation, $objectType);

        return $this->measure($name, static fn () => OpenFga::listObjects($user, $relation, $objectType));
    }

    /**
     * Measure write performance.
     *
     * @param array   $writes
     * @param array   $deletes
     * @param ?string $name
     */
    public function measureWrite(array $writes, array $deletes = [], ?string $name = null): array
    {
        $totalOps = count($writes) + count($deletes);
        $name ??= sprintf('write(%d operations)', $totalOps);

        return $this->measure($name, static fn () => OpenFga::writeBatch($writes, $deletes));
    }

    /**
     * Profile memory usage during operation.
     *
     * @param string  $name
     * @param Closure $operation
     * @param int     $samples
     */
    public function profileMemory(string $name, Closure $operation, int $samples = 10): array
    {
        $startTime = microtime(true);

        // Execute operation
        $result = $operation();

        $duration = microtime(true) - $startTime;

        // Calculate memory stats
        $initialMemory = memory_get_usage();
        $peakMemory = memory_get_peak_usage();

        return [
            'name' => $name,
            'duration' => $duration * 1000,
            'memory_usage' => [
                'initial' => $initialMemory,
                'peak' => $peakMemory,
                'difference' => $peakMemory - $initialMemory,
            ],
            'result' => $result,
        ];
    }

    /**
     * Reset all metrics.
     */
    public function reset(): self
    {
        $this->metrics = [];

        return $this;
    }

    /**
     * Calculate statistics for a set of measurements.
     *
     * @param array $values
     */
    private function calculateStats(array $values): array
    {
        sort($values);
        $count = count($values);

        return [
            'mean' => array_sum($values) / $count,
            'median' => $values[(int) ($count / 2)],
            'min' => min($values),
            'max' => max($values),
            'p95' => $values[(int) ($count * 0.95)],
            'p99' => $values[(int) ($count * 0.99)],
            'stddev' => $this->calculateStdDev($values),
        ];
    }

    /**
     * Calculate standard deviation.
     *
     * @param array $values
     */
    private function calculateStdDev(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(static fn ($x): float | int => ($x - $mean) ** 2, $values)) / count($values);

        return sqrt($variance);
    }

    /**
     * Format bytes to human readable format.
     *
     * @param int $bytes
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while (1024 <= abs($bytes) && $i < count($units) - 1) {
            $bytes /= 1024;
            ++$i;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
