<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Integration;

use OpenFGA\Laravel\Testing\IntegrationTestCase;

final class DebugIntegrationTest extends IntegrationTestCase
{
    public function test_debug_store_creation(): void
    {
        echo "\nDebug: OPENFGA_TEST_URL = " . env('OPENFGA_TEST_URL', 'not set') . "\n";
        
        $url = config('openfga.connections.integration_test.url');
        echo "Debug: Config URL = " . $url . "\n";
        
        // Try to create a store
        try {
            $response = $this->makeApiRequest('POST', '/stores', [
                'name' => 'debug_test_store',
            ]);
            
            echo "Debug: Store creation response:\n";
            print_r($response);
            
            if (isset($response['id'])) {
                echo "\nDebug: Store created successfully with ID: " . $response['id'] . "\n";
                
                // Try to delete it
                $this->makeApiRequest('DELETE', '/stores/' . $response['id']);
                echo "Debug: Store deleted successfully\n";
            } else {
                echo "\nDebug: Store creation failed - no ID in response\n";
            }
            
            $this->assertTrue(isset($response['id']), 'Store should have been created with an ID');
        } catch (\Exception $e) {
            echo "\nDebug: Exception during store creation: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}