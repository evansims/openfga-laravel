<div align="center">
  <p><a href="https://openfga.dev"><img src=".github/openfga.png" width="100" /></a></p>

  <h1>OpenFGA Laravel SDK</h1>

  <p>Stop writing authorization logic. Start asking questions.</p>
</div>

<p><br /></p>

**Every app needs permissions.** Most developers end up with authorization logic scattered across controllers, middleware, and business logic. Changes break things. New features require touching dozens of files.

**[OpenFGA](https://openfga.dev/) solves this.** Define your authorization rules once, query them anywhere. This package provides complete integration of [OpenFGA](https://openfga.dev/) and [Auth0 FGA](https://auth0.com/fine-grained-authorization) for Laravel applications.

- **Eloquent Integration** - Authorization methods on your models
- **Middleware Protection** - Secure routes with permission checks
- **Blade Directives** - Show/hide UI based on permissions
- **Testing Utilities** - Fake permissions in your tests
- **Performance Optimized** - Built-in caching and batch operations
- **Queue Support** - Async permission operations
- **Multi-tenancy Ready** - Multiple stores and connections
- **Type Safe** - PHP 8.3+ with strict typing and comprehensive generics
- **Developer Friendly** - Enhanced IDE support with detailed PHPDoc annotations

<p><br /></p>

## Installation

```bash
composer require openfga/laravel
```

Publish the configuration:

```bash
php artisan vendor:publish --tag="openfga-config"
```

Set your environment variables:

```env
OPENFGA_URL=http://localhost:8080
OPENFGA_STORE_ID=your-store-id
```

<p><br /></p>

## Usage Patterns

```php
// Controllers - Type-safe permission checks
if (cannot('edit', $document)) {
    abort(403);
}

// Middleware - Strict parameter validation
Route::put('/documents/{document}', [DocumentController::class, 'update'])
    ->middleware('openfga:editor,document:{document}');

// Blade Views - Enhanced type safety
@can('edit', 'document:' . $document->id)
    <button>Edit</button>
@endcan

// Eloquent Models - Comprehensive type annotations
$document->grant($user, 'editor');  // Grant permission
$document->check($user, 'editor');  // Check permission
$document->revoke($user, 'editor'); // Revoke permission

// Query by permissions - Generic return types
$myDocuments = Document::whereUserCan($user, 'edit')->get();
```

<p><br /></p>

## Quickstart

Let's implement a simple document sharing system with enhanced type safety.

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use OpenFGA\Laravel\Facades\OpenFGA;
use OpenFGA\Laravel\DTOs\PermissionCheckRequest;

class DocumentController extends Controller
{
    /**
     * Share a document with another user.
     *
     * @param Request  $request
     * @param Document $document
     */
    public function share(Request $request, Document $document): RedirectResponse
    {
        // Ensure user can share (only owners can share)
        $this->authorize('owner', $document);

        // Grant permission to new user - type-safe operation
        $document->grant($request->user_email, $request->permission);

        return back()->with('success', 'Document shared successfully!');
    }

    /**
     * List documents the user can view.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        // Get all documents the user can view
        // This automatically queries OpenFGA and filters results
        /** @var \Illuminate\Pagination\LengthAwarePaginator<Document> $documents */
        $documents = Document::whereUserCan(auth()->user(), 'viewer')
            ->latest()
            ->paginate();

        return view('documents.index', compact('documents'));
    }

    /**
     * Batch check multiple permissions using the new DTO pattern.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $checks
     * @return array<string, bool>
     */
    public function batchCheckPermissions(array $checks): array
    {
        return OpenFGA::batchCheck($checks);
    }

    /**
     * Use the enhanced DTO pattern for type-safe permission checks.
     */
    public function checkWithDto(Document $document): bool
    {
        $request = PermissionCheckRequest::fromUser(
            user: auth()->user(),
            relation: 'editor',
            object: $document->authorizationObject()
        );

        return OpenFGA::check(
            $request->userId,
            $request->relation,
            $request->object
        );
    }
}
```

<p><br /></p>

## Type Safety & PHP 8.3+ Features

This package leverages the latest PHP 8.3 features for maximum type safety and developer experience:

### Strict Type Declarations

Every file uses `declare(strict_types=1)` for compile-time type checking:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use OpenFGA\Laravel\Traits\HasAuthorization;

class Document extends Model
{
    use HasAuthorization;

    /**
     * Get available relations for this model.
     *
     * @return array<string>
     */
    public function getAuthorizationRelations(): array
    {
        return ['owner', 'editor', 'viewer'];
    }
}
```

### Enhanced Generic Annotations

Comprehensive PHPDoc with generics for better IDE support:

```php
/**
 * Grant multiple permissions to multiple users.
 *
 * @param array<int|Model|string> $users     Users to grant permissions to
 * @param array<string>|string    $relations Relations/permissions to grant
 *
 * @throws BindingResolutionException
 * @throws ClientThrowable
 * @throws Exception
 * @throws InvalidArgumentException
 */
public function grantMany(array $users, array|string $relations): bool
{
    $relations = is_array($relations) ? $relations : [$relations];
    $tuples = [];

    foreach ($users as $user) {
        $userId = $this->resolveUserId($user);
        
        foreach ($relations as $relation) {
            $tuples[] = [
                'user' => $userId,
                'relation' => $relation,
                'object' => $this->authorizationObject(),
            ];
        }
    }

    return $this->getOpenFgaManager()->query()->grant($tuples);
}

/**
 * Get all relations a user has with this model.
 *
 * @param int|Model|string $user      The user to check
 * @param array<string>    $relations Optional relation filters
 *
 * @return array<string, bool> Relation name => has permission
 */
public function getUserRelations($user, array $relations = []): array
{
    $userId = $this->resolveUserId($user);

    if ([] === $relations) {
        $relations = $this->getAuthorizationRelations();
    }

    return $this->getOpenFgaManager()->listRelations(
        $userId,
        $this->authorizationObject(),
        $relations,
    );
}

/**
 * List objects that a user has a specific relation to.
 *
 * @param string               $user
 * @param string               $relation
 * @param string               $type
 * @param array<TupleKey>      $contextualTuples
 * @param array<string, mixed> $context
 * @param string|null          $connection
 *
 * @return array<string> Object identifiers
 */
public function listObjects(
    string $user,
    string $relation,
    string $type,
    array $contextualTuples = [],
    array $context = [],
    ?string $connection = null,
): array {
    // Implementation with complete type safety
}
```

### DTO Pattern for Complex Operations

Replace associative arrays with type-safe DTOs:

```php
use OpenFGA\Laravel\DTOs\PermissionCheckRequest;
use Illuminate\Contracts\Auth\Authenticatable;

// Before: Associative array (error-prone)
$check = [
    'user' => 'user:123',
    'relation' => 'editor',
    'object' => 'document:456'
];

// After: Type-safe DTO with validation and autocomplete
$request = PermissionCheckRequest::fromUser(
    user: $user,
    relation: 'editor',
    object: 'document:456',
    context: ['department' => 'engineering'],
    cached: true
);

// Convert to array for logging
/** @var array<string, mixed> $logData */
$logData = $request->toArray();

// Human-readable representation
echo $request->toString(); // "user:123 can editor on document:456"

// Create request with cached result tracking
$cachedRequest = $request->withCached(true, 0.025); // 25ms duration

// Full constructor with all type annotations
final readonly class PermissionCheckRequest
{
    /**
     * @param string                $userId           User identifier
     * @param string                $relation         Permission relation
     * @param string                $object           Object identifier
     * @param array<string, mixed>  $context          Additional context
     * @param array<string, string> $contextualTuples Contextual tuples
     * @param string|null           $connection       Connection name
     * @param bool                  $cached           Use cached results
     * @param float|null            $duration         Duration in seconds
     */
    public function __construct(
        public string $userId,
        public string $relation,
        public string $object,
        public array $context = [],
        public array $contextualTuples = [],
        public ?string $connection = null,
        public bool $cached = false,
        public ?float $duration = null,
    ) {}
}
```

### Template Types for Advanced Usage

Generic interfaces for type-safe implementations:

```php
/**
 * OpenFGA-powered Gate implementation.
 *
 * @template TUser of Authenticatable&Model
 */
final class OpenFgaGate extends Gate implements OpenFgaGateInterface
{
    /**
     * Check specific OpenFGA permission.
     *
     * @param string                     $ability   OpenFGA relation/permission
     * @param array<mixed>|object|string $arguments Object identifier or model
     * @param Authenticatable|null       $user      User to check permissions for
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return bool True if permission is granted
     */
    #[Override]
    public function checkOpenFgaPermission(
        string $ability,
        mixed $arguments,
        ?Authenticatable $user = null
    ): bool {
        $user ??= $this->resolveUser();

        if (! $user instanceof Authenticatable) {
            return false;
        }

        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($arguments);

        if (null === $object) {
            return false;
        }

        return $this->manager->check($userId, $ability, $object);
    }

    /**
     * Determine if arguments represent an OpenFGA permission check.
     *
     * @param mixed $arguments Arguments to analyze
     * @return bool True if this appears to be an OpenFGA check
     */
    #[Override]
    public function isOpenFgaPermission(mixed $arguments): bool
    {
        $arguments = is_array($arguments) ? $arguments : [$arguments];

        /** @var mixed $argument */
        foreach ($arguments as $argument) {
            if (is_string($argument) && str_contains($argument, ':')) {
                return true; // object:id format
            }

            if (is_object($argument) && $argument instanceof Model) {
                return true; // Eloquent model
            }
        }

        return false;
    }
}
```

### Static Analysis Integration

Maximum code quality with comprehensive static analysis:

```bash
# PHPStan Level 8 (maximum strictness)
composer lint:phpstan

# Psalm with strict type checking
composer lint:psalm

# Rector for automated code improvements
composer lint:rector

# PHP-CS-Fixer for consistent formatting
composer lint:phpcs
```

<p><br /></p>

## Documentation

- [Installation](docs/installation.md)
- [Quickstart](docs/quickstart.md)
- [Configuration](docs/configuration.md)
- [Eloquent Integration](docs/eloquent.md)
- [Middleware](docs/middleware.md)
- [Performance](docs/performance.md)
- [Testing](docs/testing.md)
- [API Reference](docs/api-reference.md)

<p><br /></p>

## Contributing

Contributions are welcome—have a look at our [contributing guidelines](.github/CONTRIBUTING.md).
