<?php

declare(strict_types=1);

use OpenFga\Laravel\Facades\OpenFga;
use OpenFga\Sdk\Client;
use OpenFga\Sdk\ClientInterface;

it('registers the OpenFGA client as a singleton', function () {
    $client = $this->app->make(ClientInterface::class);
    $client2 = $this->app->make(ClientInterface::class);

    expect($client)->toBeInstanceOf(Client::class);
    expect($client)->toBe($client2);
});

it('provides the OpenFGA client via the facade', function () {
    $facade = OpenFga::getFacadeRoot();

    expect($facade)->toBeInstanceOf(Client::class);
});

it('publishes the configuration file', function () {
    $this->artisan('vendor:publish', ['--tag' => 'openfga-config'])
        ->assertExitCode(0);

    expect(config_path('openfga.php'))->toBeFile();
})->skip('Requires filesystem setup');

it('loads configuration from the published file', function () {
    config(['openfga.api_url' => 'https://api.example.com']);

    $client = $this->app->make(ClientInterface::class);

    expect($client)->toBeInstanceOf(Client::class);
});

it('binds the client with multiple aliases', function () {
    expect($this->app->make('openfga'))->toBeInstanceOf(Client::class);
    expect($this->app->make(Client::class))->toBeInstanceOf(Client::class);
    expect($this->app->make(ClientInterface::class))->toBeInstanceOf(Client::class);
});