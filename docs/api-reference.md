# API Reference

This document provides a complete reference for all public methods and classes in the OpenFGA Laravel package.

## Table of Contents

- [OpenFgaManager](#openfgamanager)
- [Facades](#facades)
- [Traits](#traits)
- [Middleware](#middleware)
- [Commands](#commands)
- [Events](#events)
- [Exceptions](#exceptions)
- [Testing](#testing)

## OpenFgaManager

The main service class for interacting with OpenFGA.

### Methods

#### check()

Check if a user has a specific permission on an object.

```php
public function check(
    string $user, 
    string $relation, 
    string $object, 
    ?array $contextualTuples = null,
    ?array $context = null,
    ?string $connection = null
): bool
```

**Parameters:**
- `$user` - User identifier (e.g., 'user:123' or '@me')
- `$relation` - The relation to check (e.g., 'viewer', 'editor')
- `$object` - Object identifier (e.g., 'document:456')
- `$contextualTuples` - Optional contextual tuples for the check
- `$context` - Optional context data
- `$connection` - Optional connection name

**Returns:** `bool` - Whether the user has the permission

**Example:**
```php
$allowed = OpenFga::check('user:123', 'editor', 'document:456');
```

#### grant()

Grant a permission to a user.

```php
public function grant(
    string $user, 
    string $relation, 
    string $object,
    ?string $connection = null
): void
```

**Parameters:**
- `$user` - User identifier
- `$relation` - The relation to grant
- `$object` - Object identifier
- `$connection` - Optional connection name

**Example:**
```php
OpenFga::grant('user:123', 'editor', 'document:456');
```

#### revoke()

Revoke a permission from a user.

```php
public function revoke(
    string $user, 
    string $relation, 
    string $object,
    ?string $connection = null
): void
```

**Parameters:**
- `$user` - User identifier
- `$relation` - The relation to revoke
- `$object` - Object identifier
- `$connection` - Optional connection name

**Example:**
```php
OpenFga::revoke('user:123', 'editor', 'document:456');
```

#### writeBatch()

Perform batch write operations.

```php
public function writeBatch(
    array $writes = [], 
    array $deletes = [],
    ?string $connection = null
): void
```

**Parameters:**
- `$writes` - Array of tuples to write `[user, relation, object]`
- `$deletes` - Array of tuples to delete `[user, relation, object]`
- `$connection` - Optional connection name

**Example:**
```php
OpenFga::writeBatch(
    writes: [
        ['user:123', 'viewer', 'document:456'],
        ['user:123', 'editor', 'document:789'],
    ],
    deletes: [
        ['user:456', 'viewer', 'document:456'],
    ]
);
```

#### batchCheck()

Check multiple permissions in one request.

```php
public function batchCheck(
    array $checks,
    ?string $connection = null
): array
```

**Parameters:**
- `$checks` - Array of checks `[user, relation, object]`
- `$connection` - Optional connection name

**Returns:** `array` - Array of boolean results

**Example:**
```php
$results = OpenFga::batchCheck([
    ['user:123', 'viewer', 'document:456'],
    ['user:123', 'editor', 'document:456'],
]);
// Returns: [true, false]
```

#### expand()

Expand a relation to see all users who have access.

```php
public function expand(
    string $object, 
    string $relation,
    ?string $connection = null
): array
```

**Parameters:**
- `$object` - Object identifier
- `$relation` - The relation to expand
- `$connection` - Optional connection name

**Returns:** `array` - Expansion result

**Example:**
```php
$result = OpenFga::expand('document:456', 'viewer');
```

#### listObjects()

List all objects a user has a specific permission for.

```php
public function listObjects(
    string $user, 
    string $relation, 
    string $type,
    ?array $contextualTuples = null,
    ?string $connection = null
): array
```

**Parameters:**
- `$user` - User identifier
- `$relation` - The relation to check
- `$type` - Object type to filter
- `$contextualTuples` - Optional contextual tuples
- `$connection` - Optional connection name

**Returns:** `array` - Array of object identifiers

**Example:**
```php
$documents = OpenFga::listObjects('user:123', 'viewer', 'document');
// Returns: ['document:456', 'document:789']
```

#### query()

Create a fluent query builder instance.

```php
public function query(?string $connection = null): AuthorizationQuery
```

**Parameters:**
- `$connection` - Optional connection name

**Returns:** `AuthorizationQuery` - Query builder instance

**Example:**
```php
$result = OpenFga::query()
    ->for('user:123')
    ->can('edit')
    ->on('document:456')
    ->check();
```

#### connection()

Switch to a different connection.

```php
public function connection(?string $name = null): self
```

**Parameters:**
- `$name` - Connection name (null for default)

**Returns:** `self` - Manager instance using specified connection

**Example:**
```php
OpenFga::connection('secondary')->check($user, $relation, $object);
```

## Facades

### OpenFga Facade

Provides static access to the OpenFgaManager.

```php
use OpenFGA\Laravel\Facades\OpenFga;

// All OpenFgaManager methods are available
OpenFga::check('@me', 'viewer', 'document:123');
OpenFga::grant('user:456', 'editor', 'document:123');
```

## Traits

### HasAuthorization

Add authorization capabilities to Eloquent models.

#### Methods

##### grant()

```php
public function grant(
    Model|string $user, 
    string $relation
): void
```

**Example:**
```php
$document->grant($user, 'editor');
$document->grant('user:123', 'viewer');
```

##### check()

```php
public function check(
    Model|string $user, 
    string $relation
): bool
```

**Example:**
```php
if ($document->check($user, 'editor')) {
    // User can edit
}
```

##### revoke()

```php
public function revoke(
    Model|string $user, 
    string $relation
): void
```

**Example:**
```php
$document->revoke($user, 'editor');
```

##### revokeAll()

```php
public function revokeAll(Model|string $user): void
```

**Example:**
```php
$document->revokeAll($user);
```

##### revokeAllPermissions()

```php
public function revokeAllPermissions(): void
```

**Example:**
```php
$document->revokeAllPermissions();
```

##### getUsersWithPermission()

```php
public function getUsersWithPermission(string $relation): array
```

**Example:**
```php
$editors = $document->getUsersWithPermission('editor');
```

##### authorizationObject()

```php
public function authorizationObject(): string
```

**Example:**
```php
$objectId = $document->authorizationObject();
// Returns: "document:123"
```

##### authorizationType()

```php
protected function authorizationType(): string
```

Override to customize the object type.

**Example:**
```php
protected function authorizationType(): string
{
    return 'doc'; // Results in "doc:123"
}
```

#### Scopes

##### whereUserCan()

```php
public function scopeWhereUserCan(
    Builder $query, 
    Model|string $user, 
    string $relation
): Builder
```

**Example:**
```php
$documents = Document::whereUserCan($user, 'viewer')->get();
```

##### whereUserCanAny()

```php
public function scopeWhereUserCanAny(
    Builder $query, 
    Model|string $user, 
    array $relations
): Builder
```

**Example:**
```php
$documents = Document::whereUserCanAny($user, ['viewer', 'editor'])->get();
```

##### whereUserCanAll()

```php
public function scopeWhereUserCanAll(
    Builder $query, 
    Model|string $user, 
    array $relations
): Builder
```

**Example:**
```php
$documents = Document::whereUserCanAll($user, ['viewer', 'commenter'])->get();
```

### AuthorizesWithOpenFga

Add OpenFGA authorization to Form Requests.

#### Methods

##### checkPermission()

```php
protected function checkPermission(
    string $relation, 
    Model|string $object
): bool
```

**Example:**
```php
public function authorize(): bool
{
    return $this->checkPermission('editor', $this->route('document'));
}
```

##### checkAnyPermission()

```php
protected function checkAnyPermission(
    array $relations, 
    Model|string $object
): bool
```

**Example:**
```php
public function authorize(): bool
{
    return $this->checkAnyPermission(['editor', 'owner'], $this->route('document'));
}
```

##### checkAllPermissions()

```php
protected function checkAllPermissions(
    array $relations, 
    Model|string $object
): bool
```

**Example:**
```php
public function authorize(): bool
{
    return $this->checkAllPermissions(['member', 'active'], $this->route('team'));
}
```

### FakesOpenFga

Testing trait for faking OpenFGA operations.

#### Methods

##### fakeOpenFga()

```php
protected function fakeOpenFga(): void
```

**Example:**
```php
protected function setUp(): void
{
    parent::setUp();
    $this->fakeOpenFga();
}
```

## Middleware

### OpenFgaMiddleware

Base middleware for route protection.

```php
Route::middleware(['openfga:editor,document:{document}'])
    ->get('/documents/{document}/edit', [DocumentController::class, 'edit']);
```

**Format:** `openfga:relation,object[,connection]`

### RequiresAnyPermission

Check if user has any of the specified permissions.

```php
Route::middleware(['can.any:viewer,editor,owner,document:{document}'])
    ->get('/documents/{document}', [DocumentController::class, 'show']);
```

**Format:** `can.any:relation1,relation2,...,object[,connection]`

### RequiresAllPermissions

Check if user has all specified permissions.

```php
Route::middleware(['can.all:member,billing,team:{team}'])
    ->get('/teams/{team}/billing', [BillingController::class, 'index']);
```

**Format:** `can.all:relation1,relation2,...,object[,connection]`

## Commands

### openfga:check

Check if a user has permission.

```bash
php artisan openfga:check {user} {relation} {object} [options]
```

**Options:**
- `--connection[=CONNECTION]` - The connection to use
- `--json` - Output as JSON
- `--contextual-tuple[=CONTEXTUAL-TUPLE]` - Contextual tuples (multiple allowed)
- `--context[=CONTEXT]` - Context values as key=value (multiple allowed)

**Example:**
```bash
php artisan openfga:check user:123 editor document:456
php artisan openfga:check user:123 viewer document:456 --json
```

### openfga:grant

Grant permission to a user.

```bash
php artisan openfga:grant {user} {relation} {object} [options]
```

**Options:**
- `--connection[=CONNECTION]` - The connection to use
- `--batch` - Enable batch mode for multiple users

**Example:**
```bash
php artisan openfga:grant user:123 editor document:456
php artisan openfga:grant user:123,user:456,user:789 viewer document:456 --batch
```

### openfga:revoke

Revoke permission from a user.

```bash
php artisan openfga:revoke {user} {relation} {object} [options]
```

**Options:**
- `--connection[=CONNECTION]` - The connection to use
- `--all` - Revoke all permissions for the user on this object

**Example:**
```bash
php artisan openfga:revoke user:123 editor document:456
php artisan openfga:revoke user:123 all document:456 --all
```

### openfga:expand

Expand a relation to see all users.

```bash
php artisan openfga:expand {object} {relation} [options]
```

**Options:**
- `--connection[=CONNECTION]` - The connection to use
- `--json` - Output as JSON

**Example:**
```bash
php artisan openfga:expand document:456 viewer
php artisan openfga:expand document:456 editor --json
```

### openfga:list-objects

List objects a user has permission for.

```bash
php artisan openfga:list-objects {user} {relation} {type} [options]
```

**Options:**
- `--connection[=CONNECTION]` - The connection to use
- `--json` - Output as JSON

**Example:**
```bash
php artisan openfga:list-objects user:123 viewer document
php artisan openfga:list-objects user:123 editor folder --json
```

### openfga:debug

Debug OpenFGA configuration and connection.

```bash
php artisan openfga:debug [options]
```

**Options:**
- `--connection[=CONNECTION]` - Test specific connection

**Example:**
```bash
php artisan openfga:debug
php artisan openfga:debug --connection=secondary
```

### openfga:stats

Display permission statistics.

```bash
php artisan openfga:stats [options]
```

**Options:**
- `--days[=DAYS]` - Number of days to show (default: 7)
- `--json` - Output as JSON

**Example:**
```bash
php artisan openfga:stats
php artisan openfga:stats --days=30
```

## Events

### PermissionChecked

Fired when a permission is checked.

```php
namespace OpenFGA\Laravel\Events;

class PermissionChecked
{
    public string $user;
    public string $relation;
    public string $object;
    public bool $allowed;
    public ?string $connection;
    public float $duration;
    public bool $cached;
    public array $context;
}
```

### PermissionGranted

Fired when a permission is granted.

```php
namespace OpenFGA\Laravel\Events;

class PermissionGranted
{
    public string $user;
    public string $relation;
    public string $object;
    public ?string $connection;
    public float $duration;
}
```

### PermissionRevoked

Fired when a permission is revoked.

```php
namespace OpenFGA\Laravel\Events;

class PermissionRevoked
{
    public string $user;
    public string $relation;
    public string $object;
    public ?string $connection;
    public float $duration;
}
```

### BatchWriteCompleted

Fired when a batch write completes.

```php
namespace OpenFGA\Laravel\Events;

class BatchWriteCompleted
{
    public array $writes;
    public array $deletes;
    public ?string $connection;
    public float $duration;
    public array $options;
}
```

### BatchWriteFailed

Fired when a batch write fails.

```php
namespace OpenFGA\Laravel\Events;

class BatchWriteFailed
{
    public array $writes;
    public array $deletes;
    public ?string $connection;
    public Throwable $exception;
    public array $options;
}
```

## Exceptions

### AuthorizationException

Thrown when authorization operations fail.

```php
namespace OpenFGA\Laravel\Exceptions;

class AuthorizationException extends Exception
{
    public function getUser(): ?string;
    public function getRelation(): ?string;
    public function getObject(): ?string;
}
```

### ConfigurationException

Thrown when configuration is invalid.

```php
namespace OpenFGA\Laravel\Exceptions;

class ConfigurationException extends Exception
{
    public function getConnection(): ?string;
    public function getConfigKey(): ?string;
}
```

## Testing

### FakeOpenFga

Fake implementation for testing.

#### Assertion Methods

##### assertGranted()

```php
OpenFga::assertGranted(string $user, string $relation, string $object): void
```

**Example:**
```php
OpenFga::assertGranted('user:123', 'editor', 'document:456');
```

##### assertRevoked()

```php
OpenFga::assertRevoked(string $user, string $relation, string $object): void
```

**Example:**
```php
OpenFga::assertRevoked('user:123', 'editor', 'document:456');
```

##### assertChecked()

```php
OpenFga::assertChecked(string $user, string $relation, string $object): void
```

**Example:**
```php
OpenFga::assertChecked('user:123', 'viewer', 'document:456');
```

##### assertNotChecked()

```php
OpenFga::assertNotChecked(string $user, string $relation, string $object): void
```

**Example:**
```php
OpenFga::assertNotChecked('user:123', 'admin', 'system:core');
```

##### assertNothingGranted()

```php
OpenFga::assertNothingGranted(): void
```

**Example:**
```php
OpenFga::assertNothingGranted();
```

##### assertBatchWritten()

```php
OpenFga::assertBatchWritten(array $expectedWrites, array $expectedDeletes = []): void
```

**Example:**
```php
OpenFga::assertBatchWritten([
    ['user:123', 'viewer', 'document:456'],
    ['user:456', 'editor', 'document:456'],
]);
```

#### Mocking Methods

##### shouldCheck()

```php
OpenFga::shouldCheck(
    string $user, 
    string $relation, 
    string $object, 
    bool $result
): void
```

**Example:**
```php
OpenFga::shouldCheck('user:123', 'editor', 'document:456', true);
```

##### shouldListObjects()

```php
OpenFga::shouldListObjects(
    string $user, 
    string $relation, 
    string $type, 
    array $objects
): void
```

**Example:**
```php
OpenFga::shouldListObjects(
    'user:123', 
    'viewer', 
    'document', 
    ['document:456', 'document:789']
);
```

##### shouldFail()

```php
OpenFga::shouldFail(string $message = 'Operation failed'): void
```

**Example:**
```php
OpenFga::shouldFail('Connection timeout');
```

##### shouldFailTimes()

```php
OpenFga::shouldFailTimes(int $times, string $message = 'Operation failed'): void
```

**Example:**
```php
OpenFga::shouldFailTimes(2, 'Service unavailable');
```

## Configuration

### Connection Configuration

```php
'connections' => [
    'main' => [
        'url' => string,              // OpenFGA server URL
        'store_id' => string,         // Store identifier
        'model_id' => ?string,        // Model ID (null for latest)
        'credentials' => [
            'method' => string,       // 'none', 'api_token', 'client_credentials'
            'token' => ?string,       // API token
            'client_id' => ?string,   // OAuth client ID
            'client_secret' => ?string, // OAuth client secret
            'api_token_issuer' => ?string,
            'api_audience' => ?string,
            'scopes' => array,        // OAuth scopes
        ],
        'retries' => [
            'max_retries' => int,     // Max retry attempts
            'min_wait_ms' => int,     // Min wait between retries
        ],
        'http_options' => [
            'timeout' => int,         // Request timeout
            'connect_timeout' => int, // Connection timeout
        ],
    ],
],
```

### Cache Configuration

```php
'cache' => [
    'enabled' => bool,          // Enable caching
    'store' => string,          // Cache store to use
    'ttl' => int,              // TTL in seconds
    'prefix' => string,        // Cache key prefix
],
```

### Queue Configuration

```php
'queue' => [
    'enabled' => bool,          // Enable queue support
    'connection' => string,     // Queue connection
    'queue' => string,         // Queue name
],
```

## Blade Directives

### @can

```blade
@can('editor', 'document:' . $document->id)
    <button>Edit Document</button>
@endcan
```

### @cannot

```blade
@cannot('owner', 'document:' . $document->id)
    <p>You don't own this document</p>
@endcannot
```

### @canany

```blade
@canany(['editor', 'owner'], 'document:' . $document->id)
    <button>Manage Document</button>
@endcanany
```

### @canall

```blade
@canall(['member', 'verified'], 'team:' . $team->id)
    <div>Premium Team Features</div>
@endcanall
```

## Helper Functions

### can()

```php
function can(string $relation, string $object): bool
```

**Example:**
```php
if (can('editor', 'document:123')) {
    // Current user can edit
}
```

### cannot()

```php
function cannot(string $relation, string $object): bool
```

**Example:**
```php
if (cannot('owner', 'document:123')) {
    // Current user is not owner
}
```

### canAny()

```php
function canAny(array $relations, string $object): bool
```

**Example:**
```php
if (canAny(['viewer', 'editor'], 'document:123')) {
    // User has at least one permission
}
```

### canAll()

```php
function canAll(array $relations, string $object): bool
```

**Example:**
```php
if (canAll(['member', 'active'], 'team:123')) {
    // User has all permissions
}
```