<div align="center">
  <p><a href="https://openfga.dev"><img src=".github/openfga.png" width="100" /></a></p>

  <h1>OpenFGA Laravel SDK</h1>

  <p>Stop writing authorization logic. Start asking questions.</p>
</div>

<p><br /></p>

**Every app needs permissions.** Most developers end up with authorization logic scattered across controllers, middleware, and business logic. Changes break things. New features require touching dozens of files.

**[OpenFGA](https://openfga.dev/) solves this.** Define your authorization rules once, query them anywhere. This package provides complete integration of [OpenFGA](https://openfga.dev/) and [Auth0 FGA](https://auth0.com/fine-grained-authorization) for Laravel applications.

<p><br /></p>

## Installation

This package is currenly under development.

<p><br /></p>

## Quickstart

```php
use OpenFGA\Client;
use function OpenFGA\{allowed, tuple};

$client = new Client(url: 'http://localhost:8080');

// Instead of scattered if statements in your controllers:
if ($user->isAdmin() || $user->owns($document) || $user->team->canEdit($document)) {
    // ...
}

// Ask OpenFGA:
$canEdit = allowed(
    client: $client,
    store: 'my-store',
    model: 'my-model',
    tuple: tuple('user:alice', 'editor', 'document:readme')
);

// Zero business logic coupling. Pure authorization.
```

See [the documentation](https://github.com/evansims/openfga-php/wiki) to get started.

<p><br /></p>

## Contributing

Contributions are welcome—have a look at our [contributing guidelines](.github/CONTRIBUTING.md).
