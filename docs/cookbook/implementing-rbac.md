# Implementing RBAC (Role-Based Access Control)

This recipe shows you how to implement a traditional Role-Based Access Control system using OpenFGA Laravel. RBAC is one of the most common authorization patterns, where users are assigned roles, and roles have permissions.

## Authorization Model

First, define your authorization model:

```dsl
model
  schema 1.1

type user

type role
  relations
    define assignee: [user]

type document
  relations
    define owner: [user]
    define admin: [user, role#assignee]
    define editor: [user, role#assignee] or admin
    define viewer: [user, role#assignee] or editor or owner
```

This model defines:
- **Users** can be directly assigned permissions on documents
- **Roles** can have users as assignees
- **Documents** can have permissions granted to users directly or through roles

## Setting Up Roles

### 1. Create Roles

```php
use OpenFGA\Laravel\Facades\OpenFga;

// Create organizational roles
OpenFga::writeBatch([
    // Marketing team roles
    ['role:marketing-manager', 'assignee', 'user:alice'],
    ['role:marketing-editor', 'assignee', 'user:bob'],
    ['role:marketing-viewer', 'assignee', 'user:charlie'],
    
    // Engineering team roles  
    ['role:engineering-lead', 'assignee', 'user:david'],
    ['role:senior-engineer', 'assignee', 'user:eve'],
    ['role:junior-engineer', 'assignee', 'user:frank'],
]);
```

### 2. Grant Role-Based Permissions

```php
// Grant permissions to roles on different document types

// Marketing documents
OpenFga::writeBatch([
    ['role:marketing-manager', 'admin', 'document:campaign-brief'],
    ['role:marketing-editor', 'editor', 'document:campaign-brief'],
    ['role:marketing-viewer', 'viewer', 'document:campaign-brief'],
]);

// Technical documents
OpenFga::writeBatch([
    ['role:engineering-lead', 'admin', 'document:architecture-spec'],
    ['role:senior-engineer', 'editor', 'document:architecture-spec'],
    ['role:junior-engineer', 'viewer', 'document:architecture-spec'],
]);
```

## User Management

### 1. Assign Users to Roles

```php
class RoleManager
{
    public function assignUserToRole(string $userId, string $roleId): bool
    {
        return OpenFga::grant("user:{$userId}", 'assignee', "role:{$roleId}");
    }
    
    public function removeUserFromRole(string $userId, string $roleId): bool
    {
        return OpenFga::revoke("user:{$userId}", 'assignee', "role:{$roleId}");
    }
    
    public function getUserRoles(string $userId): array
    {
        return OpenFga::listObjects("user:{$userId}", 'assignee', 'role');
    }
    
    public function getRoleUsers(string $roleId): array
    {
        return OpenFga::listUsers("role:{$roleId}", 'assignee');
    }
}
```

### 2. Check Role-Based Permissions

```php
class PermissionChecker
{
    public function canUserAccessDocument(string $userId, string $permission, string $documentId): bool
    {
        return OpenFga::check("user:{$userId}", $permission, "document:{$documentId}");
    }
    
    public function getUserDocuments(string $userId, string $permission): array
    {
        return OpenFga::listObjects("user:{$userId}", $permission, 'document');
    }
    
    public function hasRole(string $userId, string $roleId): bool
    {
        return OpenFga::check("user:{$userId}", 'assignee', "role:{$roleId}");
    }
}
```

## Eloquent Integration

### 1. User Model

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use OpenFGA\Laravel\Facades\OpenFga;

class User extends Authenticatable
{
    public function assignRole(string $role): bool
    {
        return OpenFga::grant("user:{$this->id}", 'assignee', "role:{$role}");
    }
    
    public function removeRole(string $role): bool
    {
        return OpenFga::revoke("user:{$this->id}", 'assignee', "role:{$role}");
    }
    
    public function hasRole(string $role): bool
    {
        return OpenFga::check("user:{$this->id}", 'assignee', "role:{$role}");
    }
    
    public function getRoles(): array
    {
        return OpenFga::listObjects("user:{$this->id}", 'assignee', 'role');
    }
    
    public function canAccessDocument(string $documentId, string $permission = 'viewer'): bool
    {
        return OpenFga::check("user:{$this->id}", $permission, "document:{$documentId}");
    }
}
```

### 2. Document Model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Document extends Model
{
    use HasAuthorization;
    
    protected function authorizationType(): string
    {
        return 'document';
    }
    
    public function grantRolePermission(string $role, string $permission): bool
    {
        return $this->grant("role:{$role}", $permission);
    }
    
    public function revokeRolePermission(string $role, string $permission): bool
    {
        return $this->revoke("role:{$role}", $permission);
    }
    
    public function getUsersWithRole(string $role, string $permission): array
    {
        // First check if the role has the permission
        if (!$this->check("role:{$role}", $permission)) {
            return [];
        }
        
        // Get users assigned to this role
        return OpenFga::listUsers("role:{$role}", 'assignee');
    }
}
```

## Middleware Integration

### 1. Role-Based Route Protection

```php
// In routes/web.php

// Only marketing managers can access marketing admin routes
Route::middleware(['auth', 'role:marketing-manager'])
    ->prefix('marketing')
    ->group(function () {
        Route::get('/dashboard', [MarketingController::class, 'dashboard']);
        Route::post('/campaigns', [MarketingController::class, 'store']);
    });

// Engineering leads only
Route::middleware(['auth', 'role:engineering-lead'])
    ->prefix('engineering')
    ->group(function () {
        Route::get('/architecture', [EngineeringController::class, 'architecture']);
        Route::put('/deployment', [EngineeringController::class, 'deploy']);
    });
```

### 2. Custom Role Middleware

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenFGA\Laravel\Facades\OpenFga;

class CheckRole
{
    public function handle(Request $request, Closure $next, string $role): mixed
    {
        $user = $request->user();
        
        if (!$user || !OpenFga::check("user:{$user->id}", 'assignee', "role:{$role}")) {
            abort(403, "Access denied. Required role: {$role}");
        }
        
        return $next($request);
    }
}
```

Register the middleware:

```php
// In app/Http/Kernel.php or bootstrap/app.php (Laravel 11)
protected $middlewareAliases = [
    'role' => \App\Http\Middleware\CheckRole::class,
];
```

## Advanced RBAC Patterns

### 1. Hierarchical Roles

```dsl
type role
  relations
    define assignee: [user]
    define parent: [role]
    define inherited_assignee: assignee or parent->inherited_assignee
```

```php
// Create role hierarchy
OpenFga::writeBatch([
    ['role:admin', 'parent', 'role:manager'],
    ['role:manager', 'parent', 'role:editor'],
    ['role:editor', 'parent', 'role:viewer'],
]);

// Grant permissions at different levels
OpenFga::writeBatch([
    ['role:admin', 'admin', 'document:sensitive'],
    ['role:manager', 'editor', 'document:sensitive'],
    ['role:editor', 'editor', 'document:public'],
    ['role:viewer', 'viewer', 'document:public'],
]);
```

### 2. Conditional Roles (Context-Based)

```php
class ConditionalRoleChecker
{
    public function checkWithContext(string $userId, string $permission, string $object, array $context = []): bool
    {
        // Basic role check
        $hasPermission = OpenFga::check("user:{$userId}", $permission, $object);
        
        if (!$hasPermission) {
            return false;
        }
        
        // Additional context checks
        if (isset($context['department'])) {
            $userDepartment = $this->getUserDepartment($userId);
            $objectDepartment = $this->getObjectDepartment($object);
            
            // Users can only access documents from their department
            if ($userDepartment !== $objectDepartment) {
                return false;
            }
        }
        
        if (isset($context['time_restriction'])) {
            // Check business hours
            $now = now();
            if ($now->hour < 9 || $now->hour > 17) {
                return false;
            }
        }
        
        return true;
    }
}
```

### 3. Dynamic Role Assignment

```php
class DynamicRoleManager
{
    public function assignTemporaryRole(string $userId, string $role, int $durationMinutes): void
    {
        // Grant the role
        OpenFga::grant("user:{$userId}", 'assignee', "role:{$role}");
        
        // Schedule removal
        RevokeTemporaryRoleJob::dispatch($userId, $role)
            ->delay(now()->addMinutes($durationMinutes));
    }
    
    public function assignProjectRole(string $userId, string $projectId, string $role): bool
    {
        // Create project-specific role
        $projectRole = "role:{$role}-project-{$projectId}";
        
        return OpenFga::grant("user:{$userId}", 'assignee', $projectRole);
    }
}
```

## Testing RBAC

```php
use OpenFGA\Laravel\Testing\FakesOpenFga;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use FakesOpenFga;
    
    public function test_manager_can_edit_documents()
    {
        $this->fakeOpenFga();
        
        $user = User::factory()->create();
        $document = Document::factory()->create();
        
        // Assign role and permissions
        OpenFga::grant("user:{$user->id}", 'assignee', 'role:manager');
        OpenFga::grant('role:manager', 'editor', "document:{$document->id}");
        
        $response = $this->actingAs($user)
            ->put("/documents/{$document->id}", [
                'title' => 'Updated Title',
            ]);
            
        $response->assertOk();
        
        // Verify role-based permission was checked
        OpenFga::assertChecked("user:{$user->id}", 'editor', "document:{$document->id}");
    }
    
    public function test_role_hierarchy_works()
    {
        $this->fakeOpenFga();
        
        $admin = User::factory()->create();
        $manager = User::factory()->create();
        $viewer = User::factory()->create();
        
        // Set up hierarchy
        OpenFga::grant("user:{$admin->id}", 'assignee', 'role:admin');
        OpenFga::grant("user:{$manager->id}", 'assignee', 'role:manager');
        OpenFga::grant("user:{$viewer->id}", 'assignee', 'role:viewer');
        
        OpenFga::grant('role:admin', 'parent', 'role:manager');
        OpenFga::grant('role:manager', 'parent', 'role:viewer');
        
        // Admin should have all permissions
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->hasRole('manager')); // Through hierarchy
        $this->assertTrue($admin->hasRole('viewer'));  // Through hierarchy
        
        // Manager should have manager and viewer permissions
        $this->assertFalse($manager->hasRole('admin'));
        $this->assertTrue($manager->hasRole('manager'));
        $this->assertTrue($manager->hasRole('viewer')); // Through hierarchy
        
        // Viewer should only have viewer permissions
        $this->assertFalse($viewer->hasRole('admin'));
        $this->assertFalse($viewer->hasRole('manager'));
        $this->assertTrue($viewer->hasRole('viewer'));
    }
}
```

## Best Practices

### 1. Role Naming Conventions

- Use consistent prefixes: `role:department-level` (e.g., `role:marketing-manager`)
- Be descriptive: `role:content-editor` vs `role:editor`
- Consider scope: `role:project-lead-mobile-app`

### 2. Permission Granularity

```php
// Too granular - hard to manage
'role:user-can-read-marketing-documents-on-weekdays'

// Good balance
'role:marketing-editor'

// Too broad - security risk
'role:admin-everything'
```

### 3. Regular Cleanup

```php
class RoleCleanupCommand extends Command
{
    public function handle()
    {
        // Remove expired temporary roles
        $this->cleanupExpiredRoles();
        
        // Remove roles for inactive users
        $this->cleanupInactiveUserRoles();
        
        // Audit role assignments
        $this->auditRoleAssignments();
    }
}
```

This RBAC implementation provides a solid foundation that can be extended based on your specific requirements while maintaining security and performance.