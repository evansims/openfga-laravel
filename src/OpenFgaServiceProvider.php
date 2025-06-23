<?php

declare(strict_types=1);

namespace OpenFGA\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use OpenFGA\{Client, ClientInterface};
use OpenFGA\Laravel\Console;
use Override;

use function gettype;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

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
            ]);
        }

        $this->registerMiddleware();
        $this->registerAuthorizationIntegration();
        $this->registerBladeIntegration();
        $this->loadHelpers();
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
    }

    /**
     * Register view helper services.
     */
    private function registerViewHelpers(): void
    {
        $this->app->singleton(View\JavaScriptHelper::class, function ($app) {
            return new View\JavaScriptHelper($app[OpenFgaManager::class]);
        });

        $this->app->bind(View\MenuBuilder::class, function ($app) {
            return new View\MenuBuilder($app[OpenFgaManager::class]);
        });
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
     * Register the OpenFGA manager.
     *
     * @throws LogicException
     */
    private function registerManager(): void
    {
        $this->app->singleton(OpenFgaManager::class, static function (Application $app) {
            /** @var Repository $configRepository */
            $configRepository = $app->make('config');

            /** @var array{default?: string, connections?: array<string, array<string, mixed>>, cache?: array<string, mixed>, queue?: array<string, mixed>, logging?: array<string, mixed>} $config */
            $config = $configRepository->get('openfga', []);

            return new OpenFgaManager($app, $config);
        });

        $this->app->alias(OpenFgaManager::class, 'openfga.manager');
    }

    /**
     * Register OpenFGA middleware.
     */
    private function registerMiddleware(): void
    {
        if (!$this->app->bound('router')) {
            return;
        }
        
        $router = $this->app['router'];

        $router->aliasMiddleware('openfga', Http\Middleware\OpenFgaMiddleware::class);
        $router->aliasMiddleware('openfga.permission', Http\Middleware\RequiresPermission::class);
        $router->aliasMiddleware('openfga.any', Http\Middleware\RequiresAnyPermission::class);
        $router->aliasMiddleware('openfga.all', Http\Middleware\RequiresAllPermissions::class);
        $router->aliasMiddleware('openfga.load', Http\Middleware\LoadPermissions::class);
    }

    /**
     * Register OpenFGA authorization integration.
     */
    private function registerAuthorizationIntegration(): void
    {
        // Register the authorization service provider
        $this->app->register(Authorization\AuthorizationServiceProvider::class);
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
     * Load Blade components.
     *
     * @param array<string, string> $components
     */
    private function loadBladeComponentsFrom(array $components): void
    {
        foreach ($components as $alias => $class) {
            $this->app['blade.compiler']->component($class, $alias);
        }
    }

    /**
     * Load helper functions.
     */
    private function loadHelpers(): void
    {
        if (file_exists(__DIR__ . '/helpers.php')) {
            require_once __DIR__ . '/helpers.php';
        }
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
