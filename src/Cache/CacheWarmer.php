<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Cache;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Events\CacheWarmed;

use function count;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Intelligent cache warming for OpenFGA permissions.
 *
 * @internal
 */
final class CacheWarmer
{
    /**
     * Create a new cache warmer instance.
     *
     * @param ManagerInterface     $manager
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly ManagerInterface $manager,
        private array $config = [],
    ) {
        $this->config = array_merge([
            'batch_size' => 100,
            'ttl' => config('openfga.cache.ttl', 300),
            'prefix' => config('openfga.cache.prefix', 'openfga'),
        ], $config);
    }

    /**
     * Invalidate cache for specific patterns.
     *
     * @param  ?string $user     User pattern (null for all)
     * @param  ?string $relation Relation pattern (null for all)
     * @param  ?string $object   Object pattern (null for all)
     * @return int     Number of entries invalidated
     */
    public function invalidate(?string $user = null, ?string $relation = null, ?string $object = null): int
    {
        $pattern = $this->buildCachePattern($user, $relation, $object);

        // If cache store supports pattern deletion
        $store = Cache::store();

        if (method_exists($store, 'deletePattern')) {
            /** @var mixed $result */
            $result = $store->deletePattern($pattern);

            return is_int($result) || is_numeric($result) ? (int) $result : 0;
        }

        // Fallback: track invalidated entries
        // In production, you'd want to use Redis SCAN or similar
        return 0;
    }

    /**
     * Warm cache for multiple users and objects.
     *
     * @param  array<string> $users     User identifiers
     * @param  array<string> $relations Relations to warm
     * @param  array<string> $objects   Objects to warm
     * @return int           Number of entries warmed
     */
    public function warmBatch(array $users, array $relations, array $objects): int
    {
        $warmed = 0;
        $checks = [];

        foreach ($users as $user) {
            foreach ($objects as $object) {
                foreach ($relations as $relation) {
                    $checks[] = [$user, $relation, $object];
                }
            }
        }

        // Process in batches
        $batchSize = isset($this->config['batch_size']) && is_int($this->config['batch_size']) && 0 < $this->config['batch_size'] ? $this->config['batch_size'] : 100;

        foreach (array_chunk($checks, $batchSize) as $batch) {
            $batchChecks = array_map(static fn ($check): array => ['user' => $check[0], 'relation' => $check[1], 'object' => $check[2]], $batch);
            $results = $this->manager->batchCheck($batchChecks);

            foreach ($batch as $check) {
                [$user, $relation, $object] = $check;
                $key = sprintf('%s:%s:%s', $user, $relation, $object);
                $result = $results[$key] ?? false;

                $this->cacheResult($user, $relation, $object, $result);
                ++$warmed;
            }
        }

        return $warmed;
    }

    /**
     * Warm cache for a specific user.
     *
     * @param  string        $user      User identifier
     * @param  array<string> $relations Relations to warm
     * @param  array<string> $objects   Objects to warm
     * @return int           Number of entries warmed
     */
    public function warmForUser(string $user, array $relations, array $objects): int
    {
        $warmed = 0;
        $checks = [];

        foreach ($objects as $object) {
            foreach ($relations as $relation) {
                $checks[] = [$user, $relation, $object];
            }
        }

        // Process in batches
        $batchSize = isset($this->config['batch_size']) && is_int($this->config['batch_size']) && 0 < $this->config['batch_size'] ? $this->config['batch_size'] : 100;

        foreach (array_chunk($checks, $batchSize) as $batch) {
            $batchChecks = array_map(static fn ($check): array => ['user' => $check[0], 'relation' => $check[1], 'object' => $check[2]], $batch);
            $results = $this->manager->batchCheck($batchChecks);

            foreach ($batch as $check) {
                [$checkUser, $checkRelation, $checkObject] = $check;
                $key = sprintf('%s:%s:%s', $checkUser, $checkRelation, $checkObject);
                $result = $results[$key] ?? false;

                $this->cacheResult($checkUser, $checkRelation, $checkObject, $result);
                ++$warmed;
            }
        }

        event(new CacheWarmed($user, $warmed));

        return $warmed;
    }

    /**
     * Warm cache based on recent activity patterns.
     *
     * @param  int $limit Maximum number of entries to warm
     * @return int Number of entries warmed
     */
    public function warmFromActivity(int $limit = 1000): int
    {
        $recentChecks = $this->getRecentChecks();

        if ($recentChecks->isEmpty()) {
            return 0;
        }

        $warmed = 0;
        $batch = [];

        foreach ($recentChecks as $recentCheck) {
            $batch[] = [$recentCheck['user'], $recentCheck['relation'], $recentCheck['object']];

            if (isset($this->config['batch_size']) && count($batch) >= $this->config['batch_size']) {
                $warmed += $this->processBatch($batch);
                $batch = [];
            }
        }

        if ([] !== $batch) {
            $warmed += $this->processBatch($batch);
        }

        return $warmed;
    }

    /**
     * Warm cache for hierarchical permissions.
     *
     * @param  string        $user                  User identifier
     * @param  string        $object                Object identifier
     * @param  array<string> $hierarchicalRelations Relations in hierarchical order (e.g., ['viewer', 'editor', 'owner'])
     * @return int           Number of entries warmed
     */
    public function warmHierarchy(string $user, string $object, array $hierarchicalRelations): int
    {
        $warmed = 0;
        $hasPermission = false;

        // Check from highest to lowest permission
        foreach (array_reverse($hierarchicalRelations) as $relation) {
            if (! $hasPermission) {
                $hasPermission = $this->manager->check($user, $relation, $object);
            }

            // If user has higher permission, they have all lower permissions
            $this->cacheResult($user, $relation, $object, $hasPermission);
            ++$warmed;
        }

        return $warmed;
    }

    /**
     * Warm cache for related objects based on a source object.
     *
     * @param  string        $user         User identifier
     * @param  string        $sourceObject Source object to expand from
     * @param  array<string> $relations    Relations to check
     * @return int           Number of entries warmed
     */
    public function warmRelated(string $user, string $sourceObject, array $relations): int
    {
        $warmed = 0;

        // Extract type from source object
        $objectType = explode(':', $sourceObject)[0];

        // Get all objects of the same type the user has access to
        foreach ($relations as $relationForList) {
            $objects = $this->manager->listObjects($user, $relationForList, $objectType);

            // Warm cache for each found object
            foreach ($objects as $object) {
                foreach ($relations as $relation) {
                    $result = $this->manager->check($user, $relation, $object);
                    $this->cacheResult($user, $relation, $object, $result);
                    ++$warmed;
                }
            }
        }

        return $warmed;
    }

    /**
     * Build a cache pattern for invalidation.
     *
     * @param ?string $user
     * @param ?string $relation
     * @param ?string $object
     */
    private function buildCachePattern(?string $user, ?string $relation, ?string $object): string
    {
        $parts = [
            $this->config['prefix'] ?? 'openfga',
            'check',
            $user ?? '*',
            $relation ?? '*',
            $object ?? '*',
        ];

        /** @var array<array-key, string> $parts */
        return implode(':', $parts);
    }

    /**
     * Cache a permission check result.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param bool   $result
     */
    private function cacheResult(string $user, string $relation, string $object, bool $result): void
    {
        $key = $this->getCacheKey($user, $relation, $object);
        $ttl = 300;

        if (isset($this->config['ttl']) && (is_int($this->config['ttl']) || is_numeric($this->config['ttl']))) {
            $ttl = (int) $this->config['ttl'];
        }
        Cache::put($key, $result, $ttl);
    }

    /**
     * Get the cache key for a permission check.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    private function getCacheKey(string $user, string $relation, string $object): string
    {
        $prefix = 'openfga';

        if (isset($this->config['prefix']) && is_string($this->config['prefix'])) {
            $prefix = $this->config['prefix'];
        }

        return sprintf(
            '%s:check:%s:%s:%s',
            $prefix,
            $user,
            $relation,
            $object,
        );
    }

    /**
     * Get recent permission checks from activity log.
     *
     * @return Collection<int, array{user: string, relation: string, object: string}>
     */
    private function getRecentChecks(): Collection
    {
        // This would typically query an activity log or analytics table
        // For now, return empty collection
        return collect();
    }

    /**
     * Process a batch of permission checks.
     *
     * @param  array<array{0: string, 1: string, 2: string}> $batch
     * @return int                                           Number of entries processed
     */
    private function processBatch(array $batch): int
    {
        $batchChecks = array_map(static fn ($check): array => ['user' => $check[0], 'relation' => $check[1], 'object' => $check[2]], $batch);
        $results = $this->manager->batchCheck(array_values($batchChecks));

        foreach ($batch as $check) {
            [$user, $relation, $object] = $check;
            $key = sprintf('%s:%s:%s', $user, $relation, $object);
            $result = $results[$key] ?? false;

            $this->cacheResult($user, $relation, $object, $result);
        }

        return count($batch);
    }
}
