<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Config;
use OpenFGA\Laravel\Testing\IntegrationTestCase;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;

uses(IntegrationTestCase::class);
uses(ConfigRestoration::class);

/*
 * Integration tests for OpenFGA Laravel.
 *
 * These tests run against a real OpenFGA instance.
 */
describe('OpenFGA Integration', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();

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
        $this->tearDownConfigRestoration();
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

    it('tests batch write functionality', function (): void {
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
            $this->grantPermissions($permissions);

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
            expect(count(value: $objects))->toBeGreaterThanOrEqual(count(value: $docs));

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

    it('tests multiple permission operations', function (): void {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('benchmark');
            $document = $this->createTestDocument('perf-test');

            // Set up permission
            $this->grantPermission($user, 'viewer', $document);
            $this->waitForConsistency();

            // Test multiple permission checks work correctly
            for ($i = 0; 10 > $i; $i++) {
                $result = $this->getManager()->check($user, 'viewer', $document, [], [], 'integration_test');
                expect($result)->toBeTrue();
            }

            // Test multiple write operations
            for ($i = 0; 5 > $i; $i++) {
                $this->grantPermission(
                    $this->createTestUser("write-test-user-{$i}"),
                    'viewer',
                    $this->createTestDocument("write-test-doc-{$i}"),
                );
            }

            // Verify writes succeeded
            for ($i = 0; 5 > $i; $i++) {
                $this->assertEventuallyAllowed(
                    $this->createTestUser("write-test-user-{$i}"),
                    'viewer',
                    $this->createTestDocument("write-test-doc-{$i}"),
                );
            }
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
        $testId = (string) time() . '_' . getmypid();
        $newStore = $this->createStore('test_isolation_' . $testId);
        $newStoreId = $newStore['id'];
        $this->createdStores[] = $newStoreId;

        // Update test properties to the new store temporarily
        $this->testStoreId = $newStoreId;

        // Create model in new store
        $this->setConfigWithRestore('openfga.connections.integration_test.store_id', $newStoreId);
        $newModel = $this->createAuthorizationModel($this->getTestAuthorizationModel());
        $newModelId = $newModel['authorization_model_id'];

        // Update test model ID
        $this->testModelId = $newModelId;

        // Update connection with new store/model
        $this->setConfigWithRestore('openfga.connections.integration_test.model_id', $newModelId);

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

        // Switch back to original store (restoration will happen automatically)
        // But we need to trigger the manager update
        $this->restoreOriginalConfig();
        $this->openFgaManager->updateConfig($configRepository->get('openfga', []));
        $this->openFgaClient = $this->openFgaManager->connection('integration_test');

        // Verify permission still exists in original store
        $this->assertEventuallyAllowed($user, 'viewer', $document);

        // Clean up the test isolation store
        $this->testStoreId = $originalStoreId;
        $this->testModelId = $originalModelId;
    });
});
