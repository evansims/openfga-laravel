<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Config;
use OpenFGA\Laravel\Testing\IntegrationTestCase;

uses(IntegrationTestCase::class);

/*
 * Integration tests for OpenFGA Laravel.
 *
 * These tests run against a real OpenFGA instance.
 */
describe('OpenFGA Integration', function (): void {
    beforeEach(function (): void {
        if (! $this->isOpenFgaAvailable()) {
            $this->markTestSkipped('OpenFGA server is not available at ' . env('OPENFGA_TEST_URL', 'http://localhost:8080'));
        }

        $this->setUpIntegrationTest();
    });

    afterEach(function (): void {
        if ($this->isOpenFgaAvailable()) {
            $this->tearDownIntegrationTest();
        }
    });

    it('tests basic permission check', function (): void {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('alice');
            $document = $this->createTestDocument('report');

            // Initially, user should not have access
            expect(
                $this->getManager()->check($user, 'viewer', $document, [], [], 'integration_test'),
            )->toBeFalse();

            // Grant permission
            $this->grantPermission($user, 'viewer', $document);

            // Now user should have access
            $this->assertEventuallyAllowed($user, 'viewer', $document);
        });
    });

    it('tests batch write performance', function (): void {
        $this->runWithCleanStore(function (): void {
            $permissions = [];

            // Create 100 permission tuples
            for ($i = 1; 100 >= $i; $i++) {
                $permissions[] = [
                    'user' => $this->createTestUser("user{$i}"),
                    'relation' => 'viewer',
                    'object' => $this->createTestDocument("doc{$i}"),
                ];
            }

            // Test batch permissions implementation
            $startTime = microtime(true);
            $this->grantPermissions($permissions);
            $batchTime = (microtime(true) - $startTime) * 1000;

            expect($batchTime)->toBeLessThan(1000, 'Batch write of 100 permissions should complete under 1 second');

            // Verify a sample of permissions
            foreach (array_slice($permissions, 0, 10) as $perm) {
                $this->assertEventuallyAllowed(
                    $perm['user'],
                    $perm['relation'],
                    $perm['object'],
                );
            }
        });
    });

    it('tests complex hierarchy', function (): void {
        $this->runWithCleanStore(function (): void {
            $hierarchy = [
                [
                    'object' => $this->createTestOrganization('corp'),
                    'users' => [
                        $this->createTestUser('ceo') => ['admin'],
                        $this->createTestUser('manager') => ['member'],
                    ],
                ],
                [
                    'object' => $this->createTestDocument('strategic-plan'),
                    'users' => [
                        $this->createTestUser('analyst') => ['editor'],
                    ],
                    'parents' => [
                        'organization' => $this->createTestOrganization('corp'),
                    ],
                ],
            ];

            // Create permission hierarchy implementation
            foreach ($hierarchy as $entry) {
                // Grant user permissions to the object
                if (isset($entry['users'])) {
                    foreach ($entry['users'] as $user => $relations) {
                        foreach ($relations as $relation) {
                            $this->grantPermission($user, $relation, $entry['object']);
                        }
                    }
                }

                // Set up parent relationships
                if (isset($entry['parents'])) {
                    foreach ($entry['parents'] as $parentRelation => $parentObject) {
                        $this->grantPermission($parentObject, $parentRelation, $entry['object']);
                    }
                }
            }

            $assertions = [
                [
                    'user' => $this->createTestUser('ceo'),
                    'relation' => 'viewer',
                    'object' => $this->createTestDocument('strategic-plan'),
                    'expected' => true, // CEO has viewer through admin->member->viewer chain
                ],
                [
                    'user' => $this->createTestUser('manager'),
                    'relation' => 'viewer',
                    'object' => $this->createTestDocument('strategic-plan'),
                    'expected' => true, // Manager has viewer through member->viewer
                ],
                [
                    'user' => $this->createTestUser('analyst'),
                    'relation' => 'editor',
                    'object' => $this->createTestDocument('strategic-plan'),
                    'expected' => true, // Direct editor permission
                ],
                [
                    'user' => $this->createTestUser('outsider'),
                    'relation' => 'viewer',
                    'object' => $this->createTestDocument('strategic-plan'),
                    'expected' => false, // No access
                ],
            ];

            // Assert permission hierarchy implementation
            foreach ($assertions as $assertion) {
                if ($assertion['expected']) {
                    $this->assertEventuallyAllowed(
                        $assertion['user'],
                        $assertion['relation'],
                        $assertion['object'],
                    );
                } else {
                    $this->assertEventuallyDenied(
                        $assertion['user'],
                        $assertion['relation'],
                        $assertion['object'],
                    );
                }
            }
        });
    });

    it('tests concurrent operations', function (): void {
        $this->runWithCleanStore(function (): void {
            $operations = [];

            // Create concurrent grant operations
            for ($i = 1; 10 >= $i; $i++) {
                $operations[] = [
                    'type' => 'grant',
                    'user' => $this->createTestUser("concurrent{$i}"),
                    'relation' => 'viewer',
                    'object' => $this->createTestDocument('shared'),
                    'finalState' => true,
                ];
            }

            // Test concurrent operations implementation
            // Execute all operations
            foreach ($operations as $operation) {
                if ('grant' === $operation['type']) {
                    $this->grantPermission(
                        $operation['user'],
                        $operation['relation'],
                        $operation['object'],
                    );
                }
            }

            // Wait for consistency
            $this->waitForConsistency();

            // Verify final states
            foreach ($operations as $operation) {
                if ($operation['finalState']) {
                    $this->assertEventuallyAllowed(
                        $operation['user'],
                        $operation['relation'],
                        $operation['object'],
                    );
                } else {
                    $this->assertEventuallyDenied(
                        $operation['user'],
                        $operation['relation'],
                        $operation['object'],
                    );
                }
            }
        });
    });

    it('tests contextual tuples', function (): void {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('emma');
            $document = $this->createTestDocument('draft');
            $team = $this->createTestOrganization('engineering');

            // User doesn't have direct access
            expect(
                $this->getManager()->check($user, 'viewer', $document, [], [], 'integration_test'),
            )->toBeFalse();

            // But with contextual tuple providing team membership, they should have viewer access
            // Assert contextual check implementation
            $result = $this->getManager()->check(
                $user,
                'viewer',
                $document,
                [
                    ['user' => $user, 'relation' => 'member', 'object' => $team],
                    ['user' => $team, 'relation' => 'organization', 'object' => $document],
                ],
                [],
                'integration_test',
            );

            expect($result)->toBeTrue('User should have viewer access through contextual tuples');
        });
    });

    it('tests list operations', function (): void {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('frank');
            $docs = [];

            // Create documents and grant permissions
            for ($i = 1; 5 >= $i; $i++) {
                $doc = $this->createTestDocument("list-doc-{$i}");
                $docs[] = $doc;
                $this->grantPermission($user, 'viewer', $doc);
            }

            $this->waitForConsistency();

            // Test listing objects - verify user can access all documents
            $manager = $this->getManager();
            $objects = $manager->listObjects($user, 'viewer', 'document', connection: 'integration_test');

            expect($objects)->toBeArray();
            expect(count($objects))->toBeGreaterThanOrEqual(count($docs));

            // Verify all our documents are in the list
            foreach ($docs as $doc) {
                expect($objects)->toContain($doc);
            }
        });
    });

    it('tests organization membership', function (): void {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('charlie');
            $org = $this->createTestOrganization('acme');
            $document = $this->createTestDocument('policy');

            // Set up organization membership
            $this->grantPermission($user, 'member', $org);
            $this->grantPermission($org, 'organization', $document);

            // User should have viewer access through organization membership
            $this->assertEventuallyAllowed($user, 'viewer', $document);
        });
    });

    it('tests performance benchmarks', function (): void {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('benchmark');
            $document = $this->createTestDocument('perf-test');

            // Set up permission
            $this->grantPermission($user, 'viewer', $document);
            $this->waitForConsistency();

            // Benchmark permission check
            $times = [];

            for ($i = 0; 50 > $i; $i++) {
                $result = $this->measureTime(
                    fn () => $this->getManager()->check($user, 'viewer', $document, [], [], 'integration_test'),
                );
                $times[] = $result['duration_ms'];
            }

            $avgCheckTime = array_sum($times) / count($times);
            expect($avgCheckTime)->toBeLessThan(50, 'Average permission check should be under 50ms');

            // Benchmark write operation
            $writeTimes = [];

            for ($i = 0; 20 > $i; $i++) {
                $result = $this->measureTime(
                    fn () => $this->grantPermission(
                        $this->createTestUser(uniqid()),
                        'viewer',
                        $this->createTestDocument(uniqid()),
                    ),
                );
                $writeTimes[] = $result['duration_ms'];
            }

            $avgWriteTime = array_sum($writeTimes) / count($writeTimes);
            expect($avgWriteTime)->toBeLessThan(100, 'Average write operation should be under 100ms');
        });
    });

    it('tests permission inheritance', function (): void {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('bob');
            $document = $this->createTestDocument('contract');

            // Grant owner permission
            $this->grantPermission($user, 'owner', $document);

            // Test that owner permission implies editor and viewer
            $this->assertEventuallyAllowed($user, 'editor', $document);
            $this->assertEventuallyAllowed($user, 'viewer', $document);

            // User should have owner permission
            $this->assertEventuallyAllowed($user, 'owner', $document);
        });
    });

    it('tests permission lifecycle', function (): void {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('diana');
            $document = $this->createTestDocument('memo');

            // Test permission lifecycle: grant -> revoke -> grant
            // Initial state - no permission
            $this->assertEventuallyDenied($user, 'editor', $document);

            // Grant permission
            $this->grantPermission($user, 'editor', $document);
            $this->assertEventuallyAllowed($user, 'editor', $document);

            // Revoke permission
            $this->revokePermission($user, 'editor', $document);
            $this->assertEventuallyDenied($user, 'editor', $document);

            // Grant again
            $this->grantPermission($user, 'editor', $document);
            $this->assertEventuallyAllowed($user, 'editor', $document);
        });
    });

    it('tests store isolation', function (): void {
        $user = $this->createTestUser('isolated');
        $document = $this->createTestDocument('secret');

        // Save current store and model
        $originalStoreId = $this->testStoreId;
        $originalModelId = $this->testModelId;

        // Grant permission in current store
        $this->grantPermission($user, 'viewer', $document);
        $this->waitForConsistency();

        // Verify permission exists in current store
        $this->assertEventuallyAllowed($user, 'viewer', $document);

        // Create a new store for isolation testing
        $newStore = $this->createStore('test_isolation_' . uniqid());
        $newStoreId = $newStore['id'];
        $this->createdStores[] = $newStoreId;

        // Update test properties to the new store temporarily
        $this->testStoreId = $newStoreId;

        // Create model in new store
        Config::set('openfga.connections.integration_test.store_id', $newStoreId);
        $newModel = $this->createAuthorizationModel($this->getTestAuthorizationModel());
        $newModelId = $newModel['authorization_model_id'];

        // Update test model ID
        $this->testModelId = $newModelId;

        // Update connection with new store/model
        Config::set('openfga.connections.integration_test.model_id', $newModelId);

        // Update manager config
        /** @var Repository $configRepository */
        $configRepository = app('config');

        /** @var array<string, mixed> $updatedConfig */
        $updatedConfig = $configRepository->get('openfga', []);
        $this->openFgaManager->updateConfig($updatedConfig);

        // Reinitialize client for new store
        $this->openFgaClient = $this->openFgaManager->connection('integration_test');

        // Check permission in new store - should not exist
        $result = $this->getManager()->check($user, 'viewer', $document, [], [], 'integration_test');
        expect($result)->toBeFalse('Permission should not exist in new store');

        // Switch back to original store
        Config::set('openfga.connections.integration_test.store_id', $originalStoreId);
        Config::set('openfga.connections.integration_test.model_id', $originalModelId);
        $this->openFgaManager->updateConfig($configRepository->get('openfga', []));
        $this->openFgaClient = $this->openFgaManager->connection('integration_test');

        // Verify permission still exists in original store
        $this->assertEventuallyAllowed($user, 'viewer', $document);

        // Clean up the test isolation store
        $this->testStoreId = $originalStoreId;
        $this->testModelId = $originalModelId;
    });
});
