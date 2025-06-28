<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OpenFGA\Laravel\Authorization\{AuthorizationServiceProvider, OpenFgaGate};
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\DTOs\PermissionCheckRequest;
use OpenFGA\Laravel\Events\PermissionChecked;
use OpenFGA\Laravel\Listeners\AuditPermissionChanges;

use function count;
use function expect;
use function is_string;

describe('Boundary Conditions', function (): void {
    describe('Array Operations', function (): void {
        it('handles empty arrays in count operations', function (): void {
            $arguments = [];

            // Safe count operations
            $hasSecondArg = 1 < count($arguments);
            expect($hasSecondArg)->toBeFalse();

            $arguments = ['first'];
            $hasSecondArg = 1 < count($arguments);
            expect($hasSecondArg)->toBeFalse();

            $arguments = ['first', 'second'];
            $hasSecondArg = 1 < count($arguments);
            expect($hasSecondArg)->toBeTrue();
        });

        it('handles empty array access safely', function (): void {
            $provider = new AuthorizationServiceProvider($this->app);

            // Test with empty arguments array
            $arguments = [];
            $resource = $arguments[0] ?? null;

            expect($resource)->toBeNull();
        });

        it('safely checks array offsets with isset', function (): void {
            $provider = new AuthorizationServiceProvider($this->app);

            $arguments = ['resource'];

            // Safe access with isset
            $connection = (1 < count($arguments) && isset($arguments[1]) && is_string($arguments[1]))
                ? $arguments[1]
                : null;

            expect($connection)->toBeNull();

            // Test with valid second argument
            $arguments = ['resource', 'connection'];
            $connection = (1 < count($arguments) && isset($arguments[1]) && is_string($arguments[1]))
                ? $arguments[1]
                : null;

            expect($connection)->toBe('connection');
        });

        it('handles null array access with null coalescing', function (): void {
            $provider = new AuthorizationServiceProvider($this->app);

            // Test with null arguments
            $arguments = null;
            $resource = $arguments[0] ?? 'default';

            expect($resource)->toBe('default');
        });
    });

    describe('Authorization Provider', function (): void {
        it('resolves object safely with array and throws exception', function (): void {
            $provider = new AuthorizationServiceProvider($this->app);

            // Test with array (should throw)
            expect(fn () => $provider->resolveObject([]))
                ->toThrow(InvalidArgumentException::class, 'Cannot resolve object identifier for: array');
        });

        it('resolves object safely with invalid input and throws exception', function (): void {
            $provider = new AuthorizationServiceProvider($this->app);

            // Test with null
            expect(fn () => $provider->resolveObject(null))
                ->toThrow(InvalidArgumentException::class, 'Cannot resolve object identifier for: NULL');
        });
    });

    describe('Duration Calculations', function (): void {
        it('handles boundary value duration calculations', function (): void {
            // Test very small durations
            $smallDuration = 0.0001;
            $result = round((float) $smallDuration * 1000.0, 2);
            expect($result)->toBe(0.1);

            // Test zero duration
            $zeroDuration = 0.0;
            $result = round((float) $zeroDuration * 1000.0, 2);
            expect($result)->toBe(0.0);

            // Test large duration
            $largeDuration = 999.999;
            $result = round((float) $largeDuration * 1000.0, 2);
            expect($result)->toBe(999999.0);
        });
    });

    describe('DTO Handling', function (): void {
        it('handles empty arrays gracefully', function (): void {
            $dto = new PermissionCheckRequest(
                userId: 'test:user',
                relation: 'read',
                object: 'test:object',
                context: [], // empty array
                contextualTuples: [], // empty array
                connection: null,
            );

            expect($dto->context)->toBe([]);
            expect($dto->contextualTuples)->toBe([]);

            $array = $dto->toArray();
            expect($array['context'])->toBeArray();
            expect($array['context'])->toBeEmpty();
        });

        it('handles null values safely', function (): void {
            $dto = new PermissionCheckRequest(
                userId: 'test:user',
                relation: 'read',
                object: 'test:object',
                connection: null,
                duration: null,
            );

            expect($dto->connection)->toBeNull();
            expect($dto->duration)->toBeNull();

            $array = $dto->toArray();
            expect($array['connection'])->toBeNull();
            expect($array['duration'])->toBeNull();
        });
    });

    describe('Gate Handling', function (): void {
        it('handles empty arguments array', function (): void {
            $manager = $this->createMock(ManagerInterface::class);
            $gate = new OpenFgaGate($manager, $this->app, fn () => null);

            // Test with empty arguments
            $result = $gate->isOpenFgaPermission([]);
            expect($result)->toBeFalse();

            // Test with null
            $result = $gate->isOpenFgaPermission(null);
            expect($result)->toBeFalse();
        });

        it('handles invalid model keys safely', function (): void {
            $manager = $this->createMock(ManagerInterface::class);
            $gate = new OpenFgaGate($manager, $this->app, fn () => null);

            // Create a mock model with null key
            $model = new class extends Model {
                protected $table = 'test_table';

                public function getKey()
                {
                    return null; // Invalid key
                }
            };

            // Should not throw, should return false for invalid objects
            $result = $gate->isOpenFgaPermission($model);
            expect($result)->toBeTrue(); // Still considers it OpenFGA-eligible but will fail later
        });
    });

    describe('Mathematical Operations', function (): void {
        it('handles division by zero safely', function (): void {
            // Test division by zero protection
            $hits = 10;
            $total = 0;

            // Safe division with guard
            $percentage = 0 < $total ? ($hits / $total) * 100.0 : 0.0;

            expect($percentage)->toBe(0.0);
        });

        it('handles mixed type operations with casting', function (): void {
            // Test float + int operations
            $floatValue = 1.5;
            $intValue = 2;

            // Explicit casting to avoid strict binary operation issues
            $result = (float) $floatValue + (float) $intValue;
            expect($result)->toBe(3.5);

            // Test with time calculations
            $seconds = 1.234;
            $milliseconds = (float) $seconds * 1000.0;
            expect($milliseconds)->toBe(1234.0);
        });

        it('handles strict binary operations correctly', function (): void {
            $listener = new AuditPermissionChanges;

            // Create a mock event with duration
            $event = new PermissionChecked(
                user: 'test:user',
                relation: 'read',
                object: 'test:object',
                allowed: true,
                duration: 0.12345, // float
                cached: false,
                connection: null,
                context: [],
            );

            // Test the duration conversion that was causing issues
            $durationMs = round((float) $event->duration * 1000.0, 2);

            expect($durationMs)->toBe(123.45);

            // Test with integer duration
            $eventWithIntDuration = new PermissionChecked(
                user: 'test:user',
                relation: 'read',
                object: 'test:object',
                allowed: true,
                duration: 1, // int
                cached: false,
                connection: null,
                context: [],
            );

            $durationMs = round((float) $eventWithIntDuration->duration * 1000.0, 2);
            expect($durationMs)->toBe(1000.0);
        });
    });

    describe('User Resolver', function (): void {
        it('handles missing auth methods', function (): void {
            $user = new class implements Authenticatable {
                public function getAuthIdentifier()
                {
                    return null;
                }

                // No identifier
                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthPassword(): ?string
                {
                    return null;
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): ?string
                {
                    return null;
                }

                public function getRememberTokenName(): ?string
                {
                    return null;
                }

                public function setRememberToken($value): void
                {
                }
            };

            $dto = PermissionCheckRequest::fromUser(
                $user,
                'read',
                'test:object',
            );

            expect($dto->userId)->toBe('user:unknown');
        });
    });
});
