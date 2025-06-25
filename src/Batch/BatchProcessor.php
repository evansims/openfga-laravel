<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Batch;

use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\BatchProcessed;
use OpenFGA\Laravel\Events\BatchFailed;
use OpenFGA\Laravel\OpenFgaManager;

class BatchProcessor
{
    protected OpenFgaManager $manager;
    protected BatchOptimizer $optimizer;
    protected array $config;
    protected array $stats = [
        'total_batches' => 0,
        'successful_batches' => 0,
        'failed_batches' => 0,
        'total_operations' => 0,
        'total_time' => 0,
    ];

    public function __construct(
        OpenFgaManager $manager,
        BatchOptimizer $optimizer,
        array $config = []
    ) {
        $this->manager = $manager;
        $this->optimizer = $optimizer;
        $this->config = array_merge([
            'max_retries' => 3,
            'retry_delay' => 1000, // milliseconds
            'fail_fast' => false,
            'parallel_processing' => false,
            'progress_callback' => null,
        ], $config);
    }

    /**
     * Process a large batch of operations
     */
    public function processBatch(array $writes, array $deletes = []): BatchResult
    {
        $startTime = microtime(true);
        $this->stats['total_batches']++;

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
            $this->stats['successful_batches']++;

            $result = new BatchResult(
                success: true,
                totalOperations: $totalOperations,
                processedOperations: $results['processed'],
                failedOperations: $results['failed'],
                duration: $duration,
                optimizationStats: $this->optimizer->getStats(),
                errors: $results['errors']
            );

            // Dispatch event
            event(new BatchProcessed($result));

            return $result;
        } catch (\Exception $e) {
            $this->stats['failed_batches']++;
            
            $result = new BatchResult(
                success: false,
                totalOperations: count($writes) + count($deletes),
                processedOperations: 0,
                failedOperations: count($writes) + count($deletes),
                duration: microtime(true) - $startTime,
                optimizationStats: $this->optimizer->getStats(),
                errors: [$e->getMessage()]
            );

            // Dispatch event
            event(new BatchFailed($result, $e));

            if ($this->config['fail_fast']) {
                throw $e;
            }

            return $result;
        }
    }

    /**
     * Create chunks from operations
     */
    protected function createChunks(array $writes, array $deletes): array
    {
        $writeChunks = $this->optimizer->chunkOperations($writes);
        $deleteChunks = $this->optimizer->chunkOperations($deletes);

        $chunks = [];
        $maxChunks = max(count($writeChunks), count($deleteChunks));

        for ($i = 0; $i < $maxChunks; $i++) {
            $chunks[] = [
                'writes' => $writeChunks[$i] ?? [],
                'deletes' => $deleteChunks[$i] ?? [],
            ];
        }

        return $chunks;
    }

    /**
     * Process chunks with retry logic
     */
    protected function processChunks(array $chunks): array
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
                if ($this->config['progress_callback']) {
                    call_user_func(
                        $this->config['progress_callback'],
                        $index + 1,
                        count($chunks),
                        $processed
                    );
                }
            } catch (\Exception $e) {
                $failed += $chunkSize;
                $errors[] = "Chunk {$index}: {$e->getMessage()}";

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
     * Process a single chunk with retry logic
     */
    protected function processChunkWithRetry(array $chunk): void
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->config['max_retries']) {
            try {
                $this->manager->write($chunk['writes'], $chunk['deletes']);
                return;
            } catch (\Exception $e) {
                $attempts++;
                $lastException = $e;

                if ($attempts < $this->config['max_retries']) {
                    usleep($this->config['retry_delay'] * 1000 * $attempts); // Exponential backoff
                }

                Log::warning('Batch chunk processing failed, retrying', [
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastException;
    }

    /**
     * Process operations in parallel (if supported)
     */
    public function processParallel(array $operations): BatchResult
    {
        if (! $this->config['parallel_processing']) {
            return $this->processBatch($operations);
        }

        // This would require additional implementation with process forking
        // or using a queue system for true parallel processing
        throw new \RuntimeException('Parallel processing not yet implemented');
    }

    /**
     * Get processing statistics
     */
    public function getStats(): array
    {
        $avgTime = $this->stats['total_batches'] > 0
            ? $this->stats['total_time'] / $this->stats['total_batches']
            : 0;

        $avgOps = $this->stats['total_batches'] > 0
            ? $this->stats['total_operations'] / $this->stats['total_batches']
            : 0;

        return array_merge($this->stats, [
            'average_batch_time' => round($avgTime, 3),
            'average_operations_per_batch' => round($avgOps, 2),
            'success_rate' => $this->stats['total_batches'] > 0
                ? round(($this->stats['successful_batches'] / $this->stats['total_batches']) * 100, 2)
                : 0,
        ]);
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'total_batches' => 0,
            'successful_batches' => 0,
            'failed_batches' => 0,
            'total_operations' => 0,
            'total_time' => 0,
        ];
        
        $this->optimizer->resetStats();
    }
}