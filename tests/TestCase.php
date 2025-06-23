<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use OpenFGA\Laravel\OpenFgaServiceProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

use function dirname;

class MockApplication extends Container implements Application
{
    public function basePath($path = '')
    {
        return __DIR__ . '/..' . ($path ? '/' . $path : '');
    }

    public function boot(): void
    {
    }

    public function booted($callback): void
    {
    }

    public function booting($callback): void
    {
    }

    public function bootstrapPath($path = '')
    {
        return $this->basePath('bootstrap') . ($path ? '/' . $path : '');
    }

    public function bootstrapWith(array $bootstrappers): void
    {
    }

    public function configPath($path = '')
    {
        return $this->basePath('config') . ($path ? '/' . $path : '');
    }

    public function databasePath($path = '')
    {
        return $this->basePath('database') . ($path ? '/' . $path : '');
    }

    public function environment(...$environments)
    {
        return 'testing';
    }

    public function getLocale()
    {
        return 'en';
    }

    public function getNamespace()
    {
        return 'App\\';
    }

    public function getProviders($provider)
    {
        return [];
    }

    public function hasBeenBootstrapped()
    {
        return true;
    }

    public function hasDebugModeEnabled()
    {
        return true;
    }

    public function isDownForMaintenance()
    {
        return false;
    }

    public function langPath($path = '')
    {
        return $this->basePath('lang') . ($path ? '/' . $path : '');
    }

    public function loadDeferredProviders(): void
    {
    }

    public function maintenanceMode()
    {
        return new class {
            public function active()
            {
                return false;
            }

            public function data()
            {
                return [];
            }

            public function driver()
            {
                return null;
            }
        };
    }

    public function publicPath($path = '')
    {
        return $this->basePath('public') . ($path ? '/' . $path : '');
    }

    public function register($provider, $force = false)
    {
        return new $provider($this);
    }

    public function registerConfiguredProviders(): void
    {
    }

    public function registerDeferredProvider($provider, $service = null): void
    {
    }

    public function resolveProvider($provider)
    {
        return new $provider($this);
    }

    public function resourcePath($path = '')
    {
        return $this->basePath('resources') . ($path ? '/' . $path : '');
    }

    public function runningInConsole()
    {
        return false;
    }

    public function runningUnitTests()
    {
        return true;
    }

    public function setLocale($locale): void
    {
    }

    public function shouldSkipMiddleware()
    {
        return false;
    }

    public function storagePath($path = '')
    {
        return $this->basePath('storage') . ($path ? '/' . $path : '');
    }

    public function terminate(): void
    {
    }

    public function terminating($callback): void
    {
    }

    public function version()
    {
        return '12.0.0';
    }
}

abstract class TestCase extends BaseTestCase
{
    protected MockApplication $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new MockApplication;

        // Set up configuration
        $this->app->singleton('config', fn () => new Repository([
            'openfga' => require dirname(__DIR__) . '/config/openfga.php',
        ]));

        $this->app->bind(ConfigContract::class, 'config');

        // Set container instance
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);

        // Register service provider
        $provider = new OpenFgaServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance(null);

        parent::tearDown();
    }
}
