<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;
use Illuminate\Support\Facades\Config;

use function count;
use function sprintf;

/**
 * Trait providing integration test helpers.
 */
trait IntegrationTestHelpers
{
    /**
     * Test contextual tuples.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param array  $contextualTuples
     * @param bool   $expectedResult
     */
    protected function assertContextualCheck(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples,
        bool $expectedResult,
    ): void {
        // The Laravel SDK doesn't directly support contextual tuples in check method
        // This would need to be implemented differently or using raw client
        $result = $this->getClient()->check($user, $relation, $object);

        $this->assertEquals(
            $expectedResult,
            $result,
            sprintf('Contextual check failed for %s %s %s', $user, $relation, $object),
        );
    }

    /**
     * Test permission expansion.
     *
     * @param string $object
     * @param string $relation
     * @param array  $expectedUsers
     */
    protected function assertExpansionContains(
        string $object,
        string $relation,
        array $expectedUsers,
    ): void {
        $result = $this->getClient()->expand($relation, $object);

        // Extract users from expansion tree
        $users = $this->extractUsersFromExpansion($result);

        foreach ($expectedUsers as $expectedUser) {
            $this->assertContains($expectedUser, $users, sprintf('Expected %s in expansion of %s on %s', $expectedUser, $relation, $object));
        }
    }

    /**
     * Test permission inheritance.
     *
     * @param string $user
     * @param string $directRelation
     * @param string $inheritedRelation
     * @param string $object
     */
    protected function assertInheritedPermission(
        string $user,
        string $directRelation,
        string $inheritedRelation,
        string $object,
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
     * Test listing users.
     *
     * @param string $object
     * @param string $relation
     * @param array  $expectedUsers
     */
    protected function assertObjectHasUsers(
        string $object,
        string $relation,
        array $expectedUsers,
    ): void {
        $users = $this->getClient()->listUsers($relation, $object);

        foreach ($expectedUsers as $expectedUser) {
            $this->assertContains($expectedUser, $users, sprintf('Expected %s to have %s on %s', $expectedUser, $relation, $object));
        }
    }

    /**
     * Assert permission hierarchy.
     *
     * @param array $assertions
     */
    protected function assertPermissionHierarchy(array $assertions): void
    {
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
    }

    /**
     * Test transitive permissions through relationships.
     *
     * @param string $user
     * @param string $userRelation
     * @param string $intermediateObject
     * @param string $objectRelation
     * @param string $targetObject
     * @param string $targetRelation
     */
    protected function assertTransitivePermission(
        string $user,
        string $userRelation,
        string $intermediateObject,
        string $objectRelation,
        string $targetObject,
        string $targetRelation,
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
     * Test listing objects.
     *
     * @param string $user
     * @param string $relation
     * @param string $objectType
     * @param array  $expectedObjects
     */
    protected function assertUserCanAccessObjects(
        string $user,
        string $relation,
        string $objectType,
        array $expectedObjects,
    ): void {
        $objects = $this->getClient()->listObjects($user, $relation, $objectType);

        foreach ($expectedObjects as $expectedObject) {
            $this->assertContains($expectedObject, $objects, sprintf('Expected %s in list of accessible objects', $expectedObject));
        }
    }

    /**
     * Performance test helper.
     *
     * @param string  $name
     * @param Closure $operation
     * @param int     $iterations
     */
    protected function benchmarkOperation(string $name, Closure $operation, int $iterations = 100): array
    {
        $times = [];

        // Warm up
        $operation();

        for ($i = 0; $i < $iterations; ++$i) {
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
     * Create a complex permission hierarchy.
     *
     * @param array $structure
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
     * Test batch permission operations.
     *
     * @param array   $permissions
     * @param Closure $assertions
     */
    protected function testBatchPermissions(array $permissions, Closure $assertions): void
    {
        // Measure batch write time
        $writeMetrics = $this->measureTime(function () use ($permissions): void {
            $this->grantPermissions($permissions);
        });

        $this->assertLessThan(1000, $writeMetrics['duration_ms'], 'Batch write took too long');

        // Wait for consistency
        $this->waitForConsistency();

        // Run assertions
        $assertions();
    }

    /**
     * Test concurrent permission operations.
     *
     * @param array $operations
     */
    protected function testConcurrentOperations(array $operations): void
    {
        // Execute all operations without waiting
        foreach ($operations as $op) {
            if ('grant' === $op['type']) {
                $this->grantPermission($op['user'], $op['relation'], $op['object']);
            } elseif ('revoke' === $op['type']) {
                $this->revokePermission($op['user'], $op['relation'], $op['object']);
            }
        }

        // Wait for all operations to complete
        $this->waitForConsistency(500);

        // Verify final state
        foreach ($operations as $operation) {
            if (isset($operation['finalState'])) {
                if ($operation['finalState']) {
                    $this->assertEventuallyAllowed($operation['user'], $operation['relation'], $operation['object']);
                } else {
                    $this->assertEventuallyDenied($operation['user'], $operation['relation'], $operation['object']);
                }
            }
        }
    }

    /**
     * Test permission changes over time.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param array  $lifecycle
     */
    protected function testPermissionLifecycle(
        string $user,
        string $relation,
        string $object,
        array $lifecycle,
    ): void {
        foreach ($lifecycle as $step) {
            if ('grant' === $step['action']) {
                $this->grantPermission($user, $relation, $object);
            } elseif ('revoke' === $step['action']) {
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
     * Test store isolation.
     *
     * @param Closure $storeASetup
     * @param Closure $storeBSetup
     * @param Closure $assertions
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
     * Calculate median value.
     *
     * @param array $values
     */
    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);

        if (0 === $count % 2) {
            return ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
        }

        return $values[(int) ($count / 2)];
    }

    /**
     * Extract users from expansion result.
     *
     * @param mixed $expansion
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
}
