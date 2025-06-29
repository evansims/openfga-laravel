<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\{Auth};
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\View\BladeServiceProvider;

uses(TestCase::class);

describe('BladeServiceProvider', function (): void {
    beforeEach(function (): void {
        $this->provider = new BladeServiceProvider($this->app);
    });

    it('extends Laravel ServiceProvider', function (): void {
        expect($this->provider)->toBeInstanceOf(ServiceProvider::class);
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(BladeServiceProvider::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('is marked as internal', function (): void {
        $reflection = new ReflectionClass(BladeServiceProvider::class);
        $docComment = $reflection->getDocComment();

        expect($docComment)->toContain('@internal');
    });

    it('has boot method', function (): void {
        expect(method_exists($this->provider, 'boot'))->toBeTrue();

        $reflection = new ReflectionClass($this->provider);
        $method = $reflection->getMethod('boot');

        expect($method->isPublic())->toBeTrue();
    });

    it('has public helper methods', function (): void {
        expect(method_exists($this->provider, 'checkBladePermission'))->toBeTrue();
        expect(method_exists($this->provider, 'resolveObject'))->toBeTrue();
        expect(method_exists($this->provider, 'resolveUserId'))->toBeTrue();
    });

    it('has private registration methods', function (): void {
        $reflection = new ReflectionClass($this->provider);

        expect($reflection->hasMethod('registerBladeDirectives'))->toBeTrue();
        expect($reflection->hasMethod('registerViewComposer'))->toBeTrue();

        $directivesMethod = $reflection->getMethod('registerBladeDirectives');
        expect($directivesMethod->isPrivate())->toBeTrue();

        $composerMethod = $reflection->getMethod('registerViewComposer');
        expect($composerMethod->isPrivate())->toBeTrue();
    });

    it('boots without errors', function (): void {
        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('resolves string objects', function (): void {
        $result = $this->provider->resolveObject('document:123');
        expect($result)->toBe('document:123');
    });

    it('resolves objects with authorizationObject method', function (): void {
        $object = new class {
            public function authorizationObject(): string
            {
                return 'custom:456';
            }
        };

        $result = $this->provider->resolveObject($object);
        expect($result)->toBe('custom:456');
    });

    it('throws exception for unresolvable objects', function (): void {
        expect(fn () => $this->provider->resolveObject(123))
            ->toThrow(InvalidArgumentException::class, 'Cannot resolve object identifier for: integer');
    });

    it('resolves user with authorizationUser method', function (): void {
        $user = new class implements Authenticatable {
            public function authorizationUser(): string
            {
                return 'team:dev';
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
                return 'secret';
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getRememberToken()
            {
                return null;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
            }
        };

        $result = $this->provider->resolveUserId($user);
        expect($result)->toBe('team:dev');
    });

    it('resolves user with getAuthorizationUserId method', function (): void {
        $user = new class implements Authenticatable {
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
                return 'service:api';
            }

            public function getAuthPassword()
            {
                return 'secret';
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getRememberToken()
            {
                return null;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
            }
        };

        $result = $this->provider->resolveUserId($user);
        expect($result)->toBe('service:api');
    });

    it('resolves user with default pattern', function (): void {
        $user = new class implements Authenticatable {
            public function getAuthIdentifier()
            {
                return 123;
            }

            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthPassword()
            {
                return 'secret';
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getRememberToken()
            {
                return null;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
            }
        };

        $result = $this->provider->resolveUserId($user);
        expect($result)->toBe('user:123');
    });

    it('throws exception for unresolvable user', function (): void {
        $user = new class implements Authenticatable {
            public function getAuthIdentifier()
            {
                return [];
            } // Invalid type

            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthPassword()
            {
                return 'secret';
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getRememberToken()
            {
                return null;
            }

            public function getRememberTokenName()
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
            }
        };

        expect(fn () => $this->provider->resolveUserId($user))
            ->toThrow(InvalidArgumentException::class, 'Unable to resolve user identifier');
    });

    it('returns false from checkBladePermission when not authenticated', function (): void {
        Auth::shouldReceive('check')->once()->andReturn(false);

        $result = $this->provider->checkBladePermission('viewer', 'document:123');
        expect($result)->toBeFalse();
    });

    it('validates method signatures', function (): void {
        $reflection = new ReflectionClass($this->provider);

        // checkBladePermission
        $method = $reflection->getMethod('checkBladePermission');
        $params = $method->getParameters();
        expect($params)->toHaveCount(3);
        expect($params[0]->getName())->toBe('relation');
        expect($params[1]->getName())->toBe('object');
        expect($params[2]->getName())->toBe('connection');
        expect($params[2]->isOptional())->toBeTrue();

        // resolveObject
        $method = $reflection->getMethod('resolveObject');
        $params = $method->getParameters();
        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('object');

        // resolveUserId
        $method = $reflection->getMethod('resolveUserId');
        $params = $method->getParameters();
        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('user');
    });
});
