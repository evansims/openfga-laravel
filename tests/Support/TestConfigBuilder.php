<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

/**
 * Builder for creating test configurations consistently.
 */
final class TestConfigBuilder
{
    private array $config = [];

    public function __construct()
    {
        $this->config = $this->getDefaultConfig();
    }

    /**
     * Create a new config builder instance.
     */
    public static function create(): self
    {
        return new self;
    }

    /**
     * Build the configuration array.
     */
    public function build(): array
    {
        return $this->config;
    }

    /**
     * Build as connection configuration for multiple connections.
     *
     * @param string $name
     */
    public function buildAsConnection(string $name = 'test'): array
    {
        return [
            'default' => $name,
            'connections' => [
                $name => $this->build(),
            ],
        ];
    }

    /**
     * Set custom configuration value.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function with(string $key, mixed $value): self
    {
        data_set($this->config, $key, $value);

        return $this;
    }

    /**
     * Enable caching.
     *
     * @param bool $enabled
     * @param int  $ttl
     */
    public function withCache(bool $enabled = true, int $ttl = 300): self
    {
        $this->config['cache']['enabled'] = $enabled;
        $this->config['cache']['ttl'] = $ttl;

        return $this;
    }

    /**
     * Set client credentials authentication.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param string $audience
     */
    public function withClientCredentials(string $clientId, string $clientSecret, string $audience = ''): self
    {
        $this->config['credentials']['method'] = 'client_credentials';
        $this->config['credentials']['client_id'] = $clientId;
        $this->config['credentials']['client_secret'] = $clientSecret;
        $this->config['credentials']['audience'] = $audience;
        unset($this->config['credentials']['token']);

        return $this;
    }

    /**
     * Set connection pool configuration.
     *
     * @param int $maxConnections
     * @param int $minConnections
     */
    public function withConnectionPool(int $maxConnections = 10, int $minConnections = 2): self
    {
        $this->config['pool'] = [
            'enabled' => true,
            'max_connections' => $maxConnections,
            'min_connections' => $minConnections,
            'max_idle_time' => 300,
            'connection_timeout' => 5,
        ];

        return $this;
    }

    /**
     * Set the model ID.
     *
     * @param ?string $modelId
     */
    public function withModelId(?string $modelId): self
    {
        $this->config['model_id'] = $modelId;

        return $this;
    }

    /**
     * Set authentication to none.
     */
    public function withNoAuth(): self
    {
        $this->config['credentials']['method'] = 'none';
        unset($this->config['credentials']['token'], $this->config['credentials']['client_id'], $this->config['credentials']['client_secret']);

        return $this;
    }

    /**
     * Disable caching.
     */
    public function withoutCache(): self
    {
        return $this->withCache(enabled: false);
    }

    /**
     * Disable queuing.
     */
    public function withoutQueue(): self
    {
        return $this->withQueue(enabled: false);
    }

    /**
     * Enable queuing.
     *
     * @param bool   $enabled
     * @param string $connection
     */
    public function withQueue(bool $enabled = true, string $connection = 'default'): self
    {
        $this->config['queue']['enabled'] = $enabled;
        $this->config['queue']['connection'] = $connection;

        return $this;
    }

    /**
     * Set retry configuration.
     *
     * @param int $maxRetries
     * @param int $delay
     */
    public function withRetries(int $maxRetries = 3, int $delay = 1000): self
    {
        $this->config['retries']['max_retries'] = $maxRetries;
        $this->config['retries']['delay'] = $delay;

        return $this;
    }

    /**
     * Set the store ID.
     *
     * @param ?string $storeId
     */
    public function withStoreId(?string $storeId): self
    {
        $this->config['store_id'] = $storeId;

        return $this;
    }

    /**
     * Set HTTP timeout.
     *
     * @param int $timeout
     */
    public function withTimeout(int $timeout = 10): self
    {
        $this->config['http_options']['timeout'] = $timeout;

        return $this;
    }

    /**
     * Set API token authentication.
     *
     * @param string $token
     */
    public function withTokenAuth(string $token): self
    {
        $this->config['credentials']['method'] = 'api_token';
        $this->config['credentials']['token'] = $token;
        unset($this->config['credentials']['client_id'], $this->config['credentials']['client_secret']);

        return $this;
    }

    /**
     * Set the API URL.
     *
     * @param string $url
     */
    public function withUrl(string $url): self
    {
        $this->config['url'] = $url;

        return $this;
    }

    /**
     * Set user prefix.
     *
     * @param string $prefix
     */
    public function withUserPrefix(string $prefix): self
    {
        $this->config['user_prefix'] = $prefix;

        return $this;
    }

    /**
     * Get the default configuration.
     */
    private function getDefaultConfig(): array
    {
        return [
            'url' => 'http://localhost:8080',
            'store_id' => null,
            'model_id' => null,
            'credentials' => [
                'method' => 'none',
            ],
            'cache' => [
                'enabled' => false,
                'ttl' => 300,
            ],
            'queue' => [
                'enabled' => false,
                'connection' => 'default',
            ],
            'retries' => [
                'max_retries' => 3,
                'delay' => 1000,
            ],
            'http_options' => [
                'timeout' => 10,
                'connect_timeout' => 5,
            ],
            'user_prefix' => 'user:',
        ];
    }
}
