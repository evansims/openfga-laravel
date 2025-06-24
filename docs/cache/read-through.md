# Read-Through Cache

The OpenFGA Laravel package includes a powerful read-through cache implementation that automatically fetches and caches permissions when they're not in cache, providing transparent caching behavior.

## Overview

Read-through caching is a caching pattern where:

- Cache hits return data immediately from cache
- Cache misses automatically fetch from the source and populate the cache
- The application doesn't need to manage cache population explicitly

## Features

- **Automatic cache population** - No manual cache warming needed
- **Negative result caching** - Cache "false" results with shorter TTL
- **Error caching** - Prevent hammering the API when errors occur
- **Contextual bypass** - Skip caching for requests with context
- **Cache metrics** - Track hit rates and performance
- **Tagged cache support** - Granular cache invalidation

## Configuration

```php
// config/openfga.php
'cache' => [
    'enabled' => true,
    'store' => null, // Use default cache store
    'ttl' => 300, // 5 minutes
    'prefix' => 'openfga',

    // Read-through cache settings
    'read_through' => true,
    'negative_ttl' => 60, // Cache negative results for 1 minute
    'error_ttl' => 10, // Cache errors for 10 seconds
    'log_misses' => false, // Log cache misses for debugging

    // Cache metrics
    'metrics' => [
        'enabled' => true,
    ],
],
```

## Usage

The read-through cache is automatically used by the OpenFgaManager when enabled:

```php
use OpenFGA\Laravel\OpenFgaManager;

$manager = app(OpenFgaManager::class);

// First call - cache miss, fetches from API
$allowed = $manager->check('user:123', 'viewer', 'document:456');

// Second call - cache hit, returns from cache
$allowed = $manager->check('user:123', 'viewer', 'document:456');
```

### Direct Access

You can also access the read-through cache directly:

```php
$readThroughCache = $manager->getReadThroughCache();

// Check permission with caching
$allowed = $readThroughCache->check(
    'user:123',
    'viewer',
    'document:456'
);

// List objects with caching
$objects = $readThroughCache->listObjects(
    'user:123',
    'viewer',
    'document'
);
```

## Cache Behavior

### Positive Results

When a permission check returns `true`, it's cached for the configured TTL:

```php
// Cached for 5 minutes (default TTL)
$allowed = $manager->check('user:123', 'editor', 'document:456'); // true
```

### Negative Results

When a permission check returns `false`, it's cached for a shorter duration:

```php
// Cached for 1 minute (negative_ttl)
$allowed = $manager->check('user:123', 'admin', 'document:456'); // false
```

### Error Handling

Errors are cached briefly to prevent API hammering:

```php
try {
    // If API is down, error is cached for 10 seconds
    $allowed = $manager->check('user:123', 'viewer', 'document:456');
} catch (\Exception $e) {
    // Subsequent calls within 10 seconds won't hit the API
}
```

### Contextual Requests

Requests with contextual tuples or context are never cached:

```php
// Not cached - has contextual tuples
$allowed = $manager->check(
    'user:123',
    'viewer',
    'document:456',
    $contextualTuples
);

// Not cached - has context
$allowed = $manager->check(
    'user:123',
    'viewer',
    'document:456',
    [],
    ['ip' => '192.168.1.1']
);
```

## Cache Invalidation

The read-through cache integrates with the tagged cache for intelligent invalidation:

```php
$cache = $manager->getReadThroughCache();

// Invalidate all cache entries for a user
$cache->invalidate('user:123');

// Invalidate all cache entries for an object
$cache->invalidate(null, null, 'document:456');

// Invalidate all cache entries for a relation
$cache->invalidate(null, 'viewer');

// Invalidate a specific permission
$cache->invalidate('user:123', 'viewer', 'document:456');
```

## Cache Metrics

Track cache performance with built-in metrics:

```php
$cache = $manager->getReadThroughCache();

// Get cache statistics
$stats = $cache->getStats();
// [
//     'hits' => 150,
//     'misses' => 50,
//     'hit_rate' => 75.0
// ]

// Reset statistics
$cache->resetStats();
```

### Using the CLI

```bash
# View cache statistics
php artisan openfga:cache:stats

# Output as JSON
php artisan openfga:cache:stats --json

# Reset statistics
php artisan openfga:cache:stats --reset
```

## Debugging

Enable cache miss logging for debugging:

```php
// .env
OPENFGA_CACHE_LOG_MISSES=true
```

This will log all cache misses:

```
[2024-01-15 10:30:45] local.DEBUG: OpenFGA cache miss {
    "user": "user:123",
    "relation": "viewer",
    "object": "document:456"
}
```

## Performance Considerations

### Cache Store Selection

Choose an appropriate cache store for your use case:

```php
// Redis - Recommended for production
OPENFGA_CACHE_STORE=redis

// Array - Good for testing
OPENFGA_CACHE_STORE=array

// File - Simple but slower
OPENFGA_CACHE_STORE=file
```

### TTL Configuration

Balance between performance and data freshness:

```php
// Longer TTL for stable permissions
OPENFGA_CACHE_TTL=3600 // 1 hour

// Shorter TTL for frequently changing permissions
OPENFGA_CACHE_TTL=60 // 1 minute

// Different TTL for negative results
OPENFGA_CACHE_NEGATIVE_TTL=30 // 30 seconds
```

### Memory Considerations

For high-traffic applications, monitor cache memory usage:

```php
// Limit cache entries with a shorter TTL
OPENFGA_CACHE_TTL=300

// Or use cache stores with eviction policies
OPENFGA_CACHE_STORE=redis
```

## Advanced Usage

### Custom Cache Configuration

```php
use OpenFGA\Laravel\Cache\ReadThroughCache;

$customCache = new ReadThroughCache($manager, [
    'enabled' => true,
    'ttl' => 600,
    'negative_ttl' => 120,
    'error_ttl' => 5,
    'prefix' => 'my-app',
    'metrics_enabled' => true,
]);

$allowed = $customCache->check('user:123', 'viewer', 'document:456');
```

### Warming the Cache

Combine with cache warming for optimal performance:

```php
use OpenFGA\Laravel\Cache\CacheWarmer;

$warmer = app(CacheWarmer::class);

// Warm cache for specific permissions
$warmer->warmForUser(
    'user:123',
    ['viewer', 'editor'],
    ['document:456', 'document:789']
);

// Now these will be cache hits
$allowed = $manager->check('user:123', 'viewer', 'document:456');
```

### Monitoring Cache Performance

```php
// In your monitoring system
$stats = $manager->getReadThroughCache()->getStats();

if ($stats['hit_rate'] < 50.0) {
    // Alert: Low cache hit rate
    Log::warning('OpenFGA cache hit rate below 50%', $stats);
}
```

## Best Practices

1. **Enable metrics in production** to monitor cache effectiveness
2. **Use tagged cache stores** (Redis, DynamoDB) for better invalidation
3. **Set appropriate TTLs** based on your permission volatility
4. **Monitor cache size** to prevent memory issues
5. **Use cache warming** for predictable permission patterns
6. **Disable for tests** unless specifically testing cache behavior

## Troubleshooting

### Low Hit Rate

If your cache hit rate is low:

1. Check if permissions change frequently
2. Increase TTL if appropriate
3. Use cache warming for common permissions
4. Ensure cache store is properly configured

### Cache Not Working

1. Verify cache is enabled: `OPENFGA_CACHE_ENABLED=true`
2. Check cache store supports your operations
3. Ensure proper permissions for file-based caches
4. Check Redis/Memcached connection if using

### Stale Data

If you're seeing outdated permissions:

1. Reduce TTL for frequently changing permissions
2. Implement proper cache invalidation on updates
3. Use contextual tuples for dynamic permissions
4. Consider disabling cache for specific checks
