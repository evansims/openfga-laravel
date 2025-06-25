<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;
use Illuminate\Support\Collection;

/**
 * Trait providing integration test helpers
 */
trait IntegrationTestHelpers
{
    /**
     * Test permission inheritance
     */
    protected function assertInheritedPermission(
        string $user,
        string $directRelation,
        string $inheritedRelation,
        string $object
    ): void {
        // Grant direct permission
        $this->grantPermission($user, $directRelation, $object);
        
        // Wait for consistency
        $this->waitForConsistency();
        
        // Assert both direct and inherited permissions work
        $this->assertEventuallyAllowed($user, $directRelation, $object);
        $this->assertEventuallyAllowed($user, $inheritedRelation, $object);
    }

    /**
     * Test transitive permissions through relationships
     */
    protected function assertTransitivePermission(
        string $user,
        string $userRelation,
        string $intermediateObject,
        string $objectRelation,
        string $targetObject,
        string $targetRelation
    ): void {
        // Set up relationships
        $this->grantPermission($user, $userRelation, $intermediateObject);
        $this->grantPermission($intermediateObject, $objectRelation, $targetObject);
        
        // Wait for consistency
        $this->waitForConsistency();
        
        // Assert transitive permission works
        $this->assertEventuallyAllowed($user, $targetRelation, $targetObject);
    }

    /**
     * Test batch permission operations
     */
    protected function testBatchPermissions(array $permissions, Closure $assertions): void
    {
        // Measure batch write time
        $writeMetrics = $this->measureTime(function () use ($permissions) {
            $this->grantPermissions($permissions);
        });

        $this->assertLessThan(1000, $writeMetrics['duration_ms'], 'Batch write took too long');

        // Wait for consistency
        $this->waitForConsistency();

        // Run assertions
        $assertions();
    }

    /**
     * Test permission changes over time
     */
    protected function testPermissionLifecycle(
        string $user,
        string $relation,
        string $object,
        array $lifecycle
    ): void {
        foreach ($lifecycle as $step) {
            if ($step['action'] === 'grant') {
                $this->grantPermission($user, $relation, $object);
            } elseif ($step['action'] === 'revoke') {
                $this->revokePermission($user, $relation, $object);
            }

            $this->waitForConsistency();

            if ($step['expected']) {
                $this->assertEventuallyAllowed($user, $relation, $object);
            } else {
                $this->assertEventuallyDenied($user, $relation, $object);
            }
        }
    }

    /**
     * Test concurrent permission operations
     */
    protected function testConcurrentOperations(array $operations): void
    {
        // Execute all operations without waiting
        foreach ($operations as $op) {
            if ($op['type'] === 'grant') {
                $this->grantPermission($op['user'], $op['relation'], $op['object']);
            } elseif ($op['type'] === 'revoke') {
                $this->revokePermission($op['user'], $op['relation'], $op['object']);
            }
        }

        // Wait for all operations to complete
        $this->waitForConsistency(500);

        // Verify final state
        foreach ($operations as $op) {
            if (isset($op['finalState'])) {
                if ($op['finalState']) {
                    $this->assertEventuallyAllowed($op['user'], $op['relation'], $op['object']);
                } else {
                    $this->assertEventuallyDenied($op['user'], $op['relation'], $op['object']);
                }
            }
        }
    }

    /**
     * Test permission expansion
     */
    protected function assertExpansionContains(
        string $object,
        string $relation,
        array $expectedUsers
    ): void {
        $result = $this->getClient()->expand($object, $relation);
        
        // Extract users from expansion tree
        $users = $this->extractUsersFromExpansion($result);
        
        foreach ($expectedUsers as $user) {
            $this->assertContains($user, $users, "Expected {$user} in expansion of {$relation} on {$object}");
        }
    }

    /**
     * Test listing objects
     */
    protected function assertUserCanAccessObjects(
        string $user,
        string $relation,
        string $objectType,
        array $expectedObjects
    ): void {
        $objects = $this->getClient()->listObjects($user, $relation, $objectType);
        
        foreach ($expectedObjects as $object) {
            $this->assertContains($object, $objects, "Expected {$object} in list of accessible objects");
        }
    }

    /**
     * Test listing users
     */
    protected function assertObjectHasUsers(
        string $object,
        string $relation,
        array $expectedUsers
    ): void {
        $users = $this->getClient()->listUsers($object, $relation);
        
        foreach ($expectedUsers as $user) {
            $this->assertContains($user, $users, "Expected {$user} to have {$relation} on {$object}");
        }
    }

    /**
     * Test contextual tuples
     */
    protected function assertContextualCheck(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples,
        bool $expectedResult
    ): void {
        $result = $this->getClient()->check($user, $relation, $object, [
            'contextual_tuples' => $contextualTuples,
        ]);

        $this->assertEquals($expectedResult, $result, 
            "Contextual check failed for {$user} {$relation} {$object}"
        );
    }

    /**
     * Create a complex permission hierarchy
     */
    protected function createPermissionHierarchy(array $structure): void
    {
        $tuples = [];

        foreach ($structure as $item) {
            if (isset($item['users'])) {
                foreach ($item['users'] as $user => $relations) {
                    foreach ($relations as $relation) {
                        $tuples[] = [
                            'user' => $user,
                            'relation' => $relation,
                            'object' => $item['object'],
                        ];
                    }
                }
            }

            if (isset($item['parents'])) {
                foreach ($item['parents'] as $parentRelation => $parentObject) {
                    $tuples[] = [
                        'user' => $item['object'],
                        'relation' => $parentRelation,
                        'object' => $parentObject,
                    ];
                }
            }
        }

        $this->grantPermissions($tuples);
        $this->waitForConsistency();
    }

    /**
     * Assert permission hierarchy
     */
    protected function assertPermissionHierarchy(array $assertions): void
    {
        foreach ($assertions as $assertion) {
            if ($assertion['expected']) {
                $this->assertEventuallyAllowed(
                    $assertion['user'],
                    $assertion['relation'],
                    $assertion['object']
                );
            } else {
                $this->assertEventuallyDenied(
                    $assertion['user'],
                    $assertion['relation'],
                    $assertion['object']
                );
            }
        }
    }

    /**
     * Performance test helper
     */
    protected function benchmarkOperation(string $name, Closure $operation, int $iterations = 100): array
    {
        $times = [];

        // Warm up
        $operation();

        for ($i = 0; $i < $iterations; $i++) {
            $metrics = $this->measureTime($operation);
            $times[] = $metrics['duration_ms'];
        }

        return [
            'name' => $name,
            'iterations' => $iterations,
            'average_ms' => array_sum($times) / count($times),
            'min_ms' => min($times),
            'max_ms' => max($times),
            'median_ms' => $this->calculateMedian($times),
        ];
    }

    /**
     * Test store isolation
     */
    protected function testStoreIsolation(Closure $storeASetup, Closure $storeBSetup, Closure $assertions): void
    {
        // Create two separate stores
        $storeA = $this->createStore('store_a_' . uniqid());
        $storeB = $this->createStore('store_b_' . uniqid());

        try {
            // Set up store A
            Config::set('openfga.connections.integration_test.store_id', $storeA['id']);
            $this->openFgaManager->purge('integration_test');
            $storeASetup();

            // Set up store B
            Config::set('openfga.connections.integration_test.store_id', $storeB['id']);
            $this->openFgaManager->purge('integration_test');
            $storeBSetup();

            // Run assertions
            $assertions($storeA['id'], $storeB['id']);
        } finally {
            // Clean up
            $this->deleteStore($storeA['id']);
            $this->deleteStore($storeB['id']);
        }
    }

    /**
     * Extract users from expansion result
     */
    private function extractUsersFromExpansion($expansion): array
    {
        $users = [];

        if (isset($expansion['users'])) {
            $users = array_merge($users, $expansion['users']);
        }

        if (isset($expansion['usersets'])) {
            foreach ($expansion['usersets'] as $userset) {
                $users = array_merge($users, $this->extractUsersFromExpansion($userset));
            }
        }

        return array_unique($users);
    }

    /**
     * Calculate median value
     */
    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        
        if ($count % 2 === 0) {
            return ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
        }
        
        return $values[intval($count / 2)];
    }
}