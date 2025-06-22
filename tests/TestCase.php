<?php

declare(strict_types=1);

namespace OpenFga\Laravel\Tests;

use OpenFga\Laravel\Facades\OpenFga;
use OpenFga\Laravel\OpenFgaServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('openfga.api_url', 'http://localhost:8080');
        $app['config']->set('openfga.store_id', 'test-store-id');
        $app['config']->set('openfga.authorization_model_id', 'test-model-id');
    }

    protected function getPackageAliases($app): array
    {
        return [
            'OpenFga' => OpenFga::class,
        ];
    }

    protected function getPackageProviders($app): array
    {
        return [
            OpenFgaServiceProvider::class,
        ];
    }
}
