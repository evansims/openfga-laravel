<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Batch;

use Exception;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\{BatchFailed, BatchProcessed};
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Models\Collections\TupleKeys;
use OpenFGA\Models\TupleKey;
use RuntimeException;

use function count;
use function is_callable;
use function sprintf;

/**
 * Processes large batches of authorization operations with reliability features.
 *
 * This processor handles bulk permission changes with automatic chunking,
 * retry logic, progress tracking, and error recovery. It optimizes operations
 * before execution and provides detailed statistics about processing performance.
 * Ideal for scenarios like user role migrations, bulk permission grants, or
 * large-scale access control updates where reliability and monitoring are crucial.
 *
 * @internal
 */
final class BatchProcessor
{
    /**
     * @var array{max_retries: int, retry_delay: int, fail_fast: bool, parallel_processing: bool, progress_callback: (callable(int, int, int): void)|null}
     */
    private array $config;

    /**
     * @var array{total_batches: int, successful_batches: int, failed_batches: int, total_operations: int, total_time: float}
     */
    private array $stats = [
        'total_batches' => 0,
        'successful_batches' => 0,
        'failed_batches' => 0,
        'total_operations' => 0,
        'total_time' => 0.0,
    ];

    /**
     * @param OpenFgaManager                                                                                                                                      $manager
     * @param BatchOptimizer                                                                                                                                      $optimizer
     * @param array{max_retries?: int, retry_delay?: int, fail_fast?: bool, parallel_processing?: bool, progress_callback?: (callable(int, int, int): void)|null} $config
     */
    public function __construct(
        private readonly OpenFgaManager $manager,
        private readonly BatchOptimizer $optimizer,
        array $config = [],
    ) {
        $this->config = array_merge([
            'max_retries' => 3,
            'retry_delay' => 1000, // milliseconds
            'fail_fast' => false,
            'parallel_processing' => false,
            'progress_callback' => null,
        ], $config);
    }

    /**
     * Get processing statistics.
     *
     * @return array{total_batches: int, successful_batches: int, failed_batches: int, total_operations: int, total_time: float, average_batch_time: float, average_operations_per_batch: float, success_rate: float}
     */
    public function getStats(): array
    {
        $avgTime = 0 < $this->stats['total_batches']
            ? $this->stats['total_time'] / (float) $this->stats['total_batches']
            : 0.0;

        $avgOps = 0 < $this->stats['total_batches']
            ? (float) $this->stats['total_operations'] / (float) $this->stats['total_batches']
            : 0.0;

        return array_merge($this->stats, [
            'average_batch_time' => round($avgTime, 3),
            'average_operations_per_batch' => round($avgOps, 2),
            'success_rate' => 0 < $this->stats['total_batches']
                ? round(((float) $this->stats['successful_batches'] / (float) $this->stats['total_batches']) * 100.0, 2)
                : 0.0,
        ]);
    }

    /**
     * Process a large batch of operations.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $writes
     * @param array<int, array{user: string, relation: string, object: string}> $deletes
     *
     * @throws Exception When fail_fast is enabled and an error occurs
     */
    public function processBatch(array $writes, array $deletes = []): BatchResult
    {
        $startTime = microtime(true);
        ++$this->stats['total_batches'];

        try {
            // Optimize the operations
            $optimized = $this->optimizer->optimizeMixed($writes, $deletes);
            $writes = $optimized['writes'];
            $deletes = $optimized['deletes'];

            $totalOperations = count($writes) + count($deletes);
            $this->stats['total_operations'] += $totalOperations;

            // Chunk operations
            $chunks = $this->createChunks($writes, $deletes);

            // Process chunks
            $results = $this->processChunks($chunks);

            // Calculate statistics
            $duration = microtime(true) - $startTime;
            $this->stats['total_time'] += $duration;
            ++$this->stats['successful_batches'];

            $result = new BatchResult(
                success: true,
                totalOperations: $totalOperations,
                processedOperations: $results['processed'],
                failedOperations: $results['failed'],
                duration: $duration,
                optimizationStats: $this->optimizer->getStats(),
                errors: $results['errors'],
            );

            // Dispatch event
            event(new BatchProcessed($result));

            return $result;
        } catch (Exception $exception) {
            ++$this->stats['failed_batches'];

            $result = new BatchResult(
                success: false,
                totalOperations: count($writes) + count($deletes),
                processedOperations: 0,
                failedOperations: count($writes) + count($deletes),
                duration: microtime(true) - $startTime,
                optimizationStats: $this->optimizer->getStats(),
                errors: [$exception->getMessage()],
            );

            // Dispatch event
            event(new BatchFailed($result, $exception));

            if ($this->config['fail_fast']) {
                throw $exception;
            }

            return $result;
        }
    }

    /**
     * Process operations in parallel (if supported).
     *
     * @param array<int, array{user: string, relation: string, object: string}> $operations
     *
     * @throws Exception        When fail_fast is enabled and an error occurs during processing
     * @throws RuntimeException Always thrown as parallel processing is not yet implemented
     */
    public function processParallel(array $operations): BatchResult
    {
        if (! $this->config['parallel_processing']) {
            return $this->processBatch($operations, []);
        }

        // This would require additional implementation with process forking
        // or using a queue system for true parallel processing
        throw new RuntimeException('Parallel processing not yet implemented');
    }

    /**
     * Reset statistics.
     */
    public function resetStats(): void
    {
        $this->stats = [
            'total_batches' => 0,
            'successful_batches' => 0,
            'failed_batches' => 0,
            'total_operations' => 0,
            'total_time' => 0.0,
        ];

        $this->optimizer->resetStats();
    }

    /**
     * Create chunks from operations.
     *
     * @param  array<int, array{user: string, relation: string, object: string}>                                                                                                        $writes
     * @param  array<int, array{user: string, relation: string, object: string}>                                                                                                        $deletes
     * @return array<int, array{writes: array<int, array{user: string, relation: string, object: string}>, deletes: array<int, array{user: string, relation: string, object: string}>}>
     */
    private function createChunks(array $writes, array $deletes): array
    {
        $writeChunks = $this->optimizer->chunkOperations($writes);
        $deleteChunks = $this->optimizer->chunkOperations($deletes);

        $chunks = [];
        $maxChunks = max(count($writeChunks), count($deleteChunks));

        for ($i = 0; $i < $maxChunks; ++$i) {
            $chunks[] = [
                'writes' => $writeChunks[$i] ?? [],
                'deletes' => $deleteChunks[$i] ?? [],
            ];
        }

        return $chunks;
    }

    /**
     * Process chunks with retry logic.
     *
     * @param  array<int, array{writes: array<int, array{user: string, relation: string, object: string}>, deletes: array<int, array{user: string, relation: string, object: string}>}> $chunks
     * @return array{processed: int, failed: int, errors: array<int, string>}
     */
    private function processChunks(array $chunks): array
    {
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($chunks as $index => $chunk) {
            $chunkSize = count($chunk['writes']) + count($chunk['deletes']);

            try {
                $this->processChunkWithRetry($chunk);
                $processed += $chunkSize;

                // Progress callback
                $callback = $this->config['progress_callback'];

                if (is_callable($callback)) {
                    $callback(
                        $index + 1,
                        count($chunks),
                        $processed,
                    );
                }
            } catch (Exception $e) {
                $failed += $chunkSize;
                $errors[] = sprintf('Chunk %s: %s', $index, $e->getMessage());

                if ($this->config['fail_fast']) {
                    throw $e;
                }
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Process a single chunk with retry logic.
     *
     * @param array{writes: array<int, array{user: string, relation: string, object: string}>, deletes: array<int, array{user: string, relation: string, object: string}>} $chunk
     *
     * @throws Exception        When processing fails after all retries
     * @throws RuntimeException When processing fails without a specific exception
     */
    private function processChunkWithRetry(array $chunk): void
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->config['max_retries']) {
            try {
                // Convert arrays to TupleKeysInterface for the manager
                $writeTuples = null;
                $deleteTuples = null;

                if (0 < count($chunk['writes'])) {
                    $writeTuples = new TupleKeys;

                    foreach ($chunk['writes'] as $write) {
                        $writeTuples->add(new TupleKey(
                            user: $write['user'],
                            relation: $write['relation'],
                            object: $write['object'],
                        ));
                    }
                }

                if (0 < count($chunk['deletes'])) {
                    $deleteTuples = new TupleKeys;

                    foreach ($chunk['deletes'] as $delete) {
                        $deleteTuples->add(new TupleKey(
                            user: $delete['user'],
                            relation: $delete['relation'],
                            object: $delete['object'],
                        ));
                    }
                }

                $this->manager->write($writeTuples, $deleteTuples);

                return;
            } catch (Exception $e) {
                ++$attempts;
                $lastException = $e;

                if ($attempts < $this->config['max_retries']) {
                    usleep((int) ($this->config['retry_delay'] * 1000 * $attempts)); // Exponential backoff
                }

                Log::warning('Batch chunk processing failed, retrying', [
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($lastException instanceof Exception) {
            throw $lastException;
        }

        throw new RuntimeException('Failed to process chunk after retries');
    }
}
