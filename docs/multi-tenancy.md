# Multi-Tenancy Support

OpenFGA Laravel provides first-class support for multi-tenancy, allowing you to manage authorization for multiple tenants using different stores, models, or even different OpenFGA servers.

## Configuration

Define multiple connections in your `config/openfga.php`:

```php
return [
    'default' => env('OPENFGA_CONNECTION', 'main'),
    
    'connections' => [
        'main' => [
            'url' => env('OPENFGA_URL', 'http://localhost:8080'),
            'store_id' => env('OPENFGA_STORE_ID'),
            'model_id' => env('OPENFGA_MODEL_ID'),
            'credentials' => [
                'method' => env('OPENFGA_AUTH_METHOD', 'none'),
            ],
        ],
        
        'tenant_a' => [
            'url' => env('TENANT_A_OPENFGA_URL'),
            'store_id' => env('TENANT_A_STORE_ID'),
            'model_id' => env('TENANT_A_MODEL_ID'),
            'credentials' => [
                'method' => 'api_token',
                'token' => env('TENANT_A_API_TOKEN'),
            ],
        ],
        
        'tenant_b' => [
            'url' => env('TENANT_B_OPENFGA_URL'),
            'store_id' => env('TENANT_B_STORE_ID'),
            'model_id' => env('TENANT_B_MODEL_ID'),
            'credentials' => [
                'method' => 'client_credentials',
                'client_id' => env('TENANT_B_CLIENT_ID'),
                'client_secret' => env('TENANT_B_CLIENT_SECRET'),
                'api_token_issuer' => env('TENANT_B_TOKEN_ISSUER'),
                'api_audience' => env('TENANT_B_API_AUDIENCE'),
            ],
        ],
    ],
];
```

## Usage Patterns

### Per-Request Connection Selection

Use a specific connection for individual operations:

```php
use OpenFGA\Laravel\Facades\OpenFga;

// Check permission for tenant A
$allowed = OpenFga::connection('tenant_a')->check(
    user: 'user:123',
    relation: 'viewer',
    object: 'document:456'
);

// List objects for tenant B
$documents = OpenFga::connection('tenant_b')->listObjects(
    user: 'user:123',
    relation: 'viewer',
    type: 'document'
);
```

### Switching Default Connection

Change the default connection for all subsequent operations:

```php
// Set default connection for current request
OpenFga::setConnection('tenant_a');

// All subsequent operations use tenant_a connection
OpenFga::check('user:123', 'viewer', 'document:456');
OpenFga::grant('user:123', 'editor', 'document:789');

// Switch to another tenant
OpenFga::setConnection('tenant_b');
```

### Middleware-Based Tenant Resolution

Automatically set the connection based on the current tenant:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenFGA\Laravel\Facades\OpenFga;

class SetOpenFgaTenant
{
    public function handle(Request $request, Closure $next)
    {
        // Resolve tenant from request (e.g., subdomain, header, session)
        $tenant = $request->route('tenant') 
            ?? $request->header('X-Tenant-ID')
            ?? session('tenant_id');
        
        if ($tenant) {
            // Map tenant to connection name
            $connection = $this->mapTenantToConnection($tenant);
            OpenFga::setConnection($connection);
        }
        
        return $next($request);
    }
    
    private function mapTenantToConnection(string $tenant): string
    {
        return match ($tenant) {
            'acme' => 'tenant_a',
            'globex' => 'tenant_b',
            default => 'main',
        };
    }
}
```

Register the middleware:

```php
// In app/Http/Kernel.php
protected $middlewareGroups = [
    'api' => [
        // ... other middleware
        \App\Http\Middleware\SetOpenFgaTenant::class,
    ],
];
```

### Model-Level Tenant Isolation

Use the `HasAuthorization` trait with tenant-specific connections:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Document extends Model
{
    use HasAuthorization;
    
    /**
     * Get the OpenFGA connection for this model.
     */
    protected function authorizationConnection(): ?string
    {
        // Return connection based on model's tenant
        return $this->tenant?->openfga_connection ?? 'main';
    }
}

// Usage
$document = Document::find(1);
$document->grant($user, 'viewer'); // Uses tenant-specific connection
```

### Query Builder with Tenant Context

```php
use OpenFGA\Laravel\Facades\OpenFga;

// Create tenant-specific query builders
$tenantAQuery = OpenFga::query('tenant_a');
$tenantBQuery = OpenFga::query('tenant_b');

// Perform operations on specific tenants
$allowedInA = $tenantAQuery
    ->for('user:123')
    ->can('view')
    ->on('document:456')
    ->check();

$documentsInB = $tenantBQuery
    ->for('user:123')
    ->can('edit')
    ->type('document')
    ->listObjects();
```

## Cache Isolation

Each connection maintains its own cache namespace:

```php
// Cache keys are automatically namespaced by store ID
'cache' => [
    'enabled' => true,
    'prefix' => 'openfga', // Base prefix
    // Actual cache key: openfga:{store_id}:check:user:123:viewer:document:456
],
```

## Best Practices

### 1. Connection Naming

Use descriptive connection names that reflect their purpose:

```php
'connections' => [
    'production_us_east' => [...],
    'production_eu_west' => [...],
    'staging' => [...],
    'customer_acme' => [...],
    'customer_globex' => [...],
],
```

### 2. Environment Configuration

Use environment-specific configuration files:

```bash
# .env.production
OPENFGA_CONNECTION=production_main
PRODUCTION_MAIN_URL=https://api.openfga.prod.example.com
PRODUCTION_MAIN_STORE_ID=01HQMVAH3R8XPROD123456

# .env.staging
OPENFGA_CONNECTION=staging
STAGING_URL=https://api.openfga.staging.example.com
STAGING_STORE_ID=01HQMVAH3R8XSTAGE123456
```

### 3. Connection Pooling

Enable connection pooling for better performance:

```php
'connections' => [
    'high_traffic_tenant' => [
        // ... other config
        'pool' => [
            'enabled' => true,
            'max_connections' => 20,
            'min_connections' => 5,
        ],
    ],
],
```

### 4. Health Monitoring

Monitor all tenant connections:

```php
use OpenFGA\Laravel\Facades\OpenFga;

// Check health of all connections
$health = OpenFga::healthCheckAll();

foreach ($health as $connection => $status) {
    if (!$status['healthy']) {
        Log::error("OpenFGA connection {$connection} is unhealthy", $status);
    }
}
```

### 5. Testing with Multiple Connections

```php
use OpenFGA\Laravel\Testing\OpenFgaTestCase;

class MultiTenantTest extends OpenFgaTestCase
{
    public function test_tenant_isolation(): void
    {
        // Mock different responses for different connections
        $this->mockOpenFga('tenant_a')
            ->shouldCheckPermission(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:456'
            )
            ->andReturn(true);
            
        $this->mockOpenFga('tenant_b')
            ->shouldCheckPermission(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:456'
            )
            ->andReturn(false);
        
        // Test isolation
        $this->assertTrue(
            OpenFga::connection('tenant_a')->check('user:123', 'viewer', 'document:456')
        );
        
        $this->assertFalse(
            OpenFga::connection('tenant_b')->check('user:123', 'viewer', 'document:456')
        );
    }
}
```

## Migration Between Tenants

Transfer permissions between tenants:

```php
use OpenFGA\Laravel\Facades\OpenFga;

class TenantMigration
{
    public function migrateTenant(string $from, string $to, string $userId): void
    {
        // Export permissions from source tenant
        $permissions = OpenFga::connection($from)
            ->export()
            ->forUser($userId)
            ->get();
        
        // Import to target tenant
        OpenFga::connection($to)
            ->import($permissions)
            ->execute();
    }
}
```

## Troubleshooting

### Connection Not Found

```php
// This will throw an exception
OpenFga::connection('nonexistent')->check(...);

// Safe connection checking
if (config("openfga.connections.{$tenant}")) {
    $result = OpenFga::connection($tenant)->check(...);
} else {
    // Handle missing connection
    Log::warning("Unknown tenant connection: {$tenant}");
}
```

### Performance Considerations

1. **Connection Overhead**: Each connection maintains its own HTTP client. Reuse connections when possible.

2. **Cache Warming**: Pre-warm caches for frequently accessed tenants:
   ```php
   Artisan::call('openfga:cache:warm', ['--connection' => 'tenant_a']);
   ```

3. **Batch Operations**: Group operations by tenant to minimize connection switching:
   ```php
   // Inefficient
   foreach ($items as $item) {
       OpenFga::connection($item->tenant)->check(...);
   }
   
   // Efficient
   $itemsByTenant = $items->groupBy('tenant');
   foreach ($itemsByTenant as $tenant => $tenantItems) {
       OpenFga::setConnection($tenant);
       OpenFga::batchCheck($tenantItems->map(...));
   }
   ```

## See Also

- [Configuration](configuration.md) - Detailed configuration options
- [Performance](performance.md) - Performance optimization strategies
- [Testing](testing.md) - Testing multi-tenant applications