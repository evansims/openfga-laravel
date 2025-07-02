<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Mockery;
use Mockery\MockInterface;
use OpenFGA\Laravel\Contracts\{ManagerInterface};

use function sprintf;

// Helper function to create test store ID
function createTestStoreId(string $suffix = ''): string
{
    static $counter = 0;
    ++$counter;

    return 'store_' . getmypid() . '_' . $counter . ('' !== $suffix && '0' !== $suffix ? '_' . $suffix : '');
}

// Helper function to create test model ID
function createTestModelId(string $suffix = ''): string
{
    static $counter = 0;
    ++$counter;

    return 'model_' . getmypid() . '_' . $counter . ('' !== $suffix && '0' !== $suffix ? '_' . $suffix : '');
}

// Helper function to create permission tuples
function createPermissionTuple(string $user, string $relation, string $object): array
{
    return [
        'user' => $user,
        'relation' => $relation,
        'object' => $object,
    ];
}

// Helper function to create multiple permission tuples
function createPermissionTuples(array $tuples): array
{
    return array_map(
        callback: static fn (array $tuple): array => createPermissionTuple(user: $tuple[0], relation: $tuple[1], object: $tuple[2]),
        array: $tuples,
    );
}

// Helper function to create a test authorization model
function createTestAuthorizationModel(array $typeDefinitions = []): array
{
    return [
        'schema_version' => '1.1',
        'type_definitions' => $typeDefinitions ?: [
            [
                'type' => 'document',
                'relations' => [
                    'owner' => ['this' => []],
                    'editor' => ['this' => [], 'computed_userset' => ['object' => '', 'relation' => 'owner']],
                    'viewer' => ['this' => [], 'computed_userset' => ['object' => '', 'relation' => 'editor']],
                ],
            ],
        ],
    ];
}

// Helper function to mock OpenFGA manager
function mockOpenFgaManager(): MockInterface
{
    return Mockery::mock(ManagerInterface::class);
}

// Helper function to assert batch operation results
function assertBatchResult(array $result, int $expectedTotal, int $expectedProcessed, bool $shouldSucceed = true): void
{
    expect($result)->toBeBatchResult();
    expect($result['totalOperations'])->toBe($expectedTotal);
    expect($result['processedOperations'])->toBe($expectedProcessed);
    expect($result['success'])->toBe($shouldSucceed);
}

// Helper function for testing command output
function expectCommandOutput(string $output, string $expectedPattern): void
{
    expect($output)->toMatch($expectedPattern);
}

// Helper function to create OpenFGA write tuples
function createWriteTuple(string $user, string $relation, string $object): array
{
    return [
        'user' => $user,
        'relation' => $relation,
        'object' => $object,
    ];
}

// Helper function to create batch write operations
function createBatchWriteOperations(array $tuples): array
{
    return array_map(
        callback: static fn ($tuple): array => [
            'write' => $tuple,
        ],
        array: $tuples,
    );
}

// Higher-order testing helper
function itChecksPermission(string $user, string $relation, string $object, bool $expected = true): void
{
    test(sprintf('user %s ', $user) . ($expected ? 'has' : 'does not have') . sprintf(' %s permission on %s', $relation, $object))
        ->expect($user)
        ->{$expected ? 'toHavePermission' : 'toNotHavePermission'}($relation, $object);
}

// Sequence helper for cleaner data providers
function sequence(...$values): array
{
    return $values;
}

// Skip conditions
function skipOnCI(string $reason = 'Skipped on CI'): void
{
    if (env(key: 'CI', default: false)) {
        test()->markTestSkipped($reason);
    }
}

function skipIfOpenFgaUnavailable(): void
{
    try {
        $url = env(key: 'OPENFGA_TEST_URL', default: 'http://localhost:8080');
        $context = stream_context_create(options: ['http' => ['timeout' => 1]]);

        if (false === @file_get_contents(filename: $url . '/stores', use_include_path: false, context: $context)) {
            test()->markTestSkipped('OpenFGA server is not available');
        }
    } catch (Exception $exception) {
        test()->markTestSkipped('OpenFGA server is not available: ' . $exception->getMessage());
    }
}

// Performance testing helpers
function measurePerformance(Closure $callback): array
{
    $start = microtime(true);
    $result = $callback();
    $duration = (microtime(true) - $start) * 1000;

    return [
        'result' => $result,
        'duration_ms' => $duration,
    ];
}

// Resource cleanup helper
function clearTestResources(): void
{
    // Clear any test stores created
    if (app()->bound('test.created_stores')) {
        $stores = app('test.created_stores');
        app()->forgetInstance('test.created_stores');
    }
}

// Chain helper for better readability
function chain(...$expectations): Closure
{
    return static function ($value) use ($expectations) {
        $expectation = expect($value);

        foreach ($expectations as $method => $args) {
            $expectation = is_numeric($method) ? $expectation->{$args}() : $expectation->{$method}(...(array) $args);
        }

        return $expectation;
    };
}

// Helper to create middleware test user (deprecated - use TestFactories::createTestUser)
function createMiddlewareTestUser(mixed $id, string $authId): object
{
    return TestFactories::createTestUser(authId: $authId, identifier: $id);
}

// Helper function to create test user for middleware tests (deprecated - use TestFactories::createTestUser)
function createTestUser(string $authId = 'user:123', mixed $identifier = 123): object
{
    return TestFactories::createTestUser(authId: $authId, identifier: $identifier);
}

// Helper to setup route
function setupRoute(Request $request, string $path, string $paramName, mixed $paramValue): void
{
    $route = new Route(
        methods: ['GET'],
        uri: $path,
        action: [],
    );
    $route->bind($request);
    $route->setParameter($paramName, $paramValue);

    $request->setRouteResolver(static fn (): Route => $route);
}

// Helper to convert route to object ID
function routeToObjectId(string $routePath, string $paramName, string $paramValue): string
{
    $type = str_replace(['{', '}'], '', $paramName);

    return sprintf('%s:%s', $type, $paramValue);
}

// Helper to generate batch operations
function generateBatchOperations(int $count): array
{
    $operations = [];

    for ($i = 1; $i <= $count; ++$i) {
        $operations[] = [
            'user' => 'user:' . $i,
            'relation' => 'viewer',
            'object' => 'document:' . $i,
        ];
    }

    return $operations;
}
