<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Batch;

/**
 * Comprehensive results from batch authorization operations.
 *
 * This immutable result object provides detailed metrics about batch processing
 * outcomes, including success rates, performance statistics, optimization savings,
 * and error information. Use these results to monitor batch operations, track
 * performance improvements, handle partial failures, and generate detailed
 * reports about bulk permission changes in your application.
 *
 * @internal
 */
final readonly class BatchResult
{
    /**
     * @param array<string, mixed> $optimizationStats
     * @param array<int, string>   $errors
     * @param bool                 $success
     * @param int                  $totalOperations
     * @param int                  $processedOperations
     * @param int                  $failedOperations
     * @param float                $duration
     */
    public function __construct(
        public bool $success,
        public int $totalOperations,
        public int $processedOperations,
        public int $failedOperations,
        public float $duration,
        public array $optimizationStats = [],
        public array $errors = [],
    ) {
    }

    /**
     * Get operations per second.
     */
    public function getOperationsPerSecond(): float
    {
        if (0.0 === $this->duration) {
            return 0;
        }

        return round((float) $this->processedOperations / $this->duration, 2);
    }

    /**
     * Get optimization reduction percentage.
     */
    public function getOptimizationReduction(): float
    {
        /** @var mixed $reduction */
        $reduction = $this->optimizationStats['reduction_percentage'] ?? 0;

        return is_numeric($reduction) ? (float) $reduction : 0.0;
    }

    /**
     * Get success rate.
     */
    public function getSuccessRate(): float
    {
        if (0 === $this->totalOperations) {
            return 0;
        }

        return round(((float) $this->processedOperations / (float) $this->totalOperations) * 100.0, 2);
    }

    /**
     * Check if batch was partially successful.
     */
    public function isPartialSuccess(): bool
    {
        return 0 < $this->processedOperations && 0 < $this->failedOperations;
    }

    /**
     * Convert to array.
     */
    /**
     * Convert to array.
     *
     * @return array{success: bool, total_operations: int, processed_operations: int, failed_operations: int, duration: float, operations_per_second: float, success_rate: float, optimization_stats: array<string, mixed>, errors: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'total_operations' => $this->totalOperations,
            'processed_operations' => $this->processedOperations,
            'failed_operations' => $this->failedOperations,
            'duration' => round($this->duration, 3),
            'operations_per_second' => $this->getOperationsPerSecond(),
            'success_rate' => $this->getSuccessRate(),
            'optimization_stats' => $this->optimizationStats,
            'errors' => $this->errors,
        ];
    }
}
