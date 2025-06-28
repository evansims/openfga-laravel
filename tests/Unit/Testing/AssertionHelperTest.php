<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Testing;

use OpenFGA\Laravel\Testing\{AssertionHelper, FakeOpenFga};
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

final class AssertionHelperTest extends TestCase
{
    protected FakeOpenFga $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeOpenFga;
    }

    public function test_assert_expand_contains_users_fails_when_users_missing(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that expand result for [reader] on [document:1] contains expected users (missing user: user:2)');

        AssertionHelper::assertExpandContainsUsers($this->fake, 'document:1', 'reader', ['user:1', 'user:2']);
    }

    public function test_assert_expand_contains_users_passes_when_users_exist(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:2', 'reader', 'document:1');

        // Should not throw
        AssertionHelper::assertExpandContainsUsers($this->fake, 'document:1', 'reader', ['user:1', 'user:2']);
    }

    public function test_assert_expand_does_not_contain_users_fails_when_users_exist(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:2', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that expand result for [reader] on [document:1] does not contain forbidden users (found forbidden user: user:2)');

        AssertionHelper::assertExpandDoesNotContainUsers($this->fake, 'document:1', 'reader', ['user:2', 'user:3']);
    }

    public function test_assert_expand_does_not_contain_users_passes_when_users_missing(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        // Should not throw
        AssertionHelper::assertExpandDoesNotContainUsers($this->fake, 'document:1', 'reader', ['user:2', 'user:3']);
    }

    public function test_assert_failed_check_count_fails_with_incorrect_count(): void
    {
        $this->fake->check('user:1', 'writer', 'document:1'); // fail

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that [2] failed checks were performed. Actual: [1]');

        AssertionHelper::assertFailedCheckCount($this->fake, 2);
    }

    public function test_assert_failed_check_count_passes_with_correct_count(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->fake->check('user:1', 'reader', 'document:1'); // success
        $this->fake->check('user:1', 'writer', 'document:1'); // fail
        $this->fake->check('user:2', 'reader', 'document:1'); // fail

        // Should not throw
        AssertionHelper::assertFailedCheckCount($this->fake, 2);
    }

    public function test_assert_no_tuples_fails_when_tuples_exist(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);

        AssertionHelper::assertNoTuples($this->fake);
    }

    public function test_assert_no_tuples_passes_when_no_tuples(): void
    {
        // Should not throw
        AssertionHelper::assertNoTuples($this->fake);
    }

    public function test_assert_object_check_count_fails_with_incorrect_count(): void
    {
        $this->fake->check('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that [2] checks were performed for object [document:1]. Actual: [1]');

        AssertionHelper::assertObjectCheckCount($this->fake, 'document:1', 2);
    }

    public function test_assert_object_check_count_passes_with_correct_count(): void
    {
        $this->fake->check('user:1', 'reader', 'document:1');
        $this->fake->check('user:2', 'writer', 'document:1');
        $this->fake->check('user:1', 'reader', 'document:2');

        // Should not throw
        AssertionHelper::assertObjectCheckCount($this->fake, 'document:1', 2);
    }

    public function test_assert_successful_check_count_fails_with_incorrect_count(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->check('user:1', 'reader', 'document:1'); // success

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that [2] successful checks were performed. Actual: [1]');

        AssertionHelper::assertSuccessfulCheckCount($this->fake, 2);
    }

    public function test_assert_successful_check_count_passes_with_correct_count(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->fake->check('user:1', 'reader', 'document:1'); // success
        $this->fake->check('user:1', 'writer', 'document:1'); // fail
        $this->fake->check('user:2', 'reader', 'document:1'); // fail

        // Should not throw
        AssertionHelper::assertSuccessfulCheckCount($this->fake, 1);
    }

    public function test_assert_tuple_count_fails_with_incorrect_count(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that tuple count is [2]. Actual: [1]');

        AssertionHelper::assertTupleCount($this->fake, 2);
    }

    public function test_assert_tuple_count_passes_with_correct_count(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:2', 'writer', 'document:2');

        // Should not throw
        AssertionHelper::assertTupleCount($this->fake, 2);
    }

    public function test_assert_tuples_do_not_exist_fails_when_tuples_exist(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:2', 'writer', 'document:2');

        $forbiddenTuples = [
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that forbidden tuples do not exist (found forbidden tuple: user:2#writer@document:2)');

        AssertionHelper::assertTuplesDoNotExist($this->fake, $forbiddenTuples);
    }

    public function test_assert_tuples_do_not_exist_passes_when_tuples_missing(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $forbiddenTuples = [
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ];

        // Should not throw
        AssertionHelper::assertTuplesDoNotExist($this->fake, $forbiddenTuples);

        // Add explicit assertion to verify the test passed
        $this->addToAssertionCount(1);
    }

    public function test_assert_tuples_exist_fails_when_tuples_missing(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $expectedTuples = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that expected tuples exist (missing tuple: user:2#writer@document:2)');

        AssertionHelper::assertTuplesExist($this->fake, $expectedTuples);
    }

    public function test_assert_tuples_exist_passes_when_tuples_exist(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:2', 'writer', 'document:2');

        $expectedTuples = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ];

        // Should not throw
        AssertionHelper::assertTuplesExist($this->fake, $expectedTuples);

        // Add explicit assertion to verify the test passed
        $this->addToAssertionCount(1);
    }

    public function test_assert_user_check_count_fails_with_incorrect_count(): void
    {
        $this->fake->check('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that [2] checks were performed for user [user:1]. Actual: [1]');

        AssertionHelper::assertUserCheckCount($this->fake, 'user:1', 2);
    }

    public function test_assert_user_check_count_passes_with_correct_count(): void
    {
        $this->fake->check('user:1', 'reader', 'document:1');
        $this->fake->check('user:1', 'writer', 'document:1');
        $this->fake->check('user:2', 'reader', 'document:1');

        // Should not throw
        AssertionHelper::assertUserCheckCount($this->fake, 'user:1', 2);
    }

    public function test_assert_user_does_not_have_access_to_objects_fails_when_access_exists(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:1', 'reader', 'document:2');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that user [user:1] does not have [reader] access to forbidden objects (has unexpected access to: document:2)');

        AssertionHelper::assertUserDoesNotHaveAccessToObjects($this->fake, 'user:1', 'reader', ['document:2', 'document:3']);
    }

    public function test_assert_user_does_not_have_access_to_objects_passes_when_no_access(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        // Should not throw
        AssertionHelper::assertUserDoesNotHaveAccessToObjects($this->fake, 'user:1', 'reader', ['document:2', 'document:3']);
    }

    public function test_assert_user_does_not_have_permission_fails_when_permission_exists(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that user [user:1] does not have permission [reader] on [document:1]');

        AssertionHelper::assertUserDoesNotHavePermission($this->fake, 'user:1', 'reader', 'document:1');
    }

    public function test_assert_user_does_not_have_permission_passes_when_permission_missing(): void
    {
        // Should not throw
        AssertionHelper::assertUserDoesNotHavePermission($this->fake, 'user:1', 'reader', 'document:1');
    }

    public function test_assert_user_has_access_to_object_count_fails_with_incorrect_count(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that user [user:1] has [reader] access to [2] objects of type [document]. Actual: [1]');

        AssertionHelper::assertUserHasAccessToObjectCount($this->fake, 'user:1', 'reader', 'document', 2);
    }

    public function test_assert_user_has_access_to_object_count_passes_with_correct_count(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:1', 'reader', 'document:2');

        // Should not throw
        AssertionHelper::assertUserHasAccessToObjectCount($this->fake, 'user:1', 'reader', 'document', 2);
    }

    public function test_assert_user_has_access_to_objects_fails_when_access_missing(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that user [user:1] has [reader] access to expected objects (missing access to: document:2)');

        AssertionHelper::assertUserHasAccessToObjects($this->fake, 'user:1', 'reader', ['document:1', 'document:2']);
    }

    public function test_assert_user_has_access_to_objects_passes_when_access_exists(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:1', 'reader', 'document:2');

        // Should not throw
        AssertionHelper::assertUserHasAccessToObjects($this->fake, 'user:1', 'reader', ['document:1', 'document:2']);
    }

    public function test_assert_user_has_all_permissions_fails_when_some_permissions_missing(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that user [user:1] has all permissions [reader, writer] on [document:1] (missing: writer)');

        AssertionHelper::assertUserHasAllPermissions($this->fake, 'user:1', ['reader', 'writer'], 'document:1');
    }

    public function test_assert_user_has_all_permissions_passes_when_all_permissions_exist(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:1', 'writer', 'document:1');

        // Should not throw
        AssertionHelper::assertUserHasAllPermissions($this->fake, 'user:1', ['reader', 'writer'], 'document:1');

        // Add explicit assertion to verify the test passed
        $this->addToAssertionCount(1);
    }

    public function test_assert_user_has_any_permission_fails_when_no_permissions_exist(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that user [user:1] has any of the permissions [reader, writer] on [document:1]');

        AssertionHelper::assertUserHasAnyPermission($this->fake, 'user:1', ['reader', 'writer'], 'document:1');
    }

    public function test_assert_user_has_any_permission_passes_when_one_permission_exists(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        // Should not throw
        AssertionHelper::assertUserHasAnyPermission($this->fake, 'user:1', ['reader', 'writer'], 'document:1');

        // Add explicit assertion to verify the test passed
        $this->addToAssertionCount(1);
    }

    public function test_assert_user_has_permission_fails_when_permission_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that user [user:1] has permission [reader] on [document:1]');

        AssertionHelper::assertUserHasPermission($this->fake, 'user:1', 'reader', 'document:1');
    }

    public function test_assert_user_has_permission_passes_when_permission_exists(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        // Should not throw
        AssertionHelper::assertUserHasPermission($this->fake, 'user:1', 'reader', 'document:1');
    }
}
