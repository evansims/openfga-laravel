<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Auth, View};
use Illuminate\View\View as ViewObject;
use OpenFGA\Laravel\Contracts\{AuthorizationType, AuthorizationUser, AuthorizationUserId, ManagerInterface};
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\View\MenuBuilder;

uses(TestCase::class);

describe('MenuBuilder', function (): void {
    beforeEach(function (): void {
        $this->manager = Mockery::mock(ManagerInterface::class);
        $this->app->instance(ManagerInterface::class, $this->manager);
        $this->builder = new MenuBuilder(manager: $this->manager);
    });

    it('is marked as final and readonly', function (): void {
        $reflection = new ReflectionClass(MenuBuilder::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    describe('static make method', function (): void {
        it('creates new instance with manager from container', function (): void {
            $builder = MenuBuilder::make();

            expect($builder)->toBeInstanceOf(MenuBuilder::class);
        });

        it('creates instance with custom connection', function (): void {
            $builder = MenuBuilder::make('secondary');

            expect($builder)->toBeInstanceOf(MenuBuilder::class);
        });
    });

    describe('add method', function (): void {
        it('adds menu item without permission check', function (): void {
            $this->builder->add('Home', '/home');

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
            expect($items[0]['label'])->toBe('Home');
            expect($items[0]['url'])->toBe('/home');
            expect($items[0]['relation'])->toBeNull();
            expect($items[0]['object'])->toBeNull();
            expect($items[0]['attributes'])->toBe([]);
        });

        it('adds menu item with permission check', function (): void {
            // Mock no authentication to test that item structure is correct
            Auth::shouldReceive('check')->andReturn(false);

            $this->builder->add('Dashboard', '/dashboard', 'read', 'admin:dashboard');

            // Get items directly without build() to see the structure before filtering
            $reflection = new ReflectionClass($this->builder);
            $itemsProperty = $reflection->getProperty('items');
            $itemsProperty->setAccessible(true);

            $items = $itemsProperty->getValue($this->builder);

            expect($items)->toHaveCount(1);
            expect($items[0]['label'])->toBe('Dashboard');
            expect($items[0]['url'])->toBe('/dashboard');
            expect($items[0]['relation'])->toBe('read');
            expect($items[0]['object'])->toBe('admin:dashboard');
        });

        it('adds menu item with attributes', function (): void {
            $this->builder->add('Profile', '/profile', null, null, ['class' => 'nav-item']);

            $items = $this->builder->build();

            expect($items[0]['attributes'])->toBe(['class' => 'nav-item']);
        });

        it('returns self for method chaining', function (): void {
            $result = $this->builder->add('Home', '/home');

            expect($result)->toBe($this->builder);
        });
    });

    describe('addIfCan method', function (): void {
        it('is alias for add method with required permission parameters', function (): void {
            // Mock no authentication to test that item structure is correct
            Auth::shouldReceive('check')->andReturn(false);

            $this->builder->addIfCan('Settings', '/settings', 'admin', 'system:settings');

            // Get items directly without build() to see the structure before filtering
            $reflection = new ReflectionClass($this->builder);
            $itemsProperty = $reflection->getProperty('items');
            $itemsProperty->setAccessible(true);

            $items = $itemsProperty->getValue($this->builder);

            expect($items)->toHaveCount(1);
            expect($items[0]['label'])->toBe('Settings');
            expect($items[0]['relation'])->toBe('admin');
            expect($items[0]['object'])->toBe('system:settings');
        });
    });

    describe('divider method', function (): void {
        it('adds divider item', function (): void {
            $this->builder->divider();

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
            expect($items[0]['type'])->toBe('divider');
            expect($items[0]['label'])->toBe('');
            expect($items[0]['url'])->toBeNull();
        });

        it('returns self for method chaining', function (): void {
            $result = $this->builder->divider();

            expect($result)->toBe($this->builder);
        });
    });

    describe('submenu method', function (): void {
        it('creates submenu with callback', function (): void {
            $this->builder->submenu('Admin', function (MenuBuilder $submenu): void {
                $submenu->add('Users', '/admin/users');
                $submenu->add('Settings', '/admin/settings');
            });

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
            expect($items[0]['label'])->toBe('Admin');
            expect($items[0]['url'])->toBeNull();
            expect($items[0]['children'])->toBeInstanceOf(Collection::class);
            expect($items[0]['children'])->toHaveCount(2);
            expect($items[0]['children'][0]['label'])->toBe('Users');
            expect($items[0]['children'][1]['label'])->toBe('Settings');
        });

        it('creates submenu with permission check', function (): void {
            Auth::shouldReceive('check')->andReturn(false);

            $this->builder->submenu('Admin', function (MenuBuilder $submenu): void {
                $submenu->add('Users', '/admin/users');
            }, 'admin', 'system:admin');

            // Get items directly without build() to see the structure before filtering
            $reflection = new ReflectionClass($this->builder);
            $itemsProperty = $reflection->getProperty('items');
            $itemsProperty->setAccessible(true);

            $items = $itemsProperty->getValue($this->builder);

            expect($items[0]['relation'])->toBe('admin');
            expect($items[0]['object'])->toBe('system:admin');
        });

        it('returns self for method chaining', function (): void {
            $result = $this->builder->submenu('Admin', static fn (): null => null);

            expect($result)->toBe($this->builder);
        });
    });

    describe('permission filtering', function (): void {
        it('shows items without permission requirements when not authenticated', function (): void {
            Auth::shouldReceive('check')->andReturn(false);

            $this->builder->add('Home', '/home');
            $this->builder->add('Login', '/login', 'read', 'public:login');

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
            expect($items[0]['label'])->toBe('Home');
        });

        it('filters items based on permissions when authenticated', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->twice()->andReturn(true);
            Auth::shouldReceive('user')->twice()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'admin:dashboard', [], [], null)
                ->once()
                ->andReturn(true);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'admin', 'system:settings', [], [], null)
                ->once()
                ->andReturn(false);

            $this->builder->add('Home', '/home');
            $this->builder->add('Dashboard', '/dashboard', 'read', 'admin:dashboard');
            $this->builder->add('Settings', '/settings', 'admin', 'system:settings');

            $items = $this->builder->build();

            expect($items)->toHaveCount(2);
            expect($items[0]['label'])->toBe('Home');
            expect($items[1]['label'])->toBe('Dashboard');
        });

        it('always shows dividers', function (): void {
            Auth::shouldReceive('check')->andReturn(false);

            $this->builder->add('Home', '/home');
            $this->builder->divider();
            $this->builder->add('Hidden', '/hidden', 'admin', 'system:admin');

            $items = $this->builder->build();

            expect($items)->toHaveCount(2);
            expect($items[0]['label'])->toBe('Home');
            expect($items[1]['type'])->toBe('divider');
        });

        it('filters submenu items recursively', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->andReturn(true);
            Auth::shouldReceive('user')->andReturn($user);

            // Set up permission checks - Users should be visible, Settings should be hidden
            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'admin:users', [], [], null)
                ->andReturn(true);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'admin', 'system:settings', [], [], null)
                ->andReturn(false);

            $this->builder->submenu('Admin', function (MenuBuilder $submenu): void {
                $submenu->add('Users', '/admin/users', 'read', 'admin:users');
                $submenu->add('Settings', '/admin/settings', 'admin', 'system:settings');
            });

            $items = $this->builder->build();

            // Find the Admin submenu
            $adminSubmenu = $items->firstWhere('label', 'Admin');
            expect($adminSubmenu)->not()->toBeNull();

            // Verify the submenu has children and contains Users
            expect($adminSubmenu['children']->count())->toBeGreaterThan(0);
            expect($adminSubmenu['children']->firstWhere('label', 'Users'))->not()->toBeNull();
        });

        it('hides parent menu when all children are hidden', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'admin', 'system:settings', [], [], null)
                ->once()
                ->andReturn(false);

            $this->builder->submenu('Admin', function (MenuBuilder $submenu): void {
                $submenu->add('Settings', '/admin/settings', 'admin', 'system:settings');
            });

            $items = $this->builder->build();

            expect($items)->toHaveCount(0);
        });
    });

    describe('object resolution', function (): void {
        it('handles string objects', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'doc:456', [], [], null)
                ->once()
                ->andReturn(true);

            $this->builder->add('Document', '/doc/456', 'read', 'doc:456');

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
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

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'custom:789', [], [], null)
                ->once()
                ->andReturn(true);

            $this->builder->add('Custom', '/custom', 'read', $object);

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
        });

        it('handles models with AuthorizationType interface', function (): void {
            $model = Mockery::mock(Model::class, AuthorizationType::class);
            $model->shouldReceive('authorizationType')->andReturn('document');
            $model->shouldReceive('getKey')->andReturn(999);
            $model->shouldReceive('getKeyType')->andReturn('int');

            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'document:999', [], [], null)
                ->once()
                ->andReturn(true);

            $this->builder->add('Document', '/doc/999', 'read', $model);

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
        });

        it('handles regular Eloquent models', function (): void {
            $model = Mockery::mock(Model::class);
            $model->shouldReceive('getTable')->andReturn('posts');
            $model->shouldReceive('getKey')->andReturn(42);
            $model->shouldReceive('getKeyType')->andReturn('int');

            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'posts:42', [], [], null)
                ->once()
                ->andReturn(true);

            $this->builder->add('Post', '/posts/42', 'read', $model);

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
        });

        it('handles numeric objects', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'menu-item:789', [], [], null)
                ->once()
                ->andReturn(true);

            $this->builder->add('Item', '/item/789', 'read', 789);

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
        });

        it('throws exception for invalid object types', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->builder->add('Invalid', '/invalid', 'read', ['invalid']);

            expect(fn () => $this->builder->build())
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

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->builder->add('Invalid', '/invalid', 'read', $object);

            expect(fn () => $this->builder->build())
                ->toThrow(InvalidArgumentException::class, 'authorizationObject() must return a string or stringable value');
        });

        it('handles stringable objects from authorizationObject', function (): void {
            $stringable = new class {
                public function __toString(): string
                {
                    return 'stringable:123';
                }
            };

            $object = new readonly class($stringable) {
                public function __construct(private object $stringable)
                {
                }

                public function authorizationObject(): object
                {
                    return $this->stringable;
                }
            };

            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'stringable:123', [], [], null)
                ->once()
                ->andReturn(true);

            $this->builder->add('Stringable', '/stringable', 'read', $object);

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
        });
    });

    describe('user resolution', function (): void {
        it('uses authorizationUser method when available', function (): void {
            $user = new class implements Authenticatable, AuthorizationUser {
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

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('custom:user:123', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturn(true);

            $this->builder->add('Document', '/doc', 'read', 'doc:123');

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
        });

        it('uses getAuthorizationUserId method when available', function (): void {
            $user = new class implements Authenticatable, AuthorizationUserId {
                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthorizationUserId(): string
                {
                    return '456';
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

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('456', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturn(true);

            $this->builder->add('Document', '/doc', 'read', 'doc:123');

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
        });

        it('falls back to getAuthIdentifier', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(789);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:789', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturn(true);

            $this->builder->add('Document', '/doc', 'read', 'doc:123');

            $items = $this->builder->build();

            expect($items)->toHaveCount(1);
        });

        it('throws exception when getAuthIdentifier returns non-scalar', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(['invalid']);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->builder->add('Document', '/doc', 'read', 'doc:123');

            expect(fn () => $this->builder->build())
                ->toThrow(RuntimeException::class, 'User identifier must be string or int');
        });
    });

    describe('render method', function (): void {
        it('renders menu using specified view', function (): void {
            $viewObject = Mockery::mock(ViewObject::class);
            $viewObject->shouldReceive('render')->once()->andReturn('<nav>Menu HTML</nav>');

            View::shouldReceive('make')
                ->with('custom::menu', Mockery::on(static fn ($data): bool => isset($data['extra']) && 'data' === $data['extra'] && isset($data['items']) && $data['items'] instanceof Collection), [])
                ->once()
                ->andReturn($viewObject);

            $this->builder->add('Home', '/home');

            $html = $this->builder->render('custom::menu', ['extra' => 'data']);

            expect($html)->toBe('<nav>Menu HTML</nav>');
        });

        it('uses default view when none specified', function (): void {
            $viewObject = Mockery::mock(ViewObject::class);
            $viewObject->shouldReceive('render')->once()->andReturn('<nav>Default Menu</nav>');

            View::shouldReceive('make')
                ->with('openfga::menu', Mockery::on(static fn ($data): bool => isset($data['items']) && $data['items'] instanceof Collection), [])
                ->once()
                ->andReturn($viewObject);

            $this->builder->add('Home', '/home');

            $html = $this->builder->render();

            expect($html)->toBe('<nav>Default Menu</nav>');
        });
    });

    describe('toArray method', function (): void {
        it('returns menu items as array', function (): void {
            $this->builder->add('Home', '/home');
            $this->builder->add('About', '/about');

            $array = $this->builder->toArray();

            expect($array)->toBeArray();
            expect($array)->toHaveCount(2);
            expect($array[0]['label'])->toBe('Home');
            expect($array[1]['label'])->toBe('About');
        });
    });

    describe('connection handling', function (): void {
        it('uses custom connection for permission checks', function (): void {
            $builder = new MenuBuilder(manager: $this->manager, connection: 'secondary');

            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturn(true);
            Auth::shouldReceive('user')->once()->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'admin', 'system:admin', [], [], 'secondary')
                ->once()
                ->andReturn(true);

            $builder->add('Admin', '/admin', 'admin', 'system:admin');

            $items = $builder->build();

            expect($items)->toHaveCount(1);
        });
    });
});
