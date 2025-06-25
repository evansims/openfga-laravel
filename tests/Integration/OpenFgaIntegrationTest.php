<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Integration;

use OpenFGA\Laravel\Testing\{IntegrationTestCase, IntegrationTestHelpers};

use function array_slice;
use function sprintf;

/**
 * Integration tests for OpenFGA Laravel.
 *
 * These tests run against a real OpenFGA instance.
 * Set OPENFGA_RUN_INTEGRATION_TESTS=true to run.
 */
final class OpenFgaIntegrationTest extends IntegrationTestCase
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

    public function test_basic_permission_check(): void
    {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('alice');
            $document = $this->createTestDocument('report');

            // Initially, user should not have access
            $this->assertFalse(
                $this->getClient()->check($user, 'viewer', $document),
            );

            // Grant permission
            $this->grantPermission($user, 'viewer', $document);

            // Now user should have access
            $this->assertEventuallyAllowed($user, 'viewer', $document);
        });
    }

    public function test_batch_write_performance(): void
    {
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

            $this->testBatchPermissions($permissions, function () use ($permissions): void {
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
    }

    public function test_complex_hierarchy(): void
    {
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

    public function test_concurrent_operations(): void
    {
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

            $this->testConcurrentOperations($operations);
        });
    }

    public function test_contextual_tuples(): void
    {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('emma');
            $document = $this->createTestDocument('draft');
            $team = $this->createTestOrganization('engineering');

            // User doesn't have direct access
            $this->assertFalse(
                $this->getClient()->check($user, 'editor', $document),
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
                true,
            );
        });
    }

    public function test_list_operations(): void
    {
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

            // Test listing objects
            $this->assertUserCanAccessObjects($user, 'viewer', 'document', $docs);
        });
    }

    public function test_organization_membership(): void
    {
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
    }

    public function test_performance_benchmarks(): void
    {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('benchmark');
            $document = $this->createTestDocument('perf-test');

            // Set up permission
            $this->grantPermission($user, 'viewer', $document);
            $this->waitForConsistency();

            // Benchmark permission check
            $checkBenchmark = $this->benchmarkOperation(
                'Permission Check',
                fn () => $this->getClient()->check($user, 'viewer', $document),
                50,
            );

            $this->assertLessThan(
                50,
                $checkBenchmark['average_ms'],
                'Average permission check should be under 50ms',
            );

            // Benchmark write operation
            $writeBenchmark = $this->benchmarkOperation(
                'Write Operation',
                fn () => $this->grantPermission(
                    $this->createTestUser(uniqid()),
                    'viewer',
                    $this->createTestDocument(uniqid()),
                ),
                20,
            );

            $this->assertLessThan(
                100,
                $writeBenchmark['average_ms'],
                'Average write operation should be under 100ms',
            );

            // Output results for debugging
            $this->addToAssertionCount(1);
            echo "\nPerformance Benchmarks:\n";
            echo sprintf(
                "- %s: avg=%.2fms, min=%.2fms, max=%.2fms\n",
                $checkBenchmark['name'],
                $checkBenchmark['average_ms'],
                $checkBenchmark['min_ms'],
                $checkBenchmark['max_ms'],
            );
            echo sprintf(
                "- %s: avg=%.2fms, min=%.2fms, max=%.2fms\n",
                $writeBenchmark['name'],
                $writeBenchmark['average_ms'],
                $writeBenchmark['min_ms'],
                $writeBenchmark['max_ms'],
            );
        });
    }

    public function test_permission_inheritance(): void
    {
        $this->runWithCleanStore(function (): void {
            $user = $this->createTestUser('bob');
            $document = $this->createTestDocument('contract');

            // Test that owner permission implies editor and viewer
            $this->assertInheritedPermission($user, 'owner', 'editor', $document);
            $this->assertInheritedPermission($user, 'owner', 'viewer', $document);
        });
    }

    public function test_permission_lifecycle(): void
    {
        $this->runWithCleanStore(function (): void {
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

    public function test_store_isolation(): void
    {
        $user = $this->createTestUser('isolated');
        $document = $this->createTestDocument('secret');

        $this->testStoreIsolation(
            // Store A setup
            function () use ($user, $document): void {
                $this->grantPermission($user, 'viewer', $document);
            },
            // Store B setup
            function (): void {
                // Don't grant permission in store B
            },
            // Assertions
            function ($storeAId, $storeBId) use ($user, $document): void {
                // Check store A - should have access
                Config::set('openfga.connections.integration_test.store_id', $storeAId);
                $this->openFgaManager->purge('integration_test');
                $this->assertTrue(
                    $this->getManager()->connection('integration_test')->check($user, 'viewer', $document),
                );

                // Check store B - should not have access
                Config::set('openfga.connections.integration_test.store_id', $storeBId);
                $this->openFgaManager->purge('integration_test');
                $this->assertFalse(
                    $this->getManager()->connection('integration_test')->check($user, 'viewer', $document),
                );
            },
        );
    }
}
