<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Integration;

use OpenFGA\Laravel\Testing\IntegrationTestCase;
use OpenFGA\Laravel\Testing\IntegrationTestHelpers;

/**
 * Integration tests for OpenFGA Laravel
 * 
 * These tests run against a real OpenFGA instance.
 * Set OPENFGA_RUN_INTEGRATION_TESTS=true to run.
 */
class OpenFgaIntegrationTest extends IntegrationTestCase
{
    use IntegrationTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegrationTest();
    }

    protected function tearDown(): void
    {
        $this->tearDownIntegrationTest();
        parent::tearDown();
    }

    public function test_basic_permission_check()
    {
        $this->runWithCleanStore(function () {
            $user = $this->createTestUser('alice');
            $document = $this->createTestDocument('report');

            // Initially, user should not have access
            $this->assertFalse(
                $this->getClient()->check($user, 'viewer', $document)
            );

            // Grant permission
            $this->grantPermission($user, 'viewer', $document);

            // Now user should have access
            $this->assertEventuallyAllowed($user, 'viewer', $document);
        });
    }

    public function test_permission_inheritance()
    {
        $this->runWithCleanStore(function () {
            $user = $this->createTestUser('bob');
            $document = $this->createTestDocument('contract');

            // Test that owner permission implies editor and viewer
            $this->assertInheritedPermission($user, 'owner', 'editor', $document);
            $this->assertInheritedPermission($user, 'owner', 'viewer', $document);
        });
    }

    public function test_organization_membership()
    {
        $this->runWithCleanStore(function () {
            $user = $this->createTestUser('charlie');
            $org = $this->createTestOrganization('acme');
            $document = $this->createTestDocument('policy');

            // Set up organization membership
            $this->grantPermission($user, 'member', $org);
            $this->grantPermission($org, 'organization', $document);

            // User should have viewer access through organization membership
            $this->assertEventuallyAllowed($user, 'viewer', $document);
        });
    }

    public function test_batch_write_performance()
    {
        $this->runWithCleanStore(function () {
            $permissions = [];
            
            // Create 100 permission tuples
            for ($i = 1; $i <= 100; $i++) {
                $permissions[] = [
                    'user' => $this->createTestUser("user{$i}"),
                    'relation' => 'viewer',
                    'object' => $this->createTestDocument("doc{$i}"),
                ];
            }

            $this->testBatchPermissions($permissions, function () use ($permissions) {
                // Verify a sample of permissions
                foreach (array_slice($permissions, 0, 10) as $perm) {
                    $this->assertEventuallyAllowed(
                        $perm['user'],
                        $perm['relation'],
                        $perm['object']
                    );
                }
            });
        });
    }

    public function test_permission_lifecycle()
    {
        $this->runWithCleanStore(function () {
            $user = $this->createTestUser('diana');
            $document = $this->createTestDocument('memo');

            $lifecycle = [
                ['action' => 'grant', 'expected' => true],
                ['action' => 'revoke', 'expected' => false],
                ['action' => 'grant', 'expected' => true],
            ];

            $this->testPermissionLifecycle($user, 'editor', $document, $lifecycle);
        });
    }

    public function test_concurrent_operations()
    {
        $this->runWithCleanStore(function () {
            $operations = [];
            
            // Create concurrent grant operations
            for ($i = 1; $i <= 10; $i++) {
                $operations[] = [
                    'type' => 'grant',
                    'user' => $this->createTestUser("concurrent{$i}"),
                    'relation' => 'viewer',
                    'object' => $this->createTestDocument('shared'),
                    'finalState' => true,
                ];
            }

            $this->testConcurrentOperations($operations);
        });
    }

    public function test_complex_hierarchy()
    {
        $this->runWithCleanStore(function () {
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

            $this->createPermissionHierarchy($hierarchy);

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

            $this->assertPermissionHierarchy($assertions);
        });
    }

    public function test_contextual_tuples()
    {
        $this->runWithCleanStore(function () {
            $user = $this->createTestUser('emma');
            $document = $this->createTestDocument('draft');
            $team = $this->createTestOrganization('engineering');

            // User doesn't have direct access
            $this->assertFalse(
                $this->getClient()->check($user, 'editor', $document)
            );

            // But with contextual tuple providing team membership, they should have access
            $this->assertContextualCheck(
                $user,
                'editor',
                $document,
                [
                    ['user' => $user, 'relation' => 'member', 'object' => $team],
                    ['user' => $team, 'relation' => 'organization', 'object' => $document],
                ],
                true
            );
        });
    }

    public function test_list_operations()
    {
        $this->runWithCleanStore(function () {
            $user = $this->createTestUser('frank');
            $docs = [];

            // Create documents and grant permissions
            for ($i = 1; $i <= 5; $i++) {
                $doc = $this->createTestDocument("list-doc-{$i}");
                $docs[] = $doc;
                $this->grantPermission($user, 'viewer', $doc);
            }

            $this->waitForConsistency();

            // Test listing objects
            $this->assertUserCanAccessObjects($user, 'viewer', 'document', $docs);
        });
    }

    public function test_performance_benchmarks()
    {
        $this->runWithCleanStore(function () {
            $user = $this->createTestUser('benchmark');
            $document = $this->createTestDocument('perf-test');

            // Set up permission
            $this->grantPermission($user, 'viewer', $document);
            $this->waitForConsistency();

            // Benchmark permission check
            $checkBenchmark = $this->benchmarkOperation(
                'Permission Check',
                fn() => $this->getClient()->check($user, 'viewer', $document),
                50
            );

            $this->assertLessThan(50, $checkBenchmark['average_ms'], 
                'Average permission check should be under 50ms'
            );

            // Benchmark write operation
            $writeBenchmark = $this->benchmarkOperation(
                'Write Operation',
                fn() => $this->grantPermission(
                    $this->createTestUser(uniqid()),
                    'viewer',
                    $this->createTestDocument(uniqid())
                ),
                20
            );

            $this->assertLessThan(100, $writeBenchmark['average_ms'],
                'Average write operation should be under 100ms'
            );

            // Output results for debugging
            $this->addToAssertionCount(1);
            echo "\nPerformance Benchmarks:\n";
            echo sprintf("- %s: avg=%.2fms, min=%.2fms, max=%.2fms\n",
                $checkBenchmark['name'],
                $checkBenchmark['average_ms'],
                $checkBenchmark['min_ms'],
                $checkBenchmark['max_ms']
            );
            echo sprintf("- %s: avg=%.2fms, min=%.2fms, max=%.2fms\n",
                $writeBenchmark['name'],
                $writeBenchmark['average_ms'],
                $writeBenchmark['min_ms'],
                $writeBenchmark['max_ms']
            );
        });
    }

    public function test_store_isolation()
    {
        $user = $this->createTestUser('isolated');
        $document = $this->createTestDocument('secret');

        $this->testStoreIsolation(
            // Store A setup
            function () use ($user, $document) {
                $this->grantPermission($user, 'viewer', $document);
            },
            // Store B setup
            function () use ($user, $document) {
                // Don't grant permission in store B
            },
            // Assertions
            function ($storeAId, $storeBId) use ($user, $document) {
                // Check store A - should have access
                Config::set('openfga.connections.integration_test.store_id', $storeAId);
                $this->openFgaManager->purge('integration_test');
                $this->assertTrue(
                    $this->getManager()->connection('integration_test')->check($user, 'viewer', $document)
                );

                // Check store B - should not have access
                Config::set('openfga.connections.integration_test.store_id', $storeBId);
                $this->openFgaManager->purge('integration_test');
                $this->assertFalse(
                    $this->getManager()->connection('integration_test')->check($user, 'viewer', $document)
                );
            }
        );
    }
}