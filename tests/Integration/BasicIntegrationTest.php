<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Integration;

use OpenFGA\Laravel\OpenFgaManager;
use Orchestra\Testbench\TestCase;
use OpenFGA\Laravel\OpenFgaServiceProvider;

/**
 * Basic integration test to verify Docker setup works.
 */
final class BasicIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            OpenFgaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Set default connection to integration_test for tests
        $app['config']->set('openfga.default', 'integration_test');
        
        // Configure test connection
        $app['config']->set('openfga.connections.integration_test', [
            'url' => env('OPENFGA_TEST_URL', 'http://localhost:8080'),
            'store_id' => 'test-store-id',
            'model_id' => 'test-model-id',
            'credentials' => [
                'method' => 'none',
            ],
        ]);
    }

    public function test_can_connect_to_openfga(): void
    {
        // Get the manager
        $manager = app(OpenFgaManager::class);
        
        // Try to get a connection - this should work if OpenFGA is running
        $client = $manager->connection('integration_test');
        
        $this->assertNotNull($client);
    }
}