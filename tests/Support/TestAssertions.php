<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Closure;

use function is_string;

/**
 * Custom test assertions to make tests more readable and semantic.
 */
final class TestAssertions
{
    /**
     * Assert that a response has the expected batch operation structure.
     *
     * @param array $result
     * @param int   $expectedTotal
     * @param int   $expectedProcessed
     * @param bool  $shouldSucceed
     */
    public static function assertBatchOperationResult(
        array $result,
        int $expectedTotal,
        int $expectedProcessed,
        bool $shouldSucceed = true,
    ): void {
        expect($result)->toHaveKey('totalOperations', 'Batch result should contain total operations count');
        expect($result)->toHaveKey('processedOperations', 'Batch result should contain processed operations count');
        expect($result)->toHaveKey('success', 'Batch result should contain success status');

        expect($result['totalOperations'])->toBe(
            $expectedTotal,
            "Should have {$expectedTotal} total operations",
        );
        expect($result['processedOperations'])->toBe(
            $expectedProcessed,
            "Should have processed {$expectedProcessed} operations",
        );
        expect($result['success'])->toBe(
            $shouldSucceed,
            $shouldSucceed ? 'Batch operation should succeed' : 'Batch operation should fail',
        );
    }

    /**
     * Assert that a cache operation behaves correctly.
     *
     * @param mixed  $cacheResult
     * @param mixed  $expectedValue
     * @param string $operationType
     * @param string $key
     */
    public static function assertCacheOperation(
        mixed $cacheResult,
        mixed $expectedValue,
        string $operationType,
        string $key,
    ): void {
        switch ($operationType) {
            case 'hit':
                expect($cacheResult)->toBe(
                    $expectedValue,
                    "Cache should return stored value for key '{$key}'",
                );

                break;

            case 'miss':
                expect($cacheResult)->toBeNull(
                    "Cache should return null for missing key '{$key}'",
                );

                break;

            case 'store':
                expect($cacheResult)->toBeTrue(
                    "Cache should successfully store value for key '{$key}'",
                );

                break;
        }
    }

    /**
     * Assert that a duration is within expected bounds.
     *
     * @param float  $actualDuration
     * @param float  $maxExpected
     * @param string $operationName
     */
    public static function assertDurationWithinBounds(
        float $actualDuration,
        float $maxExpected,
        string $operationName = 'operation',
    ): void {
        expect($actualDuration)->toBeLessThanOrEqual(
            $maxExpected,
            "{$operationName} should complete within {$maxExpected}ms (actual: {$actualDuration}ms)",
        );
        expect($actualDuration)->toBeGreaterThan(
            0,
            "{$operationName} should take some measurable time",
        );
    }

    /**
     * Assert that a mock receives expected method calls.
     *
     * @param mixed  $mock
     * @param string $method
     * @param array  $arguments
     * @param mixed  $returnValue
     * @param string $context
     */
    public static function assertMockReceivesCall(
        mixed $mock,
        string $method,
        array $arguments,
        mixed $returnValue,
        string $context = '',
    ): void {
        $contextMessage = $context ? " when {$context}" : '';

        $mock->shouldReceive($method)
            ->once()
            ->with(...$arguments)
            ->andReturn($returnValue);
    }

    /**
     * Assert that a model has the expected authorization properties.
     *
     * @param object $model
     * @param string $expectedAuthorizationId
     */
    public static function assertModelHasAuthorizationCapabilities(
        object $model,
        string $expectedAuthorizationId,
    ): void {
        expect(method_exists($model, 'authorizationObject'))->toBeTrue(
            'Model should have authorizationObject method',
        );
        expect($model->authorizationObject())->toBe(
            $expectedAuthorizationId,
            "Model should return correct authorization ID: {$expectedAuthorizationId}",
        );
    }

    /**
     * Assert that an array of data follows expected naming conventions.
     *
     * @param array  $data
     * @param string $prefix
     */
    public static function assertNamingConventions(array $data, string $prefix): void
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                expect($value)->toStartWith(
                    $prefix,
                    "Value '{$value}' should start with '{$prefix}' prefix for consistency",
                );
            }
        }
    }

    /**
     * Assert that a permission check returns the expected result.
     *
     * @param mixed  $result
     * @param bool   $expected
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param string $context
     */
    public static function assertPermissionCheck(
        mixed $result,
        bool $expected,
        string $user,
        string $relation,
        string $object,
        string $context = '',
    ): void {
        $contextMessage = $context ? " ({$context})" : '';
        $action = $expected ? 'should have' : 'should not have';

        if ($result !== $expected) {
            TestDebugging::failWithDebugInfo(
                "Permission check failed: User '{$user}' {$action} '{$relation}' permission on '{$object}'{$contextMessage}",
                [
                    'Expected result' => $expected ? 'allowed' : 'denied',
                    'Actual result' => $result ? 'allowed' : 'denied',
                    'User' => $user,
                    'Relation' => $relation,
                    'Object' => $object,
                    'Context' => $context ?: 'none',
                ],
            );
        }
    }

    /**
     * Assert that an array contains expected permission data structure.
     *
     * @param array $data
     */
    public static function assertPermissionDataStructure(array $data): void
    {
        expect($data)->toHaveKey('user', 'Permission data should contain user');
        expect($data)->toHaveKey('relation', 'Permission data should contain relation');
        expect($data)->toHaveKey('object', 'Permission data should contain object');

        expect($data['user'])->toBeString('User should be a string');
        expect($data['relation'])->toBeString('Relation should be a string');
        expect($data['object'])->toBeString('Object should be a string');
    }

    /**
     * Assert that a service is properly registered in the container.
     *
     * @param mixed  $service
     * @param string $expectedInterface
     * @param string $serviceName
     */
    public static function assertServiceRegistered(
        mixed $service,
        string $expectedInterface,
        string $serviceName,
    ): void {
        expect($service)->toBeInstanceOf(
            $expectedInterface,
            "{$serviceName} should implement {$expectedInterface}",
        );
        expect($service)->not->toBeNull(
            "{$serviceName} should be registered in the container",
        );
    }

    /**
     * Assert that an exception is thrown with a clear context message.
     *
     * @param Closure $callback
     * @param string  $exceptionClass
     * @param string  $expectedMessage
     * @param string  $context
     */
    public static function assertThrowsWithContext(
        Closure $callback,
        string $exceptionClass,
        string $expectedMessage,
        string $context,
    ): void {
        expect($callback)->toThrow(
            $exceptionClass,
            $expectedMessage,
            "Should throw {$exceptionClass} when {$context}",
        );
    }

    /**
     * Assert that a user has a specific permission.
     *
     * @param mixed  $result
     * @param string $user
     * @param string $action
     * @param string $resource
     */
    public static function assertUserCanAccess(
        mixed $result,
        string $user,
        string $action,
        string $resource,
    ): void {
        if (! $result) {
            TestDebugging::failWithDebugInfo(
                "Access denied: User '{$user}' should be able to '{$action}' resource '{$resource}'",
                [
                    'Expected' => 'Access allowed',
                    'Actual' => 'Access denied',
                    'User' => $user,
                    'Action' => $action,
                    'Resource' => $resource,
                    'Result value' => TestDebugging::formatValue($result),
                ],
            );
        }
    }

    /**
     * Assert that a user does not have a specific permission.
     *
     * @param mixed  $result
     * @param string $user
     * @param string $action
     * @param string $resource
     */
    public static function assertUserCannotAccess(
        mixed $result,
        string $user,
        string $action,
        string $resource,
    ): void {
        if ($result) {
            TestDebugging::failWithDebugInfo(
                "Access granted unexpectedly: User '{$user}' should not be able to '{$action}' resource '{$resource}'",
                [
                    'Expected' => 'Access denied',
                    'Actual' => 'Access allowed',
                    'User' => $user,
                    'Action' => $action,
                    'Resource' => $resource,
                    'Result value' => TestDebugging::formatValue($result),
                ],
            );
        }
    }

    /**
     * Assert that a configuration is valid.
     *
     * @param mixed  $config
     * @param string $context
     */
    public static function assertValidConfiguration(mixed $config, string $context = ''): void
    {
        $message = $context ? "Configuration for {$context} should be valid" : 'Configuration should be valid';

        expect($config)->not->toBeNull($message);
        expect($config)->toBeArray('Configuration should be an array');
        expect($config)->not->toBeEmpty('Configuration should not be empty');
    }
}
