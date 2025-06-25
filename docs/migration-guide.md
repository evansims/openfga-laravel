# Migration Guide: From Traditional Authorization to OpenFGA Laravel

This guide helps you migrate from existing Laravel authorization systems to OpenFGA Laravel, providing step-by-step instructions, code examples, and best practices.

## Table of Contents

- [Overview](#overview)
- [From Laravel's Built-in Authorization](#from-laravels-built-in-authorization)
- [From Spatie Laravel Permission](#from-spatie-laravel-permission)
- [From Laravel Sanctum/Passport with Roles](#from-laravel-sanctumpassport-with-roles)
- [From Custom Authorization Systems](#from-custom-authorization-systems)
- [Migration Strategies](#migration-strategies)
- [Common Challenges and Solutions](#common-challenges-and-solutions)
- [Testing Your Migration](#testing-your-migration)

## Overview

OpenFGA provides fine-grained, relationship-based authorization that scales better than traditional role-based systems. This guide covers migrating from various Laravel authorization patterns.

### Why Migrate to OpenFGA?

- **Fine-grained permissions**: Move beyond simple roles to relationship-based authorization
- **Scalability**: Handle complex permission hierarchies without performance degradation
- **Flexibility**: Model real-world authorization scenarios accurately
- **Auditability**: Track permission changes with detailed logging
- **Performance**: Efficient permission checking with built-in caching

## From Laravel's Built-in Authorization

Laravel's built-in authorization using Gates and Policies can be enhanced with OpenFGA's relationship-based model.

### Before: Laravel Gates

```php
// app/Providers/AuthServiceProvider.php
Gate::define('edit-post', function (User $user, Post $post) {
    return $user->id === $post->user_id || $user->isAdmin();
});

Gate::define('view-admin-panel', function (User $user) {
    return $user->role === 'admin';
});
```

### After: OpenFGA Laravel

```php
// 1. Define your authorization model
// openfga/model.fga
model
  schema 1.1

type user

type organization
  relations
    define admin: [user]
    define member: [user] or admin

type post
  relations
    define owner: [user]
    define editor: [user] or owner
    define viewer: [user] or editor or member from organization

// 2. Use in your application
class PostController extends Controller
{
    public function edit(Post $post)
    {
        $this->authorize('edit', $post); // Uses OpenFGA policy
        return view('posts.edit', compact('post'));
    }
}

// 3. Create OpenFGA Policy
class PostPolicy
{
    use \OpenFGA\Laravel\Traits\AuthorizesWithOpenFga;

    public function edit(User $user, Post $post): bool
    {
        return $this->check($user->authorizationUser(), 'editor', $post->authorizationObject());
    }
}
```

### Migration Steps

1. **Install OpenFGA Laravel**:
```bash
composer require openfga/laravel-sdk
php artisan vendor:publish --provider="OpenFGA\Laravel\OpenFgaServiceProvider"
```

2. **Define your authorization model** based on existing Gates:
```bash
php artisan openfga:model:create
```

3. **Replace Gate definitions** with OpenFGA policies:
```php
// Instead of Gate::define(), create Policy classes
php artisan make:policy PostPolicy --model=Post
```

4. **Migrate existing permissions**:
```php
// Create a migration command
php artisan make:command MigrateToOpenFga

class MigrateToOpenFga extends Command
{
    public function handle()
    {
        User::chunk(100, function ($users) {
            foreach ($users as $user) {
                if ($user->role === 'admin') {
                    // Grant admin access to organization
                    OpenFga::grant($user->authorizationUser(), 'admin', 'organization:main');
                }
                
                // Migrate post ownership
                foreach ($user->posts as $post) {
                    OpenFga::grant($user->authorizationUser(), 'owner', $post->authorizationObject());
                }
            }
        });
    }
}
```

## From Spatie Laravel Permission

Spatie's Laravel Permission package uses roles and permissions. OpenFGA provides more flexibility with relationships.

### Before: Spatie Laravel Permission

```php
// Roles and permissions
$user->assignRole('editor');
$user->givePermissionTo('edit articles');

// Checking permissions
if ($user->can('edit articles')) {
    // Allow editing
}

// Role-based checks
if ($user->hasRole('admin')) {
    // Admin actions
}
```

### After: OpenFGA Laravel

```php
// Define relationships in authorization model
type user

type role
  relations
    define assignee: [user]

type organization
  relations
    define admin: [user]
    define editor: [user] or admin
    define member: [user] or editor

type article
  relations
    define owner: [user]
    define editor: [user] or owner or editor from organization
    define viewer: [user] or editor

// Grant relationships
OpenFga::grant('user:123', 'editor', 'organization:acme');
OpenFga::grant('user:123', 'owner', 'article:456');

// Check permissions
if (OpenFga::check('user:123', 'editor', 'article:456')) {
    // Allow editing
}
```

### Compatibility Layer

Create a compatibility layer to ease migration:

```php
// app/Services/SpatieMigrationService.php
class SpatieMigrationService
{
    public function migrateUser(User $user): void
    {
        // Migrate roles
        foreach ($user->roles as $role) {
            $this->migrateRole($user, $role);
        }

        // Migrate direct permissions
        foreach ($user->permissions as $permission) {
            $this->migratePermission($user, $permission);
        }
    }

    private function migrateRole(User $user, Role $role): void
    {
        match ($role->name) {
            'admin' => OpenFga::grant($user->authorizationUser(), 'admin', 'organization:main'),
            'editor' => OpenFga::grant($user->authorizationUser(), 'editor', 'organization:main'),
            'member' => OpenFga::grant($user->authorizationUser(), 'member', 'organization:main'),
            default => $this->handleCustomRole($user, $role),
        };
    }

    private function migratePermission(User $user, Permission $permission): void
    {
        // Map Spatie permissions to OpenFGA relationships
        $mapping = [
            'edit articles' => ['editor', 'article:*'],
            'view admin panel' => ['admin', 'organization:main'],
            'manage users' => ['admin', 'organization:main'],
        ];

        if (isset($mapping[$permission->name])) {
            [$relation, $object] = $mapping[$permission->name];
            OpenFga::grant($user->authorizationUser(), $relation, $object);
        }
    }
}
```

### Migration Command

```php
class MigrateFromSpatie extends Command
{
    protected $signature = 'openfga:migrate:spatie';
    protected $description = 'Migrate from Spatie Laravel Permission to OpenFGA';

    public function handle(SpatieMigrationService $migrationService)
    {
        $this->info('Starting migration from Spatie Laravel Permission...');

        $userCount = User::count();
        $bar = $this->output->createProgressBar($userCount);

        User::with(['roles', 'permissions'])->chunk(100, function ($users) use ($migrationService, $bar) {
            foreach ($users as $user) {
                $migrationService->migrateUser($user);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Migration completed successfully!');
    }
}
```

## From Laravel Sanctum/Passport with Roles

API authentication with roles can be enhanced with OpenFGA's fine-grained permissions.

### Before: Sanctum with Abilities

```php
// Create token with abilities
$token = $user->createToken('API Token', ['read-posts', 'write-posts']);

// Check abilities in middleware
Route::middleware(['auth:sanctum', 'abilities:write-posts'])->post('/posts', [PostController::class, 'store']);

// In controller
if ($request->user()->tokenCan('write-posts')) {
    // Allow action
}
```

### After: OpenFGA Laravel with API

```php
// API middleware using OpenFGA
class OpenFgaApiMiddleware
{
    public function handle($request, Closure $next, $relation, $objectType = null)
    {
        $user = $request->user();
        $object = $objectType ?? $this->resolveObjectFromRoute($request);

        if (!OpenFga::check($user->authorizationUser(), $relation, $object)) {
            return response()->json(['error' => 'Insufficient permissions'], 403);
        }

        return $next($request);
    }
}

// Routes with OpenFGA protection
Route::middleware(['auth:sanctum', 'openfga:editor,post'])->post('/posts', [PostController::class, 'store']);
Route::middleware(['auth:sanctum', 'openfga:viewer,post'])->get('/posts/{post}', [PostController::class, 'show']);
```

### API Permission Checking Endpoint

```php
// routes/api.php
Route::middleware('auth:sanctum')->post('/permissions/check', [PermissionController::class, 'check']);

// app/Http/Controllers/Api/PermissionController.php
class PermissionController extends Controller
{
    public function check(Request $request)
    {
        $request->validate([
            'checks' => 'required|array',
            'checks.*.relation' => 'required|string',
            'checks.*.object' => 'required|string',
        ]);

        $user = $request->user();
        $results = [];

        foreach ($request->checks as $check) {
            $results[] = OpenFga::check(
                $user->authorizationUser(),
                $check['relation'],
                $check['object']
            );
        }

        return response()->json(['results' => $results]);
    }
}
```

## From Custom Authorization Systems

If you have a custom authorization system, migration requires mapping your existing logic to OpenFGA relationships.

### Analysis Phase

1. **Audit existing permissions**:
```php
php artisan make:command AnalyzePermissions

class AnalyzePermissions extends Command
{
    public function handle()
    {
        // Analyze existing permission patterns
        $this->analyzeUserRoles();
        $this->analyzeResourcePermissions();
        $this->analyzePermissionInheritance();
    }

    private function analyzeUserRoles()
    {
        $roles = DB::table('user_roles')->distinct()->pluck('role');
        $this->table(['Existing Roles'], $roles->map(fn($role) => [$role]));
    }

    private function analyzeResourcePermissions()
    {
        // Analyze how permissions are currently structured
        $permissions = DB::table('permissions')
            ->select('resource_type', 'action', DB::raw('count(*) as count'))
            ->groupBy('resource_type', 'action')
            ->get();

        $this->table(['Resource Type', 'Action', 'Count'], $permissions->toArray());
    }
}
```

2. **Map to OpenFGA model**:
```fga
model
  schema 1.1

type user

type department
  relations
    define manager: [user]
    define member: [user] or manager

type project
  relations
    define owner: [user]
    define collaborator: [user] or member from department
    define viewer: [user] or collaborator

type document
  relations
    define author: [user]
    define editor: [user] or author or collaborator from project
    define viewer: [user] or editor
```

### Migration Strategy

```php
class CustomSystemMigration
{
    public function migrate()
    {
        $this->migrateDepartments();
        $this->migrateProjects();
        $this->migrateDocuments();
        $this->migrateUserRelationships();
    }

    private function migrateDepartments()
    {
        Department::chunk(50, function ($departments) {
            foreach ($departments as $dept) {
                // Grant manager access
                if ($dept->manager) {
                    OpenFga::grant(
                        $dept->manager->authorizationUser(),
                        'manager',
                        $dept->authorizationObject()
                    );
                }

                // Grant member access
                foreach ($dept->members as $member) {
                    OpenFga::grant(
                        $member->authorizationUser(),
                        'member',
                        $dept->authorizationObject()
                    );
                }
            }
        });
    }
}
```

## Migration Strategies

### 1. Gradual Migration (Recommended)

Migrate functionality piece by piece while maintaining existing system:

```php
// Feature flag approach
class AuthorizationService
{
    public function check(User $user, string $permission, $resource = null): bool
    {
        if (config('features.openfga_authorization')) {
            return $this->checkWithOpenFga($user, $permission, $resource);
        }

        return $this->checkWithLegacySystem($user, $permission, $resource);
    }

    private function checkWithOpenFga(User $user, string $permission, $resource): bool
    {
        $relation = $this->mapPermissionToRelation($permission);
        $object = $resource ? $resource->authorizationObject() : 'organization:main';

        return OpenFga::check($user->authorizationUser(), $relation, $object);
    }
}
```

### 2. Shadow Mode

Run both systems in parallel for comparison:

```php
class ShadowModeAuthorization
{
    public function check(User $user, string $permission, $resource = null): bool
    {
        $legacyResult = $this->legacyCheck($user, $permission, $resource);
        
        if (config('features.openfga_shadow_mode')) {
            $openFgaResult = $this->openFgaCheck($user, $permission, $resource);
            
            // Log discrepancies for analysis
            if ($legacyResult !== $openFgaResult) {
                Log::warning('Authorization mismatch', [
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'resource' => $resource?->getKey(),
                    'legacy_result' => $legacyResult,
                    'openfga_result' => $openFgaResult,
                ]);
            }
        }

        return $legacyResult;
    }
}
```

### 3. Big Bang Migration

Complete migration all at once (for smaller applications):

```php
class BigBangMigration extends Command
{
    public function handle()
    {
        DB::transaction(function () {
            $this->info('Starting complete migration...');
            
            // Disable legacy authorization
            config(['features.legacy_auth' => false]);
            
            // Migrate all data
            $this->migrateAllUsers();
            $this->migrateAllResources();
            $this->migrateAllPermissions();
            
            // Enable OpenFGA
            config(['features.openfga_auth' => true]);
            
            $this->info('Migration completed!');
        });
    }
}
```

## Common Challenges and Solutions

### 1. Complex Permission Hierarchies

**Challenge**: Existing complex role hierarchies don't map directly to OpenFGA relationships.

**Solution**: Use OpenFGA's relationship inheritance:

```fga
type organization
  relations
    define owner: [user]
    define admin: [user] or owner
    define manager: [user] or admin
    define member: [user] or manager
```

### 2. Dynamic Permissions

**Challenge**: Permissions that change based on context or time.

**Solution**: Use contextual tuples and condition evaluation:

```php
// Grant temporary access
OpenFga::grant('user:123', 'editor', 'document:456', [
    'expires_at' => now()->addDays(7)->toISOString()
]);

// Check with context
$allowed = OpenFga::check('user:123', 'editor', 'document:456', [
    'current_time' => now()->toISOString()
]);
```

### 3. Performance During Migration

**Challenge**: Migration queries can be slow for large datasets.

**Solution**: Use batching and caching:

```php
class PerformantMigration
{
    public function migrateInBatches()
    {
        $batchSize = 1000;
        $offset = 0;

        do {
            $tuples = $this->prepareTuples($offset, $batchSize);
            
            if (!empty($tuples)) {
                OpenFga::writeBatch($tuples);
                $this->info("Migrated batch starting at offset {$offset}");
            }
            
            $offset += $batchSize;
        } while (count($tuples) === $batchSize);
    }

    private function prepareTuples(int $offset, int $limit): array
    {
        return User::offset($offset)
            ->limit($limit)
            ->with(['roles', 'permissions'])
            ->get()
            ->flatMap(fn($user) => $this->userToTuples($user))
            ->toArray();
    }
}
```

### 4. Data Consistency

**Challenge**: Ensuring data remains consistent during migration.

**Solution**: Use database transactions and verification:

```php
class ConsistentMigration
{
    public function migrate()
    {
        DB::transaction(function () {
            $this->performMigration();
            $this->verifyMigration();
        });
    }

    private function verifyMigration()
    {
        $sampleUsers = User::inRandomOrder()->limit(100)->get();
        
        foreach ($sampleUsers as $user) {
            $legacyPermissions = $this->getLegacyPermissions($user);
            $openFgaPermissions = $this->getOpenFgaPermissions($user);
            
            if (!$this->permissionsMatch($legacyPermissions, $openFgaPermissions)) {
                throw new Exception("Migration verification failed for user {$user->id}");
            }
        }
    }
}
```

## Testing Your Migration

### 1. Unit Tests

```php
class MigrationTest extends TestCase
{
    use RefreshDatabase, FakesOpenFga;

    public function test_admin_permissions_migrated_correctly()
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin'); // Legacy system

        $migration = new SpatieMigrationService();
        $migration->migrateUser($admin);

        $this->assertPermissionGranted(
            $admin->authorizationUser(),
            'admin',
            'organization:main'
        );
    }

    public function test_resource_ownership_migrated()
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $user->id]);

        $migration = new LegacyMigrationService();
        $migration->migratePost($post);

        $this->assertPermissionGranted(
            $user->authorizationUser(),
            'owner',
            $post->authorizationObject()
        );
    }
}
```

### 2. Integration Tests

```php
class AuthorizationIntegrationTest extends TestCase
{
    public function test_migrated_permissions_work_in_controllers()
    {
        $editor = User::factory()->create();
        OpenFga::grant($editor->authorizationUser(), 'editor', 'organization:main');

        $response = $this->actingAs($editor)
            ->post('/posts', ['title' => 'Test Post']);

        $response->assertSuccessful();
    }
}
```

### 3. Performance Tests

```php
class MigrationPerformanceTest extends TestCase
{
    public function test_migration_completes_within_time_limit()
    {
        $startTime = microtime(true);
        
        $migration = new FullMigration();
        $migration->migrate();
        
        $duration = microtime(true) - $startTime;
        
        $this->assertLessThan(300, $duration, 'Migration should complete within 5 minutes');
    }
}
```

## Post-Migration Checklist

- [ ] All user permissions migrated correctly
- [ ] All resource relationships established
- [ ] Legacy authorization system disabled
- [ ] Performance benchmarks met
- [ ] All tests passing
- [ ] Documentation updated
- [ ] Team trained on new system
- [ ] Monitoring and alerts configured
- [ ] Rollback plan tested
- [ ] Security audit completed

## Rollback Strategy

Always have a rollback plan:

```php
class RollbackMigration extends Command
{
    public function handle()
    {
        if (!$this->confirm('Are you sure you want to rollback to the legacy system?')) {
            return;
        }

        // Re-enable legacy system
        config(['features.legacy_auth' => true]);
        config(['features.openfga_auth' => false]);

        // Clear OpenFGA data if needed
        if ($this->confirm('Clear OpenFGA authorization data?')) {
            $this->clearOpenFgaData();
        }

        $this->info('Rollback completed successfully');
    }
}
```

## Support and Resources

- **Documentation**: Refer to the [OpenFGA Laravel documentation](docs/README.md)
- **Community**: Join discussions in GitHub issues
- **Examples**: Check the [example application](example/README.md)
- **Support**: For migration assistance, create a GitHub issue with the `migration` label

## Next Steps

After completing your migration:

1. **Optimize Performance**: Implement caching strategies for your use case
2. **Enhance Security**: Set up proper audit logging and monitoring
3. **Expand Usage**: Explore advanced OpenFGA features like conditions and contextual tuples
4. **Team Training**: Ensure your team understands the new authorization model

This migration guide provides a comprehensive approach to moving from traditional Laravel authorization to OpenFGA. Take time to plan your migration strategy and test thoroughly in a staging environment before deploying to production.