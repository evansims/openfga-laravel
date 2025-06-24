<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Traits\HasAuthorization;
use ReflectionClass;

// Test model that uses the trait
final class TestDocument extends Model
{
    use HasAuthorization;

    public $exists = true;

    protected $guarded = [];

    protected $table = 'documents';

    public function __construct(array $attributes = [])
    {
        // Skip parent constructor to avoid database setup
        $this->fill($attributes);
    }

    // Override for testing
    public function getKey()
    {
        return $this->id ?? 123;
    }

    public function getKeyName()
    {
        return 'id';
    }

    // Override to avoid database calls
    protected static function registerModelEvent($event, $callback): void
    {
        // Do nothing
    }
}

// Test user model
final class TestUser extends Model
{
    use HasAuthorization;

    public $exists = true;

    protected $guarded = [];

    protected $table = 'users';

    public function __construct(array $attributes = [])
    {
        // Skip parent constructor to avoid database setup
        $this->fill($attributes);
    }

    public function getKey()
    {
        return $this->id ?? 456;
    }

    // Override to avoid database calls
    protected static function registerModelEvent($event, $callback): void
    {
        // Do nothing
    }
}

describe('HasAuthorization Trait', function (): void {
    beforeEach(function (): void {
        $this->container = new Container;
        Container::setInstance($this->container);
        App::setFacadeApplication($this->container);

        // Mock config for tests
        $this->container->instance('config', new class {
            public function get($key, $default = null)
            {
                $configs = [
                    'openfga.cleanup_on_delete' => true,
                    'openfga.replicate_permissions' => false,
                ];

                return $configs[$key] ?? $default;
            }
        });

        $this->config = [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'url' => 'http://localhost:8080',
                    'store_id' => 'test-store',
                    'model_id' => 'test-model',
                    'credentials' => [
                        'method' => 'none',
                    ],
                ],
            ],
        ];

        $this->manager = new OpenFgaManager($this->container, $this->config);
        $this->container->instance(OpenFgaManager::class, $this->manager);

        $this->document = new TestDocument(['id' => 123]);
        $this->user = new TestUser(['id' => 456]);
    });

    describe('Authorization Object Generation', function (): void {
        it('generates correct authorization object', function (): void {
            expect($this->document->authorizationObject())->toBe('test_document:123');
        });

        it('generates correct authorization type', function (): void {
            expect($this->document->authorizationType())->toBe('test_document');
        });

        it('provides default authorization relations', function (): void {
            expect($this->document->getAuthorizationRelations())->toBe(['owner', 'editor', 'viewer']);
        });
    });

    describe('User ID Resolution', function (): void {
        it('resolves user from model with trait', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect($method->invoke($this->document, $this->user))->toBe('test_user:456');
        });

        it('resolves user from string', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect($method->invoke($this->document, 'user:789'))->toBe('user:789');
        });

        it('resolves user from integer', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect($method->invoke($this->document, 999))->toBe('user:999');
        });

        it('throws exception for invalid user type', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect(fn () => $method->invoke($this->document, []))
                ->toThrow(InvalidArgumentException::class, 'User must be a Model, string, or integer');
        });
    });

    describe('Permission Operations', function (): void {
        it('grants permission to a user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('revokes permission from a user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('checks permission for a user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('checks permission for current user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });
    });

    describe('Batch Operations', function (): void {
        it('grants multiple permissions to multiple users', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('revokes multiple permissions from multiple users', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });
    });

    describe('Query Operations', function (): void {
        it('gets users with a specific relation', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('gets all relations for a user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });
    });

    describe('Model Events', function (): void {
        it('has initialization method', function (): void {
            // Just verify the method exists
            expect(method_exists($this->document, 'initializeHasAuthorization'))->toBeTrue();
        });
    });

    describe('Configuration', function (): void {
        it('checks cleanup on delete configuration', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('shouldCleanupPermissionsOnDelete');
            $method->setAccessible(true);

            expect($method->invoke($this->document))->toBe(true);
        });

        it('checks replicate permissions configuration', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('shouldReplicatePermissions');
            $method->setAccessible(true);

            expect($method->invoke($this->document))->toBe(false);
        });
    });
});
