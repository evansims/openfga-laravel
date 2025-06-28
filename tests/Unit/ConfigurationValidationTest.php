<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit;

use InvalidArgumentException;
use OpenFGA\Laravel\OpenFgaServiceProvider;

describe('Configuration Validation', function (): void {
    it('validates default connection exists', function (): void {
        $this->app['config']->set('openfga', [
            'default' => 'non-existent',
            'connections' => [
                'main' => ['url' => 'http://localhost:8080'],
            ],
        ]);

        $provider = new OpenFgaServiceProvider($this->app);

        expect(fn () => $provider->boot())
            ->toThrow(InvalidArgumentException::class, 'Default OpenFGA connection [non-existent] is not configured.');
    });

    it('validates URL format', function (): void {
        $this->app['config']->set('openfga.connections.main.url', 'not-a-valid-url');

        $provider = new OpenFgaServiceProvider($this->app);

        expect(fn () => $provider->boot())
            ->toThrow(InvalidArgumentException::class, 'Invalid URL configured for OpenFGA connection [main].');
    });

    it('validates authentication method', function (): void {
        $this->app['config']->set('openfga.connections.main.credentials.method', 'invalid-method');

        $provider = new OpenFgaServiceProvider($this->app);

        expect(fn () => $provider->boot())
            ->toThrow(InvalidArgumentException::class, 'Invalid authentication method [invalid-method]');
    });

    it('validates api_token requires token', function (): void {
        $this->app['config']->set('openfga.connections.main.credentials', [
            'method' => 'api_token',
            'token' => null,
        ]);

        $provider = new OpenFgaServiceProvider($this->app);

        expect(fn () => $provider->boot())
            ->toThrow(InvalidArgumentException::class, 'API token is required when using api_token authentication');
    });

    it('validates client_credentials requires all fields', function (): void {
        $this->app['config']->set('openfga.connections.main.credentials', [
            'method' => 'client_credentials',
            'client_id' => 'test',
            // Missing other required fields
        ]);

        $provider = new OpenFgaServiceProvider($this->app);

        expect(fn () => $provider->boot())
            ->toThrow(InvalidArgumentException::class);
    });

    it('validates max_retries is non-negative integer', function (): void {
        $this->app['config']->set('openfga.connections.main.retries.max_retries', -1);

        $provider = new OpenFgaServiceProvider($this->app);

        expect(fn () => $provider->boot())
            ->toThrow(InvalidArgumentException::class, 'Invalid max_retries value');
    });

    it('validates HTTP timeouts are positive', function (): void {
        $this->app['config']->set('openfga.connections.main.http_options.timeout', 0);

        $provider = new OpenFgaServiceProvider($this->app);

        expect(fn () => $provider->boot())
            ->toThrow(InvalidArgumentException::class, 'Invalid timeout value');
    });

    it('passes validation with correct configuration', function (): void {
        $this->app['config']->set('openfga', [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'url' => 'http://localhost:8080',
                    'credentials' => [
                        'method' => 'none',
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

        expect(fn () => $provider->boot())->not->toThrow(InvalidArgumentException::class);
    });
});
