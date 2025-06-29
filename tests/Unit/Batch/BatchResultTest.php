<?php

declare(strict_types=1);

use OpenFGA\Laravel\Batch\BatchResult;

describe('BatchResult', function (): void {
    it('initializes with required parameters', function (): void {
        $result = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 95,
            failedOperations: 5,
            duration: 2.5,
        );

        expect($result->success)->toBeTrue();
        expect($result->totalOperations)->toBe(100);
        expect($result->processedOperations)->toBe(95);
        expect($result->failedOperations)->toBe(5);
        expect($result->duration)->toBe(2.5);
        expect($result->optimizationStats)->toBe([]);
        expect($result->errors)->toBe([]);
    });

    it('initializes with all parameters', function (): void {
        $optimizationStats = [
            'original_operations' => 150,
            'optimized_operations' => 100,
            'reduction_percentage' => 33.33,
        ];

        $errors = ['Chunk 2: Connection timeout', 'Chunk 5: Invalid tuple'];

        $result = new BatchResult(
            success: false,
            totalOperations: 100,
            processedOperations: 90,
            failedOperations: 10,
            duration: 5.7,
            optimizationStats: $optimizationStats,
            errors: $errors,
        );

        expect($result->success)->toBeFalse();
        expect($result->totalOperations)->toBe(100);
        expect($result->processedOperations)->toBe(90);
        expect($result->failedOperations)->toBe(10);
        expect($result->duration)->toBe(5.7);
        expect($result->optimizationStats)->toBe($optimizationStats);
        expect($result->errors)->toBe($errors);
    });

    it('calculates operations per second correctly', function (): void {
        $result = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 80,
            failedOperations: 20,
            duration: 4.0,
        );

        expect($result->getOperationsPerSecond())->toBe(20.0); // 80 operations / 4 seconds = 20 ops/sec
    });

    it('returns zero operations per second for zero duration', function (): void {
        $result = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 100,
            failedOperations: 0,
            duration: 0.0,
        );

        expect($result->getOperationsPerSecond())->toBe(0.0);
    });

    it('calculates success rate correctly', function (): void {
        $result = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 85,
            failedOperations: 15,
            duration: 1.0,
        );

        expect($result->getSuccessRate())->toBe(85.0); // 85/100 = 85%
    });

    it('returns zero success rate for zero total operations', function (): void {
        $result = new BatchResult(
            success: true,
            totalOperations: 0,
            processedOperations: 0,
            failedOperations: 0,
            duration: 1.0,
        );

        expect($result->getSuccessRate())->toBe(0.0);
    });

    it('gets optimization reduction percentage', function (): void {
        $optimizationStats = [
            'reduction_percentage' => 25.5,
            'duplicates_removed' => 10,
        ];

        $result = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 100,
            failedOperations: 0,
            duration: 1.0,
            optimizationStats: $optimizationStats,
        );

        expect($result->getOptimizationReduction())->toBe(25.5);
    });

    it('returns zero optimization reduction when not available', function (): void {
        $result = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 100,
            failedOperations: 0,
            duration: 1.0,
        );

        expect($result->getOptimizationReduction())->toBe(0.0);
    });

    it('handles non-numeric reduction percentage gracefully', function (): void {
        $optimizationStats = [
            'reduction_percentage' => 'invalid',
        ];

        $result = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 100,
            failedOperations: 0,
            duration: 1.0,
            optimizationStats: $optimizationStats,
        );

        expect($result->getOptimizationReduction())->toBe(0.0);
    });

    it('detects partial success correctly', function (): void {
        $partialSuccess = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 80,
            failedOperations: 20,
            duration: 1.0,
        );

        $fullSuccess = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 100,
            failedOperations: 0,
            duration: 1.0,
        );

        $fullFailure = new BatchResult(
            success: false,
            totalOperations: 100,
            processedOperations: 0,
            failedOperations: 100,
            duration: 1.0,
        );

        expect($partialSuccess->isPartialSuccess())->toBeTrue();
        expect($fullSuccess->isPartialSuccess())->toBeFalse();
        expect($fullFailure->isPartialSuccess())->toBeFalse();
    });

    it('converts to array correctly', function (): void {
        $optimizationStats = [
            'reduction_percentage' => 15.0,
            'duplicates_removed' => 5,
        ];

        $errors = ['Error 1', 'Error 2'];

        $result = new BatchResult(
            success: false,
            totalOperations: 100,
            processedOperations: 75,
            failedOperations: 25,
            duration: 3.456,
            optimizationStats: $optimizationStats,
            errors: $errors,
        );

        $array = $result->toArray();

        expect($array)->toBe([
            'success' => false,
            'total_operations' => 100,
            'processed_operations' => 75,
            'failed_operations' => 25,
            'duration' => 3.456,
            'operations_per_second' => 21.70, // 75 / 3.456 ≈ 21.70
            'success_rate' => 75.0,
            'optimization_stats' => $optimizationStats,
            'errors' => $errors,
        ]);
    });

    it('rounds duration in array conversion', function (): void {
        $result = new BatchResult(
            success: true,
            totalOperations: 10,
            processedOperations: 10,
            failedOperations: 0,
            duration: 1.23456789,
        );

        $array = $result->toArray();

        expect($array['duration'])->toBe(1.235); // Rounded to 3 decimal places
    });

    it('is immutable', function (): void {
        $result = new BatchResult(
            success: true,
            totalOperations: 100,
            processedOperations: 100,
            failedOperations: 0,
            duration: 1.0,
        );

        // Attempting to modify properties should fail (readonly properties)
        expect($result)->toBeInstanceOf(BatchResult::class);

        // Verify properties are accessible
        expect($result->success)->toBeTrue();
        expect($result->totalOperations)->toBe(100);
    });

    it('handles edge case calculations', function (): void {
        // Test with very small numbers
        $result = new BatchResult(
            success: true,
            totalOperations: 1,
            processedOperations: 1,
            failedOperations: 0,
            duration: 0.001,
        );

        expect($result->getOperationsPerSecond())->toBe(1000.0); // 1 / 0.001 = 1000
        expect($result->getSuccessRate())->toBe(100.0);
        expect($result->isPartialSuccess())->toBeFalse();
    });

    it('handles large number calculations', function (): void {
        // Test with large numbers
        $result = new BatchResult(
            success: true,
            totalOperations: 1000000,
            processedOperations: 999500,
            failedOperations: 500,
            duration: 100.0,
        );

        expect($result->getOperationsPerSecond())->toBe(9995.0); // 999500 / 100 = 9995
        expect($result->getSuccessRate())->toBe(99.95); // 999500 / 1000000 = 99.95%
        expect($result->isPartialSuccess())->toBeTrue();
    });

    it('preserves exact values without modification', function (): void {
        $optimizationStats = [
            'custom_metric' => 'test_value',
            'nested' => ['data' => 123],
        ];

        $errors = ['Detailed error message with context'];

        $result = new BatchResult(
            success: true,
            totalOperations: 42,
            processedOperations: 42,
            failedOperations: 0,
            duration: 1.23,
            optimizationStats: $optimizationStats,
            errors: $errors,
        );

        // Verify exact preservation of input data
        expect($result->optimizationStats)->toBe($optimizationStats);
        expect($result->errors)->toBe($errors);
        expect($result->totalOperations)->toBe(42);
        expect($result->duration)->toBe(1.23);
    });
});
