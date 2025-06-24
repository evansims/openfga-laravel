# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

#### Type Safety Enhancements
- **Strict typing throughout codebase**: All PHP files now use `declare(strict_types=1)` for enhanced type safety
- **Comprehensive generic annotations**: Added detailed PHPDoc type annotations with generics for arrays, collections, and return types
- **DTO (Data Transfer Object) pattern**: Introduced `PermissionCheckRequest` DTO to replace associative arrays, providing better type safety and structure
- **Interface contracts**: Enhanced interfaces with strict type declarations and comprehensive documentation

#### PHP 8.3+ Features
- **Readonly classes**: Implemented readonly DTOs for immutable data structures
- **Enhanced union types**: Leveraged union types for flexible parameter handling while maintaining type safety
- **Override attributes**: Added `#[Override]` attributes for better inheritance safety
- **Improved type declarations**: Utilized latest PHP 8.3 type system features for maximum safety

#### Developer Experience
- **Enhanced IDE support**: Comprehensive PHPDoc annotations enable better autocomplete and static analysis
- **Type-safe method chaining**: Fluent APIs with proper return type annotations
- **Strict parameter validation**: Runtime type checking combined with static analysis
- **Exception handling improvements**: Type-safe error handling with proper exception hierarchies

### Enhanced

#### Core Components
- **OpenFgaManager**: Added strict typing for all manager operations with comprehensive generics
- **Authorization Gate**: Enhanced gate implementation with template types and strict checking
- **Model Traits**: Improved HasAuthorization trait with type-safe method signatures
- **Cache Layer**: Type-safe caching implementation with proper key typing

#### API Signatures
```php
/**
 * Batch check multiple permissions at once.
 *
 * @param array<int, array{user: string, relation: string, object: string}> $checks
 * @param string|null $connection Optional connection name
 * @return array<string, bool> Keyed by "user:relation:object"
 */
public function batchCheck(array $checks, ?string $connection = null): array

/**
 * Check if a user has a specific permission.
 *
 * @param string $user
 * @param string $relation  
 * @param string $object
 * @param array<array{user: string, relation: string, object: string}|TupleKey> $contextualTuples
 * @param array<string, mixed> $context
 * @param string|null $connection
 */
public function check(
    string $user,
    string $relation, 
    string $object,
    array $contextualTuples = [],
    array $context = [],
    ?string $connection = null,
): bool
```

#### Model Integration
```php
/**
 * Grant multiple permissions to multiple users.
 *
 * @param array<int|Model|string> $users The users to grant permissions to
 * @param array<string>|string $relations The relations/permissions to grant
 */
public function grantMany(array $users, array|string $relations): bool

/**
 * Get all relations a user has with this model.
 *
 * @param int|Model|string $user The user to check
 * @param array<string> $relations Optional relation filters
 * @return array<string, bool>
 */
public function getUserRelations($user, array $relations = []): array
```

### Technical Improvements

#### Static Analysis
- **PHPStan Level 8**: Configured for maximum static analysis strictness
- **Psalm integration**: Added comprehensive type checking with Psalm
- **Rector support**: Automated code quality improvements and PHP version compliance
- **PHP-CS-Fixer**: Enforced consistent coding standards

#### Testing Enhancements
- **Type-safe test utilities**: Enhanced testing framework with proper type annotations
- **Mock improvements**: Better type safety in test doubles and fakes
- **Assertion helpers**: Type-safe assertion methods for authorization testing

### Breaking Changes

**None Expected** - All type safety improvements are backward compatible. Existing code will continue to work without modification.

### Migration Notes

While no breaking changes are expected, developers can benefit from the enhanced type safety by:

1. **Enabling strict typing in your models**:
   ```php
   <?php
   
   declare(strict_types=1);
   
   namespace App\Models;
   
   use OpenFGA\Laravel\Traits\HasAuthorization;
   ```

2. **Using the new DTO pattern**:
   ```php
   use OpenFGA\Laravel\DTOs\PermissionCheckRequest;
   
   $request = PermissionCheckRequest::fromUser(
       user: $user,
       relation: 'editor', 
       object: 'document:123'
   );
   ```

3. **Leveraging enhanced type hints**:
   ```php
   /** @var array<string, bool> $permissions */
   $permissions = $model->getUserRelations($user, ['editor', 'viewer']);
   ```

### Performance Improvements

- **Reduced runtime type checking overhead** through compile-time guarantees
- **Better opcode optimization** via strict type declarations  
- **Enhanced caching efficiency** with type-safe cache keys

---

## [1.0.0] - Initial Release

### Added
- Core OpenFGA integration for Laravel
- Multi-connection support
- Eloquent model integration with HasAuthorization trait
- Middleware for route protection
- Blade directives for view-level authorization
- Comprehensive testing utilities
- Artisan commands for CLI management
- Advanced caching and queue support
- Complete documentation

[Unreleased]: https://github.com/evansims/openfga-laravel/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/evansims/openfga-laravel/releases/tag/v1.0.0
