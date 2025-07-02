<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Auth, View};
use Illuminate\View\{Component, View as ViewObject};
use OpenFGA\Laravel\Contracts\{AuthorizationType, ManagerInterface};
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\View\Components\Can;

uses(TestCase::class);

describe('Can component', function (): void {
    beforeEach(function (): void {
        // Create a mock that implements the manager interface
        $this->manager = Mockery::mock(ManagerInterface::class);
        $this->app->instance(OpenFgaManager::class, $this->manager);

        // Mock the view facade to return a simple view object
        View::shouldReceive('make')
            ->with('openfga::components.can', [], [])
            ->andReturn(Mockery::mock(ViewObject::class, [
                'render' => '<div>Content</div>',
            ]));
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(Can::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('extends Component class', function (): void {
        $component = new Can(relation: 'read', object: 'doc:123');
        expect($component)->toBeInstanceOf(Component::class);
    });

    describe('constructor', function (): void {
        it('accepts required parameters', function (): void {
            $component = new Can(relation: 'read', object: 'doc:123');

            expect($component->relation)->toBe('read');
            expect($component->object)->toBe('doc:123');
            expect($component->connection)->toBeNull();
            expect($component->user)->toBeNull();
        });

        it('accepts optional parameters', function (): void {
            $component = new Can(
                relation: 'write',
                object: 'doc:456',
                connection: 'secondary',
                user: 'user:789',
            );

            expect($component->relation)->toBe('write');
            expect($component->object)->toBe('doc:456');
            expect($component->connection)->toBe('secondary');
            expect($component->user)->toBe('user:789');
        });
    });

    describe('hasPermission method', function (): void {
        it('returns false when no user is authenticated', function (): void {
            Auth::shouldReceive('user')->once()->andReturnNull();

            $component = new Can(relation: 'read', object: 'doc:123');

            expect($component->hasPermission())->toBeFalse();
        });

        it('checks permission when user is authenticated', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: 'doc:123');

            expect($component->hasPermission())->toBeTrue();
        });

        it('returns false when check fails', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturn(false);

            $component = new Can(relation: 'read', object: 'doc:123');

            expect($component->hasPermission())->toBeFalse();
        });

        it('uses custom connection when provided', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'doc:123', [], [], 'secondary')
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: 'doc:123', connection: 'secondary');

            expect($component->hasPermission())->toBeTrue();
        });
    });

    describe('render method', function (): void {
        it('returns empty string when permission is denied', function (): void {
            Auth::shouldReceive('user')->once()->andReturnNull();

            $component = new Can(relation: 'read', object: 'doc:123');

            expect($component->render())->toBe('');
        });

        it('returns view when permission is granted', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: 'doc:123');
            $result = $component->render();

            expect($result)->toBeInstanceOf(ViewObject::class);
            expect($result->render())->toBe('<div>Content</div>');
        });
    });

    describe('resolveObject method', function (): void {
        it('handles string objects', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'doc:456', [], [], null)
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: 'doc:456');

            expect($component->hasPermission())->toBeTrue();
        });

        it('handles objects with authorizationObject method', function (): void {
            $object = new class {
                public function authorizationObject(): string
                {
                    return 'custom:789';
                }
            };

            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'custom:789', [], [], null)
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: $object);

            expect($component->hasPermission())->toBeTrue();
        });

        it('handles models with AuthorizationType interface', function (): void {
            $model = Mockery::mock(Model::class, AuthorizationType::class);
            $model->shouldReceive('authorizationType')->andReturn('document');
            $model->shouldReceive('getKey')->andReturn(999);
            $model->shouldReceive('getKeyType')->andReturn('int');

            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'document:999', [], [], null)
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: $model);

            expect($component->hasPermission())->toBeTrue();
        });

        it('handles regular Eloquent models', function (): void {
            $model = Mockery::mock(Model::class);
            $model->shouldReceive('getTable')->andReturn('posts');
            $model->shouldReceive('getKey')->andReturn(42);
            $model->shouldReceive('getKeyType')->andReturn('int');

            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'posts:42', [], [], null)
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: $model);

            expect($component->hasPermission())->toBeTrue();
        });

        it('handles numeric objects', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'resource:789', [], [], null)
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: 789);

            expect($component->hasPermission())->toBeTrue();
        });

        it('throws exception for invalid object types', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $component = new Can(relation: 'read', object: ['invalid']);

            expect(static fn (): bool => $component->hasPermission())
                ->toThrow(InvalidArgumentException::class, 'Cannot resolve object identifier for: array');
        });

        it('throws exception when authorizationObject returns non-string', function (): void {
            $object = new class {
                public function authorizationObject(): array
                {
                    return ['invalid'];
                }
            };

            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $component = new Can(relation: 'read', object: $object);

            expect(static fn (): bool => $component->hasPermission())
                ->toThrow(InvalidArgumentException::class, 'authorizationObject() must return a string or stringable value');
        });
    });

    describe('resolveUserId method', function (): void {
        it('uses authorizationUser method when available', function (): void {
            $user = new class implements Authenticatable {
                public function authorizationUser(): string
                {
                    return 'custom:user:123';
                }

                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('custom:user:123', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: 'doc:123');

            expect($component->hasPermission())->toBeTrue();
        });

        it('uses getAuthorizationUserId method when available', function (): void {
            $user = new class implements Authenticatable {
                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthorizationUserId(): int
                {
                    return 456;
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('456', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: 'doc:123');

            expect($component->hasPermission())->toBeTrue();
        });

        it('falls back to getAuthIdentifier', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(789);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:789', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturn(true);

            $component = new Can(relation: 'read', object: 'doc:123');

            expect($component->hasPermission())->toBeTrue();
        });

        it('throws exception when authorizationUser returns invalid type', function (): void {
            $user = new class implements Authenticatable {
                public function authorizationUser(): array
                {
                    return ['invalid'];
                }

                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            Auth::shouldReceive('user')->once()->andReturn($user);

            $component = new Can(relation: 'read', object: 'doc:123');

            expect(static fn (): bool => $component->hasPermission())
                ->toThrow(InvalidArgumentException::class, 'authorizationUser() must return a string or numeric value');
        });

        it('throws exception when getAuthIdentifier returns non-scalar', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(['invalid']);

            Auth::shouldReceive('user')->once()->andReturn($user);

            $component = new Can(relation: 'read', object: 'doc:123');

            expect(static fn (): bool => $component->hasPermission())
                ->toThrow(InvalidArgumentException::class, 'User identifier must be scalar');
        });
    });
});
