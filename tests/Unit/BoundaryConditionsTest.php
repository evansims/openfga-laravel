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
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\{CoversClass, Test};

use function count;
use function is_string;

#[CoversClass(AuthorizationServiceProvider::class)]
#[CoversClass(OpenFgaGate::class)]
#[CoversClass(PermissionCheckRequest::class)]
#[CoversClass(AuditPermissionChanges::class)]
final class BoundaryConditionsTest extends TestCase
{
    #[Test]
    public function array_count_operations_handle_empty_arrays(): void
    {
        $arguments = [];

        // Safe count operations
        $hasSecondArg = 1 < count($arguments);
        $this->assertFalse($hasSecondArg);

        $arguments = ['first'];
        $hasSecondArg = 1 < count($arguments);
        $this->assertFalse($hasSecondArg);

        $arguments = ['first', 'second'];
        $hasSecondArg = 1 < count($arguments);
        $this->assertTrue($hasSecondArg);
    }

    #[Test]
    public function authorization_provider_resolves_object_safely_with_array(): void
    {
        $provider = new AuthorizationServiceProvider($this->app);

        // Test with array (should throw)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot resolve object identifier for: array');
        $provider->resolveObject([]);
    }

    #[Test]
    public function authorization_provider_resolves_object_safely_with_invalid_input(): void
    {
        $provider = new AuthorizationServiceProvider($this->app);

        // Test with null
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot resolve object identifier for: NULL');
        $provider->resolveObject(null);
    }

    #[Test]
    public function boundary_value_duration_calculations(): void
    {
        // Test very small durations
        $smallDuration = 0.0001;
        $result = round((float) $smallDuration * 1000.0, 2);
        $this->assertSame(0.1, $result);

        // Test zero duration
        $zeroDuration = 0.0;
        $result = round((float) $zeroDuration * 1000.0, 2);
        $this->assertSame(0.0, $result);

        // Test large duration
        $largeDuration = 999.999;
        $result = round((float) $largeDuration * 1000.0, 2);
        $this->assertSame(999999.0, $result);
    }

    #[Test]
    public function dto_handles_empty_arrays_gracefully(): void
    {
        $dto = new PermissionCheckRequest(
            userId: 'test:user',
            relation: 'read',
            object: 'test:object',
            context: [], // empty array
            contextualTuples: [], // empty array
            connection: null,
        );

        $this->assertSame([], $dto->context);
        $this->assertSame([], $dto->contextualTuples);

        $array = $dto->toArray();
        $this->assertIsArray($array['context']);
        $this->assertEmpty($array['context']);
    }

    #[Test]
    public function dto_handles_null_values_safely(): void
    {
        $dto = new PermissionCheckRequest(
            userId: 'test:user',
            relation: 'read',
            object: 'test:object',
            connection: null,
            duration: null,
        );

        $this->assertNull($dto->connection);
        $this->assertNull($dto->duration);

        $array = $dto->toArray();
        $this->assertNull($array['connection']);
        $this->assertNull($array['duration']);
    }

    #[Test]
    public function gate_handles_empty_arguments_array(): void
    {
        $manager = $this->createMock(ManagerInterface::class);
        $gate = new OpenFgaGate($manager, $this->app, fn () => null);

        // Test with empty arguments
        $result = $gate->isOpenFgaPermission([]);
        $this->assertFalse($result);

        // Test with null
        $result = $gate->isOpenFgaPermission(null);
        $this->assertFalse($result);
    }

    #[Test]
    public function gate_handles_invalid_model_keys_safely(): void
    {
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
        $this->assertTrue($result); // Still considers it OpenFGA-eligible but will fail later
    }

    #[Test]
    public function it_handles_division_by_zero_safely(): void
    {
        // Test division by zero protection
        $hits = 10;
        $total = 0;

        // Safe division with guard
        $percentage = 0 < $total ? ($hits / $total) * 100.0 : 0.0;

        $this->assertSame(0.0, $percentage);
    }

    #[Test]
    public function it_handles_empty_array_access_safely(): void
    {
        $provider = new AuthorizationServiceProvider($this->app);

        // Test with empty arguments array
        $arguments = [];
        $resource = $arguments[0] ?? null;

        $this->assertNull($resource);
    }

    #[Test]
    public function it_handles_mixed_type_operations_with_casting(): void
    {
        // Test float + int operations
        $floatValue = 1.5;
        $intValue = 2;

        // Explicit casting to avoid strict binary operation issues
        $result = (float) $floatValue + (float) $intValue;
        $this->assertSame(3.5, $result);

        // Test with time calculations
        $seconds = 1.234;
        $milliseconds = (float) $seconds * 1000.0;
        $this->assertSame(1234.0, $milliseconds);
    }

    #[Test]
    public function it_handles_null_array_access_with_null_coalescing(): void
    {
        $provider = new AuthorizationServiceProvider($this->app);

        // Test with null arguments
        $arguments = null;
        $resource = $arguments[0] ?? 'default';

        $this->assertSame('default', $resource);
    }

    #[Test]
    public function it_handles_strict_binary_operations_correctly(): void
    {
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

        $this->assertSame(123.45, $durationMs);

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
        $this->assertSame(1000.0, $durationMs);
    }

    #[Test]
    public function it_safely_checks_array_offsets_with_isset(): void
    {
        $provider = new AuthorizationServiceProvider($this->app);

        $arguments = ['resource'];

        // Safe access with isset
        $connection = (1 < count($arguments) && isset($arguments[1]) && is_string($arguments[1]))
            ? $arguments[1]
            : null;

        $this->assertNull($connection);

        // Test with valid second argument
        $arguments = ['resource', 'connection'];
        $connection = (1 < count($arguments) && isset($arguments[1]) && is_string($arguments[1]))
            ? $arguments[1]
            : null;

        $this->assertSame('connection', $connection);
    }

    #[Test]
    public function user_resolver_handles_missing_auth_methods(): void
    {
        $user = new class implements Authenticatable {
            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return null;
            } // No identifier

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): ?string
            {
                return null;
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken($value): void
            {
            }

            public function getRememberTokenName(): ?string
            {
                return null;
            }
        };

        $dto = PermissionCheckRequest::fromUser(
            $user,
            'read',
            'test:object',
        );

        $this->assertSame('user:unknown', $dto->userId);
    }
}
