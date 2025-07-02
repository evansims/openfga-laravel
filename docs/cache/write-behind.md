# Write-Behind Cache

The write-behind cache pattern improves performance by buffering write operations and flushing them to OpenFGA asynchronously. This is ideal for scenarios where immediate consistency is not critical.

## Overview

Write-behind caching:
- Buffers grant/revoke operations in memory
- Updates the read cache immediately for consistency
- Flushes operations to OpenFGA in batches
- Supports both synchronous and queue-based flushing

## Configuration

### Basic Configuration

```php
// config/openfga.php
'cache' => [
    'write_behind' => [
        'enabled' => env('OPENFGA_WRITE_BEHIND_ENABLED', false),
        'store' => env('OPENFGA_WRITE_BEHIND_STORE'), // null = default cache
        'batch_size' => env('OPENFGA_WRITE_BEHIND_BATCH_SIZE', 100),
        'flush_interval' => env('OPENFGA_WRITE_BEHIND_FLUSH_INTERVAL', 5), // seconds
        'ttl' => env('OPENFGA_WRITE_BEHIND_TTL', 300), // 5 minutes
        'periodic_flush' => env('OPENFGA_WRITE_BEHIND_PERIODIC_FLUSH', false),
        'flush_on_shutdown' => env('OPENFGA_WRITE_BEHIND_FLUSH_ON_SHUTDOWN', true),
    ],
],
```

### Queue Integration

For improved reliability and scalability, enable queue-based flushing:

```php
// config/openfga.php
'queue' => [
    'enabled' => env('OPENFGA_QUEUE_ENABLED', false),
    'connection' => env('OPENFGA_QUEUE_CONNECTION'), // null = default
    'queue' => env('OPENFGA_QUEUE_NAME', 'openfga'),
],
```

## Usage

### Automatic Buffering

When write-behind is enabled, all write operations are automatically buffered:

```php
use OpenFGA\Laravel\Facades\OpenFga;

// These operations are buffered, not immediately sent to OpenFGA
OpenFga::grant('user:123', 'editor', 'document:456');
OpenFga::revoke('user:456', 'viewer', 'document:456');

// The read cache is updated immediately, so this returns the correct result
$canEdit = OpenFga::check('user:123', 'editor', 'document:456'); // true
```

### Manual Control

You can manually control the write-behind cache:

```php
use OpenFGA\Laravel\Cache\WriteBehindCache;

$cache = app(WriteBehindCache::class);

// Check pending operations
$pending = $cache->getPendingCount();
// ['writes' => 5, 'deletes' => 2, 'total' => 7]

// Force immediate flush
$stats = $cache->flush();
// ['writes' => 5, 'deletes' => 2]

// Clear pending operations without flushing
$cache->clear();
```

## Queue-Based Flushing

### How It Works

With queue integration enabled:

1. Each operation is dispatched as a `WriteTupleToFgaJob`
2. Jobs are processed by queue workers
3. Failed jobs are automatically retried
4. Operations maintain connection context

### Benefits

- **Reliability**: Failed operations are retried automatically
- **Performance**: Web requests remain fast
- **Scalability**: Distribute load across multiple workers
- **Fault Tolerance**: Survives OpenFGA downtime

### Setup

1. Configure queue connection:
   ```env
   OPENFGA_QUEUE_ENABLED=true
   OPENFGA_QUEUE_CONNECTION=redis
   OPENFGA_QUEUE_NAME=openfga
   ```

2. Start queue workers:
   ```bash
   php artisan queue:work --queue=openfga
   ```

3. Monitor queue health:
   ```bash
   php artisan queue:monitor openfga --max=1000
   ```

### Job Configuration

The queue jobs support:

- **Retries**: 3 attempts with exponential backoff (10s, 30s, 60s)
- **Timeout**: 30 seconds per job
- **Tags**: For monitoring in Horizon
- **Connection Context**: Maintains multi-tenant context

## Flush Triggers

Operations are flushed when:

1. **Batch Size Reached**: Configurable via `batch_size`
2. **Time Interval**: After `flush_interval` seconds
3. **Manual Flush**: Via `$cache->flush()`
4. **Shutdown**: If `flush_on_shutdown` is enabled
5. **Periodic**: Via scheduler if `periodic_flush` is enabled

## Monitoring

### Artisan Commands

```bash
# View write-behind cache status
php artisan openfga:cache:status

# Manually flush write-behind cache
php artisan openfga:cache:flush

# Clear write-behind cache without flushing
php artisan openfga:cache:clear
```

### Metrics

Track write-behind performance:

```php
use OpenFGA\Laravel\Cache\WriteBehindCache;

$cache = app(WriteBehindCache::class);
$operations = $cache->getPendingOperations();

// Log metrics
Log::info('Write-behind cache metrics', [
    'pending_writes' => count($operations['writes']),
    'pending_deletes' => count($operations['deletes']),
    'oldest_operation' => min(array_column($operations['writes'], 'timestamp')),
]);
```

### Laravel Horizon

If using Horizon, monitor the `openfga` queue:

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'queue' => ['default', 'openfga'],
            'balance' => 'auto',
            'maxProcesses' => 10,
        ],
    ],
],
```

## Best Practices

### 1. Use for Non-Critical Updates

Write-behind is ideal for:
- Bulk permission imports
- Background synchronization
- Non-critical permission updates

Not recommended for:
- Security-critical operations
- Real-time permission changes
- Financial or compliance systems

### 2. Configure Appropriate Batch Sizes

```php
// For high-throughput systems
'batch_size' => 500,
'flush_interval' => 2,

// For low-throughput systems
'batch_size' => 50,
'flush_interval' => 10,
```

### 3. Monitor Queue Health

Set up alerts for:
- Queue size exceeding threshold
- Failed job rate
- Flush duration

### 4. Handle Failures Gracefully

```php
// In your exception handler
public function report(Throwable $exception)
{
    if ($exception instanceof \OpenFGA\Laravel\Exceptions\OpenFgaException) {
        // Notify ops team
        // Consider fallback strategy
    }
    
    parent::report($exception);
}
```

### 5. Test with Queue Workers

Always test with queue workers running:

```php
// In your tests
public function test_write_behind_with_queue()
{
    Queue::fake();
    
    config(['openfga.queue.enabled' => true]);
    
    OpenFga::grant('user:123', 'editor', 'document:456');
    
    Queue::assertPushed(WriteTupleToFgaJob::class, function ($job) {
        return $job->user === 'user:123'
            && $job->relation === 'editor'
            && $job->object === 'document:456';
    });
}
```

## Troubleshooting

### Operations Not Flushing

1. Check queue workers are running:
   ```bash
   php artisan queue:work --queue=openfga
   ```

2. Verify configuration:
   ```bash
   php artisan config:cache
   php artisan queue:restart
   ```

3. Check for failed jobs:
   ```bash
   php artisan queue:failed
   ```

### Cache Inconsistencies

1. Clear both caches:
   ```bash
   php artisan cache:clear
   php artisan openfga:cache:clear
   ```

2. Verify write-behind is updating read cache:
   ```php
   Log::debug('Cache update', [
       'write_behind_enabled' => config('openfga.cache.write_behind.enabled'),
       'read_cache_enabled' => config('openfga.cache.enabled'),
   ]);
   ```

### Performance Issues

1. Reduce batch size if flushes are slow
2. Increase flush interval for better batching
3. Add more queue workers for parallel processing
4. Consider using Redis for better queue performance

## Migration from Synchronous Writes

To migrate from synchronous to write-behind:

1. **Enable in staging first**:
   ```env
   OPENFGA_WRITE_BEHIND_ENABLED=true
   OPENFGA_QUEUE_ENABLED=false
   ```

2. **Monitor for issues**:
   - Check logs for flush failures
   - Verify permission consistency
   - Monitor response times

3. **Enable queue integration**:
   ```env
   OPENFGA_QUEUE_ENABLED=true
   ```

4. **Gradually increase batch size**:
   - Start with small batches (50)
   - Increase based on performance
   - Monitor OpenFGA load

## See Also

- [Performance Guide](../performance.md)
- [Queue Configuration](../configuration.md#queue-configuration)
- [Cache Configuration](../configuration.md#cache-configuration)
- [Troubleshooting](../troubleshooting.md)