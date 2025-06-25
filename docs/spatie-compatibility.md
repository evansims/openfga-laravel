# Spatie Laravel Permission Compatibility

OpenFGA Laravel provides a comprehensive compatibility layer for [Spatie Laravel Permission](https://spatie.be/docs/laravel-permission), allowing you to migrate from role-based permissions to relationship-based authorization with minimal code changes.

## Table of Contents

- [Overview](#overview)
- [Installation](#installation)
- [Configuration](#configuration)
- [User Model Integration](#user-model-integration)
- [Blade Directives](#blade-directives)
- [Middleware](#middleware)
- [Migration from Spatie](#migration-from-spatie)
- [API Reference](#api-reference)
- [Limitations](#limitations)

## Overview

The Spatie compatibility layer provides:

- **Familiar API**: Use the same method names as Spatie Laravel Permission
- **Automatic Mapping**: Roles and permissions are automatically mapped to OpenFGA relations
- **Blade Directives**: All Spatie Blade directives work with OpenFGA
- **Middleware**: Drop-in replacement for Spatie middleware
- **Migration Tools**: Commands to migrate existing Spatie data to OpenFGA

## Installation

### 1. Enable Compatibility

Add to your `.env` file:

```env
OPENFGA_SPATIE_COMPATIBILITY=true
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=openfga-spatie-config
```

### 3. Register Service Provider (Optional)

If not using Laravel's auto-discovery, add to `config/app.php`:

```php
'providers' => [
    // ...
    OpenFGA\Laravel\Providers\SpatieCompatibilityServiceProvider::class,
],
```

## Configuration

The compatibility layer is configured in `config/spatie-compatibility.php`:

```php
return [
    // Enable compatibility features
    'enabled' => env('OPENFGA_SPATIE_COMPATIBILITY', false),

    // Map Spatie permissions to OpenFGA relations
    'permission_mappings' => [
        'edit posts' => 'editor',
        'view posts' => 'viewer',
        'delete posts' => 'owner',
        'manage users' => 'admin',
        // ... more mappings
    ],

    // Map Spatie roles to OpenFGA relations
    'role_mappings' => [
        'admin' => 'admin',
        'editor' => 'editor',
        'moderator' => 'moderator',
        'user' => 'member',
        // ... more mappings
    ],

    // Default context for role/permission checks
    'default_context' => 'organization:main',

    // Enable/disable specific Blade directives
    'blade_directives' => [
        'hasrole' => true,
        'haspermission' => true,
        // ... more directives
    ],
];
```

### Custom Mappings

You can add custom mappings programmatically:

```php
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;

$compatibility = app(SpatieCompatibility::class);

// Add permission mapping
$compatibility->addPermissionMapping('custom permission', 'custom_relation');

// Add role mapping  
$compatibility->addRoleMapping('custom role', 'custom_relation');
```

## User Model Integration

### Add the Compatibility Trait

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use OpenFGA\Laravel\Traits\{HasAuthorization, SpatieCompatible};

class User extends Authenticatable
{
    use HasAuthorization, SpatieCompatible;

    // ... rest of your model
}
```

### Using Spatie Methods

Once the trait is added, you can use all familiar Spatie methods:

```php
$user = User::find(1);

// Role methods
$user->assignRole('admin');
$user->removeRole('editor');
$user->hasRole('admin'); // true
$user->hasAnyRole(['admin', 'editor']); // true
$user->hasAllRoles(['admin', 'editor']); // false if user only has admin

// Permission methods
$user->givePermissionTo('edit posts');
$user->revokePermissionTo('delete posts');
$user->hasPermissionTo('edit posts'); // true
$user->hasAnyPermission(['edit posts', 'view posts']); // true

// Get collections
$user->getRoleNames(); // Collection of role names
$user->getAllPermissions(); // Collection of permissions

// Sync methods
$user->syncRoles(['admin', 'editor']);
$user->syncPermissions(['edit posts', 'view posts']);
```

### Contextual Permissions

OpenFGA supports contextual permissions, which you can use with the compatibility layer:

```php
// Check role in specific organization
$user->hasRole('admin', 'organization:acme');

// Assign role in specific context
$user->assignRole('manager', 'department:engineering');

// Check permission on specific model
$post = Post::find(1);
$user->hasPermissionTo('edit posts', $post);
```

## Blade Directives

All Spatie Blade directives are supported:

### Role Directives

```blade
@hasrole('admin')
    <p>You are an admin!</p>
@endhasrole

@hasanyrole('admin|editor')
    <p>You are an admin or editor!</p>
@endhasanyrole

@hasallroles('admin|editor')
    <p>You are both admin and editor!</p>
@endhasallroles

@unlessrole('admin')
    <p>You are not an admin.</p>
@endunlessrole
```

### Permission Directives

```blade
@haspermission('edit posts')
    <a href="/posts/create">Create Post</a>
@endhaspermission

@hasanypermission('edit posts|view posts')
    <p>You can work with posts!</p>
@endhasanypermission

@hasallpermissions('edit posts|delete posts')
    <p>You have full post control!</p>
@endhasallpermissions

@unlesspermission('edit posts')
    <p>You cannot edit posts.</p>
@endunlesspermission
```

### With Guard Support

```blade
@role('admin', 'api')
    <p>Admin via API guard</p>
@endrole

@permission('edit posts', 'web')
    <p>Can edit posts via web guard</p>
@endpermission
```

## Middleware

The compatibility layer provides drop-in replacements for Spatie middleware:

### Register Middleware

The middleware is automatically registered when compatibility is enabled. You can use them in routes:

```php
// Role middleware
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin', [AdminController::class, 'index']);
});

// Permission middleware
Route::middleware(['auth', 'permission:edit posts'])->group(function () {
    Route::resource('posts', PostController::class);
});

// Multiple roles (OR logic)
Route::middleware(['auth', 'role:admin|editor'])->group(function () {
    Route::get('/content', [ContentController::class, 'index']);
});

// Multiple permissions (OR logic)
Route::middleware(['auth', 'permission:edit posts|edit articles'])->group(function () {
    Route::get('/content/edit', [ContentController::class, 'edit']);
});
```

### Controller Usage

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PostController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view posts')->only(['index', 'show']);
        $this->middleware('permission:edit posts')->only(['create', 'store', 'edit', 'update']);
        $this->middleware('permission:delete posts')->only(['destroy']);
    }
    
    // ... controller methods
}
```

## Migration from Spatie

### Automatic Migration

Use the migration command to automatically transfer your Spatie data to OpenFGA:

```bash
# Dry run to see what would be migrated
php artisan openfga:migrate:spatie --dry-run

# Perform the actual migration
php artisan openfga:migrate:spatie

# Migrate with verification
php artisan openfga:migrate:spatie --verify

# Migrate in smaller batches
php artisan openfga:migrate:spatie --batch-size=50
```

### Manual Migration

If you prefer manual control, you can migrate specific parts:

```php
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;

$compatibility = app(SpatieCompatibility::class);

// Migrate a user's roles
$user = User::find(1);
foreach ($user->roles as $role) {
    $compatibility->assignRole($user, $role->name);
}

// Migrate a user's direct permissions
foreach ($user->permissions as $permission) {
    $compatibility->givePermissionTo($user, $permission->name);
}
```

### Post-Migration Steps

1. **Test thoroughly**: Verify all permissions work as expected
2. **Update authorization model**: Consider optimizing your OpenFGA model
3. **Remove Spatie**: Once confident, remove the Spatie package
4. **Clean up**: Remove old Spatie tables if desired

```bash
# Remove Spatie package (when ready)
composer remove spatie/laravel-permission

# Drop Spatie tables (optional, after backup)
php artisan migrate:rollback --path=vendor/spatie/laravel-permission/database/migrations
```

## API Reference

### SpatieCompatibility Class

```php
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;

$compatibility = app(SpatieCompatibility::class);

// Permission methods
$compatibility->hasPermissionTo($user, 'edit posts', $model);
$compatibility->hasAnyPermission($user, ['edit posts', 'view posts'], $model);
$compatibility->hasAllPermissions($user, ['edit posts', 'delete posts'], $model);
$compatibility->givePermissionTo($user, 'edit posts', $model);
$compatibility->revokePermissionTo($user, 'edit posts', $model);

// Role methods
$compatibility->hasRole($user, 'admin', $context);
$compatibility->hasAnyRole($user, ['admin', 'editor'], $context);
$compatibility->hasAllRoles($user, ['admin', 'editor'], $context);
$compatibility->assignRole($user, 'admin', $context);
$compatibility->removeRole($user, 'admin', $context);

// Collection methods
$compatibility->getAllPermissions($user, $context);
$compatibility->getRoleNames($user, $context);

// Sync methods
$compatibility->syncRoles($user, ['admin', 'editor'], $context);
$compatibility->syncPermissions($user, ['edit posts', 'view posts'], $model);

// Mapping methods
$compatibility->addPermissionMapping($permission, $relation);
$compatibility->addRoleMapping($role, $relation);
$compatibility->getPermissionMappings();
$compatibility->getRoleMappings();
```

### SpatieCompatible Trait Methods

All methods from the `SpatieCompatibility` class are available on models using the trait:

```php
$user = User::find(1);

// Permission methods
$user->hasPermissionTo('edit posts');
$user->hasAnyPermission(['edit posts', 'view posts']);
$user->hasAllPermissions(['edit posts', 'delete posts']);
$user->givePermissionTo('edit posts');
$user->revokePermissionTo('edit posts');
$user->can('edit posts'); // Alias for hasPermissionTo
$user->cannot('edit posts'); // Opposite of hasPermissionTo

// Role methods
$user->hasRole('admin');
$user->hasAnyRole(['admin', 'editor']);
$user->hasAllRoles(['admin', 'editor']);
$user->assignRole('admin');
$user->removeRole('admin');

// Collection methods
$user->getAllPermissions();
$user->getRoleNames();
$user->roles(); // Returns object collection for compatibility
$user->permissions(); // Returns object collection for compatibility

// Sync methods
$user->syncRoles(['admin', 'editor']);
$user->syncPermissions(['edit posts', 'view posts']);

// Advanced methods
$user->hasDirectPermission('edit posts'); // Simplified in OpenFGA context
$user->getDirectPermissions();
$user->getPermissionsViaRoles();
$user->hasExactRoles(['admin', 'editor']);
```

## Limitations

### Differences from Spatie

1. **No Role Hierarchy**: OpenFGA uses relationships instead of role inheritance
2. **No Guards on Relations**: OpenFGA relations don't have guard concepts
3. **No Permission Models**: Permissions are relationships, not database records
4. **Different Caching**: Uses OpenFGA's caching instead of Spatie's cache

### Workarounds

1. **Role Hierarchy**: Use OpenFGA's relationship inheritance in your authorization model
2. **Complex Permissions**: Model complex scenarios using OpenFGA's relationship system
3. **Performance**: Leverage OpenFGA's built-in caching and batching

### Migration Considerations

1. **Data Loss**: Some Spatie features don't have direct OpenFGA equivalents
2. **Performance Changes**: OpenFGA may have different performance characteristics
3. **API Changes**: Some advanced Spatie features may require code changes

## Best Practices

### 1. Start with Compatibility

Use the compatibility layer to migrate gradually:

```php
// Start with compatibility
if (config('spatie-compatibility.enabled')) {
    return $user->hasPermissionTo('edit posts');
} else {
    return OpenFga::check($user->authorizationUser(), 'editor', 'post:*');
}
```

### 2. Plan Your Authorization Model

Design your OpenFGA model to match your Spatie structure:

```fga
model
  schema 1.1

type user

type organization
  relations
    define admin: [user]
    define editor: [user] or admin
    define member: [user] or editor

type post
  relations
    define owner: [user]
    define editor: [user] or owner or editor from organization
    define viewer: [user] or editor
```

### 3. Test Thoroughly

Create comprehensive tests:

```php
class MigrationTest extends TestCase
{
    public function test_spatie_compatibility_works()
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasPermissionTo('manage users'));
    }
}
```

### 4. Monitor Performance

Compare performance before and after migration:

```php
// Add timing to critical permission checks
$start = microtime(true);
$hasPermission = $user->hasPermissionTo('edit posts');
$duration = microtime(true) - $start;

Log::info('Permission check duration', ['duration' => $duration]);
```

## Support

- **Issues**: Report compatibility issues on [GitHub](https://github.com/openfga/laravel-sdk/issues)
- **Migration Help**: Use the `migration` label for migration-related questions
- **Documentation**: Check the main [OpenFGA Laravel documentation](../README.md)