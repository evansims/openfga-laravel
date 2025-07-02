<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Facade;
use OpenFGA\Laravel\Batch\BatchResult;
use OpenFGA\Laravel\OpenFgaManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Custom expectation to check if a value is a valid OpenFGA identifier
expect()->extend('toBeOpenFgaIdentifier', function (): void {
    $this->toBeString()
        ->toMatch('/^\w+:[a-zA-Z0-9._\-@+]+$/');
});

// Custom expectation to check if a value is a valid OpenFGA relation
expect()->extend('toBeOpenFgaRelation', function (): void {
    $this->toBeString()
        ->toMatch('/^[a-zA-Z]\w*$/');
});

// Custom expectation to check if a value is a valid OpenFGA type
expect()->extend('toBeOpenFgaType', function (): void {
    $this->toBeString()
        ->toMatch('/^[a-zA-Z]\w*$/');
});

// Custom expectation for permission tuples
expect()->extend('toBePermissionTuple', function (): void {
    $this->toBeArray()
        ->toHaveKeys(['user', 'relation', 'object'])
        ->and($this->value['user'])->toBeOpenFgaIdentifier()
        ->and($this->value['relation'])->toBeOpenFgaRelation()
        ->and($this->value['object'])->toBeOpenFgaIdentifier();
});

// Custom expectation for authorization models
expect()->extend('toBeAuthorizationModel', function (): void {
    $this->toBeArray()
        ->toHaveKey('schema_version')
        ->toHaveKey('type_definitions')
        ->and($this->value['type_definitions'])->toBeArray()
        ->each->toHaveKeys(['type', 'relations']);
});

// Custom expectation for batch results
expect()->extend('toBeBatchResult', function (): void {
    $this->toBeInstanceOf(BatchResult::class);

    expect($this->value->success)->toBeBool();
    expect($this->value->totalOperations)->toBeInt();
    expect($this->value->processedOperations)->toBeInt();
    expect($this->value->failedOperations)->toBeInt();
    expect($this->value->duration)->toBeFloat();
});

// Custom expectation for checking permissions
expect()->extend('toHavePermission', function (string $relation, string $object): void {
    $manager = app(OpenFgaManager::class);
    $result = $manager->check($this->value, $relation, $object);
    expect($result)->toBeTrue();
});

expect()->extend('toNotHavePermission', function (string $relation, string $object): void {
    $manager = app(OpenFgaManager::class);
    $result = $manager->check($this->value, $relation, $object);
    expect($result)->toBeFalse();
});

// Custom expectation for collections of tuples
expect()->extend('toBeValidTupleCollection', function (): void {
    $this->toBeArray()
        ->each(static fn ($tuple) => $tuple->toBePermissionTuple());
});

// Custom expectation for response times
expect()->extend('toBeWithinMilliseconds', function (float $milliseconds): void {
    $this->toBeFloat()
        ->toBeLessThanOrEqual($milliseconds);
});

// Custom expectation for Laravel service registrations
expect()->extend('toBeRegistered', function (string $app_key): void {
    $this->toBeInstanceOf(Application::class);
    expect($this->value->bound($app_key))->toBeTrue();
});

// Custom expectation for Laravel configuration
expect()->extend('toHaveConfigKey', function (string $key, mixed $expected = null): void {
    $this->toBeInstanceOf(Application::class);
    expect($this->value['config']->has($key))->toBeTrue();

    if (null !== $expected) {
        expect($this->value['config']->get($key))->toBe($expected);
    }
});

// Custom expectation for middleware responses
expect()->extend('toBeMiddlewareResponse', function (int $status = 200): void {
    $this->toBeInstanceOf(Response::class)
        ->and($this->value->getStatusCode())->toBe($status);
});

// Custom expectation for permission errors
expect()->extend('toBePermissionError', function (?string $expectedMessage = null): void {
    $this->toBeInstanceOf(HttpException::class)
        ->and($this->value->getStatusCode())->toBe(403);

    if (null !== $expectedMessage) {
        expect($this->value->getMessage())->toBe($expectedMessage);
    }
});

// Custom expectation for authorization results
expect()->extend('toBeAuthorizationResult', function (bool $expected): void {
    $this->toBeBool()->toBe($expected);
});

// Custom expectation for OpenFGA command signatures
expect()->extend('toBeValidCommandSignature', function (): void {
    $this->toBeInstanceOf(Command::class);
    expect($this->value->getName())->toBeString()->toMatch('/^openfga:/');
    expect($this->value->getDescription())->toBeString()->not->toBeEmpty();
});

// Custom expectation for cache entries
expect()->extend('toBeCacheEntry', function (?string $expectedKey = null): void {
    $this->toBeArray();

    if (null !== $expectedKey) {
        expect($this->value)->toHaveKey($expectedKey);
    }
});

// Custom expectation for JSON API responses
expect()->extend('toBeJsonApiResponse', function (int $status = 200): void {
    $this->toBeInstanceOf(JsonResponse::class)
        ->and($this->value->getStatusCode())->toBe($status)
        ->and($this->value->headers->get('Content-Type'))->toContain('application/json');
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// Include shared test helpers
require_once __DIR__ . '/Support/AuthTestHelpers.php';

require_once __DIR__ . '/Support/TestHelpers.php';

// Import helper functions from Support directory
use function OpenFGA\Laravel\Tests\Support\{
    clearTestResources,
    createTestAuthorizationModel,
    sequence
};

/*
|--------------------------------------------------------------------------
| Global Hooks
|--------------------------------------------------------------------------
|
| These hooks are executed for all tests. You can use them to set up and tear down
| resources that are shared across all your tests.
|
*/

// Ensure facades are reset after each test
// Global before each hook
beforeEach(static function (): void {
    // Reset any global state
    if (app()->bound('openfga.test.scenario')) {
        app()->forgetInstance('openfga.test.scenario');
    }
});

afterEach(static function (): void {
    // Clear all resolved facade instances
    Facade::clearResolvedInstances();

    // Close Mockery to ensure all expectations are verified
    if (class_exists(Mockery::class)) {
        Mockery::close();
    }

    // Clean up any test resources
    defer(static fn () => clearTestResources());
});

/*
|--------------------------------------------------------------------------
| Global Datasets
|--------------------------------------------------------------------------
|
| These datasets can be used across multiple test files to provide consistent
| test data and reduce duplication.
|
*/

// Common OpenFGA identifiers for testing
dataset('users', [
    'basic_user' => 'user:123',
    'admin_user' => 'user:admin',
    'guest_user' => 'user:guest',
    'special_user' => 'user:special-chars_123',
]);

dataset('objects', [
    'document' => 'document:456',
    'project' => 'project:789',
    'folder' => 'folder:abc',
    'organization' => 'organization:xyz',
]);

dataset('relations', [
    'owner',
    'editor',
    'viewer',
    'admin',
    'member',
]);

dataset('permission_tuples', static fn (): array => [
    ['user:123', 'owner', 'document:456'],
    ['user:123', 'editor', 'document:456'],
    ['user:456', 'viewer', 'project:789'],
    ['user:admin', 'admin', 'organization:xyz'],
]);

// Advanced datasets using sequences
dataset('batch_sizes', sequence(1, 10, 50, 100, 500));

dataset('performance_scenarios', [
    'small' => ['tuples' => 10, 'max_ms' => 50],
    'medium' => ['tuples' => 100, 'max_ms' => 200],
    'large' => ['tuples' => 1000, 'max_ms' => 1000],
]);

dataset('authorization_models', static function () {
    yield 'simple' => createTestAuthorizationModel();

    yield 'complex' => createTestAuthorizationModel([
        [
            'type' => 'organization',
            'relations' => [
                'admin' => ['this' => []],
                'member' => ['this' => [], 'computedUserset' => ['relation' => 'admin']],
            ],
        ],
        [
            'type' => 'document',
            'relations' => [
                'owner' => ['this' => []],
                'editor' => ['this' => [], 'computedUserset' => ['relation' => 'owner']],
                'viewer' => [
                    'union' => [
                        'child' => [
                            ['this' => []],
                            ['computedUserset' => ['relation' => 'editor']],
                            ['tupleToUserset' => [
                                'tupleset' => ['relation' => 'organization'],
                                'computedUserset' => ['relation' => 'member'],
                            ]],
                        ],
                    ],
                ],
                'organization' => ['this' => []],
            ],
        ],
    ]);
});

dataset('invalid_identifiers', [
    'empty_string' => '',
    'no_colon' => 'invalid',
    'multiple_colons' => 'user:sub:123',
    'special_chars' => 'user:@#$%',
]);

/*
|--------------------------------------------------------------------------
| Test Groups
|--------------------------------------------------------------------------
|
| You may use the uses() function to bind groups to specific directories or files.
| This allows you to run specific groups of tests using the --group flag.
|
*/

// Group tests by feature area
uses()->group('middleware')->in('Unit/Http/Middleware');
uses()->group('commands')->in('Unit/Console/Commands');
uses()->group('cache')->in('Unit/Cache');
uses()->group('testing')->in('Unit/Testing');
uses()->group('integration')->in('Integration');
uses()->group('batch')->in('Unit/Batch');
uses()->group('authorization')->in('Unit/Authorization');
uses()->group('traits')->in('Unit/Traits');
uses()->group('performance')->in('Unit/Monitoring', 'Unit/Profiling');
// Manually group architecture tests
pest()->group('architecture');
