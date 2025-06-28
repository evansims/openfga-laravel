# Quickstart Tutorial

This tutorial will get you up and running with OpenFGA Laravel in just a few minutes. We'll build a simple document management system where users can have different permissions on documents.

## Prerequisites

Make sure you've completed the [installation guide](installation.md) and have:

- OpenFGA Laravel package installed
- OpenFGA server running
- Basic configuration in place

## Setting Up Your Authorization Model

First, let's define a simple authorization model. Create a file called `document-model.openfga`:

```dsl
model
  schema 1.1

type user

type document
  relations
    define owner: [user]
    define editor: [user] or owner
    define viewer: [user] or editor
```

Load this model into your OpenFGA store using the OpenFGA CLI or API.

## Basic Usage

### 1. Granting Permissions

Let's grant a user permission to view a document:

```php
use OpenFGA\Laravel\Facades\OpenFga;

// Grant viewer permission
OpenFga::grant('user:alice', 'viewer', 'document:budget-2024');

// Grant editor permission
OpenFga::grant('user:bob', 'editor', 'document:budget-2024');

// Grant owner permission
OpenFga::grant('user:charlie', 'owner', 'document:budget-2024');
```

### 2. Checking Permissions

Check if a user has permission:

```php
// Check single permission
if (OpenFga::check('user:alice', 'viewer', 'document:budget-2024')) {
    echo "Alice can view the document";
}

// Check using the current authenticated user
if (OpenFga::check('@me', 'editor', 'document:budget-2024')) {
    echo "Current user can edit the document";
}
```

### 3. Using the Query Builder

For more complex operations, use the query builder:

```php
// Check with contextual tuples
$canEdit = OpenFga::query()
    ->for('user:alice')
    ->can('editor')
    ->on('document:budget-2024')
    ->with(['user:alice', 'member', 'team:finance'])
    ->check();

// Batch operations
OpenFga::query()
    ->grant([
        ['user:alice', 'viewer', 'document:report-1'],
        ['user:alice', 'viewer', 'document:report-2'],
        ['user:alice', 'viewer', 'document:report-3'],
    ])
    ->execute();
```

## Eloquent Integration

### 1. Add the Trait to Your Model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Document extends Model
{
    use HasAuthorization;

    // Optional: customize the authorization type
    protected function authorizationType(): string
    {
        return 'document';
    }
}
```

### 2. Working with Model Permissions

```php
$document = Document::find(1);

// Grant permission
$document->grant($user, 'editor');
$document->grant('user:alice', 'viewer');

// Check permission
if ($document->check($user, 'editor')) {
    // User can edit
}

// Revoke permission
$document->revoke($user, 'editor');

// Get all users with a specific permission
$editors = $document->getUsersWithPermission('editor');
```

### 3. Query by Permissions

```php
// Get all documents the user can view
$documents = Document::whereUserCan($user, 'viewer')->get();

// Get documents where current user is owner
$myDocuments = Document::whereUserCan('@me', 'owner')->get();
```

## Route Protection

### 1. Using Middleware

```php
// In routes/web.php
Route::middleware(['auth', 'openfga:editor,document:{document}'])
    ->get('/documents/{document}/edit', [DocumentController::class, 'edit']);

// Multiple permissions (any)
Route::middleware(['auth', 'can.any:viewer,editor,document:{document}'])
    ->get('/documents/{document}', [DocumentController::class, 'show']);

// Multiple permissions (all required)
Route::middleware(['auth', 'can.all:viewer,member,document:{document}'])
    ->get('/documents/{document}/share', [DocumentController::class, 'share']);
```

### 2. Controller Authorization

```php
namespace App\Http\Controllers;

use App\Models\Document;
use OpenFGA\Laravel\Facades\OpenFga;

class DocumentController extends Controller
{
    public function show(Document $document)
    {
        // Manual check
        if (!OpenFga::check('@me', 'viewer', $document->authorizationObject())) {
            abort(403);
        }

        return view('documents.show', compact('document'));
    }

    public function update(Request $request, Document $document)
    {
        // Using Laravel's authorize method
        $this->authorize('editor', $document);

        $document->update($request->validated());

        return redirect()->route('documents.show', $document);
    }
}
```

## Blade Templates

### 1. Conditional Rendering

```blade
@can('editor', 'document:' . $document->id)
    <a href="{{ route('documents.edit', $document) }}">Edit Document</a>
@endcan

@cannot('owner', 'document:' . $document->id)
    <p>You don't own this document</p>
@endcannot

@canany(['editor', 'owner'], 'document:' . $document->id)
    <button>Delete Document</button>
@endcanany
```

### 2. Using Components

```blade
<x-openfga-can relation="viewer" :object="$document->authorizationObject()">
    <div class="document-content">
        {{ $document->content }}
    </div>
</x-openfga-can>

<x-openfga-can-any :relations="['editor', 'owner']" :object="$document->authorizationObject()">
    <div class="document-actions">
        <button>Edit</button>
        <button>Share</button>
    </div>
</x-openfga-can-any>
```

## Form Requests

Create authorized form requests:

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenFGA\Laravel\Traits\AuthorizesWithOpenFga;

class UpdateDocumentRequest extends FormRequest
{
    use AuthorizesWithOpenFga;

    public function authorize(): bool
    {
        return $this->checkPermission('editor', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ];
    }
}
```

## Testing

### 1. Using Fake Implementation

```php
use OpenFGA\Laravel\Testing\FakesOpenFga;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use FakesOpenFga;

    public function test_user_can_view_document()
    {
        $this->fakeOpenFga();

        $user = User::factory()->create();
        $document = Document::factory()->create();

        // Grant permission in fake
        OpenFga::grant("user:{$user->id}", 'viewer', "document:{$document->id}");

        // Test the permission
        $response = $this->actingAs($user)
            ->get("/documents/{$document->id}");

        $response->assertOk();

        // Assert permission was checked
        OpenFga::assertChecked("user:{$user->id}", 'viewer', "document:{$document->id}");
    }
}
```

### 2. Testing Specific Scenarios

```php
public function test_editor_can_update_document()
{
    $this->fakeOpenFga();

    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Setup permissions
    OpenFga::grant("user:{$user->id}", 'editor', "document:{$document->id}");

    $response = $this->actingAs($user)
        ->put("/documents/{$document->id}", [
            'title' => 'Updated Title',
            'content' => 'Updated content',
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'title' => 'Updated Title',
    ]);
}
```

## Best Practices

### 1. Use Type Prefixes

Always prefix your identifiers:

- ✅ `user:123`
- ✅ `document:abc-123`
- ❌ `123`
- ❌ `abc-123`

### 2. Cache Permissions

Enable caching for better performance:

```php
// config/openfga.php
'cache' => [
    'enabled' => true,
    'ttl' => 300, // 5 minutes
],
```

### 3. Use Batch Operations

When granting/revoking multiple permissions:

```php
// Good - Single batch operation
OpenFga::writeBatch(
    writes: [
        ['user:alice', 'viewer', 'document:1'],
        ['user:alice', 'viewer', 'document:2'],
        ['user:alice', 'viewer', 'document:3'],
    ]
);

// Avoid - Multiple individual calls
OpenFga::grant('user:alice', 'viewer', 'document:1');
OpenFga::grant('user:alice', 'viewer', 'document:2');
OpenFga::grant('user:alice', 'viewer', 'document:3');
```

### 4. Clean Up Permissions

When deleting models, clean up their permissions:

```php
class Document extends Model
{
    use HasAuthorization;

    protected static function booted()
    {
        static::deleting(function (Document $document) {
            $document->revokeAllPermissions();
        });
    }
}
```

## Next Steps

- Learn about [Configuration Options](configuration.md)
- Explore [Eloquent Integration](eloquent.md) in depth
- Understand [Middleware & Authorization](middleware.md)
- Set up [Testing](testing.md) for your application
- Optimize with our [Performance Guide](performance.md)

## Getting Help

- Check the [API Reference](api-reference.md)
- See the [Troubleshooting Guide](troubleshooting.md)
- Visit our [GitHub repository](https://github.com/evansims/openfga-laravel)
