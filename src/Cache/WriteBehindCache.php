<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Cache;

use Exception;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager;
use OpenFGA\Laravel\Jobs\{FlushWriteBehindCacheJob, WriteTupleToFgaJob};

use function count;
use function sprintf;

/**
 * @internal
 */
final class WriteBehindCache
{
    private ?string $lastFlushTime = null;

    /**
     * @var array<string, array{user: string, relation: string, object: string, timestamp: int}>
     */
    private array $pendingDeletes = [];

    /**
     * @var array<string, array{user: string, relation: string, object: string, timestamp: int}>
     */
    private array $pendingWrites = [];

    public function __construct(
        private readonly Cache $cache,
        private readonly AbstractOpenFgaManager $manager,
        private readonly int $batchSize = 100,
        private readonly int $flushInterval = 5,
        private readonly bool $useQueue = false,
        private readonly ?string $queueConnection = null,
        private readonly string $queueName = 'openfga',
    ) {
    }

    /**
     * Clear all pending operations without flushing.
     */
    public function clear(): void
    {
        $this->pendingWrites = [];
        $this->pendingDeletes = [];
    }

    /**
     * Add a delete operation to the buffer.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
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
            'timestamp' => (int) now()->timestamp,
        ];

        // Update cache immediately
        $this->updateCache($user, $relation, $object, false);

        // Check if we should flush
        $this->checkFlushConditions();
    }

    /**
     * Force flush all pending operations.
     */
    /**
     * Force flush all pending operations.
     *
     * @throws ClientThrowable
     * @throws Exception
     *
     * @return array{writes: int, deletes: int}
     */
    public function flush(): array
    {
        if ([] === $this->pendingWrites && [] === $this->pendingDeletes) {
            return ['writes' => 0, 'deletes' => 0];
        }

        $writes = array_values($this->pendingWrites);
        $deletes = array_values($this->pendingDeletes);

        try {
            if ($this->useQueue) {
                // Dispatch individual jobs for each operation
                $currentConnection = $this->manager->getDefaultConnection();

                foreach ($writes as $write) {
                    WriteTupleToFgaJob::dispatch(
                        user: $write['user'],
                        relation: $write['relation'],
                        object: $write['object'],
                        operation: 'write',
                        openfgaConnection: $currentConnection,
                    )->onConnection($this->queueConnection)
                        ->onQueue($this->queueName);
                }

                foreach ($deletes as $delete) {
                    WriteTupleToFgaJob::dispatch(
                        user: $delete['user'],
                        relation: $delete['relation'],
                        object: $delete['object'],
                        operation: 'delete',
                        openfgaConnection: $currentConnection,
                    )->onConnection($this->queueConnection)
                        ->onQueue($this->queueName);
                }
            } elseif ([] !== $writes || [] !== $deletes) {
                // Perform the actual write to OpenFGA synchronously
                $this->manager->writeBatch(
                    array_map(static fn (array $w): array => [
                        'user' => $w['user'],
                        'relation' => $w['relation'],
                        'object' => $w['object'],
                    ], $writes),
                    array_map(static fn (array $d): array => [
                        'user' => $d['user'],
                        'relation' => $d['relation'],
                        'object' => $d['object'],
                    ], $deletes),
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
        } catch (Exception $exception) {
            Log::error('Write-behind cache flush failed', [
                'error' => $exception->getMessage(),
                'writes' => count($writes),
                'deletes' => count($deletes),
            ]);

            throw $exception;
        }
    }

    /**
     * Get pending operations count.
     */
    /**
     * Get pending operations count.
     *
     * @return array{writes: int, deletes: int, total: int}
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
     * Get all pending operations.
     */
    /**
     * Get all pending operations.
     *
     * @return array{writes: array<string, array{user: string, relation: string, object: string, timestamp: int}>, deletes: array<string, array{user: string, relation: string, object: string, timestamp: int}>}
     */
    public function getPendingOperations(): array
    {
        return [
            'writes' => $this->pendingWrites,
            'deletes' => $this->pendingDeletes,
        ];
    }

    /**
     * Add a write operation to the buffer.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
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
            'timestamp' => (int) now()->timestamp,
        ];

        // Update cache immediately for read consistency
        $this->updateCache($user, $relation, $object, true);

        // Check if we should flush
        $this->checkFlushConditions();
    }

    /**
     * Check if we should flush based on conditions.
     */
    private function checkFlushConditions(): void
    {
        $totalOperations = count($this->pendingWrites) + count($this->pendingDeletes);

        // Flush if batch size reached
        if ($totalOperations >= $this->batchSize) {
            $this->scheduleFlush();

            return;
        }

        // Flush if time interval passed
        $lastFlushTime = $this->lastFlushTime;

        if (null !== $lastFlushTime) {
            $secondsSinceFlush = (int) now()->timestamp - (int) strtotime($lastFlushTime);

            if ($secondsSinceFlush >= $this->flushInterval) {
                $this->scheduleFlush();
            }
        }
    }

    /**
     * Get a unique key for a tuple.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    private function getTupleKey(string $user, string $relation, string $object): string
    {
        return md5(sprintf('%s:%s:%s', $user, $relation, $object));
    }

    /**
     * Schedule a flush job.
     */
    private function scheduleFlush(): void
    {
        // Dispatch job to flush the cache
        FlushWriteBehindCacheJob::dispatch()
            ->onConnection($this->queueConnection)
            ->onQueue($this->queueName);
    }

    /**
     * Update the read cache immediately.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param bool   $allowed
     */
    private function updateCache(string $user, string $relation, string $object, bool $allowed): void
    {
        $cacheKey = sprintf('openfga.check.%s.%s.%s', $user, $relation, $object);

        /** @var int $ttl */
        $ttl = config('openfga.cache.write_behind_ttl', 300);

        $this->cache->put($cacheKey, $allowed, $ttl);
    }
}
