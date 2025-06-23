# Eloquent Integration Guide

This guide covers how to integrate OpenFGA authorization with your Eloquent models, providing a seamless authorization layer for your Laravel applications.

## Getting Started

### Adding the Trait

Add the `HasAuthorization` trait to any model that needs authorization:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Document extends Model
{
    use HasAuthorization;
}
```

## Basic Authorization Operations

### Granting Permissions

```php
$document = Document::find(1);
$user = User::find(1);

// Grant using model instance
$document->grant($user, 'editor');

// Grant using user ID string
$document->grant('user:123', 'viewer');

// Grant multiple permissions at once
$document->grantMany([
    ['user:alice', 'viewer'],
    ['user:bob', 'editor'],
    ['user:charlie', 'owner'],
]);
```

### Checking Permissions

```php
// Check permission for a user model
if ($document->check($user, 'editor')) {
    // User can edit the document
}

// Check permission for current authenticated user
if ($document->check('@me', 'viewer')) {
    // Current user can view the document
}

// Check permission with user ID string
if ($document->check('user:123', 'owner')) {
    // User 123 is the owner
}
```

### Revoking Permissions

```php
// Revoke single permission
$document->revoke($user, 'editor');

// Revoke multiple permissions
$document->revokeMany([
    ['user:alice', 'viewer'],
    ['user:bob', 'editor'],
]);

// Revoke all permissions for a user
$document->revokeAll($user);

// Revoke all permissions for everyone
$document->revokeAllPermissions();
```

## Authorization Object Customization

### Default Behavior

By default, the authorization object is generated as `{model_type}:{id}`:

```php
$document = Document::find(123);
echo $document->authorizationObject(); // "document:123"
```

### Customizing the Type

Override the `authorizationType()` method:

```php
class Document extends Model
{
    use HasAuthorization;

    protected function authorizationType(): string
    {
        return 'doc'; // Results in "doc:123"
    }
}
```

### Customizing the Object Format

Override the `authorizationObject()` method for complete control:

```php
class Document extends Model
{
    use HasAuthorization;

    public function authorizationObject(): string
    {
        return "document:{$this->team_id}:{$this->id}";
        // Results in "document:team-5:123"
    }
}
```

### Using UUIDs or Slugs

```php
class Document extends Model
{
    use HasAuthorization;

    public function authorizationObject(): string
    {
        // Use UUID if available
        if ($this->uuid) {
            return "document:{$this->uuid}";
        }

        // Fall back to ID
        return "document:{$this->id}";
    }
}
```

## Query Scopes

### whereUserCan Scope

Find all models that a user has specific permissions for:

```php
// Get all documents the current user can view
$viewableDocuments = Document::whereUserCan('@me', 'viewer')->get();

// Get all documents a specific user can edit
$editableDocuments = Document::whereUserCan($user, 'editor')->get();

// Combine with other query constraints
$recentEditableDocuments = Document::whereUserCan($user, 'editor')
    ->where('created_at', '>', now()->subDays(7))
    ->orderBy('updated_at', 'desc')
    ->get();
```

### whereUserCanAny Scope

Find models where user has any of the specified permissions:

```php
// Get documents where user can edit OR own
$documents = Document::whereUserCanAny($user, ['editor', 'owner'])->get();
```

### whereUserCanAll Scope

Find models where user has all specified permissions:

```php
// Get documents where user is both viewer AND editor
$documents = Document::whereUserCanAll($user, ['viewer', 'editor'])->get();
```

## Model Events

### Automatic Permission Cleanup

The trait automatically cleans up permissions when a model is deleted:

```php
$document->delete(); // All permissions for this document are automatically revoked
```

To disable automatic cleanup:

```php
class Document extends Model
{
    use HasAuthorization;

    protected $cleanupPermissionsOnDelete = false;
}
```

Or globally in config:

```php
// config/openfga.php
'options' => [
    'cleanup_on_delete' => false,
],
```

### Permission Events

Listen for permission changes:

```php
use OpenFGA\Laravel\Events\PermissionGranted;
use OpenFGA\Laravel\Events\PermissionRevoked;

// In EventServiceProvider
protected $listen = [
    PermissionGranted::class => [
        SendPermissionNotification::class,
        UpdateAuditLog::class,
    ],
    PermissionRevoked::class => [
        NotifyPermissionRevoked::class,
        CleanupRelatedData::class,
    ],
];
```

## Advanced Usage

### Contextual Permissions

Check permissions with contextual tuples:

```php
$document->checkWithContext(
    $user,
    'viewer',
    [
        ['user:' . $user->id, 'member', 'team:engineering'],
    ]
);
```

### Batch Operations

Perform multiple authorization operations efficiently:

```php
// Queue batch operations
$document->queueBatchWrite(
    writes: [
        ['user:alice', 'viewer'],
        ['user:bob', 'editor'],
        ['user:charlie', 'owner'],
    ],
    deletes: [
        ['user:david', 'viewer'],
    ]
);

// Execute immediately
$document->executeBatchWrite(
    writes: [
        ['user:alice', 'viewer'],
        ['user:bob', 'editor'],
    ]
);
```

### Getting Users with Permissions

```php
// Get all users who have a specific permission
$editors = $document->getUsersWithPermission('editor');

// Get users with any of the specified permissions
$users = $document->getUsersWithAnyPermission(['viewer', 'editor']);

// Get permissions for a specific user
$permissions = $document->getUserPermissions($user);
// Returns: ['viewer', 'editor']
```

### Permission Expansion

Expand permissions to see the full authorization tree:

```php
$expansion = $document->expandPermission('viewer');

// Get all users from expansion
$users = $expansion->getUsers();
```

## Relationships and Permissions

### Parent-Child Relationships

```php
class Folder extends Model
{
    use HasAuthorization;

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    // Grant permission to all documents when granting to folder
    public function grantWithChildren($user, $relation)
    {
        // Grant to folder
        $this->grant($user, $relation);

        // Grant to all documents
        $this->documents->each->grant($user, $relation);
    }
}
```

### Many-to-Many Relationships

```php
class Team extends Model
{
    use HasAuthorization;

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    // Grant team permissions to all members
    public function grantToAllMembers($relation, $object)
    {
        $writes = $this->users->map(function ($user) use ($relation, $object) {
            return ["user:{$user->id}", $relation, $object];
        })->toArray();

        OpenFga::writeBatch(writes: $writes);
    }
}
```

## Performance Optimization

### Eager Loading Permissions

Create a custom attribute to cache permission checks:

```php
class Document extends Model
{
    use HasAuthorization;

    protected $appends = ['can_edit', 'can_view'];

    public function getCanEditAttribute()
    {
        return once(fn() => $this->check('@me', 'editor'));
    }

    public function getCanViewAttribute()
    {
        return once(fn() => $this->check('@me', 'viewer'));
    }
}
```

### Batch Loading Permissions

Load permissions for multiple models at once:

```php
$documents = Document::limit(10)->get();

// Pre-load permissions
$permissions = OpenFga::batchCheck(
    $documents->map(fn($doc) => [
        'user' => 'user:' . auth()->id(),
        'relation' => 'viewer',
        'object' => $doc->authorizationObject(),
    ])->toArray()
);

// Use cached results
foreach ($documents as $index => $document) {
    $canView = $permissions[$index];
}
```

### Using with Resources

Integrate with API Resources:

```php
class DocumentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->when(
                $this->check('@me', 'viewer'),
                $this->content
            ),
            'permissions' => [
                'can_view' => $this->check('@me', 'viewer'),
                'can_edit' => $this->check('@me', 'editor'),
                'can_delete' => $this->check('@me', 'owner'),
            ],
        ];
    }
}
```

## Testing with Models

### Using Factories

```php
use OpenFGA\Laravel\Testing\FakesOpenFga;

class DocumentTest extends TestCase
{
    use FakesOpenFga;

    public function test_user_can_edit_document()
    {
        $this->fakeOpenFga();

        $user = User::factory()->create();
        $document = Document::factory()->create();

        // Grant permission
        $document->grant($user, 'editor');

        // Assert permission exists
        $this->assertTrue($document->check($user, 'editor'));

        // Assert OpenFGA was called
        OpenFga::assertGranted(
            "user:{$user->id}",
            'editor',
            "document:{$document->id}"
        );
    }
}
```

### Testing Scopes

```php
public function test_where_user_can_scope()
{
    $this->fakeOpenFga();

    $user = User::factory()->create();
    $documents = Document::factory()->count(5)->create();

    // Grant permissions to some documents
    $documents[0]->grant($user, 'viewer');
    $documents[2]->grant($user, 'viewer');
    $documents[4]->grant($user, 'viewer');

    // Mock listObjects response
    OpenFga::shouldListObjects(
        "user:{$user->id}",
        'viewer',
        'document',
        ["document:{$documents[0]->id}", "document:{$documents[2]->id}", "document:{$documents[4]->id}"]
    );

    // Test scope
    $viewableDocuments = Document::whereUserCan($user, 'viewer')->get();

    $this->assertCount(3, $viewableDocuments);
}
```

## Common Patterns

### Resource Ownership

```php
class Document extends Model
{
    use HasAuthorization;

    protected static function booted()
    {
        // Automatically grant owner permission to creator
        static::created(function (Document $document) {
            if (auth()->check()) {
                $document->grant('@me', 'owner');
            }
        });
    }

    public function isOwnedBy($user): bool
    {
        return $this->check($user, 'owner');
    }
}
```

### Hierarchical Permissions

```php
class Document extends Model
{
    use HasAuthorization;

    public function grantWithHierarchy($user, $relation)
    {
        $relations = ['viewer', 'editor', 'owner'];
        $index = array_search($relation, $relations);

        if ($index === false) {
            throw new InvalidArgumentException("Invalid relation: {$relation}");
        }

        // Grant requested permission and all lower permissions
        for ($i = 0; $i <= $index; $i++) {
            $this->grant($user, $relations[$i]);
        }
    }
}
```

### Temporary Permissions

```php
class Document extends Model
{
    use HasAuthorization;

    public function grantTemporary($user, $relation, $expiresAt)
    {
        $this->grant($user, $relation);

        // Schedule revocation
        dispatch(new RevokePermissionJob($this, $user, $relation))
            ->delay($expiresAt);
    }
}
```

## Next Steps

- Set up [Middleware & Authorization](middleware.md)
- Learn about [Testing](testing.md) your models
- Optimize with [Performance Guide](performance.md)
- Check the [API Reference](api-reference.md)
