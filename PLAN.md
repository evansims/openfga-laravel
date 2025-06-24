# OpenFGA Laravel Integration - Implementation Plan

## Overview

This document outlines the comprehensive plan for creating a top-notch developer experience for OpenFGA in Laravel applications. The goal is to provide a familiar, Laravel-native experience while leveraging the solid foundation of the OpenFGA PHP SDK.

## Core Principles

1. **Laravel-First Design**: Follow Laravel conventions and patterns
2. **Progressive Enhancement**: Basic features work out-of-the-box, advanced features available when needed
3. **Performance by Default**: Built-in caching, queue support, and batch operations
4. **Developer Education**: Comprehensive documentation with Laravel-specific examples
5. **Maintain SDK Compatibility**: Don't hide the SDK's excellent patterns, offer both approaches

## Implementation Phases

### Phase 1: Enhanced Configuration & Service Provider

#### Goals

- Multi-connection support for different stores/environments
- Environment-based configuration
- Automatic client initialization with Laravel's DI container

#### Tasks

- [x] Create comprehensive config/openfga.php with multi-connection support
- [x] Add connection manager class to handle multiple OpenFGA connections
- [x] Implement automatic PSR client detection and initialization
- [x] Add configuration validation on boot
- [x] Support both API token and OAuth2 client credentials authentication
- [x] Add retry configuration with exponential backoff
- [x] Implement connection health checks
- [x] Add configuration caching support

#### Implementation Details

```php
// config/openfga.php structure
return [
    'default' => env('OPENFGA_CONNECTION', 'main'),

    'connections' => [
        'main' => [
            'url' => env('OPENFGA_URL', 'http://localhost:8080'),
            'store_id' => env('OPENFGA_STORE_ID'),
            'model_id' => env('OPENFGA_MODEL_ID'),

            'credentials' => [
                'method' => env('OPENFGA_AUTH_METHOD', 'none'), // none, api_token, client_credentials
                'token' => env('OPENFGA_API_TOKEN'),
                'client_id' => env('OPENFGA_CLIENT_ID'),
                'client_secret' => env('OPENFGA_CLIENT_SECRET'),
                'api_token_issuer' => env('OPENFGA_TOKEN_ISSUER'),
                'api_audience' => env('OPENFGA_API_AUDIENCE'),
                'scopes' => explode(',', env('OPENFGA_SCOPES', '')),
            ],

            'retries' => [
                'max_retries' => env('OPENFGA_MAX_RETRIES', 3),
                'min_wait_ms' => env('OPENFGA_MIN_WAIT_MS', 100),
            ],

            'http_options' => [
                'timeout' => env('OPENFGA_TIMEOUT', 30),
                'connect_timeout' => env('OPENFGA_CONNECT_TIMEOUT', 10),
            ],
        ],
    ],

    'cache' => [
        'enabled' => env('OPENFGA_CACHE_ENABLED', true),
        'store' => env('OPENFGA_CACHE_STORE', 'default'),
        'ttl' => env('OPENFGA_CACHE_TTL', 300), // 5 minutes
        'prefix' => 'openfga',
    ],

    'queue' => [
        'enabled' => env('OPENFGA_QUEUE_ENABLED', false),
        'connection' => env('OPENFGA_QUEUE_CONNECTION', 'default'),
        'queue' => env('OPENFGA_QUEUE_NAME', 'openfga'),
    ],

    'logging' => [
        'enabled' => env('OPENFGA_LOGGING_ENABLED', true),
        'channel' => env('OPENFGA_LOG_CHANNEL', 'default'),
    ],
];
```

### Phase 2: Laravel-Friendly API Layer

#### Goals

- Provide clean, intuitive API that feels native to Laravel
- Support both Result pattern and exception-based approaches
- Implement caching layer for permission checks

#### Tasks

- [x] Create OpenFgaManager class for connection management
- [x] Implement Laravel-style API wrapper methods
- [x] Add automatic user ID resolution from Auth facade
- [x] Create caching decorator for check operations
- [x] Implement exception conversion from Result pattern
- [x] Add method chaining support
- [x] Create query builder-style interface for complex queries
- [x] Add support for contextual tuples

#### Implementation Details

```php
namespace OpenFga\Laravel;

class OpenFgaManager
{
    public function check(string $user, string $relation, string $object): bool
    {
        // Auto-resolve user from auth if needed
        if ($user === '@me') {
            $user = 'user:' . auth()->id();
        }

        // Check cache first
        if ($this->cacheEnabled()) {
            $cached = $this->cache->get($this->getCacheKey($user, $relation, $object));
            if ($cached !== null) {
                return $cached;
            }
        }

        // Perform check
        $result = $this->client->check(
            user: $user,
            relation: $relation,
            object: $object,
            authorizationModelId: $this->getModelId()
        );

        // Handle result based on configuration
        if ($this->shouldThrowExceptions()) {
            return $result->unwrap()->getAllowed();
        }

        return $result->val()?->getAllowed() ?? false;
    }

    public function grant(string|array $user, string $relation, string $object): void
    {
        // Support both single and batch operations
        $tuples = is_array($user)
            ? array_map(fn($u) => tuple($u, $relation, $object), $user)
            : [tuple($user, $relation, $object)];

        $this->write($tuples);
    }
}
```

### Phase 3: Eloquent Model Integration

#### Goals

- Seamless integration with Eloquent models
- Automatic object identifier generation
- Relationship-based permission methods

#### Tasks

- [x] Create HasAuthorization trait for Eloquent models
- [x] Implement automatic object identifier generation
- [x] Create scope methods for permission-based queries
- [x] Add model event listeners for permission cleanup
- [x] Implement permission replication for model duplication
- [x] Add bulk permission operations
- [x] Create migration helpers for permission data

#### Implementation Details

```php
namespace OpenFga\Laravel\Traits;

trait HasAuthorization
{
    public function initializeHasAuthorization(): void
    {
        // Add model events
        static::deleted(function ($model) {
            if (config('openfga.cleanup_on_delete', true)) {
                $model->revokeAllPermissions();
            }
        });
    }

    public function grant($user, string $relation): void
    {
        $userId = $this->resolveUserId($user);

        app(OpenFgaManager::class)->grant(
            $userId,
            $relation,
            $this->authorizationObject()
        );
    }

    public function check($user, string $relation): bool
    {
        $userId = $this->resolveUserId($user);

        return app(OpenFgaManager::class)->check(
            $userId,
            $relation,
            $this->authorizationObject()
        );
    }

    public function scopeWhereUserCan($query, $user, string $relation)
    {
        // This would need to be implemented with listObjects
        $objects = app(OpenFgaManager::class)->listObjects(
            $this->resolveUserId($user),
            $relation,
            $this->authorizationType()
        );

        $ids = collect($objects)->map(fn($obj) => Str::after($obj, ':'));

        return $query->whereIn($this->getKeyName(), $ids);
    }

    protected function authorizationObject(): string
    {
        return $this->authorizationType() . ':' . $this->getKey();
    }

    protected function authorizationType(): string
    {
        return Str::snake(class_basename($this));
    }
}
```

### Phase 4: Middleware & Authorization Integration

#### Goals

- Route-level permission checks
- Integration with Laravel's authorization system
- Policy class support

#### Tasks

- [x] Create OpenFgaMiddleware for route protection
- [x] Implement Gate service provider integration
- [x] Create base Policy class with OpenFGA support
- [x] Add Form Request authorization trait
- [x] Implement permission-based route groups
- [x] Create middleware for batch permission loading
- [x] Add support for dynamic permissions
- [x] Create authorization service provider

#### Implementation Details

```php
namespace OpenFga\Laravel\Http\Middleware;

class RequiresPermission
{
    public function handle($request, Closure $next, string $relation, ?string $object = null)
    {
        $user = 'user:' . $request->user()->id;
        $object = $object ?? $this->resolveObject($request);

        if (!app(OpenFgaManager::class)->check($user, $relation, $object)) {
            abort(403, 'Insufficient permissions');
        }

        return $next($request);
    }

    private function resolveObject($request): string
    {
        // Smart object resolution from route parameters
        foreach ($request->route()->parameters() as $key => $value) {
            if ($value instanceof Model) {
                return $value->authorizationObject();
            }
        }

        throw new InvalidArgumentException('Could not resolve authorization object');
    }
}
```

### Phase 5: Blade Directives & Helpers

#### Goals

- Clean syntax for view-level authorization
- Global helper functions
- Blade component integration

#### Tasks

- [x] Create @can, @cannot blade directives
- [x] Implement @canany, @canall directives
- [x] Add global helper functions (can(), cannot(), etc.)
- [x] Create Blade components for permission-based rendering
- [x] Add JavaScript helper generation
- [x] Implement view composer for permission data
- [x] Create permission-based menu builder
- [x] Add Livewire integration support

#### Implementation Details

```php
namespace OpenFga\Laravel\Providers;

class BladeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blade::if('can', function ($relation, $object) {
            $user = 'user:' . auth()->id();
            return app(OpenFgaManager::class)->check($user, $relation, $object);
        });

        Blade::if('canany', function ($relations, $object) {
            $user = 'user:' . auth()->id();
            foreach ($relations as $relation) {
                if (app(OpenFgaManager::class)->check($user, $relation, $object)) {
                    return true;
                }
            }
            return false;
        });
    }
}

// Global helpers in Helpers.php
if (!function_exists('can')) {
    function can(string $relation, string $object): bool
    {
        return app(OpenFgaManager::class)->check('@me', $relation, $object);
    }
}
```

### Phase 6: Testing Utilities

#### Goals

- Comprehensive testing support
- Fake implementation for unit tests
- Assertion helpers

#### Tasks

- [x] Create FakeOpenFga implementation
- [x] Implement assertion methods
- [x] Add factory traits for test data
- [ ] Create testing documentation
- [ ] Implement snapshot testing for permissions
- [ ] Add performance testing utilities
- [ ] Create integration test helpers
- [ ] Add mocking support for specific scenarios

#### Implementation Details

```php
namespace OpenFga\Laravel\Testing;

class FakeOpenFga
{
    private array $tuples = [];
    private array $checks = [];

    public function grant(string $user, string $relation, string $object): void
    {
        $this->tuples[] = compact('user', 'relation', 'object');
    }

    public function check(string $user, string $relation, string $object): bool
    {
        $this->checks[] = compact('user', 'relation', 'object');

        return collect($this->tuples)->contains(
            fn($tuple) => $tuple['user'] === $user
                && $tuple['relation'] === $relation
                && $tuple['object'] === $object
        );
    }

    public function assertGranted(string $user, string $relation, string $object): void
    {
        PHPUnit::assertTrue(
            collect($this->tuples)->contains(fn($tuple) =>
                $tuple['user'] === $user
                && $tuple['relation'] === $relation
                && $tuple['object'] === $object
            ),
            "Failed asserting that permission was granted"
        );
    }
}
```

### Phase 7: Artisan Commands

#### Goals

- CLI tools for development and debugging
- Model management commands
- Permission inspection tools

#### Tasks

- [x] Create openfga:check command
- [x] Implement openfga:grant and openfga:revoke commands
- [ ] Add openfga:model:create command for DSL files
- [ ] Create openfga:model:validate command
- [x] Implement openfga:expand command
- [x] Add openfga:list-objects command
- [x] Create openfga:debug command
- [ ] Implement openfga:store:create command
- [ ] Create permission audit commands

#### Implementation Details

```php
namespace OpenFga\Laravel\Console\Commands;

class CheckCommand extends Command
{
    protected $signature = 'openfga:check {user} {relation} {object}
                            {--connection= : The connection to use}
                            {--json : Output as JSON}';

    protected $description = 'Check if a user has a specific permission';

    public function handle(OpenFgaManager $manager): int
    {
        $allowed = $manager->connection($this->option('connection'))
            ->check(
                $this->argument('user'),
                $this->argument('relation'),
                $this->argument('object')
            );

        if ($this->option('json')) {
            $this->output->writeln(json_encode(['allowed' => $allowed]));
        } else {
            $this->info($allowed ? '✅ Permission granted' : '❌ Permission denied');
        }

        return $allowed ? 0 : 1;
    }
}
```

### Phase 8: Advanced Features

#### Goals

- Queue support for batch operations
- Event system integration
- Observability and debugging tools

#### Tasks

- [x] Create queueable job for batch operations
- [x] Implement event classes for all operations
- [ ] Add Laravel Debugbar integration
- [x] Create performance monitoring tools
- [x] Implement audit logging
- [ ] Add webhook support for permission changes
- [ ] Add permission import/export tools

#### Implementation Details

```php
namespace OpenFga\Laravel\Jobs;

class BatchWriteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private array $writes,
        private array $deletes,
        private ?string $connection = null
    ) {}

    public function handle(OpenFgaManager $manager): void
    {
        $manager->connection($this->connection)
            ->writeBatch($this->writes, $this->deletes);

        event(new BatchWriteCompleted($this->writes, $this->deletes));
    }

    public function failed(Throwable $exception): void
    {
        event(new BatchWriteFailed($this->writes, $this->deletes, $exception));
    }
}
```

### Phase 9: Documentation & Developer Experience

#### Goals

- Comprehensive documentation
- Interactive tutorials
- Migration guides

#### Tasks

- [x] Write installation guide
- [x] Create quick start tutorial
- [x] Document all configuration options
- [x] Write Eloquent integration guide
- [x] Create middleware documentation
- [x] Document testing approaches
- [x] Write performance optimization guide
- [x] Create troubleshooting guide
- [x] Add API reference documentation
- [ ] Create example application
- [ ] Write migration guide from other auth systems
- [ ] Ensure we have compatibility with Spatie's laravel-permission package

### Phase 10: Performance & Optimization

#### Goals

- Minimize API calls through intelligent caching
- Batch operations by default
- Async support where appropriate

#### Tasks

- [x] Implement intelligent cache warming
- [x] Add cache tags for granular invalidation
- [x] Create read-through cache implementation
- [ ] Implement write-behind cache for non-critical updates
- [ ] Add connection pooling
- [ ] Optimize batch operations
- [ ] Implement request deduplication
- [ ] Add performance profiling tools
- [ ] Create benchmark suite
- [ ] Document performance best practices

## Testing Strategy

### Unit Tests

- Test each component in isolation
- Mock OpenFGA client responses
- Cover all error scenarios
- Ensure Laravel integration points work correctly

### Integration Tests

- Test against real OpenFGA instance
- Verify multi-connection support
- Test cache behavior
- Ensure queue integration works

### End-to-End Tests

- Create example Laravel application
- Test common authorization scenarios
- Verify performance characteristics
- Test upgrade paths

## Release Strategy

### Version 1.0 - Core Features

- Basic configuration and service provider
- Simple API methods (check, grant, revoke)
- Eloquent trait
- Basic middleware
- Facade support

### Version 1.1 - Enhanced DX

- Blade directives
- Global helpers
- Artisan commands
- Testing utilities

### Version 1.2 - Advanced Features

- Queue support
- Event system
- Performance optimizations
- Advanced caching

### Version 2.0 - Ecosystem

- UI components
- Admin panel
- Migration tools
- Analytics dashboard

## Success Metrics

1. **Developer Adoption**

   - GitHub stars and downloads
   - Community contributions
   - Support questions (fewer = better docs)

2. **Performance**

   - Sub-10ms cached permission checks
   - Efficient batch operations
   - Minimal memory overhead

3. **Developer Satisfaction**
   - Time to first permission check < 5 minutes
   - Clear error messages
   - Intuitive API design

## Maintenance Plan

1. **Regular Updates**

   - Track OpenFGA PHP SDK updates
   - Support new Laravel versions quickly
   - Security updates within 24 hours

2. **Community Support**

   - Respond to issues within 48 hours
   - Accept and review PRs promptly
   - Maintain clear contribution guidelines

3. **Documentation**
   - Keep docs in sync with code
   - Add examples from community
   - Regular video content updates
