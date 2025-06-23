# Performance Optimization Guide

This guide covers best practices and techniques for optimizing the performance of OpenFGA authorization in your Laravel application.

## Caching Strategies

### Enable Caching

The most effective way to improve performance is to enable caching:

```php
// config/openfga.php
'cache' => [
    'enabled' => true,
    'store' => 'redis',  // Use a fast cache driver
    'ttl' => 300,        // 5 minutes
    'prefix' => 'openfga',
],
```

### Cache Warming

Pre-populate the cache with frequently checked permissions:

```php
namespace App\Jobs;

use App\Models\User;
use OpenFGA\Laravel\Facades\OpenFga;
use Illuminate\Support\Facades\Cache;

class WarmPermissionCache
{
    public function handle()
    {
        $users = User::active()->get();
        $criticalObjects = [
            'system:admin',
            'system:api',
            'system:billing',
        ];

        foreach ($users as $user) {
            foreach ($criticalObjects as $object) {
                // Pre-check permissions to warm cache
                OpenFga::check("user:{$user->id}", 'member', $object);
            }
        }
    }
}
```

### Smart Cache Invalidation

Invalidate cache selectively when permissions change:

```php
namespace App\Listeners;

use OpenFGA\Laravel\Events\PermissionGranted;
use Illuminate\Support\Facades\Cache;

class InvalidatePermissionCache
{
    public function handle(PermissionGranted $event)
    {
        // Invalidate specific cache keys
        $cacheKey = "openfga:check:{$event->user}:{$event->relation}:{$event->object}";
        Cache::forget($cacheKey);

        // Invalidate related caches
        $userType = explode(':', $event->user)[0];
        $objectType = explode(':', $event->object)[0];

        Cache::tags(["openfga:{$userType}", "openfga:{$objectType}"])->flush();
    }
}
```

## Batch Operations

### Use Batch Writes

Always batch multiple permission operations:

```php
// ❌ Bad - Multiple individual operations
foreach ($users as $user) {
    OpenFga::grant("user:{$user->id}", 'viewer', "document:{$document->id}");
}

// ✅ Good - Single batch operation
$writes = $users->map(fn($user) => [
    "user:{$user->id}", 'viewer', "document:{$document->id}"
])->toArray();

OpenFga::writeBatch(writes: $writes);
```

### Batch Permission Checks

Check multiple permissions in a single request:

```php
// ❌ Bad - Multiple individual checks
$canView = OpenFga::check($user, 'viewer', $document);
$canEdit = OpenFga::check($user, 'editor', $document);
$canDelete = OpenFga::check($user, 'owner', $document);

// ✅ Good - Single batch check
$permissions = OpenFga::batchCheck([
    [$user, 'viewer', $document],
    [$user, 'editor', $document],
    [$user, 'owner', $document],
]);

[$canView, $canEdit, $canDelete] = $permissions;
```

### Implement Batch Check Helper

```php
namespace App\Services;

use OpenFGA\Laravel\Facades\OpenFga;
use Illuminate\Support\Collection;

class PermissionService
{
    public function checkMultiplePermissions(string $user, array $permissions, string $object): array
    {
        $checks = collect($permissions)->map(fn($permission) => [
            $user, $permission, $object
        ])->toArray();

        $results = OpenFga::batchCheck($checks);

        return array_combine($permissions, $results);
    }
}

// Usage
$permissions = $permissionService->checkMultiplePermissions(
    "user:{$user->id}",
    ['viewer', 'editor', 'owner'],
    "document:{$document->id}"
);
// Returns: ['viewer' => true, 'editor' => true, 'owner' => false]
```

## Query Optimization

### Optimize whereUserCan Queries

Use database joins to reduce the number of queries:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class Document extends Model
{
    use HasAuthorization;

    // Optimized scope that pre-filters in database
    public function scopeWhereUserCanOptimized(Builder $query, $user, string $relation)
    {
        // First, get all object IDs from OpenFGA
        $objectIds = Cache::remember(
            "user_objects:{$user->id}:{$relation}:document",
            300,
            fn() => OpenFga::listObjects("user:{$user->id}", $relation, 'document')
        );

        // Extract just the IDs
        $ids = collect($objectIds)->map(fn($obj) => explode(':', $obj)[1])->toArray();

        // Use whereIn for efficient database query
        return $query->whereIn('id', $ids);
    }
}
```

### Preload Permissions for Collections

```php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use OpenFGA\Laravel\Facades\OpenFga;

class DocumentCollection extends ResourceCollection
{
    private array $permissions = [];

    public function __construct($resource)
    {
        parent::__construct($resource);

        // Preload all permissions in one batch
        $this->preloadPermissions();
    }

    private function preloadPermissions()
    {
        $user = "user:" . auth()->id();
        $checks = [];

        foreach ($this->collection as $document) {
            foreach (['viewer', 'editor', 'owner'] as $relation) {
                $checks[] = [$user, $relation, $document->authorizationObject()];
            }
        }

        $results = OpenFga::batchCheck($checks);

        // Map results back to documents
        $index = 0;
        foreach ($this->collection as $document) {
            $this->permissions[$document->id] = [
                'viewer' => $results[$index++],
                'editor' => $results[$index++],
                'owner' => $results[$index++],
            ];
        }
    }

    public function toArray($request)
    {
        return $this->collection->map(function ($document) {
            return [
                'id' => $document->id,
                'title' => $document->title,
                'permissions' => $this->permissions[$document->id] ?? [],
            ];
        });
    }
}
```

## Connection Pooling

### Configure Connection Pooling

```php
// config/openfga.php
'connections' => [
    'main' => [
        // ... other config
        'pool' => [
            'enabled' => true,
            'min_connections' => 2,
            'max_connections' => 10,
            'max_idle_time' => 60, // seconds
        ],
    ],
],
```

### Implement Custom Connection Pool

```php
namespace App\Services;

use OpenFGA\ClientInterface;
use SplObjectStorage;

class OpenFgaConnectionPool
{
    private SplObjectStorage $available;
    private SplObjectStorage $inUse;
    private array $config;
    private int $created = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->available = new SplObjectStorage();
        $this->inUse = new SplObjectStorage();

        // Create minimum connections
        for ($i = 0; $i < $config['min_connections']; $i++) {
            $this->createConnection();
        }
    }

    public function acquire(): ClientInterface
    {
        if ($this->available->count() === 0) {
            if ($this->created < $this->config['max_connections']) {
                $this->createConnection();
            } else {
                // Wait for available connection
                while ($this->available->count() === 0) {
                    usleep(10000); // 10ms
                }
            }
        }

        $connection = $this->available->current();
        $this->available->detach($connection);
        $this->inUse->attach($connection);

        return $connection;
    }

    public function release(ClientInterface $connection): void
    {
        $this->inUse->detach($connection);
        $this->available->attach($connection);
    }

    private function createConnection(): void
    {
        $connection = app(ClientInterface::class);
        $this->available->attach($connection);
        $this->created++;
    }
}
```

## Queue Optimization

### Configure Queue for Batch Operations

```php
// config/openfga.php
'queue' => [
    'enabled' => true,
    'connection' => 'redis',
    'queue' => 'openfga-high', // Dedicated high-priority queue

    'batch' => [
        'size' => 1000,     // Larger batches for bulk operations
        'timeout' => 300,   // 5 minutes for large batches
    ],

    'auto_queue' => [
        'enabled' => true,
        'threshold' => 100, // Auto-queue operations with >100 items
    ],
],
```

### Implement Smart Queuing

```php
namespace App\Services;

use OpenFGA\Laravel\Jobs\BatchWriteJob;
use OpenFGA\Laravel\Facades\OpenFga;

class SmartPermissionWriter
{
    private array $pendingWrites = [];
    private array $pendingDeletes = [];

    public function add(string $user, string $relation, string $object): void
    {
        $this->pendingWrites[] = [$user, $relation, $object];

        if (count($this->pendingWrites) >= 100) {
            $this->flush();
        }
    }

    public function remove(string $user, string $relation, string $object): void
    {
        $this->pendingDeletes[] = [$user, $relation, $object];

        if (count($this->pendingDeletes) >= 100) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->pendingWrites) && empty($this->pendingDeletes)) {
            return;
        }

        $totalOperations = count($this->pendingWrites) + count($this->pendingDeletes);

        if ($totalOperations > config('openfga.queue.auto_queue.threshold')) {
            // Queue for async processing
            BatchWriteJob::dispatch($this->pendingWrites, $this->pendingDeletes);
        } else {
            // Execute immediately
            OpenFga::writeBatch(
                writes: $this->pendingWrites,
                deletes: $this->pendingDeletes
            );
        }

        $this->pendingWrites = [];
        $this->pendingDeletes = [];
    }

    public function __destruct()
    {
        $this->flush();
    }
}
```

## Database Optimization

### Index Authorization Columns

Add database indexes for faster queries:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAuthorizationIndexes extends Migration
{
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['team_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });

        // If you store permission metadata
        Schema::table('permission_cache', function (Blueprint $table) {
            $table->index(['user_type', 'user_id']);
            $table->index(['object_type', 'object_id']);
            $table->index(['relation']);
            $table->index(['checked_at']);
        });
    }
}
```

### Optimize Permission Queries

```php
namespace App\Models;

class Document extends Model
{
    use HasAuthorization;

    // Eager load relationships needed for authorization
    protected $with = ['team', 'owner'];

    // Cache authorization object
    protected $authorizationObjectCache;

    public function authorizationObject(): string
    {
        if (!$this->authorizationObjectCache) {
            $this->authorizationObjectCache = "document:{$this->team_id}:{$this->id}";
        }

        return $this->authorizationObjectCache;
    }
}
```

## Monitoring & Profiling

### Track Performance Metrics

```php
namespace App\Listeners;

use OpenFGA\Laravel\Events\PermissionChecked;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TrackPermissionPerformance
{
    public function handle(PermissionChecked $event)
    {
        // Track response times
        Redis::hincrby('openfga:stats:checks', date('Y-m-d-H'), 1);

        // Track slow queries
        if ($event->duration > 100) { // > 100ms
            Log::warning('Slow permission check', [
                'user' => $event->user,
                'relation' => $event->relation,
                'object' => $event->object,
                'duration' => $event->duration,
                'cached' => $event->cached,
            ]);

            Redis::hincrby('openfga:stats:slow_checks', date('Y-m-d'), 1);
        }

        // Track cache hit rate
        $key = $event->cached ? 'cache_hits' : 'cache_misses';
        Redis::hincrby('openfga:stats:' . $key, date('Y-m-d'), 1);
    }
}
```

### Performance Dashboard

```php
namespace App\Http\Controllers\Admin;

use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class PerformanceController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'today' => $this->getStatsForDate(Carbon::today()),
            'yesterday' => $this->getStatsForDate(Carbon::yesterday()),
            'week' => $this->getStatsForPeriod(Carbon::now()->subWeek(), Carbon::now()),
        ];

        $slowQueries = $this->getSlowQueries();
        $cacheHitRate = $this->calculateCacheHitRate();

        return view('admin.performance', compact('stats', 'slowQueries', 'cacheHitRate'));
    }

    private function calculateCacheHitRate(): float
    {
        $hits = Redis::get('openfga:stats:cache_hits:' . date('Y-m-d')) ?? 0;
        $misses = Redis::get('openfga:stats:cache_misses:' . date('Y-m-d')) ?? 0;
        $total = $hits + $misses;

        return $total > 0 ? ($hits / $total) * 100 : 0;
    }
}
```

## Best Practices

### 1. Use Read Replicas

Configure read replicas for permission checks:

```php
'connections' => [
    'main' => [
        'url' => env('OPENFGA_WRITE_URL'),
        // ... other config
    ],
    'read' => [
        'url' => env('OPENFGA_READ_URL'),
        // ... other config
    ],
],

// Use read connection for checks
OpenFga::connection('read')->check($user, $relation, $object);
```

### 2. Implement Circuit Breaker

Prevent cascading failures:

```php
namespace App\Services;

use OpenFGA\Laravel\Facades\OpenFga;

class CircuitBreakerOpenFga
{
    private int $failureCount = 0;
    private ?Carbon $lastFailureTime = null;
    private bool $isOpen = false;

    public function check(...$args)
    {
        if ($this->isOpen && $this->shouldAttemptReset()) {
            $this->isOpen = false;
        }

        if ($this->isOpen) {
            return $this->getFallbackResponse();
        }

        try {
            $result = OpenFga::check(...$args);
            $this->onSuccess();
            return $result;
        } catch (\Exception $e) {
            $this->onFailure();
            throw $e;
        }
    }

    private function onFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = now();

        if ($this->failureCount >= 5) {
            $this->isOpen = true;
            Log::error('OpenFGA circuit breaker opened');
        }
    }

    private function onSuccess(): void
    {
        $this->failureCount = 0;
        $this->lastFailureTime = null;
    }
}
```

### 3. Use Lazy Loading

Load permissions only when needed:

```php
class Document extends Model
{
    private ?array $permissionsCache = null;

    public function getPermissionsAttribute(): array
    {
        if ($this->permissionsCache === null) {
            $this->permissionsCache = [
                'can_view' => $this->check('@me', 'viewer'),
                'can_edit' => $this->check('@me', 'editor'),
                'can_delete' => $this->check('@me', 'owner'),
            ];
        }

        return $this->permissionsCache;
    }
}
```

### 4. Optimize Model Serialization

Exclude unnecessary data:

```php
class DocumentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            // Only include permissions if requested
            $this->mergeWhen($request->has('include_permissions'), [
                'permissions' => $this->permissions,
            ]),
        ];
    }
}
```

## Debugging Performance

### Enable Query Logging

```php
// In development only
if (app()->environment('local')) {
    OpenFga::enableQueryLog();

    // After requests
    $queries = OpenFga::getQueryLog();
    Log::debug('OpenFGA Queries', ['queries' => $queries]);
}
```

### Use Laravel Debugbar Integration

```php
namespace App\Providers;

use Barryvdh\Debugbar\Facades\Debugbar;
use OpenFGA\Laravel\Events\PermissionChecked;

class DebugbarServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Event::listen(PermissionChecked::class, function ($event) {
            Debugbar::addMessage(
                "Check: {$event->user}#{$event->relation}@{$event->object} = " .
                ($event->allowed ? 'allowed' : 'denied') .
                " ({$event->duration}ms)",
                'openfga'
            );
        });
    }
}
```

## Next Steps

- Review [Troubleshooting Guide](troubleshooting.md)
- Check the [API Reference](api-reference.md)
- See [Example Application](https://github.com/openfga/laravel-example)
