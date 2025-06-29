<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests;

use OpenFGA\Laravel\OpenFgaServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function tearDown(): void
    {
        // Restore error and exception handlers to prevent risky test warnings
        while (true) {
            $previousHandler = set_error_handler(static fn () => null);
            restore_error_handler();

            if (null === $previousHandler) {
                break;
            }
            restore_error_handler();
        }

        while (true) {
            $previousHandler = set_exception_handler(static fn () => null);
            restore_exception_handler();

            if (null === $previousHandler) {
                break;
            }
            restore_exception_handler();
        }

        parent::tearDown();
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

        // Disable write-behind cache shutdown flush in tests to prevent exit code 255
        $app['config']->set('openfga.cache.write_behind_flush_on_shutdown', false);
    }

    protected function getPackageProviders($app): array
    {
        return [
            OpenFgaServiceProvider::class,
        ];
    }
}
