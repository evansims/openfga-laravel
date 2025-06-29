<?php

declare(strict_types=1);

use OpenFGA\Laravel\Testing\IntegrationTestCase;

uses(IntegrationTestCase::class);

describe('Basic Integration', function (): void {
    beforeEach(function (): void {
        // Check if we're running in Docker environment
        $isDocker = file_exists('/.dockerenv') || (is_string(env('OPENFGA_TEST_URL')) && str_contains(env('OPENFGA_TEST_URL'), 'openfga:'));

        if (! $this->isOpenFgaAvailable()) {
            if ($isDocker) {
                // In Docker, OpenFGA should always be available
                throw new RuntimeException('OpenFGA server is not available in Docker environment. URL: ' . env('OPENFGA_TEST_URL'));
            }
            $this->markTestSkipped('OpenFGA server is not available');
        }

        $this->setUpIntegrationTest();
    });

    afterEach(function (): void {
        $this->tearDownIntegrationTest();
    });

    /*
     * Basic integration test to verify Docker setup works.
     */
    it('connects to OpenFGA', function (): void {
        // Get the manager
        $manager = $this->getManager();

        // Try to get a connection - this should work if OpenFGA is running
        $client = $this->getClient();

        expect($client)->not->toBeNull();
        expect($manager)->not->toBeNull();

        // Verify we can make a basic API call
        $currentModel = $this->getCurrentModel();
        expect($currentModel)->toBeArray();
        expect($currentModel)->toHaveKey('schema_version');
    });
});
