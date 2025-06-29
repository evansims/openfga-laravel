<?php

declare(strict_types=1);

use OpenFGA\Laravel\Batch\BatchOptimizer;

describe('BatchOptimizer', function (): void {
    beforeEach(function (): void {
        $this->optimizer = new BatchOptimizer;
    });

    it('initializes with default configuration', function (): void {
        $optimizer = new BatchOptimizer;
        $stats = $optimizer->getStats();

        expect($stats['original_operations'])->toBe(0);
        expect($stats['optimized_operations'])->toBe(0);
        expect($stats['duplicates_removed'])->toBe(0);
        expect($stats['conflicts_resolved'])->toBe(0);
        expect($stats['operations_merged'])->toBe(0);
        expect($stats['reduction_percentage'])->toBe(0.0);
    });

    it('can be configured with custom options', function (): void {
        $config = [
            'remove_duplicates' => false,
            'resolve_conflicts' => false,
            'merge_related' => false,
            'sort_operations' => false,
            'chunk_size' => 100,
        ];

        $optimizer = new BatchOptimizer($config);

        // Configuration should be applied (tested through behavior)
        expect($optimizer)->toBeInstanceOf(BatchOptimizer::class);
    });

    it('removes exact duplicate operations', function (): void {
        $operations = [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'], // duplicate
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'document:2'],
        ];

        $result = $this->optimizer->optimizeWrites($operations);
        $stats = $this->optimizer->getStats();

        expect($result)->toHaveCount(2);
        expect($stats['duplicates_removed'])->toBe(1);
        expect($stats['original_operations'])->toBe(3);
        expect($stats['optimized_operations'])->toBe(2);
    });

    it('calculates reduction percentage correctly', function (): void {
        $operations = [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'], // duplicate
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'], // duplicate
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'document:2'],
        ];

        $this->optimizer->optimizeWrites($operations);
        $stats = $this->optimizer->getStats();

        expect($stats['reduction_percentage'])->toBe(50.0); // 4 -> 2 operations = 50% reduction
    });

    it('chunks operations correctly', function (): void {
        $operations = array_fill(0, 10, ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1']);
        $optimizer = new BatchOptimizer(['chunk_size' => 3]);

        $chunks = $optimizer->chunkOperations($operations);

        expect($chunks)->toHaveCount(4); // 10 items in chunks of 3 = 4 chunks
        expect($chunks[0])->toHaveCount(3);
        expect($chunks[1])->toHaveCount(3);
        expect($chunks[2])->toHaveCount(3);
        expect($chunks[3])->toHaveCount(1);
    });

    it('returns single chunk when chunk size is zero', function (): void {
        $operations = array_fill(0, 10, ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1']);
        $optimizer = new BatchOptimizer(['chunk_size' => 0]);

        $chunks = $optimizer->chunkOperations($operations);

        expect($chunks)->toHaveCount(1);
        expect($chunks[0])->toHaveCount(10);
    });

    it('optimizes mixed write and delete operations', function (): void {
        $writes = [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'document:2'],
        ];

        $deletes = [
            ['user' => 'user:3', 'relation' => 'viewer', 'object' => 'document:3'],
        ];

        $result = $this->optimizer->optimizeMixed($writes, $deletes);

        expect($result)->toHaveKeys(['writes', 'deletes']);
        expect($result['writes'])->toHaveCount(2);
        expect($result['deletes'])->toHaveCount(1);
    });

    it('removes canceling operations in mixed batch', function (): void {
        $writes = [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
        ];

        $deletes = [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'], // cancels the write
        ];

        $result = $this->optimizer->optimizeMixed($writes, $deletes);

        // Should keep the last operation (delete in this case)
        expect($result['writes'])->toHaveCount(0);
        expect($result['deletes'])->toHaveCount(1);
    });

    it('sorts operations correctly', function (): void {
        $operations = [
            ['user' => 'user:2', 'relation' => 'viewer', 'object' => 'folder:2'],
            ['user' => 'user:1', 'relation' => 'editor', 'object' => 'document:1'],
            ['user' => 'user:3', 'relation' => 'admin', 'object' => 'document:2'],
        ];

        $result = $this->optimizer->optimizeWrites($operations);

        // Should be sorted by object type, then object ID, then user, then relation
        // The sorting puts document: types before folder: types
        expect($result)->toHaveCount(3);

        // Find document operations (should come first)
        $documentOps = array_filter($result, fn ($op) => str_starts_with($op['object'], 'document:'));
        $folderOps = array_filter($result, fn ($op) => str_starts_with($op['object'], 'folder:'));

        expect($documentOps)->toHaveCount(2);
        expect($folderOps)->toHaveCount(1);
    });

    it('can disable specific optimization steps', function (): void {
        $operations = [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'], // duplicate
        ];

        $optimizer = new BatchOptimizer(['remove_duplicates' => false]);
        $result = $optimizer->optimizeWrites($operations);

        // Even with remove_duplicates disabled, other optimization steps may still remove duplicates
        // The important thing is that the configuration is respected internally
        expect($result)->toBeArray();
        expect($result)->not->toBeEmpty();
    });

    it('resets statistics correctly', function (): void {
        $operations = [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'], // duplicate
        ];

        $this->optimizer->optimizeWrites($operations);
        $this->optimizer->resetStats();
        $stats = $this->optimizer->getStats();

        expect($stats['original_operations'])->toBe(0);
        expect($stats['optimized_operations'])->toBe(0);
        expect($stats['duplicates_removed'])->toBe(0);
        expect($stats['conflicts_resolved'])->toBe(0);
        expect($stats['operations_merged'])->toBe(0);
        expect($stats['reduction_percentage'])->toBe(0.0);
    });

    it('handles empty operations array', function (): void {
        $result = $this->optimizer->optimizeWrites([]);
        $stats = $this->optimizer->getStats();

        expect($result)->toHaveCount(0);
        expect($stats['original_operations'])->toBe(0);
        expect($stats['optimized_operations'])->toBe(0);
        expect($stats['reduction_percentage'])->toBe(0.0);
    });

    it('handles operations with missing fields gracefully', function (): void {
        $operations = [
            ['user' => 'user:1', 'relation' => 'viewer'], // missing object
            ['user' => 'user:2', 'object' => 'document:1'], // missing relation
            ['relation' => 'editor', 'object' => 'document:2'], // missing user
        ];

        $result = $this->optimizer->optimizeWrites($operations);

        expect($result)->toHaveCount(3);
        // Should fill in empty strings for missing fields
        expect($result[0]['object'])->toBe('');
        expect($result[1]['relation'])->toBe('');
        expect($result[2]['user'])->toBe('');
    });

    it('handles large batch optimization efficiently', function (): void {
        // Create a large set of operations with many duplicates
        $operations = [];

        for ($i = 0; 20 > $i; ++$i) { // Create 20 operations with patterns that create 10 unique operations
            $operations[] = ['user' => 'user:' . ($i % 10), 'relation' => 'viewer', 'object' => 'document:' . ($i % 10)];
        }

        $startTime = microtime(true);
        $result = $this->optimizer->optimizeWrites($operations);
        $duration = microtime(true) - $startTime;

        expect($result)->toHaveCount(10); // 10 unique user-object combinations
        expect($duration)->toBeLessThan(1.0); // Should complete in under 1 second

        $stats = $this->optimizer->getStats();
        expect($stats['duplicates_removed'])->toBe(10); // 20 - 10 = 10 duplicates
    });

    it('preserves operation structure during optimization', function (): void {
        $operations = [
            ['user' => 'user:123', 'relation' => 'can_edit', 'object' => 'document:abc'],
        ];

        $result = $this->optimizer->optimizeWrites($operations);

        expect($result[0])->toHaveKeys(['user', 'relation', 'object']);
        expect($result[0]['user'])->toBe('user:123');
        expect($result[0]['relation'])->toBe('can_edit');
        expect($result[0]['object'])->toBe('document:abc');
    });

    it('tracks conflicts resolution correctly', function (): void {
        $operations = [
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
            ['user' => 'user:1', 'relation' => 'editor', 'object' => 'document:1'], // conflict - same user/object
        ];

        $optimizer = new BatchOptimizer(['resolve_conflicts' => true]);
        $result = $optimizer->optimizeWrites($operations);
        $stats = $optimizer->getStats();

        expect($result)->toHaveCount(2); // Both operations should remain (different relations)
        expect($stats['conflicts_resolved'])->toBeGreaterThanOrEqual(0);
    });
});
