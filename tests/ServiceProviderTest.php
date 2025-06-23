<?php

declare(strict_types=1);

use OpenFGA\{Client, ClientInterface};
use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\{OpenFgaManager, OpenFgaServiceProvider};

describe('OpenFgaServiceProvider', function (): void {
    it('registers the OpenFGA client as a singleton', function (): void {
        $client = $this->app->make(ClientInterface::class);
        $client2 = $this->app->make(ClientInterface::class);

        expect($client)->toBeInstanceOf(Client::class)
            ->and($client)->toBe($client2);
    });

    it('provides the OpenFGA manager via the facade', function (): void {
        $facade = OpenFga::getFacadeRoot();

        expect($facade)->toBeInstanceOf(OpenFgaManager::class);
    });

    it('loads configuration from the config file', function (): void {
        // Override config value
        $this->app['config']->set('openfga.api_url', 'https://api.example.com');

        // Re-register to pick up new config
        $provider = new OpenFgaServiceProvider($this->app);
        $provider->register();

        $client = $this->app->make(ClientInterface::class);

        expect($client)->toBeInstanceOf(Client::class);
    });

    it('binds the client with multiple aliases', function (): void {
        expect($this->app->make('openfga'))->toBeInstanceOf(Client::class)
            ->and($this->app->make(Client::class))->toBeInstanceOf(Client::class)
            ->and($this->app->make(ClientInterface::class))->toBeInstanceOf(Client::class);
    });

    it('registers expected services', function (): void {
        expect($this->app->bound('openfga'))->toBeTrue()
            ->and($this->app->bound(Client::class))->toBeTrue()
            ->and($this->app->bound(ClientInterface::class))->toBeTrue();
    });

    it('creates singleton instances', function (): void {
        $instance1 = $this->app->make('openfga');
        $instance2 = $this->app->make('openfga');

        expect($instance1)->toBe($instance2);
    });
});
