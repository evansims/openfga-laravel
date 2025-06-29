<?php

declare(strict_types=1);
use OpenFGA\Laravel\Testing\IntegrationTestCase;

uses(IntegrationTestCase::class);

describe('Basic Integration', function (): void {
    beforeEach(function (): void {
        $this->setUpIntegrationTest();
    });

    afterEach(function (): void {
        $this->tearDownIntegrationTest();
    });

    /*
     * Basic integration test to verify Docker setup works.
     */
    it('can connect to openfga', function (): void {
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
