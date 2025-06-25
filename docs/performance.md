# OpenFGA Laravel - Performance Best Practices

This guide covers performance optimization strategies for OpenFGA Laravel to ensure your authorization system scales efficiently.

## Table of Contents

- [Caching Strategies](#caching-strategies)
- [Batch Operations](#batch-operations)
- [Connection Pooling](#connection-pooling)
- [Queue Integration](#queue-integration)
- [Database Optimization](#database-optimization)
- [Monitoring & Profiling](#monitoring--profiling)
- [Common Pitfalls](#common-pitfalls)

## Caching Strategies

### Enable Caching

The most significant performance improvement comes from enabling caching:

```env
OPENFGA_CACHE_ENABLED=true
OPENFGA_CACHE_TTL=300  # 5 minutes
```

### Use Tagged Caching

For granular cache invalidation, use a cache store that supports tagging (Redis, Memcached):

```env
OPENFGA_CACHE_STORE=redis
OPENFGA_CACHE_TAGS_ENABLED=true
```

### Cache Warming

Pre-load frequently checked permissions:

```php
use OpenFGA\Laravel\Facades\OpenFga;

// Warm cache for common permissions
$commonChecks = [
    ['user:123', 'read', 'document:456'],
    ['user:123', 'write', 'document:456'],
    // ...
];

OpenFga::batchCheck($commonChecks);
```

Use the artisan command for bulk warming:

```bash
php artisan openfga:warm-cache --user=123 --relations=read,write --type=document
```

### Write-Behind Cache

For non-critical permission updates, enable write-behind caching:

```env
OPENFGA_WRITE_BEHIND_ENABLED=true
OPENFGA_WRITE_BEHIND_BATCH_SIZE=100
OPENFGA_WRITE_BEHIND_FLUSH_INTERVAL=5
```

## Batch Operations

### Batch Checks

Always use batch checks when checking multiple permissions:

```php
// ❌ Inefficient - Multiple API calls
$canRead = OpenFga::check('user:123', 'read', 'document:1');
$canWrite = OpenFga::check('user:123', 'write', 'document:1');
$canDelete = OpenFga::check('user:123', 'delete', 'document:1');

// ✅ Efficient - Single API call
$results = OpenFga::batchCheck([
    ['user:123', 'read', 'document:1'],
    ['user:123', 'write', 'document:1'],
    ['user:123', 'delete', 'document:1'],
]);
```

### Batch Writes

Group permission changes together:

```php
// ❌ Inefficient - Multiple API calls
OpenFga::grant('user:123', 'read', 'document:1');
OpenFga::grant('user:123', 'write', 'document:1');
OpenFga::grant('user:456', 'read', 'document:1');

// ✅ Efficient - Single API call
OpenFga::writeBatch([
    ['user:123', 'read', 'document:1'],
    ['user:123', 'write', 'document:1'],
    ['user:456', 'read', 'document:1'],
]);
```

### Eloquent Batch Operations

Use collection methods for bulk model operations:

```php
// Grant permissions to multiple users efficiently
$document->grantBulk($users, 'read');

// Check permissions for multiple models
$readableDocuments = Document::whereUserCan($user, 'read')
    ->chunk(1000, function ($documents) {
        // Process documents in chunks
    });
```

## Connection Pooling

Enable connection pooling for high-traffic applications:

```env
OPENFGA_POOL_ENABLED=true
OPENFGA_POOL_MAX_CONNECTIONS=10
OPENFGA_POOL_MIN_CONNECTIONS=2
```

Configure pool settings based on your load:

```php
// config/openfga.php
'pool' => [
    'enabled' => true,
    'max_connections' => env('OPENFGA_POOL_MAX_CONNECTIONS', 10),
    'min_connections' => env('OPENFGA_POOL_MIN_CONNECTIONS', 2),
    'max_idle_time' => env('OPENFGA_POOL_MAX_IDLE_TIME', 300),
    'connection_timeout' => env('OPENFGA_POOL_CONNECTION_TIMEOUT', 5),
],
```

## Queue Integration

### Async Permission Updates

Use queues for non-critical permission updates:

```env
OPENFGA_QUEUE_ENABLED=true
OPENFGA_QUEUE_CONNECTION=redis
OPENFGA_QUEUE_NAME=openfga-high
```

Example usage:

```php
use OpenFGA\Laravel\Jobs\BatchWriteJob;

// Queue permission updates
BatchWriteJob::dispatch($writes, $deletes)
    ->onQueue('openfga-low'); // Use different queue priorities
```

### Queue Worker Configuration

Optimize queue workers for OpenFGA jobs:

```bash
# High priority queue for critical updates
php artisan queue:work redis --queue=openfga-high --tries=3 --timeout=30

# Low priority queue for bulk operations
php artisan queue:work redis --queue=openfga-low --tries=5 --timeout=120
```

## Database Optimization

### Deduplication

Enable request deduplication to prevent duplicate API calls:

```php
use OpenFGA\Laravel\Deduplication\RequestDeduplicator;

// Deduplication is automatic for identical concurrent requests
$results = OpenFga::withDeduplication()->batchCheck($checks);
```

### Optimize Contextual Tuples

When using contextual tuples, batch them efficiently:

```php
// Include all necessary context in a single check
$allowed = OpenFga::check('user:123', 'view', 'document:456', [
    'contextual_tuples' => [
        ['user:123', 'member', 'team:789'],
        ['team:789', 'has_access', 'folder:abc'],
    ],
]);
```

## Monitoring & Profiling

### Enable Profiling

Enable profiling to identify bottlenecks:

```env
OPENFGA_PROFILING_ENABLED=true
OPENFGA_SLOW_QUERY_THRESHOLD=100
```

### View Profiling Data

```bash
# View current profile
php artisan openfga:profile

# Show only slow queries
php artisan openfga:profile --slow

# Export as JSON for analysis
php artisan openfga:profile --json > profile.json
```

### Laravel Debugbar Integration

The package automatically integrates with Laravel Debugbar when available:

```env
OPENFGA_DEBUGBAR_ENABLED=true
```

### Custom Metrics

Track custom metrics in your application:

```php
use OpenFGA\Laravel\Profiling\OpenFgaProfiler;

$profiler = app(OpenFgaProfiler::class);
$profile = $profiler->startProfile('custom_operation', ['key' => 'value']);

// Your operation here

$profile->end(true);
$profile->addMetadata('result_count', 42);
```

## Common Pitfalls

### 1. N+1 Query Problem

Avoid checking permissions in loops:

```php
// ❌ Bad - N+1 queries
foreach ($documents as $document) {
    if (OpenFga::check($user, 'read', $document->authorizationObject())) {
        // Process document
    }
}

// ✅ Good - Batch check
$checks = $documents->map(fn($doc) => [
    $user, 'read', $doc->authorizationObject()
])->toArray();

$results = OpenFga::batchCheck($checks);
```

### 2. Unnecessary Cache Invalidation

Be strategic about cache invalidation:

```php
// ❌ Bad - Invalidates entire cache
Cache::tags(['openfga'])->flush();

// ✅ Good - Invalidate specific entries
OpenFga::forgetCached('user:123', 'read', 'document:456');
```

### 3. Over-Fetching Relations

Use `listObjects` efficiently:

```php
// ❌ Bad - Fetches all objects then filters
$allDocuments = OpenFga::listObjects('user:123', 'read', 'document');
$recentDocuments = collect($allDocuments)->filter(...);

// ✅ Good - Filter at database level
$documentIds = OpenFga::listObjects('user:123', 'read', 'document');
$recentDocuments = Document::whereIn('id', $documentIds)
    ->where('created_at', '>', now()->subDays(7))
    ->get();
```

### 4. Synchronous Write-Behind Flushes

Avoid synchronous flushes in request cycle:

```php
// ❌ Bad - Blocks request
app(WriteBehindCache::class)->flush();

// ✅ Good - Use scheduled job
// In app/Console/Kernel.php
$schedule->command('openfga:flush-write-behind')->everyMinute();
```

## Performance Benchmarks

Run benchmarks to measure your setup:

```bash
# Basic benchmark
php artisan openfga:benchmark

# Detailed benchmark with custom parameters
php artisan openfga:benchmark --operations=10000 --concurrent=100 --duration=60
```

Expected performance targets:

- **Cached checks**: < 1ms
- **Single API check**: < 50ms
- **Batch check (100 items)**: < 200ms
- **Write operations**: < 100ms
- **List operations**: < 200ms

## Optimization Checklist

- [ ] Enable caching with appropriate TTL
- [ ] Use tagged caching if available
- [ ] Implement cache warming for hot paths
- [ ] Use batch operations wherever possible
- [ ] Enable connection pooling for high traffic
- [ ] Configure queue workers for async operations
- [ ] Enable request deduplication
- [ ] Set up monitoring and profiling
- [ ] Regular benchmark testing
- [ ] Review slow query logs weekly

## Advanced Optimizations

### Custom Cache Keys

Implement custom cache key strategies:

```php
use OpenFGA\Laravel\Cache\CacheKeyGenerator;

class CustomCacheKeyGenerator extends CacheKeyGenerator
{
    public function generate(string $user, string $relation, string $object): string
    {
        // Include tenant context in cache key
        $tenant = tenant()->id;
        return "openfga:{$tenant}:{$user}:{$relation}:{$object}";
    }
}
```

### Precomputed Permissions

For extremely high-performance requirements, consider precomputing permissions:

```php
// Scheduled job to precompute common permissions
class PrecomputePermissions extends Command
{
    public function handle()
    {
        $users = User::active()->get();
        $documents = Document::recent()->get();

        $checks = [];
        foreach ($users as $user) {
            foreach ($documents as $document) {
                foreach (['read', 'write'] as $relation) {
                    $checks[] = [
                        "user:{$user->id}",
                        $relation,
                        $document->authorizationObject(),
                    ];
                }
            }
        }

        // Warm cache with batch check
        OpenFga::batchCheck($checks);
    }
}
```

## Conclusion

Following these best practices will ensure your OpenFGA Laravel implementation scales efficiently. Remember to:

1. **Measure first** - Use profiling to identify actual bottlenecks
2. **Cache aggressively** - Most permission checks are read-heavy
3. **Batch operations** - Reduce API calls through batching
4. **Monitor continuously** - Set up alerts for slow queries
5. **Optimize iteratively** - Start with quick wins, then tackle complex optimizations

For more help, see our [Troubleshooting Guide](TROUBLESHOOTING.md) or visit the [OpenFGA documentation](https://openfga.dev/docs).
