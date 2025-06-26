<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;
use OpenFGA\Laravel\Facades\OpenFga;
use PHPUnit\Framework\AssertionFailedError;

use function count;
use function is_array;
use function is_string;
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
     *
     * @var array<int, array{type?: string, stats?: array<string, mixed>, timestamp?: string, name?: string, duration?: float, memory_used?: int, peak_memory?: int}>
     */
    private array $metrics = [];

    /**
     * Assert that an operation completes within a time limit.
     *
     * @param int             $milliseconds
     * @param Closure(): void $operation
     * @param ?string         $message
     *
     * @throws AssertionFailedError
     *
     * @psalm-suppress InternalClass
     * @psalm-suppress InternalMethod
     */
    public function assertCompletesWithin(int $milliseconds, Closure $operation, ?string $message = null): void
    {
        $metrics = $this->measure('assertion', $operation);

        if ($metrics['duration'] > $milliseconds) {
            $message ??= sprintf(
                'Operation took %.2fms, expected less than %dms',
                $metrics['duration'] ?? 0.0,
                $milliseconds,
            );

            throw new AssertionFailedError($message);
        }
    }

    /**
     * Assert that memory usage stays below a threshold.
     *
     * @param int             $bytes
     * @param Closure(): void $operation
     * @param ?string         $message
     *
     * @throws AssertionFailedError
     *
     * @psalm-suppress InternalClass
     * @psalm-suppress InternalMethod
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
     * @param  string                                                                                                                          $name
     * @param  Closure(): void                                                                                                                 $operation
     * @param  int                                                                                                                             $iterations
     * @return array{name: string, iterations: int, mean: float, median: float, min: float, max: float, p95: float, p99: float, stddev: float}
     */
    public function benchmark(string $name, Closure $operation, int $iterations = 100): array
    {
        $results = [];

        // Warm up (run once without measuring)
        $operation();

        for ($i = 0; $i < $iterations; ++$i) {
            $startTime = microtime(true);
            $operation();
            $duration = (microtime(true) - $startTime) * 1000.0;
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
     * @param  string                                                                                                                                                                                               $name1
     * @param  Closure(): void                                                                                                                                                                                      $operation1
     * @param  string                                                                                                                                                                                               $name2
     * @param  Closure(): void                                                                                                                                                                                      $operation2
     * @param  int                                                                                                                                                                                                  $iterations
     * @return array{test1: array<string, mixed>, test2: array<string, mixed>, difference: array{mean: float, median: float, min: float, max: float}, ratio: array{mean: float, median: float}, conclusion: string}
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
                $name1 . ' is faster by ' . number_format((1.0 - $results1['mean'] / $results2['mean']) * 100.0, 2) . '%' :
                $name2 . ' is faster by ' . number_format((1.0 - $results2['mean'] / $results1['mean']) * 100.0, 2) . '%',
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
        $report .= sprintf("- Total Time: %.2fms\n", (float) ($summary['operations']['total_time'] ?? 0));
        $report .= sprintf("- Average Time: %.2fms\n", (float) ($summary['operations']['average_time'] ?? 0));
        $report .= sprintf("- Min Time: %.2fms\n", (float) ($summary['operations']['min_time'] ?? 0));
        $report .= sprintf("- Max Time: %.2fms\n\n", (float) ($summary['operations']['max_time'] ?? 0));

        if (isset($summary['benchmarks']) && [] !== $summary['benchmarks']) {
            $report .= "Benchmarks:\n";

            /** @var array<int, array{name: mixed, mean: mixed, iterations: mixed}> $benchmarks */
            $benchmarks = $summary['benchmarks'];

            foreach ($benchmarks as $benchmark) {
                $report .= sprintf(
                    "- %s: %.2fms (avg over %d iterations)\n",
                    is_string($benchmark['name']) ? $benchmark['name'] : 'Unknown',
                    is_numeric($benchmark['mean']) ? (float) $benchmark['mean'] : 0.0,
                    is_numeric($benchmark['iterations']) ? (int) $benchmark['iterations'] : 0,
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
                        $metric['name'] ?? 'Unknown',
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
     *
     * @return array<int, array{type?: string, stats?: array<string, mixed>, timestamp?: string, name?: string, duration?: float, memory_used?: int, peak_memory?: int}>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Get metrics summary.
     *
     * @return array{total_operations: int, operations: array{count: int, total_time: float|null, average_time: float|null, min_time: float|null, max_time: float|null}, benchmarks: array<int, array{name: mixed, mean: mixed, iterations: mixed}>}
     */
    public function getSummary(): array
    {
        $operations = collect($this->metrics)
            ->where('type', '!=', 'benchmark')
            ->pluck('duration');

        $benchmarks = collect($this->metrics)
            ->where('type', 'benchmark')
            ->pluck('stats');

        $totalTime = is_numeric($operations->sum()) ? (float) $operations->sum() : 0.0;
        $minTime = is_numeric($operations->min()) ? (float) $operations->min() : 0.0;
        $maxTime = is_numeric($operations->max()) ? (float) $operations->max() : 0.0;
        $avgTime = is_numeric($operations->average()) ? (float) $operations->average() : 0.0;

        /** @var array<int, array{name: mixed, mean: mixed, iterations: mixed}> $benchmarkArray */
        $benchmarkArray = [];

        /** @var array<int, mixed> $benchmarksArray */
        $benchmarksArray = $benchmarks->toArray();

        /** @var mixed $benchmark */
        foreach ($benchmarksArray as $benchmark) {
            if (is_array($benchmark)) {
                $benchmarkArray[] = [
                    'name' => $benchmark['name'] ?? '',
                    'mean' => $benchmark['mean'] ?? 0.0,
                    'iterations' => $benchmark['iterations'] ?? 0,
                ];
            }
        }

        return [
            'total_operations' => $operations->count() + $benchmarks->count(),
            'operations' => [
                'count' => $operations->count(),
                'total_time' => $totalTime,
                'average_time' => $avgTime,
                'min_time' => $minTime,
                'max_time' => $maxTime,
            ],
            'benchmarks' => $benchmarkArray,
        ];
    }

    /**
     * Measure the performance of a single operation.
     *
     * @param  string                                                                                      $name
     * @param  Closure(): mixed                                                                            $operation
     * @return array{name: string, duration: float, memory_used: int, peak_memory: int, timestamp: string}
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
            'duration' => ($endTime - $startTime) * 1000.0, // Convert to milliseconds
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
     * @param  array<int, mixed>                                                                           $checks
     * @param  ?string                                                                                     $name
     * @return array{name: string, duration: float, memory_used: int, peak_memory: int, timestamp: string}
     */
    public function measureBatchCheck(array $checks, ?string $name = null): array
    {
        $name ??= sprintf('batchCheck(%d checks)', count($checks));

        return $this->measure($name, static fn () => OpenFga::batchCheck($checks));
    }

    /**
     * Measure permission check performance.
     *
     * @param  string                                                                                      $user
     * @param  string                                                                                      $relation
     * @param  string                                                                                      $object
     * @param  ?string                                                                                     $name
     * @return array{name: string, duration: float, memory_used: int, peak_memory: int, timestamp: string}
     */
    public function measureCheck(string $user, string $relation, string $object, ?string $name = null): array
    {
        $name ??= sprintf('check(%s, %s, %s)', $user, $relation, $object);

        return $this->measure($name, static fn () => OpenFga::check($user, $relation, $object));
    }

    /**
     * Measure expand performance.
     *
     * @param  string                                                                                      $object
     * @param  string                                                                                      $relation
     * @param  ?string                                                                                     $name
     * @return array{name: string, duration: float, memory_used: int, peak_memory: int, timestamp: string}
     */
    public function measureExpand(string $object, string $relation, ?string $name = null): array
    {
        $name ??= sprintf('expand(%s, %s)', $object, $relation);

        return $this->measure($name, static fn () => OpenFga::expand($object, $relation));
    }

    /**
     * Measure list objects performance.
     *
     * @param  string                                                                                      $user
     * @param  string                                                                                      $relation
     * @param  string                                                                                      $objectType
     * @param  ?string                                                                                     $name
     * @return array{name: string, duration: float, memory_used: int, peak_memory: int, timestamp: string}
     */
    public function measureListObjects(string $user, string $relation, string $objectType, ?string $name = null): array
    {
        $name ??= sprintf('listObjects(%s, %s, %s)', $user, $relation, $objectType);

        return $this->measure($name, static fn () => OpenFga::listObjects($user, $relation, $objectType));
    }

    /**
     * Measure write performance.
     *
     * @param  array<int, array{user: string, relation: string, object: string}>                           $writes
     * @param  array<int, array{user: string, relation: string, object: string}>                           $deletes
     * @param  ?string                                                                                     $name
     * @return array{name: string, duration: float, memory_used: int, peak_memory: int, timestamp: string}
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
     * @param  string                                                                                                             $name
     * @param  Closure(): mixed                                                                                                   $operation
     * @param  int                                                                                                                $samples
     * @return array{name: string, duration: float, memory_usage: array{initial: int, peak: int, difference: int}, result: mixed}
     */
    public function profileMemory(string $name, Closure $operation, int $samples = 10): array
    {
        $startTime = microtime(true);

        // Execute operation
        /** @var mixed $result */
        $result = $operation();

        $duration = microtime(true) - $startTime;

        // Calculate memory stats
        $initialMemory = memory_get_usage();
        $peakMemory = memory_get_peak_usage();

        return [
            'name' => $name,
            'duration' => $duration * 1000.0,
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
     * @param  array<int, float>                                                                                $values
     * @return array{mean: float, median: float, min: float, max: float, p95: float, p99: float, stddev: float}
     */
    private function calculateStats(array $values): array
    {
        sort($values);
        $count = count($values);

        if (0 === $count) {
            return [
                'mean' => 0.0,
                'median' => 0.0,
                'min' => 0.0,
                'max' => 0.0,
                'p95' => 0.0,
                'p99' => 0.0,
                'stddev' => 0.0,
            ];
        }

        /** @var non-empty-list<float> $values */
        return [
            'mean' => array_sum($values) / (float) $count,
            'median' => $values[(int) ($count / 2)],
            'min' => min($values),
            'max' => max($values),
            'p95' => $values[(int) ((float) $count * 0.95)],
            'p99' => $values[(int) ((float) $count * 0.99)],
            'stddev' => $this->calculateStdDev($values),
        ];
    }

    /**
     * Calculate standard deviation.
     *
     * @param array<int, float> $values
     */
    private function calculateStdDev(array $values): float
    {
        $count = count($values);

        if (0 === $count) {
            return 0.0;
        }
        $mean = array_sum($values) / (float) $count;
        $variance = array_sum(array_map(static fn (float $x): float => ($x - $mean) ** 2.0, $values)) / (float) $count;

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
        $size = (float) $bytes;

        while (1024 <= abs($size) && $i < count($units) - 1) {
            $size /= 1024.0;
            ++$i;
        }

        /** @var int<0, 3> $i */
        return number_format($size, 2) . ' ' . $units[$i];
    }
}
