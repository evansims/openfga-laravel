<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Cache;

use Illuminate\Cache\TaggedCache as LaravelTaggedCache;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

use function count;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Tagged cache implementation for granular cache invalidation.
 */
final class TaggedCache
{
    /**
     * Create a new tagged cache instance.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config = [],
    ) {
        /** @var mixed $prefix */
        $prefix = config('openfga.cache.prefix', 'openfga');

        /** @var mixed $ttl */
        $ttl = config('openfga.cache.ttl', 300);

        /** @var mixed $enabled */
        $enabled = config('openfga.cache.tags.enabled', true);

        $this->config = array_merge([
            'prefix' => is_string($prefix) ? $prefix : 'openfga',
            'ttl' => is_int($ttl) ? $ttl : 300,
            'enabled' => is_bool($enabled) ? $enabled : true,
        ], $config);
    }

    /**
     * Clear cache by tags.
     *
     * @param array<string> $tags
     */
    public function flush(array $tags): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        /** @var mixed $cache */
        $cache = $this->cache($tags);

        if ($cache instanceof LaravelTaggedCache) {
            return $cache->flush();
        }

        return false;
    }

    /**
     * Remove a value from cache.
     *
     * @param string        $key
     * @param array<string> $tags
     */
    public function forget(string $key, array $tags): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        /** @var mixed $cache */
        $cache = $this->cache($tags);

        if ($cache instanceof LaravelTaggedCache) {
            return $cache->forget($key);
        }

        return false;
    }

    /**
     * Get a value from cache using tags.
     *
     * @param string        $key
     * @param array<string> $tags
     *
     * @throws InvalidArgumentException
     *
     * @return mixed
     */
    public function get(string $key, array $tags)
    {
        if (! $this->isEnabled()) {
            return null;
        }

        /** @var mixed $cache */
        $cache = $this->cache($tags);

        if ($cache instanceof LaravelTaggedCache) {
            return $cache->get($key);
        }

        return null;
    }

    /**
     * Get a permission check result from cache.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     *
     * @throws InvalidArgumentException
     */
    public function getPermission(string $user, string $relation, string $object): ?bool
    {
        $key = $this->getPermissionKey($user, $relation, $object);
        $tags = $this->getPermissionTags($user, $relation, $object);

        /** @var mixed $result */
        $result = $this->get($key, $tags);

        return null === $result ? null : (bool) $result;
    }

    /**
     * Invalidate cache for a specific object.
     *
     * @param string $object
     */
    public function invalidateObject(string $object): bool
    {
        return $this->flush([$this->getObjectTag($object)]);
    }

    /**
     * Invalidate cache for a specific object type.
     *
     * @param string $type
     */
    public function invalidateObjectType(string $type): bool
    {
        return $this->flush([$this->getObjectTypeTag($type)]);
    }

    /**
     * Invalidate cache for a specific relation.
     *
     * @param string $relation
     */
    public function invalidateRelation(string $relation): bool
    {
        return $this->flush([$this->getRelationTag($relation)]);
    }

    /**
     * Invalidate cache for a specific user.
     *
     * @param string $user
     */
    public function invalidateUser(string $user): bool
    {
        return $this->flush([$this->getUserTag($user)]);
    }

    /**
     * Invalidate cache for a specific user type.
     *
     * @param string $type
     */
    public function invalidateUserType(string $type): bool
    {
        return $this->flush([$this->getUserTypeTag($type)]);
    }

    /**
     * Store a value in cache with tags.
     *
     * @param string        $key
     * @param mixed         $value
     * @param array<string> $tags
     * @param ?int          $ttl
     */
    public function put(string $key, $value, array $tags, ?int $ttl = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if (null === $ttl) {
            /** @var mixed $configTtl */
            $configTtl = $this->config['ttl'] ?? 300;
            $ttl = is_int($configTtl) || is_numeric($configTtl) ? (int) $configTtl : 300;
        }

        /** @var mixed $cache */
        $cache = $this->cache($tags);

        if ($cache instanceof LaravelTaggedCache) {
            return $cache->put($key, $value, $ttl);
        }

        return false;
    }

    /**
     * Store a permission check result with automatic tagging.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param bool   $result
     * @param ?int   $ttl
     */
    public function putPermission(
        string $user,
        string $relation,
        string $object,
        bool $result,
        ?int $ttl = null,
    ): bool {
        $key = $this->getPermissionKey($user, $relation, $object);
        $tags = $this->getPermissionTags($user, $relation, $object);

        return $this->put($key, $result, $tags, $ttl);
    }

    /**
     * Get the tagged cache instance.
     *
     * @param  array<string>            $tags
     * @return LaravelTaggedCache|mixed
     */
    private function cache(array $tags)
    {
        /** @var mixed $storeConfig */
        $storeConfig = $this->config['store'] ?? null;
        $store = Cache::store(is_string($storeConfig) ? $storeConfig : null);

        if (method_exists($store, 'tags')) {
            return $store->tags($tags);
        }

        // Fallback for stores that don't support tagging
        // In production, you'd want to handle this more gracefully
        return $store;
    }

    /**
     * Extract type from an identifier (e.g., "user:123" -> "user").
     *
     * @param string $identifier
     */
    private function extractType(string $identifier): ?string
    {
        $parts = explode(':', $identifier, 2);

        return 2 === count($parts) ? $parts[0] : null;
    }

    /**
     * Get tag for a specific object.
     *
     * @param string $object
     */
    private function getObjectTag(string $object): string
    {
        /** @var mixed $prefix */
        $prefix = $this->config['prefix'] ?? 'openfga';

        return (is_string($prefix) ? $prefix : 'openfga') . ':object:' . $object;
    }

    /**
     * Get tag for an object type.
     *
     * @param string $type
     */
    private function getObjectTypeTag(string $type): string
    {
        /** @var mixed $prefix */
        $prefix = $this->config['prefix'] ?? 'openfga';

        return (is_string($prefix) ? $prefix : 'openfga') . ':object-type:' . $type;
    }

    /**
     * Get the cache key for a permission check.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    private function getPermissionKey(string $user, string $relation, string $object): string
    {
        /** @var mixed $configPrefix */
        $configPrefix = $this->config['prefix'] ?? null;
        $prefix = is_string($configPrefix) ? $configPrefix : 'openfga';

        return sprintf(
            '%s:check:%s:%s:%s',
            $prefix,
            $user,
            $relation,
            $object,
        );
    }

    /**
     * Get tags for a permission check.
     *
     * @param  string        $user
     * @param  string        $relation
     * @param  string        $object
     * @return array<string>
     */
    private function getPermissionTags(string $user, string $relation, string $object): array
    {
        $tags = [
            $this->getUserTag($user),
            $this->getRelationTag($relation),
            $this->getObjectTag($object),
        ];

        // Add type tags
        $userType = $this->extractType($user);

        if (null !== $userType) {
            $tags[] = $this->getUserTypeTag($userType);
        }

        $objectType = $this->extractType($object);

        if (null !== $objectType) {
            $tags[] = $this->getObjectTypeTag($objectType);
        }

        return array_unique($tags);
    }

    /**
     * Get tag for a specific relation.
     *
     * @param string $relation
     */
    private function getRelationTag(string $relation): string
    {
        /** @var mixed $prefix */
        $prefix = $this->config['prefix'] ?? 'openfga';

        return (is_string($prefix) ? $prefix : 'openfga') . ':relation:' . $relation;
    }

    /**
     * Get tag for a specific user.
     *
     * @param string $user
     */
    private function getUserTag(string $user): string
    {
        /** @var mixed $prefix */
        $prefix = $this->config['prefix'] ?? 'openfga';

        return (is_string($prefix) ? $prefix : 'openfga') . ':user:' . $user;
    }

    /**
     * Get tag for a user type.
     *
     * @param string $type
     */
    private function getUserTypeTag(string $type): string
    {
        /** @var mixed $prefix */
        $prefix = $this->config['prefix'] ?? 'openfga';

        return (is_string($prefix) ? $prefix : 'openfga') . ':user-type:' . $type;
    }

    /**
     * Check if tagged caching is enabled and supported.
     */
    private function isEnabled(): bool
    {
        $enabled = $this->config['enabled'] ?? false;

        if (! is_bool($enabled) || ! $enabled) {
            return false;
        }

        /** @var mixed $storeConfig */
        $storeConfig = $this->config['store'] ?? null;
        $store = Cache::store(is_string($storeConfig) ? $storeConfig : null);

        return method_exists($store, 'tags');
    }
}
