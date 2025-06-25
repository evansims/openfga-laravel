<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Batch;

use Illuminate\Support\Collection;

use function count;
use function sprintf;

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
        return array_chunk($operations, $this->config['chunk_size']);
    }

    /**
     * Get optimization statistics.
     *
     * @return array{original_operations: int, optimized_operations: int, duplicates_removed: int, conflicts_resolved: int, operations_merged: int, optimization_ratio: float}
     */
    public function getStats(): array
    {
        $reduction = 0 < $this->stats['original_operations']
            ? round((1 - ($this->stats['optimized_operations'] / $this->stats['original_operations'])) * 100, 2)
            : 0;

        return array_merge($this->stats, [
            'reduction_percentage' => $reduction,
        ]);
    }

    /**
     * Optimize mixed writes and deletes.
     *
     * @param array $writes
     * @param array $deletes
     */
    public function optimizeMixed(array $writes, array $deletes): array
    {
        // Convert to unified format for processing
        $operations = [];

        foreach ($writes as $write) {
            $operations[] = array_merge($write, ['operation' => 'write']);
        }

        foreach ($deletes as $delete) {
            $operations[] = array_merge($delete, ['operation' => 'delete']);
        }

        // Optimize
        $collection = collect($operations);

        // Remove operations that cancel each other out
        $collection = $this->removeCancelingOperations($collection);

        // Separate back into writes and deletes
        $optimizedWrites = $collection
            ->where('operation', 'write')
            ->map(static fn ($op): array => array_diff_key($op, ['operation' => '']))
            ->values()
            ->toArray();

        $optimizedDeletes = $collection
            ->where('operation', 'delete')
            ->map(static fn ($op): array => array_diff_key($op, ['operation' => '']))
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
     * @param array $writes
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

        $optimized = $collection->values()->toArray();
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
     * @param array $operation
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
     * @param Collection $operations
     */
    private function mergeRelated(Collection $operations): Collection
    {
        // Group by object to find related operations
        $grouped = $operations->groupBy(static fn ($op): mixed => $op['object'] ?? '');

        $merged = collect();

        foreach ($grouped as $group) {
            // Check if we can merge operations on the same object
            $relations = $group->groupBy(static fn ($op): mixed => $op['user'] ?? '');

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
     * @param Collection $operations
     */
    private function removeCancelingOperations(Collection $operations): Collection
    {
        $grouped = $operations->groupBy(fn ($op): string => $this->getOperationKey($op));

        return $grouped->map(static function ($group) {
            $writes = $group->where('operation', 'write');
            $deletes = $group->where('operation', 'delete');

            // If we have both write and delete for same tuple
            if ($writes->isNotEmpty() && $deletes->isNotEmpty()) {
                // Keep the last operation
                return $group->sortBy('timestamp')->last();
            }

            return $group->last();
        })->values();
    }

    /**
     * Remove duplicate operations.
     *
     * @param Collection $operations
     */
    private function removeDuplicates(Collection $operations): Collection
    {
        $unique = $operations->unique(fn ($op): string => $this->getOperationKey($op));

        $this->stats['duplicates_removed'] = $operations->count() - $unique->count();

        return $unique;
    }

    /**
     * Resolve conflicting operations.
     *
     * @param Collection $operations
     */
    private function resolveConflicts(Collection $operations): Collection
    {
        $grouped = $operations->groupBy(fn ($op): string => $this->getOperationKey($op));

        $resolved = $grouped->map(function ($group) {
            if (1 === $group->count()) {
                return $group->first();
            }

            // Multiple operations on same tuple - keep the last one
            ++$this->stats['conflicts_resolved'];

            return $group->last();
        });

        return $resolved->values();
    }

    /**
     * Sort operations for optimal processing.
     *
     * @param Collection $operations
     */
    private function sortOperations(Collection $operations): Collection
    {
        return $operations->sortBy([
            // Sort by object type first (for better cache locality)
            static fn ($op): string => explode(':', $op['object'] ?? '')[0],
            // Then by object ID
            static fn ($op) => $op['object'] ?? '',
            // Then by user
            static fn ($op) => $op['user'] ?? '',
            // Finally by relation
            static fn ($op) => $op['relation'] ?? '',
        ]);
    }
}
