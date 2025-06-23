# Configuration Guide

This guide covers all configuration options available in the OpenFGA Laravel package. The configuration file is located at `config/openfga.php` after publishing.

## Configuration File Structure

```php
<?php

return [
    'default' => env('OPENFGA_CONNECTION', 'main'),

    'connections' => [
        // Connection configurations
    ],

    'cache' => [
        // Cache settings
    ],

    'queue' => [
        // Queue settings
    ],

    'logging' => [
        // Logging settings
    ],

    'options' => [
        // Global options
    ],
];
```

## Connection Configuration

### Single Connection

For most applications, a single connection is sufficient:

```php
'connections' => [
    'main' => [
        'url' => env('OPENFGA_URL', 'http://localhost:8080'),
        'store_id' => env('OPENFGA_STORE_ID'),
        'model_id' => env('OPENFGA_MODEL_ID'),

        'credentials' => [
            'method' => env('OPENFGA_AUTH_METHOD', 'none'),
            'token' => env('OPENFGA_API_TOKEN'),
            'client_id' => env('OPENFGA_CLIENT_ID'),
            'client_secret' => env('OPENFGA_CLIENT_SECRET'),
            'api_token_issuer' => env('OPENFGA_TOKEN_ISSUER'),
            'api_audience' => env('OPENFGA_API_AUDIENCE'),
            'scopes' => explode(',', env('OPENFGA_SCOPES', '')),
        ],

        'retries' => [
            'max_retries' => 3,
            'min_wait_ms' => 100,
        ],

        'http_options' => [
            'timeout' => 30,
            'connect_timeout' => 10,
        ],
    ],
],
```

### Multiple Connections

Configure multiple connections for different environments or stores:

```php
'connections' => [
    'production' => [
        'url' => env('OPENFGA_PROD_URL'),
        'store_id' => env('OPENFGA_PROD_STORE_ID'),
        'model_id' => env('OPENFGA_PROD_MODEL_ID'),
        // ... other settings
    ],

    'staging' => [
        'url' => env('OPENFGA_STAGING_URL'),
        'store_id' => env('OPENFGA_STAGING_STORE_ID'),
        'model_id' => env('OPENFGA_STAGING_MODEL_ID'),
        // ... other settings
    ],

    'development' => [
        'url' => 'http://localhost:8080',
        'store_id' => 'dev-store',
        'model_id' => null, // Uses latest model
        // ... other settings
    ],
],
```

Using different connections:

```php
use OpenFGA\Laravel\Facades\OpenFga;

// Use default connection
OpenFga::check('user:123', 'reader', 'document:456');

// Use specific connection
OpenFga::connection('staging')->check('user:123', 'reader', 'document:456');

// Switch default connection
OpenFga::setDefaultConnection('production');
```

## Authentication Methods

### No Authentication

For local development or unsecured environments:

```php
'credentials' => [
    'method' => 'none',
],
```

### API Token

For token-based authentication:

```php
'credentials' => [
    'method' => 'api_token',
    'token' => env('OPENFGA_API_TOKEN'),
],
```

### OAuth2 Client Credentials

For OAuth2 authentication:

```php
'credentials' => [
    'method' => 'client_credentials',
    'client_id' => env('OPENFGA_CLIENT_ID'),
    'client_secret' => env('OPENFGA_CLIENT_SECRET'),
    'api_token_issuer' => env('OPENFGA_TOKEN_ISSUER'),
    'api_audience' => env('OPENFGA_API_AUDIENCE'),
    'scopes' => ['read', 'write', 'admin'],
],
```

## Retry Configuration

Configure retry behavior for failed requests:

```php
'retries' => [
    'max_retries' => 3,        // Maximum number of retry attempts
    'min_wait_ms' => 100,      // Minimum wait time between retries
    'max_wait_ms' => 5000,     // Maximum wait time between retries
    'retry_on' => [            // HTTP status codes to retry on
        429, // Too Many Requests
        500, // Internal Server Error
        502, // Bad Gateway
        503, // Service Unavailable
        504, // Gateway Timeout
    ],
],
```

## HTTP Options

Configure HTTP client options:

```php
'http_options' => [
    'timeout' => 30,           // Request timeout in seconds
    'connect_timeout' => 10,   // Connection timeout in seconds
    'proxy' => env('HTTP_PROXY'),
    'verify' => true,          // Verify SSL certificates
    'headers' => [
        'User-Agent' => 'OpenFGA-Laravel/1.0',
        'X-Custom-Header' => 'value',
    ],
],
```

## Cache Configuration

### Basic Cache Settings

```php
'cache' => [
    'enabled' => env('OPENFGA_CACHE_ENABLED', true),
    'store' => env('OPENFGA_CACHE_STORE', 'default'),
    'ttl' => env('OPENFGA_CACHE_TTL', 300), // 5 minutes
    'prefix' => 'openfga',
],
```

### Advanced Cache Configuration

```php
'cache' => [
    'enabled' => true,
    'store' => 'redis',
    'ttl' => 300,
    'prefix' => 'openfga',

    // Cache specific operations
    'operations' => [
        'check' => true,        // Cache permission checks
        'list_objects' => true, // Cache object listings
        'expand' => false,      // Don't cache expansions
    ],

    // Cache tags for invalidation
    'tags' => [
        'enabled' => true,
        'prefix' => 'openfga-tags',
    ],

    // Warm cache on specific events
    'warming' => [
        'enabled' => true,
        'on_grant' => true,     // Warm cache when granting permissions
        'on_revoke' => false,   // Don't warm cache on revoke
    ],
],
```

## Queue Configuration

### Basic Queue Settings

```php
'queue' => [
    'enabled' => env('OPENFGA_QUEUE_ENABLED', false),
    'connection' => env('OPENFGA_QUEUE_CONNECTION', 'default'),
    'queue' => env('OPENFGA_QUEUE_NAME', 'openfga'),
],
```

### Advanced Queue Configuration

```php
'queue' => [
    'enabled' => true,
    'connection' => 'redis',
    'queue' => 'openfga',

    // Batch settings
    'batch' => [
        'size' => 100,          // Max operations per batch
        'delay' => 0,           // Delay in seconds
        'tries' => 3,           // Retry attempts
        'timeout' => 120,       // Job timeout
    ],

    // Auto-queue large operations
    'auto_queue' => [
        'enabled' => true,
        'threshold' => 50,      // Queue if more than 50 operations
    ],
],
```

## Logging Configuration

### Basic Logging

```php
'logging' => [
    'enabled' => env('OPENFGA_LOGGING_ENABLED', true),
    'channel' => env('OPENFGA_LOG_CHANNEL', 'default'),
],
```

### Advanced Logging

```php
'logging' => [
    'enabled' => true,
    'channel' => 'openfga',

    // Log levels for different operations
    'levels' => [
        'check' => 'debug',
        'write' => 'info',
        'error' => 'error',
        'performance' => 'info',
    ],

    // Performance logging
    'performance' => [
        'enabled' => true,
        'threshold' => 100,     // Log requests taking > 100ms
    ],

    // Sensitive data handling
    'redact' => [
        'user_ids' => false,    // Don't redact user IDs
        'object_ids' => false,  // Don't redact object IDs
        'context' => true,      // Redact context data
    ],
],
```

## Global Options

### Exception Handling

```php
'options' => [
    'throw_on_error' => env('OPENFGA_THROW_ON_ERROR', true),
    'exception_class' => \OpenFGA\Laravel\Exceptions\AuthorizationException::class,
],
```

### Model Cleanup

```php
'options' => [
    'cleanup_on_delete' => true,  // Auto-revoke permissions when models are deleted
    'cleanup_queue' => true,      // Use queue for cleanup operations
],
```

### Performance Options

```php
'options' => [
    'batch_size' => 100,          // Default batch size for operations
    'connection_pooling' => true, // Enable connection pooling
    'persistent_connections' => false,
],
```

## Environment-Specific Configuration

### Using Environment Files

```env
# Production
OPENFGA_URL=https://api.openfga.example.com
OPENFGA_STORE_ID=prod-store-id
OPENFGA_MODEL_ID=prod-model-id
OPENFGA_CACHE_ENABLED=true
OPENFGA_QUEUE_ENABLED=true
OPENFGA_LOGGING_ENABLED=false

# Development
OPENFGA_URL=http://localhost:8080
OPENFGA_STORE_ID=dev-store-id
OPENFGA_MODEL_ID=
OPENFGA_CACHE_ENABLED=false
OPENFGA_QUEUE_ENABLED=false
OPENFGA_LOGGING_ENABLED=true
```

### Dynamic Configuration

```php
// In AppServiceProvider
use OpenFGA\Laravel\Facades\OpenFga;

public function boot()
{
    if ($this->app->environment('production')) {
        config(['openfga.cache.ttl' => 600]); // 10 minutes in production
        config(['openfga.queue.enabled' => true]);
    }

    if ($this->app->environment('local')) {
        config(['openfga.logging.levels.check' => 'debug']);
    }
}
```

## Configuration Validation

The package validates configuration on boot. Common validation errors:

### Invalid Store ID

```
OpenFGA Configuration Error: Store ID is required for connection 'main'
```

**Solution**: Set `OPENFGA_STORE_ID` in your `.env` file

### Invalid Authentication

```
OpenFGA Configuration Error: Invalid authentication method 'invalid'
```

**Solution**: Use one of: `none`, `api_token`, `client_credentials`

### Missing Credentials

```
OpenFGA Configuration Error: API token required for 'api_token' authentication
```

**Solution**: Set `OPENFGA_API_TOKEN` in your `.env` file

## Configuration Caching

For production, cache your configuration:

```bash
php artisan config:cache
```

Clear cached configuration:

```bash
php artisan config:clear
```

## Debugging Configuration

Use the debug command to inspect current configuration:

```bash
php artisan openfga:debug
```

Output:

```
OpenFGA Configuration Debug
===========================

Default Connection: main

Connections:
  [main]
    URL: http://localhost:8080
    Store ID: 01J5KGFHSDHGJSD123
    Model ID: 01J5KGH7SDFGHJKL456
    Auth Method: api_token
    Cache: enabled (300s TTL)
    Queue: disabled

  [production]
    URL: https://api.openfga.example.com
    Store ID: prod-store-123
    Model ID: prod-model-456
    Auth Method: client_credentials
    Cache: enabled (600s TTL)
    Queue: enabled

Cache Status: ✓ Enabled (redis driver)
Queue Status: ✗ Disabled
Logging Status: ✓ Enabled (openfga channel)
```

## Next Steps

- Learn about [Eloquent Integration](eloquent.md)
- Configure [Middleware & Authorization](middleware.md)
- Set up [Testing](testing.md)
- Optimize with [Performance Guide](performance.md)
