# Testing Guide

This guide covers how to test your application's authorization logic using the OpenFGA Laravel package's testing utilities.

## Getting Started

### Setting Up Tests

The package provides a `FakesOpenFga` trait that replaces the real OpenFGA client with a fake implementation:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use OpenFGA\Laravel\Testing\FakesOpenFga;
use App\Models\User;
use App\Models\Document;

class DocumentAuthorizationTest extends TestCase
{
    use FakesOpenFga;

    protected function setUp(): void
    {
        parent::setUp();

        // Initialize the fake OpenFGA implementation
        $this->fakeOpenFga();
    }
}
```

## Basic Testing

### Testing Permission Grants

```php
public function test_user_can_be_granted_permission()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Grant permission
    OpenFga::grant("user:{$user->id}", 'editor', "document:{$document->id}");

    // Assert permission was granted
    OpenFga::assertGranted("user:{$user->id}", 'editor', "document:{$document->id}");

    // Verify the permission exists
    $this->assertTrue(
        OpenFga::check("user:{$user->id}", 'editor', "document:{$document->id}")
    );
}
```

### Testing Permission Checks

```php
public function test_permission_check_returns_correct_result()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Initially, user should not have permission
    $this->assertFalse(
        OpenFga::check("user:{$user->id}", 'viewer', "document:{$document->id}")
    );

    // Grant permission
    OpenFga::grant("user:{$user->id}", 'viewer', "document:{$document->id}");

    // Now user should have permission
    $this->assertTrue(
        OpenFga::check("user:{$user->id}", 'viewer', "document:{$document->id}")
    );

    // Assert the check was performed
    OpenFga::assertChecked("user:{$user->id}", 'viewer', "document:{$document->id}");
}
```

### Testing Permission Revocation

```php
public function test_permission_can_be_revoked()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Grant and then revoke permission
    OpenFga::grant("user:{$user->id}", 'editor', "document:{$document->id}");
    OpenFga::revoke("user:{$user->id}", 'editor', "document:{$document->id}");

    // Assert permission was revoked
    OpenFga::assertRevoked("user:{$user->id}", 'editor', "document:{$document->id}");

    // Verify permission no longer exists
    $this->assertFalse(
        OpenFga::check("user:{$user->id}", 'editor', "document:{$document->id}")
    );
}
```

## Testing with Models

### Testing Model Trait Methods

```php
public function test_model_can_grant_permissions()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Use model method to grant permission
    $document->grant($user, 'editor');

    // Assert using the expected format
    OpenFga::assertGranted(
        "user:{$user->id}",
        'editor',
        $document->authorizationObject()
    );

    // Verify through model method
    $this->assertTrue($document->check($user, 'editor'));
}
```

### Testing Query Scopes

```php
public function test_where_user_can_scope_filters_correctly()
{
    $user = User::factory()->create();
    $documents = Document::factory()->count(5)->create();

    // Grant permissions to specific documents
    $documents[0]->grant($user, 'viewer');
    $documents[2]->grant($user, 'viewer');
    $documents[4]->grant($user, 'viewer');

    // Mock the listObjects response
    OpenFga::shouldListObjects(
        "user:{$user->id}",
        'viewer',
        'document',
        [
            $documents[0]->authorizationObject(),
            $documents[2]->authorizationObject(),
            $documents[4]->authorizationObject(),
        ]
    );

    // Test the scope
    $viewableDocuments = Document::whereUserCan($user, 'viewer')->get();

    $this->assertCount(3, $viewableDocuments);
    $this->assertTrue($viewableDocuments->contains($documents[0]));
    $this->assertTrue($viewableDocuments->contains($documents[2]));
    $this->assertTrue($viewableDocuments->contains($documents[4]));
}
```

## Advanced Testing Features

### Mocking Specific Responses

```php
public function test_handles_specific_permission_scenarios()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Mock specific check responses
    OpenFga::shouldCheck(
        "user:{$user->id}",
        'viewer',
        "document:{$document->id}",
        true // Will return true
    );

    OpenFga::shouldCheck(
        "user:{$user->id}",
        'editor',
        "document:{$document->id}",
        false // Will return false
    );

    // Test the mocked responses
    $this->assertTrue($document->check($user, 'viewer'));
    $this->assertFalse($document->check($user, 'editor'));
}
```

### Testing Batch Operations

```php
public function test_batch_write_operations()
{
    $users = User::factory()->count(3)->create();
    $document = Document::factory()->create();

    // Perform batch write
    OpenFga::writeBatch(
        writes: [
            ["user:{$users[0]->id}", 'viewer', "document:{$document->id}"],
            ["user:{$users[1]->id}", 'editor', "document:{$document->id}"],
            ["user:{$users[2]->id}", 'owner', "document:{$document->id}"],
        ]
    );

    // Assert all permissions were granted
    OpenFga::assertBatchWritten([
        ["user:{$users[0]->id}", 'viewer', "document:{$document->id}"],
        ["user:{$users[1]->id}", 'editor', "document:{$document->id}"],
        ["user:{$users[2]->id}", 'owner', "document:{$document->id}"],
    ]);

    // Verify individual permissions
    foreach ($users as $index => $user) {
        $relations = ['viewer', 'editor', 'owner'];
        $this->assertTrue(
            OpenFga::check("user:{$user->id}", $relations[$index], "document:{$document->id}")
        );
    }
}
```

### Testing with Contextual Tuples

```php
public function test_contextual_permission_checks()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();
    $team = Team::factory()->create();

    // Mock contextual check
    OpenFga::shouldCheckWithContext(
        "user:{$user->id}",
        'viewer',
        "document:{$document->id}",
        [
            ["user:{$user->id}", 'member', "team:{$team->id}"],
        ],
        true
    );

    // Test contextual check
    $result = OpenFga::checkWithContext(
        "user:{$user->id}",
        'viewer',
        "document:{$document->id}",
        [
            ["user:{$user->id}", 'member', "team:{$team->id}"],
        ]
    );

    $this->assertTrue($result);
}
```

## Testing Failures and Errors

### Simulating Failures

```php
public function test_handles_openfga_failures_gracefully()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Simulate a failure
    OpenFga::shouldFail('Connection timeout');

    // Test that your application handles the failure
    try {
        $document->grant($user, 'editor');
        $this->fail('Expected exception was not thrown');
    } catch (\Exception $e) {
        $this->assertEquals('Connection timeout', $e->getMessage());
    }
}
```

### Testing Error Recovery

```php
public function test_retries_failed_operations()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Fail first attempt, succeed on retry
    OpenFga::shouldFailTimes(1);

    // Your application should retry and succeed
    $document->grant($user, 'editor');

    // Verify the permission was eventually granted
    OpenFga::assertGranted("user:{$user->id}", 'editor', "document:{$document->id}");
}
```

## Testing HTTP Requests

### Testing Protected Routes

```php
public function test_protected_route_requires_permission()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Test without permission
    $response = $this->actingAs($user)
        ->get("/documents/{$document->id}/edit");

    $response->assertForbidden();

    // Grant permission
    OpenFga::grant("user:{$user->id}", 'editor', "document:{$document->id}");

    // Test with permission
    $response = $this->actingAs($user)
        ->get("/documents/{$document->id}/edit");

    $response->assertOk();

    // Verify the permission was checked
    OpenFga::assertChecked("user:{$user->id}", 'editor', "document:{$document->id}");
}
```

### Testing API Endpoints

```php
public function test_api_endpoint_authorization()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    // Grant API permission
    OpenFga::grant("user:{$user->id}", 'api_user', 'system:api');
    OpenFga::grant("user:{$user->id}", 'editor', "document:{$document->id}");

    // Make API request
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json',
    ])->putJson("/api/documents/{$document->id}", [
        'title' => 'Updated Title',
        'content' => 'Updated content',
    ]);

    $response->assertOk();
    $response->assertJson([
        'data' => [
            'id' => $document->id,
            'title' => 'Updated Title',
        ],
    ]);
}
```

## Testing Events

### Testing Permission Events

```php
use Illuminate\Support\Facades\Event;
use OpenFGA\Laravel\Events\PermissionGranted;
use OpenFGA\Laravel\Events\PermissionChecked;

public function test_events_are_dispatched()
{
    Event::fake([
        PermissionGranted::class,
        PermissionChecked::class,
    ]);

    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Grant permission
    $document->grant($user, 'editor');

    // Check permission
    $document->check($user, 'editor');

    // Assert events were dispatched
    Event::assertDispatched(PermissionGranted::class, function ($event) use ($user, $document) {
        return $event->user === "user:{$user->id}"
            && $event->relation === 'editor'
            && $event->object === "document:{$document->id}";
    });

    Event::assertDispatched(PermissionChecked::class);
}
```

## Testing Utilities

### Creating Test Factories

```php
use OpenFGA\Laravel\Testing\CreatesPermissionData;

class PermissionFactory
{
    use CreatesPermissionData;

    public function documentWithFullPermissions(User $user): Document
    {
        $document = Document::factory()->create();

        $this->grantPermissions($document, $user, [
            'viewer',
            'editor',
            'owner',
        ]);

        return $document;
    }

    public function teamWithMembers(array $users, array $permissions): Team
    {
        $team = Team::factory()->create();

        foreach ($users as $user) {
            foreach ($permissions as $permission) {
                $this->grant("user:{$user->id}", $permission, "team:{$team->id}");
            }
        }

        return $team;
    }
}
```

### Custom Assertions

```php
trait CustomOpenFgaAssertions
{
    protected function assertUserHasAllPermissions(User $user, Model $model, array $relations)
    {
        foreach ($relations as $relation) {
            $this->assertTrue(
                OpenFga::check("user:{$user->id}", $relation, $model->authorizationObject()),
                "User should have {$relation} permission on {$model->authorizationObject()}"
            );
        }
    }

    protected function assertUserHasNoPermissions(User $user, Model $model, array $relations)
    {
        foreach ($relations as $relation) {
            $this->assertFalse(
                OpenFga::check("user:{$user->id}", $relation, $model->authorizationObject()),
                "User should not have {$relation} permission on {$model->authorizationObject()}"
            );
        }
    }
}
```

## Performance Testing

### Testing Query Performance

```php
public function test_permission_checks_are_cached()
{
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Grant permission
    OpenFga::grant("user:{$user->id}", 'viewer', "document:{$document->id}");

    // First check - should hit OpenFGA
    $document->check($user, 'viewer');

    // Reset check count
    OpenFga::resetCheckCount();

    // Second check - should hit cache
    $document->check($user, 'viewer');

    // Assert no additional checks were made
    $this->assertEquals(0, OpenFga::getCheckCount());
}
```

### Testing Batch Performance

```php
public function test_batch_operations_are_efficient()
{
    $users = User::factory()->count(100)->create();
    $document = Document::factory()->create();

    // Track operation count
    OpenFga::resetOperationCount();

    // Perform batch grant
    $writes = $users->map(fn($user) => [
        "user:{$user->id}", 'viewer', "document:{$document->id}"
    ])->toArray();

    OpenFga::writeBatch(writes: $writes);

    // Should be 1 batch operation, not 100 individual operations
    $this->assertEquals(1, OpenFga::getBatchWriteCount());
    $this->assertEquals(0, OpenFga::getIndividualWriteCount());
}
```

## Test Organization

### Using Test Traits

```php
namespace Tests\Traits;

trait SetsUpDocumentPermissions
{
    protected function grantDocumentPermissions(User $user, Document $document, array $permissions)
    {
        foreach ($permissions as $permission) {
            OpenFga::grant("user:{$user->id}", $permission, "document:{$document->id}");
        }
    }

    protected function createDocumentWithOwner(User $owner): Document
    {
        $document = Document::factory()->create();
        OpenFga::grant("user:{$owner->id}", 'owner', "document:{$document->id}");
        return $document;
    }
}
```

### Test Data Builders

```php
class DocumentTestBuilder
{
    private Document $document;
    private array $permissions = [];

    public function __construct()
    {
        $this->document = Document::factory()->create();
    }

    public function withViewer(User $user): self
    {
        $this->permissions[] = ["user:{$user->id}", 'viewer', $this->document->authorizationObject()];
        return $this;
    }

    public function withEditor(User $user): self
    {
        $this->permissions[] = ["user:{$user->id}", 'editor', $this->document->authorizationObject()];
        return $this;
    }

    public function build(): Document
    {
        if (!empty($this->permissions)) {
            OpenFga::writeBatch(writes: $this->permissions);
        }

        return $this->document;
    }
}
```

## Integration Testing

### Testing with Real OpenFGA

```php
namespace Tests\Integration;

use Tests\TestCase;

class RealOpenFgaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Skip if not in integration test environment
        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Integration tests disabled');
        }

        // Don't use fake - test against real OpenFGA
        // $this->fakeOpenFga(); // DON'T DO THIS
    }

    public function test_real_permission_check()
    {
        $user = User::factory()->create();
        $document = Document::factory()->create();

        // This will hit the real OpenFGA server
        $document->grant($user, 'editor');

        // Verify against real server
        $this->assertTrue($document->check($user, 'editor'));

        // Clean up
        $document->revoke($user, 'editor');
    }
}
```

## Next Steps

- Optimize with [Performance Guide](performance.md)
- See [Troubleshooting Guide](troubleshooting.md)
- Check the [API Reference](api-reference.md)
- Review [Example Application](https://github.com/openfga/laravel-example)
