<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Support;

use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

use function count;
use function expect;
use function is_string;

describe('Array Operations Safety', function (): void {
    /*
     * These tests verify that array operations handle boundary conditions safely,
     * preventing common PHP errors when working with empty or malformed arrays.
     */

    describe('Array Length Checks', function (): void {
        it('should safely check for second argument existence in empty arrays', function (): void {
            $arguments = [];

            // This pattern is used throughout the codebase to safely check for optional parameters
            $hasSecondArg = 1 < count($arguments);
            expect($hasSecondArg)->toBeFalse('Empty array should not have second argument');

            $arguments = ['first'];
            $hasSecondArg = 1 < count($arguments);
            expect($hasSecondArg)->toBeFalse('Single element array should not have second argument');

            $arguments = ['first', 'second'];
            $hasSecondArg = 1 < count($arguments);
            expect($hasSecondArg)->toBeTrue('Two element array should have second argument');
        });
    });

    describe('Safe Array Access Patterns', function (): void {
        it('should return null when accessing undefined array index with null coalescing', function (): void {
            // This pattern prevents "Undefined array key" warnings
            $arguments = [];
            $resource = $arguments[0] ?? null;

            expect($resource)->toBeNull('Undefined array index should return null with ?? operator');
        });

        it('should safely validate array elements before access', function (): void {
            $arguments = ['resource'];

            // This pattern ensures type safety when accessing array elements
            $connection = (1 < count($arguments) && isset($arguments[1]) && is_string($arguments[1]))
                ? $arguments[1]
                : null;

            expect($connection)->toBeNull('Missing second argument should return null');

            // Test with valid second argument
            $arguments = ['resource', 'connection'];
            $connection = (1 < count($arguments) && isset($arguments[1]) && is_string($arguments[1]))
                ? $arguments[1]
                : null;

            expect($connection)->toBe('connection', 'Valid second argument should be returned');
        });

        it('should handle null array gracefully with null coalescing operator', function (): void {
            // This test ensures we handle cases where the array itself might be null
            $arguments = null;
            $resource = $arguments[0] ?? 'default';

            expect($resource)->toBe('default', 'Null array access should return default value');
        });
    });
});
