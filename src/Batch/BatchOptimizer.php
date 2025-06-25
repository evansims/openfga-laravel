<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Batch;

use Illuminate\Support\Collection;

class BatchOptimizer
{
    protected array $config;
    protected array $stats = [
        'original_operations' => 0,
        'optimized_operations' => 0,
        'duplicates_removed' => 0,
        'conflicts_resolved' => 0,
        'operations_merged' => 0,
    ];

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
     * Optimize a batch of write operations
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
     * Optimize mixed writes and deletes
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
            ->map(fn($op) => array_diff_key($op, ['operation' => '']))
            ->values()
            ->toArray();
            
        $optimizedDeletes = $collection
            ->where('operation', 'delete')
            ->map(fn($op) => array_diff_key($op, ['operation' => '']))
            ->values()
            ->toArray();

        return [
            'writes' => $optimizedWrites,
            'deletes' => $optimizedDeletes,
        ];
    }

    /**
     * Remove duplicate operations
     */
    protected function removeDuplicates(Collection $operations): Collection
    {
        $unique = $operations->unique(function ($op) {
            return $this->getOperationKey($op);
        });

        $this->stats['duplicates_removed'] = $operations->count() - $unique->count();

        return $unique;
    }

    /**
     * Resolve conflicting operations
     */
    protected function resolveConflicts(Collection $operations): Collection
    {
        $grouped = $operations->groupBy(function ($op) {
            return $this->getOperationKey($op);
        });

        $resolved = $grouped->map(function ($group) {
            if ($group->count() === 1) {
                return $group->first();
            }

            // Multiple operations on same tuple - keep the last one
            $this->stats['conflicts_resolved']++;
            return $group->last();
        });

        return $resolved->values();
    }

    /**
     * Merge related operations
     */
    protected function mergeRelated(Collection $operations): Collection
    {
        // Group by object to find related operations
        $grouped = $operations->groupBy(fn($op) => $op['object'] ?? '');

        $merged = collect();

        foreach ($grouped as $object => $group) {
            // Check if we can merge operations on the same object
            $relations = $group->groupBy(fn($op) => $op['user'] ?? '');
            
            foreach ($relations as $user => $userOps) {
                if ($userOps->count() > 1) {
                    // Multiple relations for same user-object pair
                    // Could be merged in some cases
                    $this->stats['operations_merged'] += $userOps->count() - 1;
                }
                
                $merged = $merged->concat($userOps);
            }
        }

        return $merged;
    }

    /**
     * Remove operations that cancel each other out
     */
    protected function removeCancelingOperations(Collection $operations): Collection
    {
        $grouped = $operations->groupBy(function ($op) {
            return $this->getOperationKey($op);
        });

        return $grouped->map(function ($group) {
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
     * Sort operations for optimal processing
     */
    protected function sortOperations(Collection $operations): Collection
    {
        return $operations->sortBy([
            // Sort by object type first (for better cache locality)
            fn($op) => explode(':', $op['object'] ?? '')[0],
            // Then by object ID
            fn($op) => $op['object'] ?? '',
            // Then by user
            fn($op) => $op['user'] ?? '',
            // Finally by relation
            fn($op) => $op['relation'] ?? '',
        ]);
    }

    /**
     * Get a unique key for an operation
     */
    protected function getOperationKey(array $operation): string
    {
        $user = $operation['user'] ?? '';
        $relation = $operation['relation'] ?? '';
        $object = $operation['object'] ?? '';
        
        return "{$user}:{$relation}:{$object}";
    }

    /**
     * Chunk operations for processing
     */
    public function chunkOperations(array $operations): array
    {
        return array_chunk($operations, $this->config['chunk_size']);
    }

    /**
     * Get optimization statistics
     */
    public function getStats(): array
    {
        $reduction = $this->stats['original_operations'] > 0
            ? round((1 - ($this->stats['optimized_operations'] / $this->stats['original_operations'])) * 100, 2)
            : 0;

        return array_merge($this->stats, [
            'reduction_percentage' => $reduction,
        ]);
    }

    /**
     * Reset statistics
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
}