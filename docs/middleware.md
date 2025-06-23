# Middleware & Authorization Guide

This guide covers how to protect your routes and integrate OpenFGA with Laravel's authorization system using middleware, gates, and policies.

## Middleware Setup

### Registering Middleware

Register the middleware in your `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    // ... other middleware
    'openfga' => \OpenFGA\Laravel\Http\Middleware\OpenFgaMiddleware::class,
    'can.any' => \OpenFGA\Laravel\Http\Middleware\RequiresAnyPermission::class,
    'can.all' => \OpenFGA\Laravel\Http\Middleware\RequiresAllPermissions::class,
];
```

## Basic Route Protection

### Single Permission Check

Protect routes requiring a specific permission:

```php
// User must have 'editor' permission on document
Route::middleware(['auth', 'openfga:editor,document:{document}'])
    ->get('/documents/{document}/edit', [DocumentController::class, 'edit']);

// User must be owner of the team
Route::middleware(['auth', 'openfga:owner,team:{team}'])
    ->get('/teams/{team}/settings', [TeamController::class, 'settings']);
```

### Dynamic Object Resolution

The middleware automatically resolves route parameters:

```php
// Middleware will check: user:123#viewer@document:456
Route::middleware(['auth', 'openfga:viewer,document:{document}'])
    ->get('/documents/{document}', [DocumentController::class, 'show']);

// For nested resources
Route::middleware(['auth', 'openfga:member,team:{team}'])
    ->get('/teams/{team}/documents/{document}', function (Team $team, Document $document) {
        // Both $team and $document are available
    });
```

## Multiple Permission Checks

### Any Permission (OR Logic)

User needs at least one of the specified permissions:

```php
// User can view OR edit OR own the document
Route::middleware(['auth', 'can.any:viewer,editor,owner,document:{document}'])
    ->get('/documents/{document}', [DocumentController::class, 'show']);

// More complex example
Route::middleware([
    'auth',
    'can.any:admin,moderator,owner,forum:{forum}'
])->group(function () {
    Route::get('/forums/{forum}/manage', [ForumController::class, 'manage']);
    Route::post('/forums/{forum}/settings', [ForumController::class, 'updateSettings']);
});
```

### All Permissions (AND Logic)

User needs all specified permissions:

```php
// User must be both a member AND have billing permission
Route::middleware(['auth', 'can.all:member,billing_manager,team:{team}'])
    ->get('/teams/{team}/billing', [BillingController::class, 'index']);

// Multiple requirements
Route::middleware([
    'auth',
    'can.all:verified_user,premium_member,active,user:{user}'
])->get('/premium-content', [PremiumController::class, 'index']);
```

## Advanced Middleware Usage

### Custom Object Resolution

Create custom middleware for complex object resolution:

```php
namespace App\Http\Middleware;

use OpenFGA\Laravel\Http\Middleware\OpenFgaMiddleware;

class ProjectPermission extends OpenFgaMiddleware
{
    protected function resolveObject($request): string
    {
        $project = $request->route('project');
        $team = $project->team;

        // Check permission on team instead of project
        return "team:{$team->id}";
    }
}
```

### Contextual Permissions

Add contextual tuples in middleware:

```php
namespace App\Http\Middleware;

use OpenFGA\Laravel\Http\Middleware\OpenFgaMiddleware;

class ContextualPermission extends OpenFgaMiddleware
{
    protected function getContextualTuples($request): array
    {
        $user = $request->user();
        $organization = $user->organization;

        return [
            ["user:{$user->id}", 'member', "organization:{$organization->id}"],
            ["user:{$user->id}", 'employee', "company:{$organization->company_id}"],
        ];
    }
}
```

### Connection-Specific Checks

Use different OpenFGA connections:

```php
Route::middleware(['auth', 'openfga:admin,system:core,connection:admin'])
    ->get('/admin', [AdminController::class, 'dashboard']);
```

## Gate Integration

### Registering Gates

The package automatically registers gates based on your permissions:

```php
// In AuthServiceProvider or OpenFgaServiceProvider
use Illuminate\Support\Facades\Gate;
use OpenFGA\Laravel\Facades\OpenFga;

public function boot()
{
    // Define a gate using OpenFGA
    Gate::define('edit-document', function ($user, $document) {
        return OpenFga::check(
            "user:{$user->id}",
            'editor',
            "document:{$document->id}"
        );
    });

    // Dynamic gate registration
    $relations = ['viewer', 'editor', 'owner'];
    foreach ($relations as $relation) {
        Gate::define("{$relation}-document", function ($user, $document) use ($relation) {
            return OpenFga::check(
                "user:{$user->id}",
                $relation,
                "document:{$document->id}"
            );
        });
    }
}
```

### Using Gates in Controllers

```php
class DocumentController extends Controller
{
    public function edit(Document $document)
    {
        // Using Gate facade
        if (Gate::denies('edit-document', $document)) {
            abort(403);
        }

        // Using authorize helper
        $this->authorize('editor-document', $document);

        // Using can method
        if (!auth()->user()->can('edit-document', $document)) {
            abort(403);
        }

        return view('documents.edit', compact('document'));
    }
}
```

### Gate Responses

Return detailed responses from gates:

```php
Gate::define('manage-team', function ($user, $team) {
    if (!OpenFga::check("user:{$user->id}", 'admin', "team:{$team->id}")) {
        return Response::deny('You must be a team admin to manage settings.');
    }

    if (!$team->is_active) {
        return Response::deny('This team is inactive.');
    }

    return Response::allow();
});
```

## Policy Classes

### Creating an OpenFGA Policy

```php
namespace App\Policies;

use App\Models\User;
use App\Models\Document;
use OpenFGA\Laravel\Policies\OpenFgaPolicy;

class DocumentPolicy extends OpenFgaPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $this->check($user, 'viewer', $document);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->check($user, 'editor', $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->check($user, 'owner', $document);
    }

    public function restore(User $user, Document $document): bool
    {
        return $this->check($user, 'owner', $document);
    }

    public function forceDelete(User $user, Document $document): bool
    {
        return $this->check($user, 'owner', $document)
            && $this->check($user, 'admin', 'system:documents');
    }
}
```

### Advanced Policy Methods

```php
class DocumentPolicy extends OpenFgaPolicy
{
    public function viewAny(User $user): bool
    {
        // Check if user can view any documents
        return $this->check($user, 'member', 'system:documents');
    }

    public function create(User $user): bool
    {
        // Check if user can create documents
        return $this->check($user, 'creator', 'system:documents')
            && $user->hasActiveSubscription();
    }

    public function share(User $user, Document $document): bool
    {
        // Custom method for sharing
        return $this->check($user, 'owner', $document)
            || $this->check($user, 'editor', $document);
    }

    public function publish(User $user, Document $document): bool
    {
        // Multiple checks with context
        return $this->checkWithContext(
            $user,
            'publisher',
            $document,
            [
                ["user:{$user->id}", 'verified', 'system:users'],
            ]
        );
    }
}
```

### Registering Policies

Register your policies in `AuthServiceProvider`:

```php
protected $policies = [
    Document::class => DocumentPolicy::class,
    Team::class => TeamPolicy::class,
    Project::class => ProjectPolicy::class,
];
```

## Form Request Authorization

### Basic Form Request

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenFGA\Laravel\Traits\AuthorizesWithOpenFga;

class UpdateDocumentRequest extends FormRequest
{
    use AuthorizesWithOpenFga;

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $this->checkPermission('editor', $document);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'tags' => 'array',
            'tags.*' => 'string|max:50',
        ];
    }
}
```

### Complex Authorization Logic

```php
class PublishDocumentRequest extends FormRequest
{
    use AuthorizesWithOpenFga;

    public function authorize(): bool
    {
        $document = $this->route('document');
        $user = $this->user();

        // Must be editor or owner
        if (!$this->checkAnyPermission(['editor', 'owner'], $document)) {
            return false;
        }

        // Must have publishing rights
        if (!$this->checkPermission('publisher', 'system:documents')) {
            return false;
        }

        // Document must be in draft state
        return $document->status === 'draft';
    }

    protected function failedAuthorization()
    {
        throw new AuthorizationException('You are not authorized to publish this document.');
    }
}
```

### Contextual Form Requests

```php
class TransferOwnershipRequest extends FormRequest
{
    use AuthorizesWithOpenFga;

    public function authorize(): bool
    {
        $document = $this->route('document');
        $team = $document->team;

        // Check with contextual tuples
        return $this->checkPermissionWithContext(
            'owner',
            $document,
            [
                ["user:{$this->user()->id}", 'admin', "team:{$team->id}"],
            ]
        );
    }
}
```

## Middleware Groups

### Creating Permission-Based Route Groups

```php
// routes/web.php
Route::middleware(['auth'])->group(function () {
    // Public routes - only need authentication
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Editor routes
    Route::middleware(['can.any:editor,admin,document:{document}'])->group(function () {
        Route::get('/documents/{document}/edit', [DocumentController::class, 'edit']);
        Route::put('/documents/{document}', [DocumentController::class, 'update']);
    });

    // Admin routes
    Route::middleware(['openfga:admin,system:core'])->prefix('admin')->group(function () {
        Route::get('/', [AdminController::class, 'index']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/settings', [AdminController::class, 'settings']);
    });
});
```

### Resource Controllers with Middleware

```php
Route::resource('documents', DocumentController::class)
    ->middleware([
        'index' => 'openfga:viewer,system:documents',
        'create' => 'openfga:creator,system:documents',
        'store' => 'openfga:creator,system:documents',
        'show' => 'openfga:viewer,document:{document}',
        'edit' => 'openfga:editor,document:{document}',
        'update' => 'openfga:editor,document:{document}',
        'destroy' => 'openfga:owner,document:{document}',
    ]);
```

## API Authentication

### Protecting API Routes

```php
// routes/api.php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::middleware(['openfga:api_user,system:api'])->group(function () {
        Route::apiResource('documents', Api\DocumentController::class);
    });

    Route::middleware(['openfga:api_admin,system:api'])->prefix('admin')->group(function () {
        Route::get('/stats', [Api\AdminController::class, 'stats']);
        Route::get('/audit', [Api\AdminController::class, 'audit']);
    });
});
```

### Token-Based Permissions

```php
namespace App\Http\Middleware;

use OpenFGA\Laravel\Http\Middleware\OpenFgaMiddleware;

class ApiPermission extends OpenFgaMiddleware
{
    protected function resolveUser($request): string
    {
        $token = $request->user()->currentAccessToken();

        // Use token ID for API permissions
        return "token:{$token->id}";
    }
}
```

## Error Handling

### Custom Error Responses

```php
namespace App\Http\Middleware;

use OpenFGA\Laravel\Http\Middleware\OpenFgaMiddleware;

class CustomPermissionMiddleware extends OpenFgaMiddleware
{
    protected function handleUnauthorized($request, $relation, $object)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Insufficient permissions',
                'required' => $relation,
                'object' => $object,
            ], 403);
        }

        return redirect()
            ->route('access-denied')
            ->with('error', "You need {$relation} permission to access this resource.");
    }
}
```

### Logging Failed Attempts

```php
class AuditedPermissionMiddleware extends OpenFgaMiddleware
{
    protected function handleUnauthorized($request, $relation, $object)
    {
        Log::warning('Unauthorized access attempt', [
            'user' => $request->user()->id,
            'relation' => $relation,
            'object' => $object,
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
        ]);

        return parent::handleUnauthorized($request, $relation, $object);
    }
}
```

## Testing Middleware

### Testing Route Protection

```php
use OpenFGA\Laravel\Testing\FakesOpenFga;

class DocumentMiddlewareTest extends TestCase
{
    use FakesOpenFga;

    public function test_editor_can_access_edit_page()
    {
        $this->fakeOpenFga();

        $user = User::factory()->create();
        $document = Document::factory()->create();

        OpenFga::grant("user:{$user->id}", 'editor', "document:{$document->id}");

        $response = $this->actingAs($user)
            ->get("/documents/{$document->id}/edit");

        $response->assertOk();
    }

    public function test_viewer_cannot_access_edit_page()
    {
        $this->fakeOpenFga();

        $user = User::factory()->create();
        $document = Document::factory()->create();

        OpenFga::grant("user:{$user->id}", 'viewer', "document:{$document->id}");

        $response = $this->actingAs($user)
            ->get("/documents/{$document->id}/edit");

        $response->assertForbidden();
    }
}
```

### Testing Multiple Permissions

```php
public function test_any_permission_middleware()
{
    $this->fakeOpenFga();

    $user = User::factory()->create();
    $document = Document::factory()->create();

    // User only has viewer permission
    OpenFga::grant("user:{$user->id}", 'viewer', "document:{$document->id}");

    // Route requires viewer OR editor OR owner
    $response = $this->actingAs($user)
        ->get("/documents/{$document->id}/details");

    $response->assertOk();

    // Verify the correct permission was checked
    OpenFga::assertChecked("user:{$user->id}", 'viewer', "document:{$document->id}");
}
```

## Performance Tips

### Preload Permissions

```php
namespace App\Http\Middleware;

class PreloadPermissions
{
    public function handle($request, Closure $next)
    {
        if ($user = $request->user()) {
            // Preload common permissions
            $permissions = OpenFga::batchCheck([
                ["user:{$user->id}", 'member', 'system:app'],
                ["user:{$user->id}", 'premium', 'system:features'],
                ["user:{$user->id}", 'admin', 'system:core'],
            ]);

            // Store in request for later use
            $request->attributes->set('user_permissions', $permissions);
        }

        return $next($request);
    }
}
```

### Cache Middleware Results

```php
class CachedPermissionMiddleware extends OpenFgaMiddleware
{
    protected function checkPermission($user, $relation, $object): bool
    {
        $cacheKey = "permission:{$user}:{$relation}:{$object}";

        return Cache::remember($cacheKey, 300, function () use ($user, $relation, $object) {
            return parent::checkPermission($user, $relation, $object);
        });
    }
}
```

## Next Steps

- Learn about [Testing](testing.md) authorization
- Optimize with [Performance Guide](performance.md)
- See [Troubleshooting Guide](troubleshooting.md) for common issues
- Check the [API Reference](api-reference.md)
