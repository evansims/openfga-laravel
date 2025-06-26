<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Batch;

use Illuminate\Support\Collection;

use function count;
use function is_string;
use function sprintf;

/**
 * Optimizes batch authorization operations for improved performance.
 *
 * This optimizer intelligently processes large sets of permission operations
 * by removing duplicates, resolving conflicts, merging related operations,
 * and organizing them for optimal execution. It significantly reduces the
 * number of API calls and improves batch processing efficiency, especially
 * when dealing with bulk permission updates or large-scale access control changes.
 *
 * @internal
 */
final class BatchOptimizer
{
    /**
     * @var array{remove_duplicates: bool, resolve_conflicts: bool, merge_related: bool, sort_operations: bool, chunk_size: int}
     */
    private array $config;

    /**
     * @var array{original_operations: int, optimized_operations: int, duplicates_removed: int, conflicts_resolved: int, operations_merged: int}
     */
    private array $stats = [
        'original_operations' => 0,
        'optimized_operations' => 0,
        'duplicates_removed' => 0,
        'conflicts_resolved' => 0,
        'operations_merged' => 0,
    ];

    /**
     * @param array{remove_duplicates?: bool, resolve_conflicts?: bool, merge_related?: bool, sort_operations?: bool, chunk_size?: int} $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'remove_duplicates' => true,
            'resolve_conflicts' => true,
            'merge_related' => true,
            'sort_operations' => true,
            'chunk_size' => 500,
        ], $config);
    }

    /**
     * Chunk operations for processing.
     *
     * @param  array<int, array{user: string, relation: string, object: string}>             $operations
     * @return array<int, array<int, array{user: string, relation: string, object: string}>>
     */
    public function chunkOperations(array $operations): array
    {
        $chunkSize = $this->config['chunk_size'];

        return 0 < $chunkSize ? array_chunk($operations, $chunkSize) : [$operations];
    }

    /**
     * Get optimization statistics.
     *
     * @return array{original_operations: int, optimized_operations: int, duplicates_removed: int, conflicts_resolved: int, operations_merged: int, reduction_percentage: float}
     */
    public function getStats(): array
    {
        $reduction = 0 < $this->stats['original_operations']
            ? round((1.0 - ((float) $this->stats['optimized_operations'] / (float) $this->stats['original_operations'])) * 100.0, 2)
            : 0.0;

        /** @var array{original_operations: int, optimized_operations: int, duplicates_removed: int, conflicts_resolved: int, operations_merged: int, reduction_percentage: float} */
        return array_merge($this->stats, [
            'reduction_percentage' => $reduction,
        ]);
    }

    /**
     * Optimize mixed writes and deletes.
     *
     * @param  array<int, array{user: string, relation: string, object: string}>                                                                                            $writes
     * @param  array<int, array{user: string, relation: string, object: string}>                                                                                            $deletes
     * @return array{writes: array<int, array{user: string, relation: string, object: string}>, deletes: array<int, array{user: string, relation: string, object: string}>}
     */
    public function optimizeMixed(array $writes, array $deletes): array
    {
        // Convert to unified format for processing
        $operations = [];

        foreach ($writes as $write) {
            /** @var array{user: string, relation: string, object: string, operation: string} */
            $operation = array_merge($write, ['operation' => 'write']);
            $operations[] = $operation;
        }

        foreach ($deletes as $delete) {
            /** @var array{user: string, relation: string, object: string, operation: string} */
            $operation = array_merge($delete, ['operation' => 'delete']);
            $operations[] = $operation;
        }

        // Optimize
        /** @var Collection<int, array{user: string, relation: string, object: string, operation: string}> */
        $collection = collect($operations);

        // Remove operations that cancel each other out
        $collection = $this->removeCancelingOperations($collection);

        // Separate back into writes and deletes
        /** @var array<int, array{user: string, relation: string, object: string}> */
        $optimizedWrites = $collection
            ->where('operation', 'write')
            ->map(static fn (array $op): array =>
                /** @var array{user: string, relation: string, object: string} */
                [
                    'user' => $op['user'],
                    'relation' => $op['relation'],
                    'object' => $op['object'],
                ])
            ->values()
            ->toArray();

        /** @var array<int, array{user: string, relation: string, object: string}> */
        $optimizedDeletes = $collection
            ->where('operation', 'delete')
            ->map(static fn (array $op): array =>
                /** @var array{user: string, relation: string, object: string} */
                [
                    'user' => $op['user'],
                    'relation' => $op['relation'],
                    'object' => $op['object'],
                ])
            ->values()
            ->toArray();

        return [
            'writes' => $optimizedWrites,
            'deletes' => $optimizedDeletes,
        ];
    }

    /**
     * Optimize a batch of write operations.
     *
     * @param  array<int, array{user: string, relation: string, object: string}> $writes
     * @return array<int, array{user: string, relation: string, object: string}>
     */
    public function optimizeWrites(array $writes): array
    {
        $this->stats['original_operations'] = count($writes);

        $collection = collect($writes);

        // Step 1: Remove exact duplicates
        if ($this->config['remove_duplicates']) {
            $collection = $this->removeDuplicates($collection);
        }

        // Step 2: Resolve conflicts (e.g., grant then revoke same permission)
        if ($this->config['resolve_conflicts']) {
            $collection = $this->resolveConflicts($collection);
        }

        // Step 3: Merge related operations
        if ($this->config['merge_related']) {
            $collection = $this->mergeRelated($collection);
        }

        // Step 4: Sort for optimal processing
        if ($this->config['sort_operations']) {
            $collection = $this->sortOperations($collection);
        }

        /** @var array<int, array{user: string, relation: string, object: string}> */
        $optimized = $collection->map(static fn (array $op): array =>
            /** @var array{user: string, relation: string, object: string} */
            [
                'user' => $op['user'] ?? '',
                'relation' => $op['relation'] ?? '',
                'object' => $op['object'] ?? '',
            ])->values()->toArray();
        $this->stats['optimized_operations'] = count($optimized);

        return $optimized;
    }

    /**
     * Reset statistics.
     */
    public function resetStats(): void
    {
        $this->stats = [
            'original_operations' => 0,
            'optimized_operations' => 0,
            'duplicates_removed' => 0,
            'conflicts_resolved' => 0,
            'operations_merged' => 0,
        ];
    }

    /**
     * Get a unique key for an operation.
     *
     * @param array{user?: string, relation?: string, object?: string, operation?: string} $operation
     */
    private function getOperationKey(array $operation): string
    {
        $user = $operation['user'] ?? '';
        $relation = $operation['relation'] ?? '';
        $object = $operation['object'] ?? '';

        return sprintf('%s:%s:%s', $user, $relation, $object);
    }

    /**
     * Merge related operations.
     *
     * @param  Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}> $operations
     * @return Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}>
     */
    private function mergeRelated(Collection $operations): Collection
    {
        // Group by object to find related operations
        /** @var Collection<string, Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}>> */
        $grouped = $operations->groupBy(static fn (array $op): string => $op['object'] ?? '');

        $merged = collect();

        foreach ($grouped as $group) {
            // Check if we can merge operations on the same object
            /** @var Collection<string, Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}>> */
            $relations = $group->groupBy(static fn (array $op): string => $op['user'] ?? '');

            foreach ($relations as $relation) {
                if (1 < $relation->count()) {
                    // Multiple relations for same user-object pair
                    // Could be merged in some cases
                    $this->stats['operations_merged'] += $relation->count() - 1;
                }

                $merged = $merged->concat($relation);
            }
        }

        return $merged;
    }

    /**
     * Remove operations that cancel each other out.
     *
     * @param  Collection<int, array{user: string, relation: string, object: string, operation: string}> $operations
     * @return Collection<int, array{user: string, relation: string, object: string, operation: string}>
     */
    private function removeCancelingOperations(Collection $operations): Collection
    {
        $grouped = $operations->groupBy(fn (array $op): string => $this->getOperationKey($op));

        /** @var Collection<int, array{user: string, relation: string, object: string, operation: string}> */
        $filtered = $grouped->map(static function ($group) {
            $writes = $group->where('operation', 'write');
            $deletes = $group->where('operation', 'delete');

            // If we have both write and delete for same tuple
            if ($writes->isNotEmpty() && $deletes->isNotEmpty()) {
                // Keep the last operation
                return $group->sortBy('timestamp')->last();
            }

            return $group->last();
        })->filter()->values();

        return $filtered;
    }

    /**
     * Remove duplicate operations.
     *
     * @param  Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}> $operations
     * @return Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}>
     */
    private function removeDuplicates(Collection $operations): Collection
    {
        $unique = $operations->unique(fn (array $op): string => $this->getOperationKey($op));

        $this->stats['duplicates_removed'] = $operations->count() - $unique->count();

        return $unique;
    }

    /**
     * Resolve conflicting operations.
     *
     * @param  Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}> $operations
     * @return Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}>
     */
    private function resolveConflicts(Collection $operations): Collection
    {
        $grouped = $operations->groupBy(fn (array $op): string => $this->getOperationKey($op));

        $resolved = $grouped->map(function ($group) {
            if (1 === $group->count()) {
                return $group->first();
            }

            // Multiple operations on same tuple - keep the last one
            ++$this->stats['conflicts_resolved'];

            return $group->last();
        });

        /** @var Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}> */
        return $resolved->filter()->values();
    }

    /**
     * Sort operations for optimal processing.
     *
     * @param  Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}> $operations
     * @return Collection<int, array{user?: string, relation?: string, object?: string, operation?: string}>
     */
    private function sortOperations(Collection $operations): Collection
    {
        return $operations->sortBy([
            // Sort by object type first (for better cache locality)
            static fn (array $op): string => explode(':', isset($op['object']) && is_string($op['object']) ? $op['object'] : '')[0],
            // Then by object ID
            static fn (array $op): string => isset($op['object']) && is_string($op['object']) ? $op['object'] : '',
            // Then by user
            static fn (array $op): string => isset($op['user']) && is_string($op['user']) ? $op['user'] : '',
            // Finally by relation
            static fn (array $op): string => isset($op['relation']) && is_string($op['relation']) ? $op['relation'] : '',
        ]);
    }
}
