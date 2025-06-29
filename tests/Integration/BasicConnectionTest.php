<?php

declare(strict_types=1);

use OpenFGA\Laravel\Testing\IntegrationTestCase;

uses(IntegrationTestCase::class);

describe('Basic Connection', function (): void {
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

    it('creates store and model', function (): void {
        // Verify store and model are created
        expect($this->testStoreId)->not->toBeNull();
        expect($this->testModelId)->not->toBeNull();

        // Try to write a tuple using the raw API
        $writeResponse = $this->makeApiRequest('POST', sprintf('/stores/%s/write', $this->testStoreId), [
            'writes' => [
                'tuple_keys' => [
                    [
                        'user' => 'user:test',
                        'relation' => 'viewer',
                        'object' => 'document:test',
                    ],
                ],
            ],
            'authorization_model_id' => $this->testModelId,
        ]);

        // Check if write was successful
        expect($writeResponse)->not->toHaveKey('error', 'Write operation should not have error');

        // Try to check permission using raw API
        $checkResponse = $this->makeApiRequest('POST', sprintf('/stores/%s/check', $this->testStoreId), [
            'tuple_key' => [
                'user' => 'user:test',
                'relation' => 'viewer',
                'object' => 'document:test',
            ],
            'authorization_model_id' => $this->testModelId,
        ]);

        expect($checkResponse)->toHaveKey('allowed');
        expect($checkResponse['allowed'])->toBeTrue('Permission check should return true');
    });
});
