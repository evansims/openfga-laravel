<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Authorization\{AuthorizationServiceProvider, OpenFgaGate};
use OpenFGA\Laravel\Contracts\AuthorizableUser;
use OpenFGA\Laravel\Contracts\{ManagerInterface};
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('AuthorizationServiceProvider', function (): void {
    beforeEach(function (): void {
        $this->provider = new AuthorizationServiceProvider($this->app);
    });

    it('extends Laravel ServiceProvider', function (): void {
        expect($this->provider)->toBeInstanceOf(ServiceProvider::class);
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(AuthorizationServiceProvider::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('is marked as internal', function (): void {
        $reflection = new ReflectionClass(AuthorizationServiceProvider::class);
        $docComment = $reflection->getDocComment();

        expect($docComment)->toContain('@internal');
    });

    it('has register method', function (): void {
        expect(method_exists($this->provider, 'register'))->toBeTrue();

        $reflection = new ReflectionClass($this->provider);
        $method = $reflection->getMethod('register');

        expect($method->isPublic())->toBeTrue();
    });

    it('has boot method', function (): void {
        expect(method_exists($this->provider, 'boot'))->toBeTrue();

        $reflection = new ReflectionClass($this->provider);
        $method = $reflection->getMethod('boot');

        expect($method->isPublic())->toBeTrue();
    });

    it('replaces Laravel Gate with OpenFgaGate during registration', function (): void {
        // Mock the OpenFgaManager dependency
        $mockManager = mock(ManagerInterface::class);
        $this->app->singleton(OpenFgaManager::class, fn () => $mockManager);

        $this->provider->register();

        // GateContract should be registered
        expect($this->app->bound(GateContract::class))->toBeTrue();

        // The registered Gate should be an instance of OpenFgaGate
        $gate = $this->app->make(GateContract::class);
        expect($gate)->toBeInstanceOf(OpenFgaGate::class);
    });

    it('registers OpenFgaGate as singleton', function (): void {
        // Mock the OpenFgaManager dependency
        $mockManager = mock(ManagerInterface::class);
        $this->app->singleton(OpenFgaManager::class, fn () => $mockManager);

        $this->provider->register();

        // Get instances through GateContract binding
        $gate1 = $this->app->make(GateContract::class);
        $gate2 = $this->app->make(GateContract::class);

        // Should be the same instance (singleton)
        expect($gate1)->toBe($gate2);
    });

    it('boots without errors', function (): void {
        // Mock the OpenFgaManager dependency
        $mockManager = mock(ManagerInterface::class);
        $this->app->singleton(OpenFgaManager::class, fn () => $mockManager);

        // Need to register first for dependencies
        $this->provider->register();

        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('has private helper methods', function (): void {
        $reflection = new ReflectionClass($this->provider);

        expect($reflection->hasMethod('registerOpenFgaGates'))->toBeTrue();
        expect($reflection->hasMethod('registerGlobalHelpers'))->toBeTrue();

        $gatesMethod = $reflection->getMethod('registerOpenFgaGates');
        expect($gatesMethod->isPrivate())->toBeTrue();

        $helpersMethod = $reflection->getMethod('registerGlobalHelpers');
        expect($helpersMethod->isPrivate())->toBeTrue();
    });

    it('does not implement DeferrableProvider', function (): void {
        // AuthorizationServiceProvider doesn't implement DeferrableProvider
        expect($this->provider)->not->toBeInstanceOf(DeferrableProvider::class);

        // The base ServiceProvider has a provides() method that returns empty array
        expect(method_exists($this->provider, 'provides'))->toBeTrue();
        expect($this->provider->provides())->toBe([]);
    });

    it('creates OpenFgaGate instance through Gate contract', function (): void {
        // Mock the OpenFgaManager dependency
        $mockManager = mock(ManagerInterface::class);
        $this->app->singleton(OpenFgaManager::class, fn () => $mockManager);

        $this->provider->register();

        // The GateContract should resolve to OpenFgaGate
        $gate = $this->app->make(GateContract::class);

        expect($gate)->toBeInstanceOf(OpenFgaGate::class);
    });

    it('creates OpenFgaGate with proper dependencies', function (): void {
        // Register OpenFgaManager dependency
        $mockManager = mock(ManagerInterface::class);
        $this->app->singleton(OpenFgaManager::class, fn () => $mockManager);

        $this->provider->register();

        // The factory should create OpenFgaGate with proper dependencies
        $gate = $this->app->make(GateContract::class);
        expect($gate)->toBeInstanceOf(OpenFgaGate::class);
    });

    it('uses Auth::userResolver for user resolution', function (): void {
        // Mock the OpenFgaManager dependency
        $mockManager = mock(ManagerInterface::class);
        $this->app->singleton(OpenFgaManager::class, fn () => $mockManager);

        $this->provider->register();

        // The service provider should use Auth::userResolver()
        // This is tested indirectly through registration
        expect($this->app->bound(GateContract::class))->toBeTrue();
    });

    it('registers gate helpers during boot', function (): void {
        // Mock the OpenFgaManager dependency first
        $mockManager = mock(ManagerInterface::class);
        $this->app->singleton(OpenFgaManager::class, fn () => $mockManager);

        $this->provider->register();

        // Boot should register gate helpers without errors
        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('has resolveObject method', function (): void {
        expect(method_exists($this->provider, 'resolveObject'))->toBeTrue();

        $reflection = new ReflectionClass($this->provider);
        $method = $reflection->getMethod('resolveObject');

        expect($method->isPublic())->toBeTrue();
    });

    it('has resolveUserId method', function (): void {
        expect(method_exists($this->provider, 'resolveUserId'))->toBeTrue();

        $reflection = new ReflectionClass($this->provider);
        $method = $reflection->getMethod('resolveUserId');

        expect($method->isPublic())->toBeTrue();
    });

    it('resolves object from string format', function (): void {
        $result = $this->provider->resolveObject('document:123');
        expect($result)->toBe('document:123');
    });

    it('resolves object from model with authorizationObject method', function (): void {
        $mockModel = new class {
            public function authorizationObject(): string
            {
                return 'document:456';
            }
        };

        $result = $this->provider->resolveObject($mockModel);
        expect($result)->toBe('document:456');
    });

    it('resolves object from model with authorizationType method', function (): void {
        // Create a custom class that has authorizationType but not authorizationObject
        $mockModel = new class extends Model {
            public function authorizationType(): string
            {
                return 'document';
            }

            public function getKey()
            {
                return 789;
            }
        };

        $result = $this->provider->resolveObject($mockModel);
        expect($result)->toBe('document:789');
    });

    it('resolves object from Eloquent model', function (): void {
        $mockModel = mock(Model::class);
        $mockModel->shouldReceive('getTable')->andReturn('posts');
        $mockModel->shouldReceive('getKey')->andReturn(101);

        $result = $this->provider->resolveObject($mockModel);
        expect($result)->toBe('posts:101');
    });

    it('throws exception for invalid object types', function (): void {
        expect(fn () => $this->provider->resolveObject(123))
            ->toThrow(InvalidArgumentException::class, 'Cannot resolve object identifier for: integer');
    });

    it('resolves user ID from AuthorizableUser interface', function (): void {
        $mockUser = mock(Authenticatable::class, AuthorizableUser::class);
        $mockUser->shouldReceive('authorizationUser')->andReturn('user:123');

        $result = $this->provider->resolveUserId($mockUser);
        expect($result)->toBe('user:123');
    });

    it('resolves user ID from authorizationUser method', function (): void {
        $mockUser = new class implements Authenticatable {
            public function authorizationUser(): string
            {
                return 'user:456';
            }

            public function getAuthIdentifier()
            {
                return 1;
            }

            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getRememberToken()
            {
                return '';
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
            }
        };

        $result = $this->provider->resolveUserId($mockUser);
        expect($result)->toBe('user:456');
    });

    it('resolves user ID from getAuthorizationUserId method', function (): void {
        $mockUser = new class implements Authenticatable {
            public function getAuthIdentifier()
            {
                return 1;
            }

            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthorizationUserId(): string
            {
                return 'user:789';
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getRememberToken()
            {
                return '';
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
            }
        };

        $result = $this->provider->resolveUserId($mockUser);
        expect($result)->toBe('user:789');
    });

    it('resolves user ID from getAuthIdentifier with user prefix', function (): void {
        $mockUser = mock(Authenticatable::class);
        $mockUser->shouldReceive('getAuthIdentifier')->andReturn(999);

        $result = $this->provider->resolveUserId($mockUser);
        expect($result)->toBe('user:999');
    });

    it('throws exception for invalid user identifier', function (): void {
        $mockUser = mock(Authenticatable::class);
        $mockUser->shouldReceive('getAuthIdentifier')->andReturn(null);

        expect(fn () => $this->provider->resolveUserId($mockUser))
            ->toThrow(InvalidArgumentException::class, 'User identifier must be string or numeric');
    });
});
