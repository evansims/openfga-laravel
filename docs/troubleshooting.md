# Troubleshooting Guide

This guide helps you diagnose and resolve common issues when using OpenFGA Laravel.

## Common Issues

### Connection Issues

#### Error: "Connection refused" or "Could not connect to OpenFGA server"

**Symptoms:**
- Operations fail with connection errors
- Health check command fails
- Timeout errors when making requests

**Solutions:**

1. **Verify OpenFGA server is running:**
   ```bash
   # Check if server is accessible
   curl http://localhost:8080/healthz
   ```

2. **Check your configuration:**
   ```bash
   php artisan openfga:debug
   ```

3. **Verify environment variables:**
   ```bash
   # Check .env file
   OPENFGA_URL=http://localhost:8080  # Ensure URL is correct
   OPENFGA_STORE_ID=01ARZ3NDEKTSV4RRFFQ69G5FAV
   ```

4. **Test with different timeout settings:**
   ```php
   // config/openfga.php
   'http_options' => [
       'timeout' => 60,         // Increase timeout
       'connect_timeout' => 30, // Increase connection timeout
   ],
   ```

#### Error: "Invalid store ID" or "Store not found"

**Solutions:**

1. **Verify store exists:**
   ```bash
   # List stores using OpenFGA CLI
   fga store list
   ```

2. **Create a new store if needed:**
   ```bash
   fga store create --name "my-app"
   ```

3. **Update your .env with correct store ID:**
   ```env
   OPENFGA_STORE_ID=01GXSA8YR785C4FYS3C0RTG7B1
   ```

### Authentication Issues

#### Error: "Unauthorized" or "Invalid credentials"

**Solutions:**

1. **For API Token authentication:**
   ```env
   OPENFGA_AUTH_METHOD=api_token
   OPENFGA_API_TOKEN=your-valid-token-here
   ```

2. **For OAuth2 Client Credentials:**
   ```env
   OPENFGA_AUTH_METHOD=client_credentials
   OPENFGA_CLIENT_ID=your-client-id
   OPENFGA_CLIENT_SECRET=your-client-secret
   OPENFGA_TOKEN_ISSUER=https://your-issuer.com
   OPENFGA_API_AUDIENCE=https://api.your-domain.com
   ```

3. **Test authentication:**
   ```php
   // Create a test route
   Route::get('/test-auth', function () {
       try {
           $result = OpenFga::check('user:test', 'reader', 'document:test');
           return 'Authentication successful';
       } catch (\Exception $e) {
           return 'Authentication failed: ' . $e->getMessage();
       }
   });
   ```

### Permission Check Issues

#### Checks always return false

**Solutions:**

1. **Verify permissions exist:**
   ```bash
   php artisan openfga:expand document:123 viewer
   ```

2. **Check tuple format:**
   ```php
   // ✅ Correct format
   OpenFga::grant('user:123', 'viewer', 'document:456');

   // ❌ Incorrect format
   OpenFga::grant('123', 'viewer', '456');
   ```

3. **Verify model exists:**
   ```bash
   php artisan openfga:debug
   # Check that Model ID is set
   ```

4. **Clear cache if enabled:**
   ```bash
   php artisan cache:clear
   ```

#### Inconsistent permission results

**Solutions:**

1. **Check cache configuration:**
   ```php
   // Temporarily disable cache for testing
   'cache' => [
       'enabled' => false,
   ],
   ```

2. **Verify no duplicate tuples:**
   ```php
   // Check for duplicate grants
   $expansion = OpenFga::expand('document:123', 'viewer');
   dd($expansion); // Inspect the results
   ```

3. **Use consistent user identifiers:**
   ```php
   // Always use the same format
   $userId = "user:{$user->id}";
   // Not sometimes 'user:123' and sometimes 'user:uuid-123'
   ```

### Model Integration Issues

#### Trait methods not working

**Error:** "Call to undefined method grant()"

**Solutions:**

1. **Ensure trait is properly imported:**
   ```php
   use OpenFGA\Laravel\Traits\HasAuthorization;

   class Document extends Model
   {
       use HasAuthorization;
   }
   ```

2. **Clear Laravel's cache:**
   ```bash
   php artisan clear-compiled
   php artisan cache:clear
   composer dump-autoload
   ```

#### whereUserCan scope returns empty results

**Solutions:**

1. **Mock listObjects in tests:**
   ```php
   OpenFga::shouldListObjects(
       "user:{$user->id}",
       'viewer',
       'document',
       ['document:1', 'document:2', 'document:3']
   );
   ```

2. **Check the authorization type:**
   ```php
   class Document extends Model
   {
       use HasAuthorization;

       // Ensure this matches your OpenFGA model
       protected function authorizationType(): string
       {
           return 'document'; // Must match OpenFGA type
       }
   }
   ```

### Middleware Issues

#### 403 Forbidden on all routes

**Solutions:**

1. **Debug the middleware:**
   ```php
   // Add temporary logging
   class OpenFgaMiddleware
   {
       public function handle($request, $next, $relation, $object)
       {
           $user = "user:{$request->user()->id}";
           \Log::info('Checking permission', [
               'user' => $user,
               'relation' => $relation,
               'object' => $object,
           ]);

           // ... rest of middleware
       }
   }
   ```

2. **Check middleware parameters:**
   ```php
   // Correct format
   Route::middleware(['openfga:editor,document:{document}'])

   // Incorrect - missing object
   Route::middleware(['openfga:editor'])
   ```

3. **Verify user is authenticated:**
   ```php
   Route::middleware(['auth', 'openfga:editor,document:{document}'])
   ```

### Queue Issues

#### Batch operations not processing

**Solutions:**

1. **Ensure queue worker is running:**
   ```bash
   php artisan queue:work --queue=openfga
   ```

2. **Check queue configuration:**
   ```env
   OPENFGA_QUEUE_ENABLED=true
   OPENFGA_QUEUE_CONNECTION=redis
   OPENFGA_QUEUE_NAME=openfga
   ```

3. **Monitor failed jobs:**
   ```bash
   php artisan queue:failed
   ```

4. **Process failed jobs:**
   ```bash
   php artisan queue:retry all
   ```

### Performance Issues

#### Slow permission checks

**Solutions:**

1. **Enable caching:**
   ```env
   OPENFGA_CACHE_ENABLED=true
   OPENFGA_CACHE_STORE=redis
   OPENFGA_CACHE_TTL=300
   ```

2. **Use batch operations:**
   ```php
   // Instead of multiple checks
   $permissions = OpenFga::batchCheck([
       ['user:123', 'viewer', 'document:1'],
       ['user:123', 'editor', 'document:1'],
       ['user:123', 'owner', 'document:1'],
   ]);
   ```

3. **Monitor slow queries:**
   ```bash
   php artisan openfga:stats
   ```

4. **Implement connection pooling:**
   ```php
   'pool' => [
       'enabled' => true,
       'min_connections' => 2,
       'max_connections' => 10,
   ],
   ```

## Debugging Tools

### Enable Debug Mode

```php
// In your .env file
APP_DEBUG=true
OPENFGA_LOGGING_ENABLED=true
OPENFGA_LOG_CHANNEL=daily
```

### Use the Debug Command

```bash
php artisan openfga:debug
```

Output shows:
- Current configuration
- Connection status
- Store and model information
- Cache and queue status

### Enable Query Logging

```php
// In a service provider or route
OpenFga::enableQueryLog();

// After operations
$queries = OpenFga::getQueryLog();
\Log::debug('OpenFGA Queries', $queries);
```

### Laravel Telescope Integration

```php
// In TelescopeServiceProvider
use OpenFGA\Laravel\Events\PermissionChecked;

public function register()
{
    Telescope::filter(function (IncomingEntry $entry) {
        if ($entry->type === 'event') {
            return !Str::startsWith($entry->content['name'], 'OpenFGA\\Laravel\\Events\\');
        }
        return true;
    });
}
```

## Testing Issues

### Fake not working in tests

**Solutions:**

1. **Ensure trait is used:**
   ```php
   use OpenFGA\Laravel\Testing\FakesOpenFga;

   class MyTest extends TestCase
   {
       use FakesOpenFga;

       protected function setUp(): void
       {
           parent::setUp();
           $this->fakeOpenFga();
       }
   }
   ```

2. **Clear bindings between tests:**
   ```php
   protected function tearDown(): void
   {
       OpenFga::clearFake();
       parent::tearDown();
   }
   ```

### Assertions not working

**Solutions:**

1. **Check assertion order:**
   ```php
   // First perform the action
   $document->grant($user, 'editor');

   // Then assert
   OpenFga::assertGranted(
       "user:{$user->id}",
       'editor',
       "document:{$document->id}"
   );
   ```

2. **Use correct format in assertions:**
   ```php
   // Match the exact format used in your code
   OpenFga::assertChecked(
       "user:{$user->id}", // Not just $user->id
       'viewer',
       "document:{$document->id}" // Not just $document->id
   );
   ```

## Custom Exception Types

OpenFGA Laravel provides typed exceptions for better error handling:

### AuthorizationException

Thrown when authorization operations fail.

```php
use OpenFGA\Laravel\Exceptions\AuthorizationException;

try {
    $allowed = OpenFga::check('user:123', 'viewer', 'document:456');
} catch (AuthorizationException $e) {
    // Specific handling for authorization failures
    \Log::error('Authorization failed: ' . $e->getMessage());
}
```

### ConnectionException

Thrown when there are connection issues with OpenFGA.

```php
use OpenFGA\Laravel\Exceptions\ConnectionException;

try {
    $result = OpenFga::listStores();
} catch (ConnectionException $e) {
    // Handle connection issues
    if (str_contains($e->getMessage(), 'timed out')) {
        // Retry with longer timeout
    }
}
```

### ModelNotFoundException

Thrown when a specified authorization model cannot be found.

```php
use OpenFGA\Laravel\Exceptions\ModelNotFoundException;

try {
    $model = OpenFga::readAuthorizationModel($modelId);
} catch (ModelNotFoundException $e) {
    // Model doesn't exist
    \Log::warning('Model not found: ' . $modelId);
}
```

### StoreNotFoundException

Thrown when a specified store cannot be found.

```php
use OpenFGA\Laravel\Exceptions\StoreNotFoundException;

try {
    $store = OpenFga::getStore($storeId);
} catch (StoreNotFoundException $e) {
    // Store doesn't exist
    // Maybe create a new store or use default
}
```

### InvalidTupleException

Thrown when tuple format is invalid.

```php
use OpenFGA\Laravel\Exceptions\InvalidTupleException;

try {
    OpenFga::grant('invalid-format', 'viewer', 'document:123');
} catch (InvalidTupleException $e) {
    // Fix the tuple format
    // Should be 'type:id' format
}
```

### ConnectionPoolException

Thrown when connection pool operations fail.

```php
use OpenFGA\Laravel\Exceptions\ConnectionPoolException;

try {
    // Connection pool operations
} catch (ConnectionPoolException $e) {
    // Handle pool exhaustion or connection failures
}
```

### Base OpenFgaException

All custom exceptions extend from `OpenFgaException`:

```php
use OpenFGA\Laravel\Exceptions\OpenFgaException;

try {
    // Any OpenFGA operation
} catch (OpenFgaException $e) {
    // Catches any OpenFGA-related exception
    \Log::error('OpenFGA error: ' . $e->getMessage());
}
```

## Error Messages Reference

### "Result unwrapping failed"

This occurs when using exception mode and an operation fails.

**Solution:**
```php
try {
    $result = OpenFga::check($user, $relation, $object);
} catch (\OpenFGA\Laravel\Exceptions\AuthorizationException $e) {
    // Handle the error
    \Log::error('Authorization check failed', [
        'error' => $e->getMessage(),
        'user' => $user,
        'relation' => $relation,
        'object' => $object,
    ]);
}
```

### "Invalid tuple format"

**Solution:**
Ensure tuples follow the format:
```php
// Correct formats
'user:123'
'group:admins'
'folder:projects#parent'
'team:engineering#member'

// Incorrect formats
'123'           // Missing type prefix
'user-123'      // Should use colon, not dash
'user::123'     // Double colon
```

### "Model not found"

**Solution:**
1. Check model ID in configuration
2. Verify model exists in the store
3. Try without specifying model ID (uses latest)

## Getting Help

### 1. Check Logs

```bash
# Laravel logs
tail -f storage/logs/laravel.log

# OpenFGA specific logs
tail -f storage/logs/openfga.log
```

### 2. Enable Verbose Output

```bash
php artisan openfga:check user:123 viewer document:456 -vvv
```

### 3. Community Support

- **GitHub Issues**: Report bugs or request features
- **Discussions**: Ask questions and share solutions
- **Stack Overflow**: Tag questions with `openfga` and `laravel`

### 4. Professional Support

For enterprise support, contact the OpenFGA team through official channels.

## Prevention Tips

1. **Always validate configuration on deployment:**
   ```bash
   php artisan openfga:debug
   ```

2. **Use health checks:**
   ```php
   Route::get('/health/openfga', function () {
       try {
           OpenFga::check('user:health', 'check', 'system:health');
           return response()->json(['status' => 'healthy']);
       } catch (\Exception $e) {
           return response()->json(['status' => 'unhealthy', 'error' => $e->getMessage()], 503);
       }
   });
   ```

3. **Monitor performance:**
   ```bash
   php artisan openfga:stats
   ```

4. **Keep dependencies updated:**
   ```bash
   composer update evansms/openfga-laravel
   ```
