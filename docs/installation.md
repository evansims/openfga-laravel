# Installation Guide

This guide will walk you through installing and configuring the OpenFGA Laravel package in your Laravel application.

## Requirements

- PHP 8.3 or higher
- Laravel 12.0 or higher
- OpenFGA server (self-hosted or cloud)
- Composer

## Installation

### Step 1: Install via Composer

Install the package using Composer:

```bash
composer require openfga/laravel
```

### Step 2: Publish Configuration

Publish the configuration file to customize your setup:

```bash
php artisan vendor:publish --tag="openfga-config"
```

This will create a `config/openfga.php` file in your application.

### Step 3: Configure Environment Variables

Add the following environment variables to your `.env` file:

```env
# Basic OpenFGA Configuration
OPENFGA_URL=http://localhost:8080
OPENFGA_STORE_ID=your-store-id
OPENFGA_MODEL_ID=your-model-id

# Authentication (choose one method)
OPENFGA_AUTH_METHOD=none  # Options: none, api_token, client_credentials

# For API Token authentication
OPENFGA_API_TOKEN=your-api-token

# For OAuth2 Client Credentials
OPENFGA_CLIENT_ID=your-client-id
OPENFGA_CLIENT_SECRET=your-client-secret
OPENFGA_TOKEN_ISSUER=your-token-issuer
OPENFGA_API_AUDIENCE=your-api-audience

# Optional: Performance & Features
OPENFGA_CACHE_ENABLED=true
OPENFGA_CACHE_TTL=300
OPENFGA_QUEUE_ENABLED=false
OPENFGA_LOGGING_ENABLED=true
```

### Step 4: Register Service Providers (Optional)

The package's service providers are automatically discovered by Laravel. However, if you've disabled package discovery, add these to your `config/app.php`:

```php
'providers' => [
    // ...
    OpenFGA\Laravel\OpenFgaServiceProvider::class,
    OpenFGA\Laravel\Providers\AuthorizationServiceProvider::class,
    OpenFGA\Laravel\Providers\BladeServiceProvider::class,
    OpenFGA\Laravel\Providers\EventServiceProvider::class,
],
```

### Step 5: Add Middleware (Optional)

To use the authorization middleware, add it to your `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    // ...
    'openfga' => \OpenFGA\Laravel\Http\Middleware\OpenFgaMiddleware::class,
    'can.any' => \OpenFGA\Laravel\Http\Middleware\RequiresAnyPermission::class,
    'can.all' => \OpenFGA\Laravel\Http\Middleware\RequiresAllPermissions::class,
];
```

## Configuration Options

### Multiple Connections

You can configure multiple OpenFGA connections for different stores or environments:

```php
// config/openfga.php
return [
    'default' => env('OPENFGA_CONNECTION', 'main'),

    'connections' => [
        'main' => [
            'url' => env('OPENFGA_URL'),
            'store_id' => env('OPENFGA_STORE_ID'),
            'model_id' => env('OPENFGA_MODEL_ID'),
            // ...
        ],

        'secondary' => [
            'url' => env('OPENFGA_SECONDARY_URL'),
            'store_id' => env('OPENFGA_SECONDARY_STORE_ID'),
            'model_id' => env('OPENFGA_SECONDARY_MODEL_ID'),
            // ...
        ],
    ],
];
```

### Caching

Configure caching to improve performance:

```php
'cache' => [
    'enabled' => true,
    'store' => 'redis',  // Use your preferred cache store
    'ttl' => 300,        // Cache TTL in seconds
    'prefix' => 'openfga',
],
```

### Queue Support

Enable queue support for batch operations:

```php
'queue' => [
    'enabled' => true,
    'connection' => 'redis',
    'queue' => 'openfga',
],
```

## Verifying Installation

### Step 1: Check Configuration

Run the debug command to verify your configuration:

```bash
php artisan openfga:debug
```

### Step 2: Test Connection

Test the connection to your OpenFGA server:

```bash
php artisan openfga:check user:test reader document:test
```

### Step 3: Create Test Permission

Grant a test permission to verify write operations:

```bash
php artisan openfga:grant user:test writer document:test
```

## Common Issues

### Connection Refused

If you're getting connection errors:

1. Verify your OpenFGA server is running
2. Check the `OPENFGA_URL` is correct
3. Ensure no firewall is blocking the connection

### Invalid Store ID

If you're getting store-related errors:

1. Verify the `OPENFGA_STORE_ID` exists
2. Check you have proper permissions for the store
3. Try creating a new store if needed

### Authentication Errors

For authentication issues:

1. Verify your credentials are correct
2. Check the authentication method matches your server configuration
3. Ensure tokens/credentials haven't expired

## Next Steps

- Read the [Quick Start Tutorial](quickstart.md) to learn basic usage
- Explore [Configuration Options](configuration.md) for advanced settings
- Check out [Eloquent Integration](eloquent.md) for model authorization
- Learn about [Testing](testing.md) with the package

## Support

If you encounter any issues:

1. Check the [Troubleshooting Guide](troubleshooting.md)
2. Search existing [GitHub Issues](https://github.com/openfga/laravel/issues)
3. Create a new issue with detailed information about your problem

## Version Compatibility

| Laravel Version | Package Version | PHP Version |
| --------------- | --------------- | ----------- |
| 10.x            | 1.x             | 8.1+        |
| 11.x            | 1.x             | 8.2+        |
