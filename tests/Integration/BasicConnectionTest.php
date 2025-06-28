<?php

declare(strict_types=1);

use OpenFGA\Laravel\Testing\IntegrationTestCase;

uses(IntegrationTestCase::class);

describe('Basic Connection', function (): void {
    beforeEach(function (): void {
        $this->setUpIntegrationTest();
    });

    afterEach(function (): void {
        $this->tearDownIntegrationTest();
    });

    it('can create store and model', function (): void {
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
