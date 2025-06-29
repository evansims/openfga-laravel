<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use OpenFGA\Laravel\Support\{ArrayHelper, MessageFormatter};
use PHPUnit\Framework\{Assert, AssertionFailedError, Exception, ExpectationFailedException, GeneratorNotSupportedException};
use RuntimeException;
use Throwable;

use function count;
use function sprintf;

/**
 * Comprehensive assertion helpers for testing OpenFGA authorization logic.
 *
 * This helper class provides a rich set of assertion methods specifically designed
 * for testing authorization scenarios with FakeOpenFga. It includes assertions for
 * permission checks, tuple existence, user access patterns, and expansion results.
 * These helpers make authorization tests more readable, maintainable, and expressive
 * by providing semantic assertions that clearly communicate testing intent.
 */
final class AssertionHelper
{
    /**
     * Assert that expand result contains expected users.
     *
     * @param array<string> $expectedUsers
     * @param FakeOpenFga   $fake
     * @param string        $object
     * @param string        $relation
     * @param ?string       $message
     *
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function assertExpandContainsUsers(FakeOpenFga $fake, string $object, string $relation, array $expectedUsers, ?string $message = null): void
    {
        $expansion = $fake->expand($object, $relation);

        /** @var array<string> $actualUsers */
        $actualUsers = ArrayHelper::getExpansionUsers($expansion);

        $message ??= sprintf('Failed asserting that expand result for [%s] on [%s] contains expected users', $relation, $object);

        foreach ($expectedUsers as $expectedUser) {
            Assert::assertContains($expectedUser, $actualUsers, $message . sprintf(' (missing user: %s)', $expectedUser));
        }
    }

    /**
     * Assert that expand result does not contain specific users.
     *
     * @param array<string> $forbiddenUsers
     * @param FakeOpenFga   $fake
     * @param string        $object
     * @param string        $relation
     * @param ?string       $message
     *
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function assertExpandDoesNotContainUsers(FakeOpenFga $fake, string $object, string $relation, array $forbiddenUsers, ?string $message = null): void
    {
        $expansion = $fake->expand($object, $relation);

        /** @var array<string> $actualUsers */
        $actualUsers = ArrayHelper::getExpansionUsers($expansion);

        $message ??= sprintf('Failed asserting that expand result for [%s] on [%s] does not contain forbidden users', $relation, $object);

        foreach ($forbiddenUsers as $forbiddenUser) {
            Assert::assertNotContains($forbiddenUser, $actualUsers, $message . sprintf(' (found forbidden user: %s)', $forbiddenUser));
        }
    }

    /**
     * Assert that a specific number of failed checks were performed.
     *
     * @param FakeOpenFga $fake
     * @param int         $expectedCount
     * @param ?string     $message
     *
     * @throws ExpectationFailedException
     */
    public static function assertFailedCheckCount(FakeOpenFga $fake, int $expectedCount, ?string $message = null): void
    {
        $failedChecks = array_filter($fake->getChecks(), static fn ($check): bool => false === $check['result']);

        $actualCount = count($failedChecks);
        $message ??= MessageFormatter::formatCountAssertion('failed checks were performed', $expectedCount, $actualCount);

        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that no tuples exist (empty system).
     *
     * @param FakeOpenFga $fake
     * @param ?string     $message
     *
     * @throws ExpectationFailedException
     */
    public static function assertNoTuples(FakeOpenFga $fake, ?string $message = null): void
    {
        self::assertTupleCount($fake, 0, $message);
    }

    /**
     * Assert that a specific number of checks were performed for an object.
     *
     * @param FakeOpenFga $fake
     * @param string      $object
     * @param int         $expectedCount
     * @param ?string     $message
     *
     * @throws ExpectationFailedException
     */
    public static function assertObjectCheckCount(FakeOpenFga $fake, string $object, int $expectedCount, ?string $message = null): void
    {
        $objectChecks = array_filter($fake->getChecks(), static fn ($check): bool => $check['object'] === $object);

        $actualCount = count($objectChecks);
        $message ??= sprintf('Failed asserting that [%d] checks were performed for object [%s]. Actual: [%d]', $expectedCount, $object, $actualCount);

        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that a specific number of successful checks were performed.
     *
     * @param FakeOpenFga $fake
     * @param int         $expectedCount
     * @param ?string     $message
     *
     * @throws ExpectationFailedException
     */
    public static function assertSuccessfulCheckCount(FakeOpenFga $fake, int $expectedCount, ?string $message = null): void
    {
        $successfulChecks = array_filter($fake->getChecks(), static fn ($check): bool => $check['result']);

        $actualCount = count($successfulChecks);
        $message ??= sprintf('Failed asserting that [%d] successful checks were performed. Actual: [%d]', $expectedCount, $actualCount);

        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that the total number of tuples matches expectation.
     *
     * @param FakeOpenFga $fake
     * @param int         $expectedCount
     * @param ?string     $message
     *
     * @throws ExpectationFailedException
     */
    public static function assertTupleCount(FakeOpenFga $fake, int $expectedCount, ?string $message = null): void
    {
        $actualCount = count($fake->getTuples());
        $message ??= sprintf('Failed asserting that tuple count is [%d]. Actual: [%d]', $expectedCount, $actualCount);

        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that specific tuples do not exist in the system.
     *
     * @param array<array{user: string, relation: string, object: string}> $forbiddenTuples
     * @param FakeOpenFga                                                  $fake
     * @param ?string                                                      $message
     *
     * @throws AssertionFailedError
     */
    public static function assertTuplesDoNotExist(FakeOpenFga $fake, array $forbiddenTuples, ?string $message = null): void
    {
        $message ??= 'Failed asserting that forbidden tuples do not exist';
        $actualTuples = $fake->getTuples();

        foreach ($forbiddenTuples as $forbiddenTuple) {
            foreach ($actualTuples as $actualTuple) {
                if ($actualTuple['user'] === $forbiddenTuple['user']
                    && $actualTuple['relation'] === $forbiddenTuple['relation']
                    && $actualTuple['object'] === $forbiddenTuple['object']) {
                    Assert::fail($message . sprintf(' (found forbidden tuple: %s#%s@%s)', $forbiddenTuple['user'], $forbiddenTuple['relation'], $forbiddenTuple['object']));
                }
            }
        }

        // All forbidden tuples were verified to not exist
    }

    /**
     * Assert that specific tuples exist in the system.
     *
     * @param array<array{user: string, relation: string, object: string}> $expectedTuples
     * @param FakeOpenFga                                                  $fake
     * @param ?string                                                      $message
     *
     * @throws AssertionFailedError
     */
    public static function assertTuplesExist(FakeOpenFga $fake, array $expectedTuples, ?string $message = null): void
    {
        $message ??= 'Failed asserting that expected tuples exist';
        $actualTuples = $fake->getTuples();

        foreach ($expectedTuples as $expectedTuple) {
            $found = false;

            foreach ($actualTuples as $actualTuple) {
                if ($actualTuple['user'] === $expectedTuple['user']
                    && $actualTuple['relation'] === $expectedTuple['relation']
                    && $actualTuple['object'] === $expectedTuple['object']) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                Assert::fail($message . sprintf(' (missing tuple: %s#%s@%s)', $expectedTuple['user'], $expectedTuple['relation'], $expectedTuple['object']));
            }
        }

        // All expected tuples were verified to exist
    }

    /**
     * Assert that a specific number of checks were performed for a user.
     *
     * @param FakeOpenFga $fake
     * @param string      $user
     * @param int         $expectedCount
     * @param ?string     $message
     *
     * @throws ExpectationFailedException
     */
    public static function assertUserCheckCount(FakeOpenFga $fake, string $user, int $expectedCount, ?string $message = null): void
    {
        $userChecks = array_filter($fake->getChecks(), static fn ($check): bool => $check['user'] === $user);

        $actualCount = count($userChecks);
        $message ??= sprintf('Failed asserting that [%d] checks were performed for user [%s]. Actual: [%d]', $expectedCount, $user, $actualCount);

        Assert::assertSame($expectedCount, $actualCount, $message);
    }

    /**
     * Assert that a user does not have access to specific objects.
     *
     * @param array<string> $forbiddenObjects
     * @param FakeOpenFga   $fake
     * @param string        $user
     * @param string        $relation
     * @param ?string       $message
     *
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function assertUserDoesNotHaveAccessToObjects(FakeOpenFga $fake, string $user, string $relation, array $forbiddenObjects, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that user [%s] does not have [%s] access to forbidden objects', $user, $relation);

        foreach ($forbiddenObjects as $forbiddenObject) {
            Assert::assertFalse($fake->check($user, $relation, $forbiddenObject), $message . sprintf(' (has unexpected access to: %s)', $forbiddenObject));
        }
    }

    /**
     * Assert that a user does not have a specific permission.
     *
     * @param FakeOpenFga $fake
     * @param string      $user
     * @param string      $relation
     * @param string      $object
     * @param ?string     $message
     *
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function assertUserDoesNotHavePermission(FakeOpenFga $fake, string $user, string $relation, string $object, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that user [%s] does not have permission [%s] on [%s]', $user, $relation, $object);

        Assert::assertFalse($fake->check($user, $relation, $object), $message);
    }

    /**
     * Assert that a user has access to a specific number of objects.
     *
     * @param FakeOpenFga $fake
     * @param string      $user
     * @param string      $relation
     * @param string      $type
     * @param int         $expectedCount
     * @param ?string     $message
     *
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws GeneratorNotSupportedException
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function assertUserHasAccessToObjectCount(FakeOpenFga $fake, string $user, string $relation, string $type, int $expectedCount, ?string $message = null): void
    {
        $objects = $fake->listObjects($user, $relation, $type);
        $actualCount = count($objects);

        $message ??= sprintf('Failed asserting that user [%s] has [%s] access to [%d] objects of type [%s]. Actual: [%d]', $user, $relation, $expectedCount, $type, $actualCount);

        Assert::assertCount($expectedCount, $objects, $message);
    }

    /**
     * Assert that a user has access to specific objects.
     *
     * @param array<string> $expectedObjects
     * @param FakeOpenFga   $fake
     * @param string        $user
     * @param string        $relation
     * @param ?string       $message
     *
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function assertUserHasAccessToObjects(FakeOpenFga $fake, string $user, string $relation, array $expectedObjects, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that user [%s] has [%s] access to expected objects', $user, $relation);

        foreach ($expectedObjects as $expectedObject) {
            Assert::assertTrue($fake->check($user, $relation, $expectedObject), $message . sprintf(' (missing access to: %s)', $expectedObject));
        }
    }

    /**
     * Assert that a user has all of the specified permissions.
     *
     * @param array<string> $relations
     * @param FakeOpenFga   $fake
     * @param string        $user
     * @param string        $object
     * @param ?string       $message
     *
     * @throws AssertionFailedError
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function assertUserHasAllPermissions(FakeOpenFga $fake, string $user, array $relations, string $object, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that user [%s] has all permissions [', $user) . implode(', ', $relations) . sprintf('] on [%s]', $object);

        foreach ($relations as $relation) {
            if (! $fake->check($user, $relation, $object)) {
                Assert::fail($message . sprintf(' (missing: %s)', $relation));
            }
        }

        // All permissions are present
    }

    /**
     * Assert that a user has any of the specified permissions.
     *
     * @param array<string> $relations
     * @param FakeOpenFga   $fake
     * @param string        $user
     * @param string        $object
     * @param ?string       $message
     *
     * @throws AssertionFailedError
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function assertUserHasAnyPermission(FakeOpenFga $fake, string $user, array $relations, string $object, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that user [%s] has any of the permissions [', $user) . implode(', ', $relations) . sprintf('] on [%s]', $object);

        foreach ($relations as $relation) {
            if ($fake->check($user, $relation, $object)) {
                // User has at least one of the required permissions
                return;
            }
        }

        Assert::fail($message);
    }

    /**
     * Assert that a user has a specific permission.
     *
     * @param FakeOpenFga $fake
     * @param string      $user
     * @param string      $relation
     * @param string      $object
     * @param ?string     $message
     *
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws Throwable
     */
    public static function assertUserHasPermission(FakeOpenFga $fake, string $user, string $relation, string $object, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that user [%s] has permission [%s] on [%s]', $user, $relation, $object);

        Assert::assertTrue($fake->check($user, $relation, $object), $message);
    }
}
