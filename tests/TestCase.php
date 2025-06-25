<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use OpenFGA\Laravel\OpenFgaServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            OpenFgaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Set up default configuration for tests
        $app['config']->set('openfga.default', 'main');
        $app['config']->set('openfga.connections.main', [
            'url' => 'https://api.openfga.example',
            'store_id' => 'test-store-id',
            'model_id' => 'test-model-id',
            'credentials' => [
                'method' => 'none',
            ],
            'cache' => [
                'enabled' => false,
            ],
        ]);
        
        // Set up cache configuration
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
    }
}