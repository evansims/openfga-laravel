<?php

declare(strict_types=1);

namespace OpenFGA\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use OpenFGA\{Client, ClientInterface};
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Profiling\OpenFgaProfiler;
use OpenFGA\Laravel\View\{JavaScriptHelper, MenuBuilder};
use Override;
use RuntimeException;

use function gettype;
use function in_array;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Service provider for integrating OpenFGA with Laravel.
 *
 * This provider registers the OpenFGA client, configuration, middleware, and all
 * related services into the Laravel container. It handles authentication setup,
 * cache configuration, Blade integration, and command registration. The provider
 * is deferred for optimal performance, only loading when OpenFGA services are
 * actually requested.
 *
 * @api
 */
final class OpenFgaServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Bootstrap the application services.
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/openfga.php' => config_path('openfga.php'),
            ], 'openfga-config');

            $this->commands([
                Console\Commands\MakePermissionMigrationCommand::class,
                Console\Commands\MakePermissionSeederCommand::class,
                Console\Commands\CheckCommand::class,
                Console\Commands\GrantCommand::class,
                Console\Commands\RevokeCommand::class,
                Console\Commands\ExpandCommand::class,
                Console\Commands\ListObjectsCommand::class,
                Console\Commands\DebugCommand::class,
                Console\Commands\StatsCommand::class,
                Console\Commands\WarmCacheCommand::class,
                Console\Commands\ClearCacheCommand::class,
                Console\Commands\CacheStatsCommand::class,
                Console\Commands\MigrateFromSpatieCommand::class,
                Console\Commands\SnapshotCommand::class,
                Console\Commands\BenchmarkCommand::class,
                Console\Commands\SetupIntegrationTestsCommand::class,
                Console\Commands\ModelCreateCommand::class,
                Console\Commands\ModelValidateCommand::class,
                Console\Commands\StoreCreateCommand::class,
                Console\Commands\AuditPermissionsCommand::class,
                Console\Commands\AnalyzePermissionsCommand::class,
                Console\Commands\WebhookCommand::class,
                Console\Commands\ImportCommand::class,
                Console\Commands\ExportCommand::class,
                Console\Commands\ProfileCommand::class,
            ]);
        }

        $this->registerMiddleware();
        $this->registerAuthorizationIntegration();
        $this->registerBladeIntegration();
        $this->registerSpatieCompatibility();
        $this->registerDebugbarIntegration();
        $this->registerWebhooks();
        $this->registerProfiling();
        // Helper functions are autoloaded via composer.json
        $this->validateConfiguration();
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            OpenFgaManager::class,
            ClientInterface::class,
            Client::class,
            'openfga',
            'openfga.manager',
        ];
    }

    /**
     * Register the service provider.
     *
     * @throws LogicException
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/openfga.php', 'openfga');

        $this->registerManager();
        $this->registerDefaultClient();
        $this->registerViewHelpers();
        $this->registerImportExport();
    }

    /**
     * Load Blade components.
     *
     * @param array<string, string> $components
     */
    private function loadBladeComponentsFrom(array $components): void
    {
        if (! $this->app->bound('blade.compiler')) {
            return;
        }

        try {
            $bladeCompiler = $this->app->make('blade.compiler');
        } catch (BindingResolutionException) {
            return;
        }

        if (! is_object($bladeCompiler) || ! method_exists($bladeCompiler, 'component')) {
            return;
        }

        foreach ($components as $alias => $class) {
            $bladeCompiler->component($class, $alias);
        }
    }

    // loadHelpers method removed - helpers are autoloaded via composer.json

    /**
     * Register OpenFGA authorization integration.
     */
    private function registerAuthorizationIntegration(): void
    {
        // Register the authorization service provider
        $this->app->register(Authorization\AuthorizationServiceProvider::class);
    }

    /**
     * Register Blade components.
     */
    private function registerBladeComponents(): void
    {
        if (method_exists($this->app, 'make') && $this->app->bound('blade.compiler')) {
            $this->loadBladeComponentsFrom([
                'openfga-can' => View\Components\Can::class,
                'openfga-cannot' => View\Components\Cannot::class,
                'openfga-can-any' => View\Components\CanAny::class,
                'openfga-can-all' => View\Components\CanAll::class,
            ]);
        }
    }

    /**
     * Register OpenFGA Blade integration.
     */
    private function registerBladeIntegration(): void
    {
        // Register the Blade service provider
        $this->app->register(View\BladeServiceProvider::class);

        // Register Blade components
        $this->registerBladeComponents();

        // Register view paths
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'openfga');
    }

    /**
     * Register Laravel Debugbar integration if available.
     */
    private function registerDebugbarIntegration(): void
    {
        if (class_exists(\Barryvdh\Debugbar\ServiceProvider::class)) {
            $this->app->register(Debugbar\DebugbarServiceProvider::class);
        }
    }

    /**
     * Register the default client binding.
     *
     * @throws LogicException
     */
    private function registerDefaultClient(): void
    {
        $this->app->bind(ClientInterface::class, static function (Application $app) {
            $manager = $app->make(OpenFgaManager::class);

            return $manager->connection();
        });

        $this->app->alias(ClientInterface::class, 'openfga');
        $this->app->alias(ClientInterface::class, Client::class);
    }

    /**
     * Register import/export services.
     */
    private function registerImportExport(): void
    {
        $this->app->bind(Import\PermissionImporter::class);
        $this->app->bind(Export\PermissionExporter::class);
    }

    /**
     * Register the OpenFGA manager.
     *
     * @throws LogicException
     */
    private function registerManager(): void
    {
        $this->app->singleton(OpenFgaManager::class, static function (Application $app): OpenFgaManager {
            /** @var Repository $configRepository */
            $configRepository = $app->make('config');

            /** @var array{default?: string, connections?: array<string, array<string, mixed>>, cache?: array<string, mixed>, queue?: array<string, mixed>, logging?: array<string, mixed>} $config */
            $config = $configRepository->get('openfga', []);

            return new OpenFgaManager($app, $config);
        });

        $this->app->alias(OpenFgaManager::class, 'openfga.manager');
        $this->app->bind(ManagerInterface::class, OpenFgaManager::class);
    }

    /**
     * Register OpenFGA middleware.
     */
    private function registerMiddleware(): void
    {
        if (! $this->app->bound('router')) {
            return;
        }

        try {
            $router = $this->app->make('router');
        } catch (BindingResolutionException) {
            return;
        }

        if (! is_object($router) || ! method_exists($router, 'aliasMiddleware')) {
            return;
        }

        $router->aliasMiddleware('openfga', Http\Middleware\OpenFgaMiddleware::class);
        $router->aliasMiddleware('openfga.permission', Http\Middleware\RequiresPermission::class);
        $router->aliasMiddleware('openfga.any', Http\Middleware\RequiresAnyPermission::class);
        $router->aliasMiddleware('openfga.all', Http\Middleware\RequiresAllPermissions::class);
        $router->aliasMiddleware('openfga.load', Http\Middleware\LoadPermissions::class);
    }

    /**
     * Register profiling services.
     *
     * @throws BindingResolutionException
     */
    private function registerProfiling(): void
    {
        $this->app->singleton(OpenFgaProfiler::class);

        /** @var bool $profilingEnabled */
        $profilingEnabled = config('openfga.profiling.enabled', false);

        if ($profilingEnabled) {
            // Register profiling event listener
            /** @var Dispatcher $events */
            $events = $this->app->make('events');
            $events->subscribe(Listeners\ProfileOpenFgaOperations::class);

            // Register profiling middleware if web injection is enabled
            /** @var bool $injectMiddleware */
            $injectMiddleware = config('openfga.profiling.inject_web_middleware', false);

            if ($injectMiddleware && $this->app->bound('router')) {
                try {
                    /** @var mixed $router */
                    $router = $this->app->make('router');

                    if (is_object($router) && method_exists($router, 'pushMiddlewareToGroup')) {
                        $router->pushMiddlewareToGroup('web', Profiling\ProfilingMiddleware::class);
                    }
                } catch (BindingResolutionException) {
                    // Router not available, skip
                }
            }

            // Register Laravel Debugbar collector if enabled
            /** @var bool $debugbarEnabled */
            $debugbarEnabled = config('openfga.profiling.debugbar.enabled', true);

            if ($debugbarEnabled && class_exists(\Barryvdh\Debugbar\ServiceProvider::class) && $this->app->bound('debugbar')) {
                try {
                    /** @var mixed $debugbar */
                    $debugbar = $this->app->make('debugbar');

                    if (is_object($debugbar) && method_exists($debugbar, 'addCollector')) {
                        /** @var mixed $collectorClass */
                        $collectorClass = config('openfga.profiling.debugbar.collector');

                        if (is_string($collectorClass)) {
                            $debugbar->addCollector($this->app->make($collectorClass));
                        }
                    }
                } catch (BindingResolutionException) {
                    // Debugbar not available, skip
                }
            }
        }
    }

    /**
     * Register Spatie Laravel Permission compatibility layer if enabled.
     */
    private function registerSpatieCompatibility(): void
    {
        /** @var mixed $enabled */
        $enabled = config('spatie-compatibility.enabled', false);

        if (true === $enabled) {
            $this->app->register(Providers\SpatieCompatibilityServiceProvider::class);
        }
    }

    /**
     * Register view helper services.
     */
    private function registerViewHelpers(): void
    {
        $this->app->singleton(JavaScriptHelper::class, static function (Container $app): JavaScriptHelper {
            $manager = $app->get(OpenFgaManager::class);

            if (! $manager instanceof OpenFgaManager) {
                throw new RuntimeException('Failed to resolve OpenFgaManager from container');
            }

            return new JavaScriptHelper($manager);
        });

        $this->app->bind(MenuBuilder::class, static function (Container $app): MenuBuilder {
            $manager = $app->get(OpenFgaManager::class);

            if (! $manager instanceof OpenFgaManager) {
                throw new RuntimeException('Failed to resolve OpenFgaManager from container');
            }

            return new MenuBuilder($manager);
        });
    }

    /**
     * Register webhook support.
     */
    private function registerWebhooks(): void
    {
        $this->app->register(Webhooks\WebhookServiceProvider::class);
    }

    /**
     * Validate the configuration.
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     */
    private function validateConfiguration(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        /** @var array<string, mixed> $openfgaConfig */
        $openfgaConfig = $config->get('openfga', []);

        // Validate default connection exists
        /** @var string $defaultConnection */
        $defaultConnection = $openfgaConfig['default'] ?? 'main';

        /** @var array<string, array<string, mixed>>|null $connections */
        $connections = $openfgaConfig['connections'] ?? null;

        if (null === $connections || ! isset($connections[$defaultConnection])) {
            throw new InvalidArgumentException(sprintf('Default OpenFGA connection [%s] is not configured.', $defaultConnection));
        }

        // Validate each connection
        foreach ($connections as $name => $connection) {
            $this->validateConnection($name, $connection);
        }
    }

    /**
     * Validate a single connection configuration.
     *
     * @param string               $name
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    private function validateConnection(string $name, array $config): void
    {
        // Validate URL format
        if (isset($config['url']) && false === filter_var($config['url'], FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(sprintf('Invalid URL configured for OpenFGA connection [%s].', $name));
        }

        // Validate credentials
        if (isset($config['credentials']) && is_array($config['credentials']) && isset($config['credentials']['method'])) {
            $method = $config['credentials']['method'];

            if (! is_string($method) || ! in_array($method, ['none', 'api_token', 'client_credentials'], true)) {
                $methodStr = is_string($method) ? $method : gettype($method);

                throw new InvalidArgumentException(sprintf('Invalid authentication method [%s] for OpenFGA connection [%s]. ', $methodStr, $name) . 'Supported methods are: none, api_token, client_credentials.');
            }

            // Validate required fields for each auth method
            if ('api_token' === $method && (! isset($config['credentials']['token']) || ! is_string($config['credentials']['token']) || '' === $config['credentials']['token'])) {
                throw new InvalidArgumentException(sprintf('API token is required when using api_token authentication for connection [%s].', $name));
            }

            if ('client_credentials' === $method) {
                $required = ['client_id', 'client_secret', 'api_token_issuer', 'api_audience'];

                foreach ($required as $field) {
                    if (! isset($config['credentials'][$field]) || ! is_string($config['credentials'][$field]) || '' === $config['credentials'][$field]) {
                        throw new InvalidArgumentException(sprintf('Field [%s] is required when using client_credentials authentication for connection [%s].', $field, $name));
                    }
                }
            }
        }

        // Validate retry configuration
        if (isset($config['retries']) && is_array($config['retries']) && isset($config['retries']['max_retries'])) {
            $maxRetries = $config['retries']['max_retries'];

            if (! is_int($maxRetries) || 0 > $maxRetries) {
                throw new InvalidArgumentException(sprintf('Invalid max_retries value for OpenFGA connection [%s]. Must be a non-negative integer.', $name));
            }
        }

        // Validate HTTP options
        if (isset($config['http_options']) && is_array($config['http_options'])) {
            foreach (['timeout', 'connect_timeout'] as $option) {
                if (isset($config['http_options'][$option])) {
                    $value = $config['http_options'][$option];

                    if (! is_numeric($value) || 0 >= $value) {
                        throw new InvalidArgumentException(sprintf('Invalid %s value for OpenFGA connection [%s]. Must be a positive number.', $option, $name));
                    }
                }
            }
        }
    }
}
