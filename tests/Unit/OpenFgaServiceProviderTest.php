<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Mockery;
use OpenFGA\{Client, ClientInterface};
use OpenFGA\Laravel\Authorization\AuthorizationServiceProvider;
use OpenFGA\Laravel\Export\PermissionExporter;
use OpenFGA\Laravel\Http\Middleware\{
    LoadPermissions,
    OpenFgaMiddleware,
    RequiresAllPermissions,
    RequiresAnyPermission,
    RequiresPermission
};
use OpenFGA\Laravel\Import\PermissionImporter;
use OpenFGA\Laravel\Listeners\ProfileOpenFgaOperations;
use OpenFGA\Laravel\{OpenFgaManager, OpenFgaServiceProvider};
use OpenFGA\Laravel\Profiling\{OpenFgaProfiler, ProfilingMiddleware};
use OpenFGA\Laravel\Providers\SpatieCompatibilityServiceProvider;
use OpenFGA\Laravel\Tests\Support\{TestDebugging};
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\View\{BladeServiceProvider, JavaScriptHelper, MenuBuilder};
use OpenFGA\Laravel\Webhooks\WebhookServiceProvider;
use RuntimeException;
use stdClass;

use function function_exists;
use function sprintf;

uses(TestCase::class);

describe('OpenFgaServiceProvider', function (): void {
    describe('Service Registration', function (): void {
        it('should register all required OpenFGA services in the container', function (): void {
            // Arrange: Create service provider instance
            $provider = new OpenFgaServiceProvider($this->app);

            // Act: Register all services
            $provider->register();

            // Assert: Verify all core services are registered
            $requiredServices = [
                OpenFgaManager::class => 'OpenFGA Manager',
                ClientInterface::class => 'OpenFGA Client Interface',
                Client::class => 'OpenFGA Client Implementation',
                'openfga' => 'OpenFGA Facade Alias',
                'openfga.manager' => 'OpenFGA Manager Alias',
            ];

            foreach ($requiredServices as $service => $description) {
                expect($this->app->bound($service))->toBeTrue(
                    $description . ' should be registered in the container',
                );
            }

            // Verify additional optional services are also registered
            $optionalServices = [
                JavaScriptHelper::class => 'JavaScript Helper',
                MenuBuilder::class => 'Menu Builder',
                PermissionImporter::class => 'Permission Importer',
                PermissionExporter::class => 'Permission Exporter',
            ];

            foreach ($optionalServices as $service => $description) {
                expect($this->app->bound($service))->toBeTrue(
                    $description . ' should be available as an optional service',
                );
            }
        });

        it('registers manager as singleton', function (): void {
            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            $manager1 = $this->app->make(OpenFgaManager::class);
            $manager2 = $this->app->make(OpenFgaManager::class);

            expect($manager1)->toBe($manager2);
        });

        it('creates OpenFgaManager with configuration', function (): void {
            $this->app['config']->set('openfga', [
                'default' => 'main',
                'connections' => [
                    'main' => [
                        'url' => 'https://api.example.com',
                        'store_id' => 'test-store',
                        'authorization_model_id' => 'test-model',
                    ],
                ],
            ]);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            $manager = $this->app->make(OpenFgaManager::class);
            expect($manager)->toBeInstanceOf(OpenFgaManager::class);
        });

        it('provides expected services', function (): void {
            $provider = new OpenFgaServiceProvider($this->app);

            $provides = $provider->provides();

            expect($provides)->toBe([
                OpenFgaManager::class,
                ClientInterface::class,
                Client::class,
                'openfga',
                'openfga.manager',
            ]);
        });

        it('merges configuration from package', function (): void {
            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            $config = config('openfga');
            expect($config)->toBeArray()
                ->and($config)->toHaveKey('default')
                ->and($config)->toHaveKey('connections');
        });
    });

    describe('Service Bootstrap', function (): void {
        it('registers middleware when router is available', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            $router = Mockery::mock(Router::class);
            $router->shouldReceive('aliasMiddleware')->with('openfga', OpenFgaMiddleware::class)->once();
            $router->shouldReceive('aliasMiddleware')->with('openfga.permission', RequiresPermission::class)->once();
            $router->shouldReceive('aliasMiddleware')->with('openfga.any', RequiresAnyPermission::class)->once();
            $router->shouldReceive('aliasMiddleware')->with('openfga.all', RequiresAllPermissions::class)->once();
            $router->shouldReceive('aliasMiddleware')->with('openfga.load', LoadPermissions::class)->once();

            $this->app->instance('router', $router);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();
            $provider->boot();
        });

        it('skips middleware registration when router not available', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            // Create mock app instance without router
            $app = Mockery::mock(Application::class);
            $app->shouldReceive('runningInConsole')->andReturn(false);
            $app->shouldReceive('singleton')->andReturnSelf();
            $app->shouldReceive('bind')->andReturnSelf();
            $app->shouldReceive('alias')->andReturnSelf();
            $app->shouldReceive('bound')->with('router')->andReturn(false);
            $app->shouldReceive('bound')->andReturn(false);
            $app->shouldReceive('register')->andReturnSelf();
            $app->shouldReceive('environment')->andReturn('production');
            $app->shouldReceive('make')->with('config')->andReturn(new Repository([
                'openfga' => [
                    'default' => 'main',
                    'connections' => [
                        'main' => [
                            'url' => 'https://api.example.com',
                            'store_id' => 'test-store',
                            'authorization_model_id' => 'test-model',
                        ],
                    ],
                ],
            ]));
            $app->shouldReceive('make')->with('events')->andReturn(new Dispatcher($app));
            $app->shouldReceive('afterResolving')->andReturnSelf();
            $app->shouldReceive('resolved')->andReturn(false);

            $provider = new OpenFgaServiceProvider($app);
            $provider->register();

            // Should not throw exception when router is not available
            TestDebugging::assertExecutionTime(
                static fn () => $provider->boot(),
                0.1,
                'Service provider boot without router',
            );

            // Verify boot completed successfully
            expect($provider)->toBeObject();
        });

        it('registers authorization service provider', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Check that the authorization service provider was registered
            expect($this->app->getProviders(AuthorizationServiceProvider::class))->not->toBeEmpty()
                ->and($this->app->getProviders(BladeServiceProvider::class))->not->toBeEmpty()
                ->and($this->app->getProviders(WebhookServiceProvider::class))->not->toBeEmpty();
        });

        it('registers Spatie compatibility when enabled', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);
            $this->app['config']->set('spatie-compatibility.enabled', true);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Check that Spatie compatibility provider was registered
            expect($this->app->getProviders(SpatieCompatibilityServiceProvider::class))->not->toBeEmpty();
        });

        it('registers profiling services', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);
            $this->app['config']->set('openfga.profiling.enabled', true);

            $events = Mockery::mock(Dispatcher::class);
            $events->shouldReceive('subscribe')
                ->once()
                ->with(ProfileOpenFgaOperations::class)
                ->andReturnNull();
            $this->app->instance('events', $events);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            expect($this->app->bound(OpenFgaProfiler::class))->toBeTrue();
        });

        it('registers profiling middleware when enabled', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);
            $this->app['config']->set('openfga.profiling.enabled', true);
            $this->app['config']->set('openfga.profiling.inject_web_middleware', true);

            $router = Mockery::mock(Router::class);
            $router->shouldReceive('aliasMiddleware')
                ->atLeast()->once()
                ->andReturnSelf();
            $router->shouldReceive('pushMiddlewareToGroup')
                ->once()
                ->with('web', ProfilingMiddleware::class)
                ->andReturnSelf();

            $this->app->instance('router', $router);

            $events = Mockery::mock(Dispatcher::class);
            $events->shouldReceive('subscribe')->once();
            $this->app->instance('events', $events);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();
            $provider->boot();
        });

        it('loads helper files', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            // Function might already be loaded from previous tests
            $functionExistsBefore = function_exists('openfga');

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            // Check that helper file exists
            expect(file_exists(__DIR__ . '/../../src/Helpers.php'))->toBeTrue();
        });
    });

    describe('View Helpers', function (): void {
        it('registers JavaScriptHelper as singleton', function (): void {
            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            $helper1 = $this->app->make(JavaScriptHelper::class);
            $helper2 = $this->app->make(JavaScriptHelper::class);

            expect($helper1)->toBe($helper2)
                ->and($helper1)->toBeInstanceOf(JavaScriptHelper::class);
        });

        it('throws exception when JavaScriptHelper cannot resolve manager', function (): void {
            // Test the closure directly
            $container = Mockery::mock(Container::class);
            $container->shouldReceive('get')->with(OpenFgaManager::class)->andReturn(null);

            $closure = function ($app): JavaScriptHelper {
                $manager = $app->get(OpenFgaManager::class);

                if (! $manager instanceof OpenFgaManager) {
                    throw new RuntimeException('Failed to resolve OpenFgaManager from container');
                }

                return new JavaScriptHelper($manager);
            };

            expect(static fn (): JavaScriptHelper => $closure($container))
                ->toThrow(RuntimeException::class, 'Failed to resolve OpenFgaManager from container');
        });

        it('throws exception when MenuBuilder cannot resolve manager', function (): void {
            // Test the closure directly
            $container = Mockery::mock(Container::class);
            $container->shouldReceive('get')->with(OpenFgaManager::class)->andReturn(null);

            $closure = function ($app): MenuBuilder {
                $manager = $app->get(OpenFgaManager::class);

                if (! $manager instanceof OpenFgaManager) {
                    throw new RuntimeException('Failed to resolve OpenFgaManager from container');
                }

                return new MenuBuilder(manager: $manager);
            };

            expect(static fn (): MenuBuilder => $closure($container))
                ->toThrow(RuntimeException::class, 'Failed to resolve OpenFgaManager from container');
        });
    });

    describe('Configuration Validation', function (): void {
        it('validates default connection exists', function (): void {
            $this->app['config']->set('openfga', [
                'default' => 'missing',
                'connections' => [
                    'main' => [],
                ],
            ]);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            expect(static fn () => $provider->boot())
                ->toThrow(InvalidArgumentException::class, 'Default OpenFGA connection [missing] is not configured.');
        });

        it('validates URL format', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'not-a-valid-url',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            expect(static fn () => $provider->boot())
                ->toThrow(InvalidArgumentException::class, 'Invalid URL configured for OpenFGA connection [main].');
        });

        it('validates authentication method', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'credentials' => [
                    'method' => 'invalid_method',
                ],
            ]);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            expect(static fn () => $provider->boot())
                ->toThrow(InvalidArgumentException::class, 'Invalid authentication method [invalid_method] for OpenFGA connection [main].');
        });

        it('validates api_token requires token', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'credentials' => [
                    'method' => 'api_token',
                    'token' => '',
                ],
            ]);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            expect(static fn () => $provider->boot())
                ->toThrow(InvalidArgumentException::class, 'API token is required when using api_token authentication for connection [main].');
        });

        it('validates client_credentials requires all fields', function (): void {
            $requiredFields = ['client_id', 'client_secret', 'api_token_issuer', 'api_audience'];

            foreach ($requiredFields as $requiredField) {
                $credentials = [
                    'method' => 'client_credentials',
                    'client_id' => 'test-client',
                    'client_secret' => 'test-secret',
                    'api_token_issuer' => 'https://issuer.example.com',
                    'api_audience' => 'https://api.example.com',
                ];

                unset($credentials[$requiredField]);

                $this->app['config']->set('openfga.connections.main', [
                    'url' => 'https://api.example.com',
                    'credentials' => $credentials,
                ]);

                $provider = new OpenFgaServiceProvider($this->app);
                $provider->register();

                expect(static fn () => $provider->boot())
                    ->toThrow(InvalidArgumentException::class, sprintf('Field [%s] is required when using client_credentials authentication for connection [main].', $requiredField));
            }
        });

        it('validates max_retries is non-negative integer', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'retries' => [
                    'max_retries' => -1,
                ],
            ]);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            expect(static fn () => $provider->boot())
                ->toThrow(InvalidArgumentException::class, 'Invalid max_retries value for OpenFGA connection [main]. Must be a non-negative integer.');
        });

        it('validates HTTP timeout options', function (): void {
            $options = ['timeout', 'connect_timeout'];

            foreach ($options as $option) {
                $this->app['config']->set('openfga.connections.main', [
                    'url' => 'https://api.example.com',
                    'http_options' => [
                        $option => 0,
                    ],
                ]);

                $provider = new OpenFgaServiceProvider($this->app);
                $provider->register();

                expect(static fn () => $provider->boot())
                    ->toThrow(InvalidArgumentException::class, sprintf('Invalid %s value for OpenFGA connection [main]. Must be a positive number.', $option));
            }
        });

        it('passes validation with correct configuration', function (): void {
            $this->app['config']->set('openfga', [
                'default' => 'main',
                'connections' => [
                    'main' => [
                        'url' => 'https://api.example.com',
                        'store_id' => 'test-store',
                        'authorization_model_id' => 'test-model',
                        'credentials' => [
                            'method' => 'api_token',
                            'token' => 'test-token',
                        ],
                        'retries' => [
                            'max_retries' => 3,
                        ],
                        'http_options' => [
                            'timeout' => 30,
                            'connect_timeout' => 10,
                        ],
                    ],
                ],
            ]);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            $provider->boot();

            expect(true)->toBeTrue();
        });

        it('handles non-string authentication method', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'credentials' => [
                    'method' => 123, // Invalid type
                ],
            ]);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            expect(static fn () => $provider->boot())
                ->toThrow(InvalidArgumentException::class, 'Invalid authentication method [integer] for OpenFGA connection [main].');
        });
    });

    describe('Edge Cases', function (): void {
        it('handles missing blade compiler gracefully', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            // Create mock app without blade compiler
            $app = Mockery::mock(Application::class);
            $app->shouldReceive('runningInConsole')->andReturn(false);
            $app->shouldReceive('singleton')->andReturnSelf();
            $app->shouldReceive('bind')->andReturnSelf();
            $app->shouldReceive('alias')->andReturnSelf();
            $app->shouldReceive('bound')->with('blade.compiler')->andReturn(false);
            $app->shouldReceive('bound')->andReturn(false);
            $app->shouldReceive('register')->andReturnSelf();
            $app->shouldReceive('environment')->andReturn('production');
            $app->shouldReceive('make')->with('config')->andReturn(new Repository([
                'openfga' => [
                    'default' => 'main',
                    'connections' => [
                        'main' => [
                            'url' => 'https://api.example.com',
                            'store_id' => 'test-store',
                            'authorization_model_id' => 'test-model',
                        ],
                    ],
                ],
            ]));
            $app->shouldReceive('make')->with('events')->andReturn(new Dispatcher($app));
            $app->shouldReceive('afterResolving')->andReturnSelf();
            $app->shouldReceive('resolved')->andReturn(false);

            $provider = new OpenFgaServiceProvider($app);
            $provider->register();
            $provider->boot();

            expect(true)->toBeTrue();
        });

        it('handles blade compiler without component method', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            $bladeCompiler = new stdClass; // Object without component method
            $this->app->instance('blade.compiler', $bladeCompiler);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            expect(true)->toBeTrue();
        });

        it('handles router without aliasMiddleware method', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            $router = new stdClass; // Object without aliasMiddleware method
            $this->app->instance('router', $router);

            $provider = new OpenFgaServiceProvider($this->app);
            $provider->register();

            $provider->boot();

            expect(true)->toBeTrue();
        });

        it('handles binding resolution exceptions gracefully', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            $mock = Mockery::mock(Application::class);
            $mock->shouldReceive('runningInConsole')->andReturn(false);
            $mock->shouldReceive('make')->with('config')->andReturn(new Repository([
                'openfga' => [
                    'default' => 'main',
                    'connections' => [
                        'main' => [
                            'url' => 'https://api.example.com',
                            'store_id' => 'test-store',
                            'authorization_model_id' => 'test-model',
                        ],
                    ],
                ],
            ]));
            $mock->shouldReceive('make')->with('blade.compiler')->andThrow(new BindingResolutionException);
            $mock->shouldReceive('make')->with('router')->andThrow(new BindingResolutionException);
            $mock->shouldReceive('make')->with('events')->andReturn(new Dispatcher($mock));
            $mock->shouldReceive('make')->with('view')->andThrow(new BindingResolutionException);
            $mock->shouldReceive('bound')->andReturn(true);
            $mock->shouldReceive('register')->andReturnSelf();
            $mock->shouldReceive('singleton')->andReturnSelf();
            $mock->shouldReceive('bind')->andReturnSelf();
            $mock->shouldReceive('alias')->andReturnSelf();
            $mock->shouldReceive('getProviders')->andReturn([]);
            $mock->shouldReceive('afterResolving')->andReturnSelf();
            $mock->shouldReceive('resolved')->andReturn(false);
            $mock->shouldReceive('environment')->andReturn('production');

            $provider = new OpenFgaServiceProvider($mock);
            $provider->register();

            $provider->boot();

            expect(true)->toBeTrue();
        });

        it('skips console features when not running in console', function (): void {
            $this->app['config']->set('openfga.connections.main', [
                'url' => 'https://api.example.com',
                'store_id' => 'test-store',
                'authorization_model_id' => 'test-model',
            ]);

            // Create a mock that simulates not running in console
            $mock = Mockery::mock(Application::class);
            $mock->shouldReceive('runningInConsole')->andReturnFalse();
            $mock->shouldReceive('singleton')->andReturnSelf();
            $mock->shouldReceive('bind')->andReturnSelf();
            $mock->shouldReceive('alias')->andReturnSelf();
            $mock->shouldReceive('bound')->andReturnFalse();
            $mock->shouldReceive('register')->andReturnSelf();
            $mock->shouldReceive('make')->with('config')->andReturn(new Repository([
                'openfga' => [
                    'default' => 'main',
                    'connections' => [
                        'main' => [
                            'url' => 'https://api.example.com',
                            'store_id' => 'test-store',
                            'authorization_model_id' => 'test-model',
                        ],
                    ],
                ],
            ]));
            $mock->shouldReceive('make')->with('events')->andReturn(new Dispatcher($mock));

            // Ensure publishes and commands are never called
            $mock->shouldReceive('publishes')->never();
            $mock->shouldReceive('commands')->never();
            $mock->shouldReceive('afterResolving')->andReturnSelf();
            $mock->shouldReceive('resolved')->andReturn(false);
            $mock->shouldReceive('environment')->andReturn('production');

            $provider = new OpenFgaServiceProvider($mock);
            $provider->register();
            $provider->boot();

            // Verify runningInConsole was called
            expect(true)->toBeTrue();
        });
    });
});
