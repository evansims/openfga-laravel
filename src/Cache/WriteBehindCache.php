<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Cache;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Jobs\FlushWriteBehindCacheJob;
use OpenFGA\Laravel\OpenFgaManager;

class WriteBehindCache
{
    protected Cache $cache;
    protected Queue $queue;
    protected OpenFgaManager $manager;
    protected array $pendingWrites = [];
    protected array $pendingDeletes = [];
    protected int $batchSize;
    protected int $flushInterval;
    protected ?string $lastFlushTime = null;

    public function __construct(
        Cache $cache,
        Queue $queue,
        OpenFgaManager $manager,
        int $batchSize = 100,
        int $flushInterval = 5 // seconds
    ) {
        $this->cache = $cache;
        $this->queue = $queue;
        $this->manager = $manager;
        $this->batchSize = $batchSize;
        $this->flushInterval = $flushInterval;
    }

    /**
     * Add a write operation to the buffer
     */
    public function write(string $user, string $relation, string $object): void
    {
        $key = $this->getTupleKey($user, $relation, $object);
        
        // Remove from deletes if present
        unset($this->pendingDeletes[$key]);
        
        // Add to writes
        $this->pendingWrites[$key] = [
            'user' => $user,
            'relation' => $relation,
            'object' => $object,
            'timestamp' => now()->timestamp,
        ];

        // Update cache immediately for read consistency
        $this->updateCache($user, $relation, $object, true);

        // Check if we should flush
        $this->checkFlushConditions();
    }

    /**
     * Add a delete operation to the buffer
     */
    public function delete(string $user, string $relation, string $object): void
    {
        $key = $this->getTupleKey($user, $relation, $object);
        
        // Remove from writes if present
        unset($this->pendingWrites[$key]);
        
        // Add to deletes
        $this->pendingDeletes[$key] = [
            'user' => $user,
            'relation' => $relation,
            'object' => $object,
            'timestamp' => now()->timestamp,
        ];

        // Update cache immediately
        $this->updateCache($user, $relation, $object, false);

        // Check if we should flush
        $this->checkFlushConditions();
    }

    /**
     * Force flush all pending operations
     */
    public function flush(): array
    {
        if (empty($this->pendingWrites) && empty($this->pendingDeletes)) {
            return ['writes' => 0, 'deletes' => 0];
        }

        $writes = array_values($this->pendingWrites);
        $deletes = array_values($this->pendingDeletes);

        try {
            // Perform the actual write to OpenFGA
            if (! empty($writes) || ! empty($deletes)) {
                $this->manager->write(
                    array_map(fn($w) => [
                        'user' => $w['user'],
                        'relation' => $w['relation'],
                        'object' => $w['object'],
                    ], $writes),
                    array_map(fn($d) => [
                        'user' => $d['user'],
                        'relation' => $d['relation'],
                        'object' => $d['object'],
                    ], $deletes)
                );
            }

            $stats = [
                'writes' => count($writes),
                'deletes' => count($deletes),
            ];

            // Clear buffers
            $this->pendingWrites = [];
            $this->pendingDeletes = [];
            $this->lastFlushTime = now()->toDateTimeString();

            return $stats;
        } catch (\Exception $e) {
            Log::error('Write-behind cache flush failed', [
                'error' => $e->getMessage(),
                'writes' => count($writes),
                'deletes' => count($deletes),
            ]);

            throw $e;
        }
    }

    /**
     * Check if we should flush based on conditions
     */
    protected function checkFlushConditions(): void
    {
        $totalOperations = count($this->pendingWrites) + count($this->pendingDeletes);

        // Flush if batch size reached
        if ($totalOperations >= $this->batchSize) {
            $this->scheduleFlush();
            return;
        }

        // Flush if time interval passed
        if ($this->lastFlushTime) {
            $secondsSinceFlush = now()->timestamp - strtotime($this->lastFlushTime);
            if ($secondsSinceFlush >= $this->flushInterval) {
                $this->scheduleFlush();
            }
        }
    }

    /**
     * Schedule a flush job
     */
    protected function scheduleFlush(): void
    {
        // Dispatch job to flush the cache
        dispatch(new FlushWriteBehindCacheJob())->onQueue('openfga-write-behind');
    }

    /**
     * Update the read cache immediately
     */
    protected function updateCache(string $user, string $relation, string $object, bool $allowed): void
    {
        $cacheKey = "openfga.check.{$user}.{$relation}.{$object}";
        $ttl = config('openfga.cache.write_behind_ttl', 300);
        
        $this->cache->put($cacheKey, $allowed, $ttl);
    }

    /**
     * Get a unique key for a tuple
     */
    protected function getTupleKey(string $user, string $relation, string $object): string
    {
        return md5("{$user}:{$relation}:{$object}");
    }

    /**
     * Get pending operations count
     */
    public function getPendingCount(): array
    {
        return [
            'writes' => count($this->pendingWrites),
            'deletes' => count($this->pendingDeletes),
            'total' => count($this->pendingWrites) + count($this->pendingDeletes),
        ];
    }

    /**
     * Clear all pending operations without flushing
     */
    public function clear(): void
    {
        $this->pendingWrites = [];
        $this->pendingDeletes = [];
    }

    /**
     * Get all pending operations
     */
    public function getPendingOperations(): array
    {
        return [
            'writes' => $this->pendingWrites,
            'deletes' => $this->pendingDeletes,
        ];
    }
}