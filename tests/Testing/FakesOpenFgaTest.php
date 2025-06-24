<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Testing;

use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Testing\FakesOpenFga;
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

final class FakesOpenFgaTest extends TestCase
{
    use FakesOpenFga;

    public function test_assertion_no_permission_checks_fails_when_checks_performed(): void
    {
        $fake = $this->fakeOpenFga();
        $fake->check('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);

        $this->assertNoPermissionChecks();
    }

    public function test_assertion_no_permission_checks_passes(): void
    {
        $this->fakeOpenFga();

        // Should not throw
        $this->assertNoPermissionChecks();
    }

    public function test_assertion_permission_check_count_fails_with_wrong_count(): void
    {
        $fake = $this->fakeOpenFga();
        $fake->check('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);

        $this->assertPermissionCheckCount(2);
    }

    public function test_assertion_permission_check_count_passes(): void
    {
        $fake = $this->fakeOpenFga();
        $fake->check('user:1', 'reader', 'document:1');
        $fake->check('user:1', 'writer', 'document:1');

        // Should not throw
        $this->assertPermissionCheckCount(2);
    }

    public function test_assertion_permission_checked_fails_when_not_checked(): void
    {
        $this->fakeOpenFga();

        $this->expectException(AssertionFailedError::class);

        $this->assertPermissionChecked('user:1', 'reader', 'document:1');
    }

    public function test_assertion_permission_checked_passes(): void
    {
        $fake = $this->fakeOpenFga();
        $fake->check('user:1', 'reader', 'document:1');

        // Should not throw
        $this->assertPermissionChecked('user:1', 'reader', 'document:1');
    }

    public function test_assertion_permission_granted_fails_when_not_granted(): void
    {
        $this->fakeOpenFga();

        $this->expectException(AssertionFailedError::class);

        $this->assertPermissionGranted('user:1', 'reader', 'document:1');
    }

    public function test_assertion_permission_granted_passes(): void
    {
        $fake = $this->fakeOpenFga();
        $fake->grant('user:1', 'reader', 'document:1');

        // Should not throw
        $this->assertPermissionGranted('user:1', 'reader', 'document:1');
    }

    public function test_assertion_permission_not_checked_fails_when_checked(): void
    {
        $fake = $this->fakeOpenFga();
        $fake->check('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);

        $this->assertPermissionNotChecked('user:1', 'reader', 'document:1');
    }

    public function test_assertion_permission_not_checked_passes(): void
    {
        $this->fakeOpenFga();

        // Should not throw
        $this->assertPermissionNotChecked('user:1', 'reader', 'document:1');
    }

    public function test_assertion_permission_not_granted_fails_when_granted(): void
    {
        $fake = $this->fakeOpenFga();
        $fake->grant('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);

        $this->assertPermissionNotGranted('user:1', 'reader', 'document:1');
    }

    public function test_assertion_permission_not_granted_passes(): void
    {
        $this->fakeOpenFga();

        // Should not throw
        $this->assertPermissionNotGranted('user:1', 'reader', 'document:1');
    }

    public function test_assertions_fail_when_fake_not_active(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('OpenFGA fake is not active. Call fakeOpenFga() first.');

        $this->assertPermissionGranted('user:1', 'reader', 'document:1');
    }

    public function test_it_can_expand_through_manager(): void
    {
        $fake = $this->fakeOpenFga();
        $manager = app(OpenFgaManager::class);

        $fake->grant('user:1', 'reader', 'document:1');
        $fake->grant('user:2', 'reader', 'document:1');

        $expansion = $manager->expand('document:1', 'reader');

        $this->assertArrayHasKey('tree', $expansion);
        $users = $expansion['tree']['root']['leaf']['users'];
        $this->assertCount(2, $users);
        $this->assertContains('user:1', $users);
        $this->assertContains('user:2', $users);
    }

    public function test_it_can_fake_openfga_manager(): void
    {
        $fake = $this->fakeOpenFga();

        $this->assertNotNull($fake);
        $this->assertSame($fake, $this->getFakeOpenFga());

        // Test that the manager is properly replaced (we expect an anonymous class now)
        $manager = app(OpenFgaManager::class);
        $this->assertNotNull($manager);

        // Test that it responds to expected methods
        $this->assertTrue(method_exists($manager, 'check'));
        $this->assertTrue(method_exists($manager, 'grant'));
        $this->assertTrue(method_exists($manager, 'revoke'));
    }

    public function test_it_can_grant_and_revoke_through_manager(): void
    {
        $fake = $this->fakeOpenFga();
        $manager = app(OpenFgaManager::class);

        // Grant through manager
        $manager->grant('user:1', 'reader', 'document:1');

        // Verify through fake
        $this->assertTrue($fake->check('user:1', 'reader', 'document:1'));

        // Revoke through manager
        $manager->revoke('user:1', 'reader', 'document:1');

        // Verify through fake
        $this->assertFalse($fake->check('user:1', 'reader', 'document:1'));
    }

    public function test_it_can_list_objects_through_manager(): void
    {
        $fake = $this->fakeOpenFga();
        $manager = app(OpenFgaManager::class);

        $fake->grant('user:1', 'reader', 'document:1');
        $fake->grant('user:1', 'reader', 'document:2');

        $objects = $manager->listObjects('user:1', 'reader', 'document');

        $this->assertCount(2, $objects);
        $this->assertContains('document:1', $objects);
        $this->assertContains('document:2', $objects);
    }

    public function test_it_can_test_permissions_through_manager(): void
    {
        $fake = $this->fakeOpenFga();
        $manager = app(OpenFgaManager::class);

        // Grant permission through fake
        $fake->grant('user:1', 'reader', 'document:1');

        // Check through manager (should delegate to fake)
        $this->assertTrue($manager->check('user:1', 'reader', 'document:1'));
        $this->assertFalse($manager->check('user:1', 'writer', 'document:1'));
    }

    public function test_it_can_write_batch_through_manager(): void
    {
        $fake = $this->fakeOpenFga();
        $manager = app(OpenFgaManager::class);

        $writes = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
        ];

        $manager->writeBatch($writes);

        $this->assertTrue($fake->check('user:1', 'reader', 'document:1'));
        $this->assertCount(1, $fake->getWrites());
    }
}
