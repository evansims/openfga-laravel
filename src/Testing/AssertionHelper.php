<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use PHPUnit\Framework\Assert;

/**
 * Additional assertion helpers for OpenFGA testing.
 */
class AssertionHelper
{
    /**
     * Assert that a user has a specific permission.
     */
    public static function assertUserHasPermission(FakeOpenFga $fake, string $user, string $relation, string $object, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that user [{$user}] has permission [{$relation}] on [{$object}]";
        
        Assert::assertTrue($fake->check($user, $relation, $object), $message);
    }

    /**
     * Assert that a user does not have a specific permission.
     */
    public static function assertUserDoesNotHavePermission(FakeOpenFga $fake, string $user, string $relation, string $object, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that user [{$user}] does not have permission [{$relation}] on [{$object}]";
        
        Assert::assertFalse($fake->check($user, $relation, $object), $message);
    }

    /**
     * Assert that a user has any of the specified permissions.
     *
     * @param array<string> $relations
     */
    public static function assertUserHasAnyPermission(FakeOpenFga $fake, string $user, array $relations, string $object, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that user [{$user}] has any of the permissions [" . implode(', ', $relations) . "] on [{$object}]";
        
        foreach ($relations as $relation) {
            if ($fake->check($user, $relation, $object)) {
                Assert::assertTrue(true);
                return;
            }
        }
        
        Assert::fail($message);
    }

    /**
     * Assert that a user has all of the specified permissions.
     *
     * @param array<string> $relations
     */
    public static function assertUserHasAllPermissions(FakeOpenFga $fake, string $user, array $relations, string $object, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that user [{$user}] has all permissions [" . implode(', ', $relations) . "] on [{$object}]";
        
        foreach ($relations as $relation) {
            if (!$fake->check($user, $relation, $object)) {
                Assert::fail($message . " (missing: {$relation})");
            }
        }
        
        Assert::assertTrue(true);
    }

    /**
     * Assert that a user has access to a specific number of objects.
     */
    public static function assertUserHasAccessToObjectCount(FakeOpenFga $fake, string $user, string $relation, string $type, int $expectedCount, ?string $message = null): void
    {
        $objects = $fake->listObjects($user, $relation, $type);
        $actualCount = count($objects);
        
        $message = $message ?? "Failed asserting that user [{$user}] has [{$relation}] access to [{$expectedCount}] objects of type [{$type}]. Actual: [{$actualCount}]";
        
        Assert::assertCount($expectedCount, $objects, $message);
    }

    /**
     * Assert that a user has access to specific objects.
     *
     * @param array<string> $expectedObjects
     */
    public static function assertUserHasAccessToObjects(FakeOpenFga $fake, string $user, string $relation, array $expectedObjects, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that user [{$user}] has [{$relation}] access to expected objects";
        
        foreach ($expectedObjects as $object) {
            Assert::assertTrue($fake->check($user, $relation, $object), $message . " (missing access to: {$object})");
        }
    }

    /**
     * Assert that a user does not have access to specific objects.
     *
     * @param array<string> $forbiddenObjects
     */
    public static function assertUserDoesNotHaveAccessToObjects(FakeOpenFga $fake, string $user, string $relation, array $forbiddenObjects, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that user [{$user}] does not have [{$relation}] access to forbidden objects";
        
        foreach ($forbiddenObjects as $object) {
            Assert::assertFalse($fake->check($user, $relation, $object), $message . " (has unexpected access to: {$object})");
        }
    }

    /**
     * Assert that specific tuples exist in the system.
     *
     * @param array<array{user: string, relation: string, object: string}> $expectedTuples
     */
    public static function assertTuplesExist(FakeOpenFga $fake, array $expectedTuples, ?string $message = null): void
    {
        $message = $message ?? 'Failed asserting that expected tuples exist';
        $actualTuples = $fake->getTuples();
        
        foreach ($expectedTuples as $expectedTuple) {
            $found = false;
            foreach ($actualTuples as $actualTuple) {
                if ($actualTuple['user'] === $expectedTuple['user'] &&
                    $actualTuple['relation'] === $expectedTuple['relation'] &&
                    $actualTuple['object'] === $expectedTuple['object']) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                Assert::fail($message . " (missing tuple: {$expectedTuple['user']}#{$expectedTuple['relation']}@{$expectedTuple['object']})");
            }
        }
        
        Assert::assertTrue(true);
    }

    /**
     * Assert that specific tuples do not exist in the system.
     *
     * @param array<array{user: string, relation: string, object: string}> $forbiddenTuples
     */
    public static function assertTuplesDoNotExist(FakeOpenFga $fake, array $forbiddenTuples, ?string $message = null): void
    {
        $message = $message ?? 'Failed asserting that forbidden tuples do not exist';
        $actualTuples = $fake->getTuples();
        
        foreach ($forbiddenTuples as $forbiddenTuple) {
            foreach ($actualTuples as $actualTuple) {
                if ($actualTuple['user'] === $forbiddenTuple['user'] &&
                    $actualTuple['relation'] === $forbiddenTuple['relation'] &&
                    $actualTuple['object'] === $forbiddenTuple['object']) {
                    Assert::fail($message . " (found forbidden tuple: {$forbiddenTuple['user']}#{$forbiddenTuple['relation']}@{$forbiddenTuple['object']})");
                }
            }
        }
        
        Assert::assertTrue(true);
    }

    /**
     * Assert that the total number of tuples matches expectation.
     */
    public static function assertTupleCount(FakeOpenFga $fake, int $expectedCount, ?string $message = null): void
    {
        $actualCount = count($fake->getTuples());
        $message = $message ?? "Failed asserting that tuple count is [{$expectedCount}]. Actual: [{$actualCount}]";
        
        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that no tuples exist (empty system).
     */
    public static function assertNoTuples(FakeOpenFga $fake, ?string $message = null): void
    {
        self::assertTupleCount($fake, 0, $message);
    }

    /**
     * Assert that a specific number of checks were performed for a user.
     */
    public static function assertUserCheckCount(FakeOpenFga $fake, string $user, int $expectedCount, ?string $message = null): void
    {
        $userChecks = array_filter($fake->getChecks(), function ($check) use ($user) {
            return $check['user'] === $user;
        });
        
        $actualCount = count($userChecks);
        $message = $message ?? "Failed asserting that [{$expectedCount}] checks were performed for user [{$user}]. Actual: [{$actualCount}]";
        
        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that a specific number of checks were performed for an object.
     */
    public static function assertObjectCheckCount(FakeOpenFga $fake, string $object, int $expectedCount, ?string $message = null): void
    {
        $objectChecks = array_filter($fake->getChecks(), function ($check) use ($object) {
            return $check['object'] === $object;
        });
        
        $actualCount = count($objectChecks);
        $message = $message ?? "Failed asserting that [{$expectedCount}] checks were performed for object [{$object}]. Actual: [{$actualCount}]";
        
        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that a specific number of successful checks were performed.
     */
    public static function assertSuccessfulCheckCount(FakeOpenFga $fake, int $expectedCount, ?string $message = null): void
    {
        $successfulChecks = array_filter($fake->getChecks(), function ($check) {
            return $check['result'] === true;
        });
        
        $actualCount = count($successfulChecks);
        $message = $message ?? "Failed asserting that [{$expectedCount}] successful checks were performed. Actual: [{$actualCount}]";
        
        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that a specific number of failed checks were performed.
     */
    public static function assertFailedCheckCount(FakeOpenFga $fake, int $expectedCount, ?string $message = null): void
    {
        $failedChecks = array_filter($fake->getChecks(), function ($check) {
            return $check['result'] === false;
        });
        
        $actualCount = count($failedChecks);
        $message = $message ?? "Failed asserting that [{$expectedCount}] failed checks were performed. Actual: [{$actualCount}]";
        
        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that expand result contains expected users.
     *
     * @param array<string> $expectedUsers
     */
    public static function assertExpandContainsUsers(FakeOpenFga $fake, string $object, string $relation, array $expectedUsers, ?string $message = null): void
    {
        $expansion = $fake->expand($object, $relation);
        $actualUsers = $expansion['tree']['root']['leaf']['users'] ?? [];
        
        $message = $message ?? "Failed asserting that expand result for [{$relation}] on [{$object}] contains expected users";
        
        foreach ($expectedUsers as $expectedUser) {
            Assert::assertContains($expectedUser, $actualUsers, $message . " (missing user: {$expectedUser})");
        }
    }

    /**
     * Assert that expand result does not contain specific users.
     *
     * @param array<string> $forbiddenUsers
     */
    public static function assertExpandDoesNotContainUsers(FakeOpenFga $fake, string $object, string $relation, array $forbiddenUsers, ?string $message = null): void
    {
        $expansion = $fake->expand($object, $relation);
        $actualUsers = $expansion['tree']['root']['leaf']['users'] ?? [];
        
        $message = $message ?? "Failed asserting that expand result for [{$relation}] on [{$object}] does not contain forbidden users";
        
        foreach ($forbiddenUsers as $forbiddenUser) {
            Assert::assertNotContains($forbiddenUser, $actualUsers, $message . " (found forbidden user: {$forbiddenUser})");
        }
    }
}