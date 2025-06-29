# Test Support Utilities

This directory contains utilities to make tests more maintainable, consistent, and easier to write.

## Overview

The test support utilities were created to address common maintainability issues:

- **Code Duplication**: Repeated test setup, data creation, and mock configurations
- **Hard-coded Values**: Magic numbers and strings scattered throughout tests
- **Complex Setup**: Verbose test initialization that obscures test intent
- **Inconsistent Patterns**: Different approaches to similar testing scenarios

## Core Utilities

### TestFactories

**Purpose**: Create test objects consistently without duplicating anonymous class definitions.

**Location**: `tests/Support/TestFactories.php`

**Usage**:
```php
// Instead of creating anonymous classes:
$user = new class implements Authenticatable, AuthorizationUser {
    // 20+ lines of boilerplate...
};

// Use the factory:
$user = TestFactories::createTestUser(authId: 'user:123', identifier: 123);
$document = TestFactories::createTestDocument(objectId: 'document:456');
$organization = TestFactories::createTestOrganization();
```

**Available Methods**:
- `createTestUser()` - User with authentication and authorization interfaces
- `createTestDocument()` - Document model with authorization interface
- `createTestOrganization()` - Organization model
- `createTestUserWithObject()` - User that's also an authorization object
- `createMockManager()` - Mock OpenFGA manager
- `createMockClient()` - Mock OpenFGA client
- `createPermissionTuple()` - Single permission tuple
- `createPermissionTuples()` - Multiple permission tuples
- `createAuthorizationModel()` - Test authorization model

### TestConfigBuilder

**Purpose**: Build test configurations using a fluent API instead of large arrays.

**Location**: `tests/Support/TestConfigBuilder.php`

**Usage**:
```php
// Instead of large configuration arrays:
$config = [
    'url' => 'http://localhost:8080',
    'store_id' => 'test-store',
    'credentials' => ['method' => 'api_token', 'token' => 'test-token'],
    'cache' => ['enabled' => true, 'ttl' => 300],
    // ... many more lines
];

// Use the builder:
$config = TestConfigBuilder::create()
    ->withUrl('http://localhost:8080')
    ->withStoreId('test-store')
    ->withTokenAuth('test-token')
    ->withCache(enabled: true, ttl: 300)
    ->build();
```

**Available Methods**:
- `withUrl()` - Set API URL
- `withStoreId()` / `withModelId()` - Set store/model IDs
- `withNoAuth()` / `withTokenAuth()` / `withClientCredentials()` - Authentication
- `withCache()` / `withoutCache()` - Cache configuration
- `withQueue()` / `withoutQueue()` - Queue configuration
- `withRetries()` - Retry settings
- `withTimeout()` - HTTP timeout
- `withConnectionPool()` - Connection pooling
- `buildAsConnection()` - Build as connection configuration

### TestConstants

**Purpose**: Centralized constants to eliminate hard-coded values throughout tests.

**Location**: `tests/Support/TestConstants.php`

**Usage**:
```php
// Instead of hard-coded values scattered everywhere:
$user = 'user:123';
$document = 'document:456';
$timeout = 10;

// Use centralized constants:
$user = TestConstants::DEFAULT_USER_ID;
$document = TestConstants::DEFAULT_DOCUMENT_ID;
$timeout = TestConstants::DEFAULT_TIMEOUT;
```

**Available Constants**:
- **Identifiers**: `DEFAULT_USER_ID`, `DEFAULT_DOCUMENT_ID`, etc.
- **Relations**: `RELATION_OWNER`, `RELATION_EDITOR`, `RELATION_VIEWER`
- **URLs**: `DEFAULT_API_URL`, `ALTERNATIVE_API_URL`
- **Timeouts**: `DEFAULT_TIMEOUT`, `SHORT_TIMEOUT`, `LONG_TIMEOUT`
- **Cache Settings**: `DEFAULT_CACHE_TTL`, `SHORT_CACHE_TTL`
- **Timestamps**: `FIXED_TIMESTAMP` (for deterministic testing)

### MockScenarios

**Purpose**: Pre-built mock scenarios for common testing patterns.

**Location**: `tests/Support/MockScenarios.php`

**Usage**:
```php
// Instead of setting up complex mocks manually:
$manager = Mockery::mock(ManagerInterface::class);
$manager->shouldReceive('check')->andReturn(true);
$manager->shouldReceive('grant')->andReturn(true);
// ... many more lines

// Use pre-built scenarios:
$manager = MockScenarios::managerAlwaysAllows();
$manager = MockScenarios::managerAlwaysDenies();
$manager = MockScenarios::managerThrowsExceptions();
```

**Available Scenarios**:
- `managerAlwaysAllows()` - Manager that always permits
- `managerAlwaysDenies()` - Manager that always denies
- `managerThrowsExceptions()` - Manager that throws exceptions
- `managerWithMixedResults()` - Manager with varied responses
- `clientWithStandardResponses()` - Client with typical API responses
- `cacheAlwaysHits()` / `cacheAlwaysMisses()` - Cache behaviors
- `managerExpectingCalls()` - Manager with specific expectations

### TestSetup

**Purpose**: Common setup patterns and environment configuration.

**Location**: `tests/Support/TestSetup.php`

**Usage**:
```php
// Simplified environment setup:
TestSetup::configureOpenFga(['url' => 'http://test-server']);
TestSetup::configureCache('array');
TestSetup::configureAuth($testUser);
TestSetup::setupRoute($request, '/test/{id}', ['id' => '123']);
```

**Available Methods**:
- `configureOpenFga()` - Set up OpenFGA configuration
- `configureCache()` / `configureQueue()` - Service configuration
- `setupRoute()` - Route parameter setup
- `setupIntegrationTesting()` - Integration test environment
- `cleanupTestEnvironment()` - Test cleanup
- `waitForConsistency()` - Handle eventual consistency
- `skipOnCI()` / `skipIfOpenFgaUnavailable()` - Conditional test skipping

## Migration Guide

### Replacing Anonymous Classes

**Before**:
```php
$user = new class implements Authenticatable, AuthorizationUser {
    public function authorizationUser(): string { return 'user:123'; }
    public function getAuthIdentifier(): mixed { return 123; }
    // ... 15+ more methods
};
```

**After**:
```php
$user = TestFactories::createTestUser(authId: 'user:123', identifier: 123);
```

### Replacing Hard-coded Values

**Before**:
```php
$this->manager->shouldReceive('check')
    ->with('user:123', 'viewer', 'document:456')
    ->andReturn(true);
```

**After**:
```php
$this->manager = MockScenarios::managerExpectingCalls([
    'check' => [
        'with' => [TestConstants::DEFAULT_USER_ID, TestConstants::RELATION_VIEWER, TestConstants::DEFAULT_DOCUMENT_ID],
        'andReturn' => true,
    ],
]);
```

### Replacing Configuration Arrays

**Before**:
```php
config(['openfga' => [
    'default' => 'test',
    'connections' => [
        'test' => [
            'url' => 'http://localhost:8080',
            'store_id' => null,
            'credentials' => ['method' => 'none'],
            // ... many more lines
        ],
    ],
]]);
```

**After**:
```php
TestSetup::configureOpenFga(
    TestConfigBuilder::create()
        ->withUrl(TestConstants::DEFAULT_API_URL)
        ->withNoAuth()
        ->build()
);
```

## Best Practices

### 1. Use Constants for Test Data
Always use `TestConstants` instead of hard-coding values:
```php
// Good
$user = TestConstants::DEFAULT_USER_ID;

// Bad
$user = 'user:123';
```

### 2. Use Factories for Object Creation
Prefer factories over anonymous classes:
```php
// Good
$user = TestFactories::createTestUser();

// Bad
$user = new class implements AuthorizationUser { /* ... */ };
```

### 3. Use Builders for Configuration
Use `TestConfigBuilder` for complex configurations:
```php
// Good
$config = TestConfigBuilder::create()
    ->withTokenAuth('test-token')
    ->withCache()
    ->build();

// Bad
$config = [
    'credentials' => ['method' => 'api_token', 'token' => 'test-token'],
    'cache' => ['enabled' => true, 'ttl' => 300],
];
```

### 4. Use Mock Scenarios
Prefer pre-built scenarios over manual mock setup:
```php
// Good
$manager = MockScenarios::managerAlwaysAllows();

// Bad
$manager = Mockery::mock(ManagerInterface::class);
$manager->shouldReceive('check')->andReturn(true);
// ... more setup
```

### 5. Use Setup Helpers
Use `TestSetup` for common initialization patterns:
```php
// Good
TestSetup::configureOpenFga();
TestSetup::setupIntegrationTesting();

// Bad
// 10+ lines of manual configuration
```

## Benefits

Using these utilities provides:

1. **Reduced Duplication**: Common patterns are abstracted into reusable utilities
2. **Consistency**: Standardized approach to test data and configuration
3. **Maintainability**: Changes to interfaces or contracts only require updates in one place
4. **Readability**: Test intent is clearer without boilerplate setup code
5. **Reliability**: Pre-tested utilities reduce the chance of test configuration errors
6. **Flexibility**: Builder patterns and configurable factories adapt to different test needs

## Future Improvements

Consider these enhancements as the test suite grows:

1. **Test Data Builders**: More sophisticated builders for complex test scenarios
2. **Custom Expectations**: Domain-specific test expectations for OpenFGA concepts
3. **Performance Helpers**: Utilities for performance and load testing
4. **Integration Helpers**: More sophisticated integration test utilities
5. **Documentation Generation**: Auto-generate test documentation from these utilities