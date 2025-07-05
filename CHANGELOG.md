# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-01-05

### Added

#### Core Features
- **OpenFGA Integration**: Complete integration with OpenFGA for fine-grained authorization in Laravel applications
- **Multi-connection Support**: Configure and use multiple OpenFGA instances simultaneously
- **Eloquent Model Integration**: `HasAuthorization` trait for seamless model-based permissions
- **Middleware Protection**: Route middleware for declarative authorization (`openfga`, `openfga.any`, `openfga.all`)
- **Blade Directives**: Authorization directives for view-level access control (`@can`, `@cannot`, `@canany`)
- **Artisan Commands**: CLI commands for managing permissions and debugging authorization
- **Queue Integration**: Asynchronous permission operations with Laravel's queue system
- **Advanced Caching**: Multi-tier caching with WriteBehindCache for performance optimization
- **OpenTelemetry Support**: Built-in observability with OpenTelemetry integration

#### Type Safety Enhancements
- **Strict typing throughout codebase**: All PHP files use `declare(strict_types=1)` for enhanced type safety
- **Comprehensive generic annotations**: Detailed PHPDoc type annotations with generics for arrays, collections, and return types
- **DTO (Data Transfer Object) pattern**: Introduced `PermissionCheckRequest` DTO for better type safety and structure
- **Interface contracts**: Enhanced interfaces with strict type declarations and comprehensive documentation

#### PHP 8.3+ Features
- **Readonly classes**: Implemented readonly DTOs for immutable data structures
- **Enhanced union types**: Leveraged union types for flexible parameter handling while maintaining type safety
- **Override attributes**: Added `#[Override]` attributes for better inheritance safety
- **Improved type declarations**: Utilized latest PHP 8.3 type system features for maximum safety

#### Developer Experience
- **Enhanced IDE support**: Comprehensive PHPDoc annotations enable better autocomplete and static analysis
- **Type-safe method chaining**: Fluent APIs with proper return type annotations
- **Comprehensive Example Application**: Full-featured example app demonstrating best practices
- **Testing Utilities**: `FakesOpenFga` trait and test helpers for easy testing
- **Docker Integration**: Optimized Docker setup for integration testing

#### Documentation
- **Complete API documentation**: All public methods fully documented with type information
- **Example application**: Comprehensive example showing real-world usage patterns
- **Migration guides**: Clear instructions for integrating into existing applications
- **Best practices guide**: Authorization patterns and performance optimization tips

### Technical Details

#### Static Analysis
- **PHPStan Level 2**: Configured for robust static analysis
- **Psalm Level 6**: Type checking with Psalm for additional safety
- **Rector support**: Automated code quality improvements and PHP version compliance
- **PHP-CS-Fixer**: Enforced PSR-12 coding standards

#### Testing
- **Unit Tests**: Comprehensive unit test coverage with Pest PHP
- **Integration Tests**: Full integration test suite with real OpenFGA instance
- **Example Tests**: Complete test suite for the example application
- **GitHub Actions**: Automated CI/CD pipeline for quality assurance

#### Performance
- **Efficient caching strategies**: Multi-tier caching with write-behind optimization
- **Batch operations**: Support for batch permission checks and writes
- **Connection pooling**: Efficient HTTP client management
- **Lazy loading**: Deferred service provider for faster boot times

### Security
- **Secure by default**: No credentials in code, environment-based configuration
- **Input validation**: Comprehensive validation of all authorization inputs
- **Error handling**: Graceful degradation with proper error messages

### Compatibility
- **Laravel**: 11.x and 12.x
- **PHP**: 8.3+
- **OpenFGA**: 1.x
- **PSR Standards**: PSR-4, PSR-12 compliant

[Unreleased]: https://github.com/evansims/openfga-laravel/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/evansims/openfga-laravel/releases/tag/v1.0.0
