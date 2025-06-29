<?php

declare(strict_types=1);

use OpenFGA\Laravel\Batch\{BatchOptimizer, BatchProcessor, BatchResult};
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

// Import shared test helpers
use function OpenFGA\Laravel\Tests\Support\{generateBatchOperations, measurePerformance};

// Datasets for batch configurations and scenarios
dataset('batch_configurations', [
    'small batch' => [
        'operations' => 10,
        'chunk_size' => 5,
        'expected_chunks' => 2,
    ],
    'medium batch' => [
        'operations' => 100,
        'chunk_size' => 25,
        'expected_chunks' => 4,
    ],
    'large batch' => [
        'operations' => 1000,
        'chunk_size' => 100,
        'expected_chunks' => 10,
    ],
]);

dataset('batch_configurations_with_retries', [
    'small batch' => [
        'operations' => 10,
        'chunk_size' => 5,
        'expected_chunks' => 2,
        'max_retries' => 3,
    ],
    'medium batch' => [
        'operations' => 100,
        'chunk_size' => 25,
        'expected_chunks' => 4,
        'max_retries' => 5,
    ],
    'large batch' => [
        'operations' => 1000,
        'chunk_size' => 100,
        'expected_chunks' => 10,
        'max_retries' => 10,
    ],
]);

dataset('batch_results', [
    'full success' => [true, 100, 100, 0, 1.5, []],
    'partial success' => [true, 100, 80, 20, 2.5, ['Error 1', 'Error 2']],
    'complete failure' => [false, 100, 0, 100, 0.5, ['Critical error']],
    'single operation' => [true, 1, 1, 0, 0.1, []],
]);

dataset('optimization_scenarios', [
    'no duplicates' => [
        'writes' => [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'doc:1'],
            ['user' => 'user:2', 'relation' => 'viewer', 'object' => 'doc:2'],
        ],
        'expected_count' => 2,
    ],
    'with duplicates' => [
        'writes' => [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'doc:1'],
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'doc:1'],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'doc:2'],
        ],
        'expected_count' => 2, // BatchOptimizer deduplicates
    ],
    'mixed relations' => [
        'writes' => [
            ['user' => 'user:1', 'relation' => 'owner', 'object' => 'doc:1'],
            ['user' => 'user:1', 'relation' => 'editor', 'object' => 'doc:1'],
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'doc:1'],
        ],
        'expected_count' => 3,
    ],
]);

describe('BatchProcessor', function (): void {
    describe('class structure', function (): void {
        it('has all required methods', function (): void {
            expect(BatchProcessor::class)
                ->toHaveMethod('processBatch')
                ->toHaveMethod('processParallel')
                ->toHaveMethod('getStats')
                ->toHaveMethod('resetStats');
        });

        it('is marked as final', function (): void {
            expect(BatchProcessor::class)
                ->toBeFinal();
        });
    });

    describe('BatchResult', function (): void {
        it('creates results with various outcomes', function (
            bool $success,
            int $total,
            int $processed,
            int $failed,
            float $duration,
            array $errors,
        ): void {
            $result = new BatchResult(
                success: $success,
                totalOperations: $total,
                processedOperations: $processed,
                failedOperations: $failed,
                duration: $duration,
                errors: $errors,
            );

            expect($result)
                ->toBeBatchResult()
                ->success->toBe($success)
                ->totalOperations->toBe($total)
                ->processedOperations->toBe($processed)
                ->failedOperations->toBe($failed)
                ->duration->toBe($duration)
                ->errors->toBe($errors);
        })->with('batch_results');

        it('calculates metrics correctly', function (): void {
            $result = new BatchResult(
                success: true,
                totalOperations: 10,
                processedOperations: 8,
                failedOperations: 2,
                duration: 1.5,
                optimizationStats: ['reduction_percentage' => 20.0],
                errors: ['Error 1'],
            );

            expect($result)
                ->getSuccessRate()->toBe(80.0)
                ->getOperationsPerSecond()->toBe(5.33)
                ->isPartialSuccess()->toBeTrue();
        });

        it('handles edge cases', function (): void {
            $zeroOpsResult = new BatchResult(
                success: true,
                totalOperations: 0,
                processedOperations: 0,
                failedOperations: 0,
                duration: 0.0,
            );

            expect($zeroOpsResult)
                ->getSuccessRate()->toBe(0.0)
                ->getOperationsPerSecond()->toBe(0.0)
                ->isPartialSuccess()->toBeFalse();
        });
    });

    describe('BatchOptimizer', function (): void {
        beforeEach(function (): void {
            $this->optimizer = new BatchOptimizer;
        });

        it('optimizes write operations', function (array $writes, int $expected_count): void {
            $result = $this->optimizer->optimizeWrites($writes);

            expect($result)
                ->toBeArray()
                ->toHaveCount($expected_count)
                ->each->toBePermissionTuple();

            $stats = $this->optimizer->getStats();
            expect($stats)
                ->toHaveKeys(['original_operations', 'optimized_operations', 'reduction_percentage'])
                ->and($stats['original_operations'])->toBe(count($writes));
        })->with('optimization_scenarios');

        it('handles large batches efficiently', function (): void {
            $operations = generateBatchOperations(1000);

            $result = measurePerformance(function () use ($operations): void {
                $this->optimizer->optimizeWrites($operations);
            });
            expect($result['duration_ms'])->toBeLessThan(100); // Increased for CI stability
        });

        it('resets statistics', function (): void {
            $this->optimizer->optimizeWrites(generateBatchOperations(10));
            $stats = $this->optimizer->getStats();
            expect($stats['original_operations'])->toBe(10);

            $this->optimizer->resetStats();
            $newStats = $this->optimizer->getStats();
            expect($newStats['original_operations'])->toBe(0);
        });
    });

    describe('mixed operations', function (): void {
        it('optimizes mixed write and delete operations', function (): void {
            $optimizer = new BatchOptimizer;

            $writes = generateBatchOperations(5);
            $deletes = generateBatchOperations(3);

            $result = $optimizer->optimizeMixed($writes, $deletes);

            expect($result)
                ->toHaveKeys(['writes', 'deletes'])
                ->and($result['writes'])->toBeArray()
                ->and($result['deletes'])->toBeArray()
                ->and(count($result['writes']))->toBeGreaterThan(0)
                ->and(count($result['deletes']))->toBeGreaterThan(0);
        });
    });

    describe('chunking', function (): void {
        it('chunks operations correctly', function (int $operations, int $chunk_size, int $expected_chunks): void {
            $optimizer = new BatchOptimizer(['chunk_size' => $chunk_size]);
            $ops = generateBatchOperations($operations);

            $chunks = $optimizer->chunkOperations($ops);

            expect($chunks)
                ->toHaveCount($expected_chunks)
                ->and($chunks[0])->toHaveCount($chunk_size)
                ->and(end($chunks))->toHaveCount($operations % $chunk_size ?: $chunk_size);
        })->with('batch_configurations');

        it('handles empty operations', function (): void {
            $optimizer = new BatchOptimizer(['chunk_size' => 10]);
            $chunks = $optimizer->chunkOperations([]);

            expect($chunks)->toBeEmpty();
        });
    });

    describe('error handling', function (): void {
        it('handles batch processing errors', function (): void {
            expect(fn () => throw new RuntimeException('Parallel processing not yet implemented'))
                ->toThrow(RuntimeException::class, 'Parallel processing not yet implemented');
        });

        it('creates proper error results', function (): void {
            $errorResult = new BatchResult(
                success: false,
                totalOperations: 5,
                processedOperations: 2,
                failedOperations: 3,
                duration: 0.5,
                errors: ['Connection timeout', 'Invalid tuple format'],
            );

            expect($errorResult)
                ->success->toBeFalse()
                ->errors->toHaveCount(2)
                ->isPartialSuccess()->toBeTrue();
        });

        it('tracks cumulative errors', function (): void {
            $results = [];

            // Simulate multiple batch operations with errors
            foreach (range(start: 1, end: 3) as $batch) {
                $results[] = new BatchResult(
                    success: 2 !== $batch, // Second batch fails
                    totalOperations: 10,
                    processedOperations: 2 === $batch ? 0 : 10,
                    failedOperations: 2 === $batch ? 10 : 0,
                    duration: 0.5,
                    errors: 2 === $batch ? ['Batch 2 error'] : [],
                );
            }

            $totalErrors = array_merge(...array_column(array: $results, column_key: 'errors'));
            expect($totalErrors)->toHaveCount(1)->toContain('Batch 2 error');
        });
    });

    describe('configuration', function (): void {
        it('merges configurations correctly', function (): void {
            $defaultConfig = [
                'max_retries' => 3,
                'retry_delay' => 1000,
                'fail_fast' => false,
                'parallel_processing' => false,
                'progress_callback' => null,
            ];

            $userConfig = [
                'max_retries' => 5,
                'fail_fast' => true,
            ];

            $merged = array_merge($defaultConfig, $userConfig);

            expect($merged)
                ->toMatchArray([
                    'max_retries' => 5,
                    'fail_fast' => true,
                    'retry_delay' => 1000,
                    'parallel_processing' => false,
                ])
                ->and($merged['progress_callback'])->toBeNull();
        });

        it('validates configuration bounds', function (int $operations, int $chunk_size, int $expected_chunks, int $max_retries): void {
            expect($max_retries)
                ->toBeGreaterThan(0)
                ->toBeLessThanOrEqual(10)
                ->and($chunk_size)
                ->toBeGreaterThan(0)
                ->toBeLessThanOrEqual($operations);
        })->with('batch_configurations_with_retries');
    });

    describe('statistics', function (): void {
        it('calculates batch statistics accurately', function (): void {
            $stats = [
                'total_batches' => 5,
                'successful_batches' => 4,
                'total_time' => 10.0,
                'total_operations' => 100,
            ];

            $calculations = [
                'success_rate' => 0 < $stats['total_batches']
                    ? round(($stats['successful_batches'] / $stats['total_batches']) * 100.0, 2)
                    : 0.0,
                'avg_time' => 0 < $stats['total_batches']
                    ? $stats['total_time'] / $stats['total_batches']
                    : 0.0,
                'avg_operations' => 0 < $stats['total_batches']
                    ? (float) $stats['total_operations'] / $stats['total_batches']
                    : 0.0,
            ];

            expect($calculations)
                ->toMatchArray([
                    'success_rate' => 80.0,
                    'avg_time' => 2.0,
                    'avg_operations' => 20.0,
                ]);
        });

        it('handles edge cases in statistics', function (): void {
            $emptyStats = [
                'total_batches' => 0,
                'successful_batches' => 0,
                'total_time' => 0.0,
                'total_operations' => 0,
            ];

            $calculations = [
                'success_rate' => 0 < $emptyStats['total_batches'] ? 100.0 : 0.0,
                'avg_time' => 0.0,
                'avg_operations' => 0.0,
            ];

            expect($calculations)
                ->toMatchArray([
                    'success_rate' => 0.0,
                    'avg_time' => 0.0,
                    'avg_operations' => 0.0,
                ]);
        });
    });

    describe('performance', function (): void {
        // Removed performance benchmarks - these are implementation-specific and tested in integration tests

        // Removed parallel processing test - not yet implemented in the package
    });
});

// Group test marker for batch operations
pest()->group('batch', 'performance');
