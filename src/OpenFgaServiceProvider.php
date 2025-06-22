<?php

declare(strict_types=1);

namespace OpenFGA\Laravel;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use OpenFGA\{Client, ClientInterface};
use Override;

use function is_string;

final class OpenFgaServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/openfga.php' => config_path('openfga.php'),
            ], 'openfga-config');
        }
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
            ClientInterface::class,
            Client::class,
            'openfga',
        ];
    }

    /**
     * Register the service provider.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/openfga.php', 'openfga');

        $this->app->singleton(ClientInterface::class, static function ($app) {
            $config = $app->make('config')->get('openfga');

            return new Client(
                url: $config['api_url'] ?? 'http://localhost:8080',
            );
        });

        $this->app->alias(ClientInterface::class, 'openfga');
        $this->app->alias(ClientInterface::class, Client::class);
    }

    /**
     * Build credentials array from configuration.
     *
     * @param  array<string, mixed>      $config
     * @return array<string, mixed>|null
     */
    private function buildCredentials(array $config): ?array
    {
        if (empty($config['credentials'])) {
            return null;
        }

        $credentials = $config['credentials'];

        if (is_string($credentials)) {
            return ['api_token' => $credentials];
        }

        if (isset($credentials['method'])) {
            return match ($credentials['method']) {
                'api_token' => [
                    'api_token' => $credentials['token'] ?? null,
                ],
                'client_credentials' => [
                    'client_id' => $credentials['client_id'] ?? null,
                    'client_secret' => $credentials['client_secret'] ?? null,
                    'api_token_issuer' => $credentials['api_token_issuer'] ?? null,
                    'api_audience' => $credentials['api_audience'] ?? null,
                    'scopes' => $credentials['scopes'] ?? [],
                ],
                default => null,
            };
        }

        return $credentials;
    }
}
