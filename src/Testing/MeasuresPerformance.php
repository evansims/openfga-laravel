<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;

/**
 * Trait for adding performance testing capabilities to test classes
 */
trait MeasuresPerformance
{
    protected ?PerformanceTesting $performanceTester = null;

    /**
     * Set up performance testing
     */
    protected function setUpPerformanceTesting(): void
    {
        $this->performanceTester = new PerformanceTesting();
    }

    /**
     * Get the performance tester instance
     */
    protected function performance(): PerformanceTesting
    {
        if (!$this->performanceTester) {
            $this->performanceTester = new PerformanceTesting();
        }

        return $this->performanceTester;
    }

    /**
     * Assert that an operation completes within a time limit
     */
    protected function assertCompletesWithin(int $milliseconds, Closure $operation, ?string $message = null): void
    {
        $this->performance()->assertCompletesWithin($milliseconds, $operation, $message);
    }

    /**
     * Assert that memory usage stays below a threshold
     */
    protected function assertMemoryUsageBelow(int $bytes, Closure $operation, ?string $message = null): void
    {
        $this->performance()->assertMemoryUsageBelow($bytes, $operation, $message);
    }

    /**
     * Benchmark an operation
     */
    protected function benchmark(string $name, Closure $operation, int $iterations = 100): array
    {
        return $this->performance()->benchmark($name, $operation, $iterations);
    }

    /**
     * Compare performance of two operations
     */
    protected function comparePerformance(
        string $name1,
        Closure $operation1,
        string $name2,
        Closure $operation2,
        int $iterations = 50
    ): array {
        return $this->performance()->compare($name1, $operation1, $name2, $operation2, $iterations);
    }

    /**
     * Measure a single operation
     */
    protected function measure(string $name, Closure $operation): array
    {
        return $this->performance()->measure($name, $operation);
    }

    /**
     * Assert that one operation is faster than another
     */
    protected function assertFasterThan(
        Closure $fastOperation,
        Closure $slowOperation,
        ?string $message = null,
        int $iterations = 50
    ): void {
        $fast = $this->benchmark('fast', $fastOperation, $iterations);
        $slow = $this->benchmark('slow', $slowOperation, $iterations);

        if ($fast['mean'] >= $slow['mean']) {
            $message = $message ?? sprintf(
                'Expected first operation (%.2fms) to be faster than second operation (%.2fms)',
                $fast['mean'],
                $slow['mean']
            );
            
            $this->fail($message);
        }
    }

    /**
     * Assert that an operation's performance is within a percentage of a baseline
     */
    protected function assertPerformanceWithin(
        int $percentage,
        Closure $baseline,
        Closure $operation,
        ?string $message = null,
        int $iterations = 50
    ): void {
        $baselineResult = $this->benchmark('baseline', $baseline, $iterations);
        $operationResult = $this->benchmark('operation', $operation, $iterations);

        $threshold = $baselineResult['mean'] * (1 + $percentage / 100);

        if ($operationResult['mean'] > $threshold) {
            $message = $message ?? sprintf(
                'Operation (%.2fms) exceeded %d%% threshold (%.2fms) of baseline (%.2fms)',
                $operationResult['mean'],
                $percentage,
                $threshold,
                $baselineResult['mean']
            );
            
            $this->fail($message);
        }
    }

    /**
     * Get performance report
     */
    protected function getPerformanceReport(): string
    {
        return $this->performance()->generateReport();
    }

    /**
     * Print performance report
     */
    protected function printPerformanceReport(): void
    {
        echo "\n" . $this->getPerformanceReport() . "\n";
    }

    /**
     * Clean up after performance testing
     */
    protected function tearDownPerformanceTesting(): void
    {
        if ($this->performanceTester) {
            // Optionally print report if tests are verbose
            if (in_array('--verbose', $_SERVER['argv'] ?? [])) {
                $this->printPerformanceReport();
            }

            $this->performanceTester = null;
        }
    }
}