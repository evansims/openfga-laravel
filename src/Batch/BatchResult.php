<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Batch;

final class BatchResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $totalOperations,
        public readonly int $processedOperations,
        public readonly int $failedOperations,
        public readonly float $duration,
        public readonly array $optimizationStats = [],
        public readonly array $errors = [],
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

        return round($this->processedOperations / $this->duration, 2);
    }

    /**
     * Get optimization reduction percentage.
     */
    public function getOptimizationReduction(): float
    {
        return $this->optimizationStats['reduction_percentage'] ?? 0;
    }

    /**
     * Get success rate.
     */
    public function getSuccessRate(): float
    {
        if (0 === $this->totalOperations) {
            return 0;
        }

        return round(($this->processedOperations / $this->totalOperations) * 100, 2);
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
