# GEMINI.md

This file provides guidance to Google Gemini when working with code in this repository.

## Project Overview

This is the OpenFGA Laravel SDK - a Laravel package that provides complete integration of OpenFGA (Open Fine-Grained Authorization) and Auth0 FGA for Laravel applications. The package follows the principle: "Stop writing authorization logic. Start asking questions."

## Development Commands

### Testing

- `composer test` - Run the Pest test suite
- `vendor/bin/pest tests/ServiceProviderTest.php` - Run a specific test file
- `vendor/bin/pest --filter "test name"` - Run a specific test by name

### Code Quality

- `composer lint` - Run all linting tools (PHPStan, Psalm, Rector, PHP CS Fixer)
- `composer lint:fix` - Auto-fix code style issues and apply Rector rules
- `composer lint:phpstan` - Run PHPStan static analysis (level 2)
- `composer lint:psalm` - Run Psalm type checking (level 6)
- `composer lint:rector` - Check what Rector would change (dry-run)
- `composer lint:phpcs` - Check PHP CS Fixer violations (dry-run)

## Architecture

### Core Components

1. **Service Provider** (`src/OpenFgaServiceProvider.php`):

   - Registers OpenFGA client as singleton in Laravel container
   - Handles configuration publishing and merging
   - Supports multiple authentication methods (API token, client credentials)
   - Implements DeferrableProvider for performance

2. **Facade** (`src/Facades/OpenFga.php`):

   - Provides static access to OpenFGA client methods
   - Extensive IDE support through method annotations
   - Key methods: `check`, `batchCheck`, `expand`, `listObjects`, `listRelations`, `listUsers`, `write`

3. **Configuration** (`config/openfga.php`):
   - API URL, Store ID, and Authorization Model ID
   - Authentication credentials configuration
   - Retry and HTTP client settings

### Code Standards

- **PHP 8.3+** with strict typing (`declare(strict_types=1)`)
- Modern PHP features: constructor property promotion, readonly properties, `#[Override]` attributes
- Comprehensive Rector rules for code modernization
- PHPStan level 1 and Psalm level 1 for type safety
- Pest for testing with Laravel plugin
- For clarity, always use named parameters whenever possible (rather than positional parameters)

### Testing Approach

Tests are written using Pest PHP framework. Key test patterns:

- Service provider registration tests
- Facade accessibility tests
- Configuration binding verification
- Use `pest()` function for test definitions
- Laravel test helpers available through pest-plugin-laravel

## Development Guidelines

1. **Before Making Changes**: Run `composer lint` to ensure code quality baseline
2. **After Making Changes**: Run `composer lint:fix` then `composer lint` to verify all checks pass
3. **Testing**: Add tests for new functionality using Pest framework
4. **Type Safety**: Maintain strict typing and add proper PHPDoc annotations
5. **Laravel Integration**: Follow Laravel package development best practices
