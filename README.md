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

class DocumentController extends Controller
{
    /**
     * Share a document with another user.
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
     */
    public function index(): View
    {
        $documents = Document::whereUserCan(auth()->user(), 'viewer')
            ->latest()
            ->paginate();

        return view('documents.index', compact('documents'));
    }
}
```

<p><br /></p>

## Documentation

- [Installation](docs/installation.md)
- [Quickstart](docs/quickstart.md)
- [Configuration](docs/configuration.md)
- [Eloquent Integration](docs/eloquent.md)
- [Middleware](docs/middleware.md)
- [Performance & Optimization](docs/performance.md)
- [Testing](docs/testing.md)
- [API Reference](docs/api-reference.md)

<p><br /></p>

## Contributing

Contributions are welcome—have a look at our [contributing guidelines](.github/CONTRIBUTING.md).
