<?php

declare(strict_types=1);

namespace OpenFGA\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use OpenFGA\{Client, ClientInterface};
use Override;

use function gettype;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

final class OpenFgaServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Bootstrap the application services.
     */
    /**
     * @throws InvalidArgumentException
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/openfga.php' => config_path('openfga.php'),
            ], 'openfga-config');
        }

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
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/openfga.php', 'openfga');

        $this->registerManager();
        $this->registerDefaultClient();
    }

    /**
     * Register the default client binding.
     */
    protected function registerDefaultClient(): void
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
     */
    protected function registerManager(): void
    {
        $this->app->singleton(OpenFgaManager::class, function (Application $app) {
            /** @var Repository $configRepository */
            $configRepository = $app->make('config');

            /** @var array{default?: string, connections?: array<string, array<string, mixed>>, cache?: array<string, mixed>, queue?: array<string, mixed>, logging?: array<string, mixed>} $config */
            $config = $configRepository->get('openfga', []);

            return new OpenFgaManager($app, $config);
        });

        $this->app->alias(OpenFgaManager::class, 'openfga.manager');
    }

    /**
     * Validate the configuration.
     *
     * @throws InvalidArgumentException
     */
    protected function validateConfiguration(): void
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
            throw new InvalidArgumentException("Default OpenFGA connection [{$defaultConnection}] is not configured.");
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
    protected function validateConnection(string $name, array $config): void
    {
        // Validate URL format
        if (isset($config['url']) && false === filter_var($config['url'], FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL configured for OpenFGA connection [{$name}].");
        }

        // Validate credentials
        if (isset($config['credentials']) && is_array($config['credentials']) && isset($config['credentials']['method'])) {
            $method = $config['credentials']['method'];

            if (! is_string($method) || ! in_array($method, ['none', 'api_token', 'client_credentials'], true)) {
                $methodStr = is_string($method) ? $method : gettype($method);

                throw new InvalidArgumentException("Invalid authentication method [{$methodStr}] for OpenFGA connection [{$name}]. " . 'Supported methods are: none, api_token, client_credentials.');
            }

            // Validate required fields for each auth method
            if ('api_token' === $method && (! isset($config['credentials']['token']) || ! is_string($config['credentials']['token']) || '' === $config['credentials']['token'])) {
                throw new InvalidArgumentException("API token is required when using api_token authentication for connection [{$name}].");
            }

            if ('client_credentials' === $method) {
                $required = ['client_id', 'client_secret', 'api_token_issuer', 'api_audience'];

                foreach ($required as $field) {
                    if (! isset($config['credentials'][$field]) || ! is_string($config['credentials'][$field]) || '' === $config['credentials'][$field]) {
                        throw new InvalidArgumentException("Field [{$field}] is required when using client_credentials authentication for connection [{$name}].");
                    }
                }
            }
        }

        // Validate retry configuration
        if (isset($config['retries']) && is_array($config['retries']) && isset($config['retries']['max_retries'])) {
            $maxRetries = $config['retries']['max_retries'];

            if (! is_int($maxRetries) || 0 > $maxRetries) {
                throw new InvalidArgumentException("Invalid max_retries value for OpenFGA connection [{$name}]. Must be a non-negative integer.");
            }
        }

        // Validate HTTP options
        if (isset($config['http_options']) && is_array($config['http_options'])) {
            foreach (['timeout', 'connect_timeout'] as $option) {
                if (isset($config['http_options'][$option])) {
                    $value = $config['http_options'][$option];

                    if (! is_numeric($value) || 0 >= $value) {
                        throw new InvalidArgumentException("Invalid {$option} value for OpenFGA connection [{$name}]. Must be a positive number.");
                    }
                }
            }
        }
    }
}
