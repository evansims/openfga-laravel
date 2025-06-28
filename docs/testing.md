# Testing with OpenFGA Laravel

This comprehensive guide covers how to effectively test applications that use OpenFGA for authorization, including unit tests, integration tests, and end-to-end testing strategies.

## Table of Contents

- [Getting Started](#getting-started)
- [Testing Utilities Overview](#testing-utilities-overview)
- [Basic Testing](#basic-testing)
- [Testing with Models](#testing-with-models)
- [Advanced Testing Features](#advanced-testing-features)
- [Testing Failures and Errors](#testing-failures-and-errors)
- [Testing HTTP Requests](#testing-http-requests)
- [Testing Events](#testing-events)
- [Common Testing Scenarios](#common-testing-scenarios)
- [Testing Utilities](#testing-utilities)
- [Performance Testing](#performance-testing)
- [Test Organization](#test-organization)
- [Integration Testing](#integration-testing)
- [Testing Best Practices](#testing-best-practices)
- [Troubleshooting](#troubleshooting)

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

## Testing Utilities Overview

The package provides several testing utilities to make testing authorization logic straightforward and reliable:

### 1. FakesOpenFga Trait

Replaces the real OpenFGA service with a fake implementation for testing:

```php
use OpenFGA\Laravel\Testing\FakesOpenFga;

class MyTest extends TestCase
{
    use FakesOpenFga;

    public function test_authorization()
    {
        $fake = $this->fakeOpenFga();

        // Your test code here
    }
}
```

### 2. CreatesPermissionData Trait

Provides pre-built permission scenarios for common testing needs:

```php
use OpenFGA\Laravel\Testing\CreatesPermissionData;

class MyTest extends TestCase
{
    use FakesOpenFga, CreatesPermissionData;

    public function test_blog_permissions()
    {
        $fake = $this->fakeOpenFga();
        $data = $this->createBlogSystem($fake);

        // Test with pre-configured blog permission structure
        $this->assertTrue($fake->check($data['users']['admin'], 'admin', $data['blog']));
    }
}
```

### 3. AssertionHelper Class

Provides specialized assertions for permission testing:

```php
use OpenFGA\Laravel\Testing\AssertionHelper;

// Assert user has specific permission
AssertionHelper::assertUserHasPermission($fake, 'user:123', 'read', 'document:456');

// Assert user has any of multiple permissions
AssertionHelper::assertUserHasAnyPermission($fake, 'user:123', ['read', 'write'], 'document:456');

// Assert user has all permissions
AssertionHelper::assertUserHasAllPermissions($fake, 'user:123', ['read', 'write'], 'document:456');
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

## Common Testing Scenarios

The `CreatesPermissionData` trait provides pre-built scenarios for common authorization patterns. Here are some examples:

### Blog System Testing

```php
public function test_blog_author_permissions()
{
    $fake = $this->fakeOpenFga();
    $data = $this->createBlogSystem($fake);

    // Author can edit their own posts
    $this->assertTrue($fake->check($data['users']['author1'], 'author', $data['posts']['post1']));

    // Author cannot edit other's posts
    $this->assertFalse($fake->check($data['users']['author1'], 'author', $data['posts']['post2']));

    // Editor can edit all posts
    $this->assertTrue($fake->check($data['users']['editor'], 'editor', $data['blog']));

    // Subscribers can read posts
    $this->assertTrue($fake->check($data['users']['subscriber'], 'reader', $data['posts']['post1']));
}
```

### File System Testing

```php
public function test_file_system_permissions()
{
    $fake = $this->fakeOpenFga();
    $data = $this->createFileSystem($fake);

    // Users can access their home directories
    AssertionHelper::assertUserHasAllPermissions(
        $fake,
        $data['users']['user1'],
        ['read', 'write', 'execute'],
        $data['folders']['user1_home']
    );

    // Users cannot access other home directories
    AssertionHelper::assertUserDoesNotHavePermission(
        $fake,
        $data['users']['user1'],
        'read',
        $data['folders']['user2_home']
    );

    // Shared files are accessible to all
    AssertionHelper::assertUserHasAccessToObjects(
        $fake,
        $data['users']['guest'],
        'read',
        [$data['files']['shared_file']]
    );
}
```

### E-commerce Testing

```php
public function test_ecommerce_permissions()
{
    $fake = $this->fakeOpenFga();
    $data = $this->createEcommerceSystem($fake);

    // Customers can view their own orders
    $this->assertTrue($fake->check($data['users']['customer1'], 'view', $data['orders']['order1']));

    // Support can view all orders
    AssertionHelper::assertUserHasAccessToObjects(
        $fake,
        $data['users']['support'],
        'view',
        array_values($data['orders'])
    );

    // Vendors can manage their products
    $this->assertTrue($fake->check($data['users']['vendor1'], 'manage', $data['products']['product1']));
    $this->assertFalse($fake->check($data['users']['vendor1'], 'manage', $data['products']['product3']));
}
```

### Organization Structure Testing

```php
public function test_organization_hierarchy()
{
    $fake = $this->fakeOpenFga();
    $data = $this->createOrganizationStructure($fake);

    // CEO has admin access to organization
    $this->assertTrue($fake->check($data['users']['ceo'], 'admin', $data['organization']));

    // Department managers can manage their departments
    $this->assertTrue($fake->check($data['users']['hr_manager'], 'manager', $data['departments']['hr']));
    $this->assertFalse($fake->check($data['users']['hr_manager'], 'manager', $data['departments']['it']));

    // Project contributors have appropriate access
    $this->assertTrue($fake->check($data['users']['developer'], 'contributor', $data['projects']['project1']));
}
```

### Complex Hierarchy Testing

```php
public function test_nested_hierarchy_permissions()
{
    $fake = $this->fakeOpenFga();
    $data = $this->createNestedHierarchy($fake);

    // Super admin has top-level access
    $this->assertTrue($fake->check($data['users']['super_admin'], 'super_admin', $data['hierarchy']['company']));

    // Managers have appropriate scope
    $this->assertTrue($fake->check($data['users']['dept_manager'], 'manager', $data['hierarchy']['department']));
    $this->assertTrue($fake->check($data['users']['team_lead'], 'lead', $data['hierarchy']['team']));

    // Contributors have project access
    AssertionHelper::assertUserHasAllPermissions(
        $fake,
        $data['users']['employee'],
        ['contributor'],
        $data['hierarchy']['project']
    );
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

## Testing Best Practices

### 1. Isolate Tests

Always use the fake implementation for unit tests to ensure isolation:

```php
public function test_isolated_permission_check()
{
    $fake = $this->fakeOpenFga();

    // Each test starts with a clean slate
    $this->assertNoPermissionChecks();
}
```

### 2. Test Permission Boundaries

Test both positive and negative cases:

```php
public function test_permission_boundaries()
{
    $fake = $this->fakeOpenFga();

    // Grant specific permission
    $fake->grant('user:123', 'read', 'document:456');

    // Test granted permission
    $this->assertTrue($fake->check('user:123', 'read', 'document:456'));

    // Test different user (should fail)
    $this->assertFalse($fake->check('user:999', 'read', 'document:456'));

    // Test different permission (should fail)
    $this->assertFalse($fake->check('user:123', 'write', 'document:456'));

    // Test different object (should fail)
    $this->assertFalse($fake->check('user:123', 'read', 'document:999'));
}
```

### 3. Test Complex Scenarios

Use the permission data creators for complex scenarios:

```php
public function test_complex_organization_permissions()
{
    $fake = $this->fakeOpenFga();
    $data = $this->createOrganizationStructure($fake);

    // Test CEO has admin access to organization
    $this->assertTrue($fake->check($data['users']['ceo'], 'admin', $data['organization']));

    // Test department managers can manage their departments
    $this->assertTrue($fake->check($data['users']['hr_manager'], 'manager', $data['departments']['hr']));
    $this->assertFalse($fake->check($data['users']['hr_manager'], 'manager', $data['departments']['it']));
}
```

### 4. Verify No Unexpected Checks

Ensure your code doesn't make unnecessary permission checks:

```php
public function test_no_redundant_permission_checks()
{
    $fake = $this->fakeOpenFga();

    // Perform action that should only check once
    $service = new DocumentService();
    $service->getPublicDocuments();

    // Assert exactly the expected number of checks
    $this->assertPermissionCheckCount(1);
}
```

### 5. Test Cache Behavior

Verify that caching works as expected:

```php
public function test_permission_checks_are_cached()
{
    $fake = $this->fakeOpenFga();
    $user = User::factory()->create();
    $document = Document::factory()->create();

    // Grant permission
    $fake->grant("user:{$user->id}", 'viewer', "document:{$document->id}");

    // First check
    $document->check($user, 'viewer');

    // Second check should hit cache (if implemented)
    $document->check($user, 'viewer');

    // Verify only one actual check was made
    $this->assertPermissionCheckCount(1);
}
```

### 6. Use Descriptive Test Names

Make test intentions clear:

```php
public function test_document_owner_can_grant_editor_permissions_to_other_users()
{
    // Test is self-documenting from the name
}

public function test_non_owner_cannot_grant_permissions_and_receives_403_error()
{
    // Clear what should happen
}
```

### 7. Group Related Tests

Organize tests logically:

```php
class DocumentPermissionTest extends TestCase
{
    // All document-related permission tests
}

class UserRoleTest extends TestCase
{
    // All user role tests
}
```

## Troubleshooting

### Common Issues

#### 1. Fake Not Active

```
Error: OpenFGA fake is not active. Call fakeOpenFga() first.
```

**Solution**: Always call `$this->fakeOpenFga()` before making assertions:

```php
public function test_something()
{
    $fake = $this->fakeOpenFga(); // Must call this first

    // Now you can use assertions
    $this->assertNoPermissionChecks();
}
```

#### 2. Permission Not Found

```
Error: Failed asserting that permission was granted
```

**Solution**: Ensure you're using exact string matching for user, relation, and object:

```php
// Wrong - inconsistent formatting
$fake->grant('user:123', 'read', 'document:456');
$this->assertPermissionGranted('user:123', 'read', 'document:457'); // Different ID

// Correct
$fake->grant('user:123', 'read', 'document:456');
$this->assertPermissionGranted('user:123', 'read', 'document:456');
```

#### 3. Unexpected Permission Checks

Use the assertion helpers to debug what checks are being made:

```php
public function test_debug_checks()
{
    $fake = $this->fakeOpenFga();

    // Your code here

    // Debug what checks were made
    $checks = $fake->getChecks();
    dd($checks); // See all permission checks that occurred
}
```

### Testing Environment Setup

For consistent testing, configure your test environment:

```php
// tests/TestCase.php
protected function setUp(): void
{
    parent::setUp();

    // Always use fake in tests by default
    if (!app()->environment('production')) {
        $this->fakeOpenFga();
    }
}
```

### Test Data Management

Create reusable test data:

```php
// tests/Fixtures/PermissionFixtures.php
class PermissionFixtures
{
    public static function grantBasicDocumentPermissions(FakeOpenFga $fake, User $user, Document $document): void
    {
        $fake->grant("user:{$user->id}", 'read', "document:{$document->id}");
        $fake->grant("user:{$user->id}", 'write', "document:{$document->id}");
    }
}
```

## Next Steps

- Optimize with [Performance Guide](performance.md)
- See [Troubleshooting Guide](troubleshooting.md)
- Check the [API Reference](api-reference.md)
- Review [Example Application](https://github.com/evansims/openfga-laravel)

## Additional Resources

- [OpenFGA Documentation](https://openfga.dev/docs)
- [Laravel Testing Documentation](https://laravel.com/docs/testing)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Pest PHP Testing Framework](https://pestphp.com/)

For more advanced testing scenarios and examples, see the `tests/` directory in this package for comprehensive test suites covering all features.
