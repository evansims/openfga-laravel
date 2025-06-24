<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Cache;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Models\TupleKey;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Read-through cache implementation that automatically fetches
 * and caches permissions when they're not in cache.
 */
final class ReadThroughCache
{
    /**
     * The cache store instance.
     */
    private ?Repository $cache = null;

    /**
     * Tagged cache instance for granular invalidation.
     */
    private ?TaggedCache $taggedCache = null;

    /**
     * Create a new read-through cache instance.
     *
     * @param ManagerInterface     $manager
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly ManagerInterface $manager,
        private array $config = [],
    ) {
        $this->config = array_merge([
            'enabled' => config('openfga.cache.enabled', true),
            'store' => config('openfga.cache.store'),
            'ttl' => config('openfga.cache.ttl', 300),
            'prefix' => config('openfga.cache.prefix', 'openfga'),
            'tags_enabled' => config('openfga.cache.tags.enabled', true),
            'negative_ttl' => config('openfga.cache.negative_ttl', 60), // Cache negative results for shorter time
            'error_ttl' => config('openfga.cache.error_ttl', 10), // Cache errors for very short time
            'log_misses' => config('openfga.cache.log_misses', false),
            'metrics_enabled' => config('openfga.cache.metrics.enabled', false),
        ], $config);
    }

    /**
     * Check permission with read-through caching.
     *
     * @param string               $user
     * @param string               $relation
     * @param string               $object
     * @param array<TupleKey>      $contextualTuples
     * @param array<string, mixed> $context
     * @param ?string              $connection
     *
     * @throws Throwable
     */
    public function check(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): bool {
        if (! $this->isEnabled()) {
            return $this->fetchFromSource($user, $relation, $object, $contextualTuples, $context, $connection);
        }

        // Don't cache requests with contextual tuples or context
        if ([] !== $contextualTuples || [] !== $context) {
            return $this->fetchFromSource($user, $relation, $object, $contextualTuples, $context, $connection);
        }

        $key = $this->getCacheKey($user, $relation, $object);
        $tags = $this->getCacheTags($user, $relation, $object);

        // Try to get from cache
        $cached = $this->getFromCache($key, $tags);

        if (null !== $cached && is_array($cached) && array_key_exists('value', $cached)) {
            $this->recordHit();

            return (bool) $cached['value'];
        }

        // Cache miss - fetch from source
        $this->recordMiss();

        if (isset($this->config['log_misses']) && true === $this->config['log_misses'] && app()->bound(LoggerInterface::class)) {
            app(LoggerInterface::class)->debug('OpenFGA cache miss', [
                'user' => $user,
                'relation' => $relation,
                'object' => $object,
            ]);
        }

        try {
            $result = $this->fetchFromSource($user, $relation, $object, $contextualTuples, $context, $connection);
            $ttl = 300;

            if ($result && isset($this->config['ttl']) && (is_int($this->config['ttl']) || is_numeric($this->config['ttl']))) {
                $ttl = (int) $this->config['ttl'];
            } elseif (! $result && isset($this->config['negative_ttl']) && (is_int($this->config['negative_ttl']) || is_numeric($this->config['negative_ttl']))) {
                $ttl = (int) $this->config['negative_ttl'];
            }

            $this->putInCache($key, $tags, ['value' => $result, 'cached_at' => time()], $ttl);

            return $result;
        } catch (Throwable $throwable) {
            // Cache the error for a very short time to prevent hammering
            $errorTtl = 10;

            if (isset($this->config['error_ttl']) && (is_int($this->config['error_ttl']) || is_numeric($this->config['error_ttl']))) {
                $errorTtl = (int) $this->config['error_ttl'];
            }
            $this->putInCache($key, $tags, ['error' => true, 'cached_at' => time()], $errorTtl);

            throw $throwable;
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array{hits: int, misses: int, hit_rate: float}
     */
    public function getStats(): array
    {
        if (! isset($this->config['metrics_enabled']) || true !== $this->config['metrics_enabled']) {
            return ['hits' => 0, 'misses' => 0, 'hit_rate' => 0.0];
        }

        $prefix = 'openfga';

        if (isset($this->config['prefix']) && is_string($this->config['prefix'])) {
            $prefix = $this->config['prefix'];
        }
        $hits = 0;
        $cached_hits = $this->getCache()->get($prefix . ':stats:hits', 0);

        if (is_int($cached_hits) || is_numeric($cached_hits)) {
            $hits = (int) $cached_hits;
        }
        $misses = 0;
        $cached_misses = $this->getCache()->get($prefix . ':stats:misses', 0);

        if (is_int($cached_misses) || is_numeric($cached_misses)) {
            $misses = (int) $cached_misses;
        }
        $total = $hits + $misses;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => 0 < $total ? round((float) $hits / (float) $total * 100, 2) : 0.0,
        ];
    }

    /**
     * Invalidate cache entries.
     *
     * @param  ?string $user
     * @param  ?string $relation
     * @param  ?string $object
     * @return int     Number of entries invalidated
     */
    public function invalidate(?string $user = null, ?string $relation = null, ?string $object = null): int
    {
        if (! $this->isEnabled() || ! $this->getTaggedCache() instanceof TaggedCache) {
            return 0;
        }

        $invalidated = 0;
        $taggedCache = $this->getTaggedCache();

        // Invalidate based on what's provided
        if (null !== $user && null !== $relation && null !== $object) {
            // Specific permission
            $key = $this->getCacheKey($user, $relation, $object);
            $tags = $this->getCacheTags($user, $relation, $object);

            if ($taggedCache->forget($key, $tags)) {
                ++$invalidated;
            }
        } elseif (null !== $user) {
            // All permissions for a user
            if ($taggedCache->invalidateUser($user)) {
                ++$invalidated; // We don't know exact count
            }
        } elseif (null !== $object) {
            // All permissions for an object
            if ($taggedCache->invalidateObject($object)) {
                ++$invalidated;
            }
        } elseif (null !== $relation) {
            // All permissions for a relation
            if ($taggedCache->invalidateRelation($relation)) {
                ++$invalidated;
            }
        }

        return $invalidated;
    }

    /**
     * List objects with read-through caching.
     *
     * @param  string               $user
     * @param  string               $relation
     * @param  string               $type
     * @param  array<TupleKey>      $contextualTuples
     * @param  array<string, mixed> $context
     * @param  ?string              $connection
     * @return array<string>
     */
    public function listObjects(
        string $user,
        string $relation,
        string $type,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        if (! $this->isEnabled()) {
            return $this->manager->listObjects($user, $relation, $type, $contextualTuples, $context, $connection);
        }

        // Don't cache requests with contextual tuples or context
        if ([] !== $contextualTuples || [] !== $context) {
            return $this->manager->listObjects($user, $relation, $type, $contextualTuples, $context, $connection);
        }

        $key = $this->getListCacheKey($user, $relation, $type);
        $tags = $this->getListCacheTags($user, $relation, $type);

        // Try to get from cache
        $cached = $this->getFromCache($key, $tags);

        if (null !== $cached && is_array($cached) && array_key_exists('value', $cached)) {
            $this->recordHit();

            $value = $cached['value'];

            if (! is_array($value)) {
                return [];
            }

            /** @var array<string> $value */
            return $value;
        }

        // Cache miss - fetch from source
        $this->recordMiss();

        try {
            $result = $this->manager->listObjects($user, $relation, $type, $contextualTuples, $context, $connection);

            $ttl = 300;

            if (isset($this->config['ttl']) && (is_int($this->config['ttl']) || is_numeric($this->config['ttl']))) {
                $ttl = (int) $this->config['ttl'];
            }
            $this->putInCache($key, $tags, ['value' => $result, 'cached_at' => time()], $ttl);

            return $result;
        } catch (Throwable $throwable) {
            // Cache the error for a very short time
            $errorTtl = 10;

            if (isset($this->config['error_ttl']) && (is_int($this->config['error_ttl']) || is_numeric($this->config['error_ttl']))) {
                $errorTtl = (int) $this->config['error_ttl'];
            }
            $this->putInCache($key, $tags, ['error' => true, 'cached_at' => time()], $errorTtl);

            throw $throwable;
        }
    }

    /**
     * Reset cache statistics.
     */
    public function resetStats(): void
    {
        if (isset($this->config['metrics_enabled']) && true === $this->config['metrics_enabled']) {
            $prefix = 'openfga';

            if (isset($this->config['prefix']) && is_string($this->config['prefix'])) {
                $prefix = $this->config['prefix'];
            }
            $this->getCache()->forget($prefix . ':stats:hits');
            $this->getCache()->forget($prefix . ':stats:misses');
        }
    }

    /**
     * Fetch permission from the source.
     *
     * @param string               $user
     * @param string               $relation
     * @param string               $object
     * @param array<TupleKey>      $contextualTuples
     * @param array<string, mixed> $context
     * @param ?string              $connection
     */
    private function fetchFromSource(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples,
        array $context,
        ?string $connection,
    ): bool {
        return $this->manager->check($user, $relation, $object, $contextualTuples, $context, $connection);
    }

    /**
     * Get the cache store instance.
     */
    private function getCache(): Repository
    {
        if (! $this->cache instanceof Repository) {
            $store = is_string($this->config['store']) ? $this->config['store'] : null;
            $repository = Cache::store($store);

            if (! $repository instanceof Repository) {
                throw new RuntimeException('Cache store must return Repository instance');
            }
            $this->cache = $repository;
        }

        return $this->cache;
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
     * Get cache tags for a permission check.
     *
     * @param  string        $user
     * @param  string        $relation
     * @param  string        $object
     * @return array<string>
     */
    private function getCacheTags(string $user, string $relation, string $object): array
    {
        if (! $this->getTaggedCache() instanceof TaggedCache) {
            return [];
        }

        $prefix = 'openfga';

        if (isset($this->config['prefix']) && is_string($this->config['prefix'])) {
            $prefix = $this->config['prefix'];
        }

        return [
            $prefix . ':user:' . $user,
            $prefix . ':relation:' . $relation,
            $prefix . ':object:' . $object,
        ];
    }

    /**
     * Get a value from cache.
     *
     * @param  string        $key
     * @param  array<string> $tags
     * @return mixed
     */
    private function getFromCache(string $key, array $tags)
    {
        if ($this->getTaggedCache() instanceof TaggedCache) {
            return $this->getTaggedCache()->get($key, $tags);
        }

        return $this->getCache()->get($key);
    }

    /**
     * Get the cache key for a list objects query.
     *
     * @param string $user
     * @param string $relation
     * @param string $type
     */
    private function getListCacheKey(string $user, string $relation, string $type): string
    {
        $prefix = 'openfga';

        if (isset($this->config['prefix']) && is_string($this->config['prefix'])) {
            $prefix = $this->config['prefix'];
        }

        return sprintf(
            '%s:list:%s:%s:%s',
            $prefix,
            $user,
            $relation,
            $type,
        );
    }

    /**
     * Get cache tags for a list objects query.
     *
     * @param  string        $user
     * @param  string        $relation
     * @param  string        $type
     * @return array<string>
     */
    private function getListCacheTags(string $user, string $relation, string $type): array
    {
        if (! $this->getTaggedCache() instanceof TaggedCache) {
            return [];
        }

        $prefix = 'openfga';

        if (isset($this->config['prefix']) && is_string($this->config['prefix'])) {
            $prefix = $this->config['prefix'];
        }

        return [
            $prefix . ':user:' . $user,
            $prefix . ':relation:' . $relation,
            $prefix . ':type:' . $type,
        ];
    }

    /**
     * Get the tagged cache instance.
     */
    private function getTaggedCache(): ?TaggedCache
    {
        if (! $this->taggedCache instanceof TaggedCache && isset($this->config['tags_enabled']) && true === $this->config['tags_enabled']) {
            $this->taggedCache = new TaggedCache($this->config);
        }

        return $this->taggedCache;
    }

    /**
     * Check if caching is enabled.
     */
    private function isEnabled(): bool
    {
        return (bool) $this->config['enabled'];
    }

    /**
     * Put a value in cache.
     *
     * @param string               $key
     * @param array<string>        $tags
     * @param array<string, mixed> $value
     * @param int                  $ttl
     */
    private function putInCache(string $key, array $tags, array $value, int $ttl): bool
    {
        if ($this->getTaggedCache() instanceof TaggedCache) {
            return $this->getTaggedCache()->put($key, $value, $tags, $ttl);
        }

        return $this->getCache()->put($key, $value, $ttl);
    }

    /**
     * Record a cache hit.
     */
    private function recordHit(): void
    {
        if (isset($this->config['metrics_enabled']) && true === $this->config['metrics_enabled']) {
            $prefix = 'openfga';

            if (isset($this->config['prefix']) && is_string($this->config['prefix'])) {
                $prefix = $this->config['prefix'];
            }
            $this->getCache()->increment($prefix . ':stats:hits');
        }
    }

    /**
     * Record a cache miss.
     */
    private function recordMiss(): void
    {
        if (isset($this->config['metrics_enabled']) && true === $this->config['metrics_enabled']) {
            $prefix = 'openfga';

            if (isset($this->config['prefix']) && is_string($this->config['prefix'])) {
                $prefix = $this->config['prefix'];
            }
            $this->getCache()->increment($prefix . ':stats:misses');
        }
    }
}
