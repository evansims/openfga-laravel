<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Testing;

use InvalidArgumentException;
use OpenFGA\Laravel\Testing\FakeOpenFga;
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

final class FakeOpenFgaTest extends TestCase
{
    protected FakeOpenFga $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeOpenFga;
    }

    public function test_assertion_batch_written_fails_when_no_batch_performed(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that a batch write was performed');

        $this->fake->assertBatchWritten();
    }

    public function test_assertion_batch_written_passes_when_batch_performed(): void
    {
        $this->fake->writeBatch([['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1']]);

        // Should not throw
        $this->fake->assertBatchWritten();
    }

    public function test_assertion_check_count_fails_with_incorrect_count(): void
    {
        $this->fake->check('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that [2] checks were performed. Actual: [1]');

        $this->fake->assertCheckCount(2);
    }

    public function test_assertion_check_count_passes_with_correct_count(): void
    {
        $this->fake->check('user:1', 'reader', 'document:1');
        $this->fake->check('user:1', 'writer', 'document:1');

        // Should not throw
        $this->fake->assertCheckCount(2);
    }

    public function test_assertion_checked_fails_when_check_not_performed(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that check [reader] was performed for [user:1] on [document:1]');

        $this->fake->assertChecked('user:1', 'reader', 'document:1');
    }

    public function test_assertion_checked_passes_when_check_performed(): void
    {
        $this->fake->check('user:1', 'reader', 'document:1');

        // Should not throw
        $this->fake->assertChecked('user:1', 'reader', 'document:1');
    }

    public function test_assertion_granted_fails_when_permission_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that permission [reader] was granted to [user:1] on [document:1]');

        $this->fake->assertGranted('user:1', 'reader', 'document:1');
    }

    public function test_assertion_granted_passes_when_permission_exists(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        // Should not throw
        $this->fake->assertGranted('user:1', 'reader', 'document:1');
    }

    public function test_assertion_no_batch_writes_fails_when_batch_performed(): void
    {
        $this->fake->writeBatch([['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1']]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that no batch writes were performed');

        $this->fake->assertNoBatchWrites();
    }

    public function test_assertion_no_batch_writes_passes_when_no_batches_performed(): void
    {
        // Should not throw
        $this->fake->assertNoBatchWrites();
    }

    public function test_assertion_no_checks_fails_when_checks_performed(): void
    {
        $this->fake->check('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that [0] checks were performed. Actual: [1]');

        $this->fake->assertNoChecks();
    }

    public function test_assertion_no_checks_passes_when_no_checks_performed(): void
    {
        // Should not throw
        $this->fake->assertNoChecks();
    }

    public function test_assertion_not_granted_fails_when_permission_exists(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that permission [reader] was not granted to [user:1] on [document:1]');

        $this->fake->assertNotGranted('user:1', 'reader', 'document:1');
    }

    public function test_assertion_not_granted_passes_when_permission_missing(): void
    {
        // Should not throw
        $this->fake->assertNotGranted('user:1', 'reader', 'document:1');
    }

    public function test_it_can_expand_relations(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:2', 'reader', 'document:1');

        $expansion = $this->fake->expand('document:1', 'reader');

        $this->assertArrayHasKey('tree', $expansion);
        $this->assertArrayHasKey('root', $expansion['tree']);
        $this->assertArrayHasKey('leaf', $expansion['tree']['root']);
        $this->assertArrayHasKey('users', $expansion['tree']['root']['leaf']);

        $users = $expansion['tree']['root']['leaf']['users'];
        $this->assertCount(2, $users);
        $this->assertContains('user:1', $users);
        $this->assertContains('user:2', $users);
    }

    public function test_it_can_grant_and_check_permissions(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');

        $this->assertTrue($this->fake->check('user:1', 'reader', 'document:1'));
        $this->assertFalse($this->fake->check('user:1', 'writer', 'document:1'));
        $this->assertFalse($this->fake->check('user:2', 'reader', 'document:1'));
    }

    public function test_it_can_list_objects(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->grant('user:1', 'reader', 'document:2');
        $this->fake->grant('user:1', 'writer', 'document:1');

        $objects = $this->fake->listObjects('user:1', 'reader', 'document');
        $this->assertCount(2, $objects);
        $this->assertContains('document:1', $objects);
        $this->assertContains('document:2', $objects);

        $objects = $this->fake->listObjects('user:1', 'writer', 'document');
        $this->assertCount(1, $objects);
        $this->assertContains('document:1', $objects);
    }

    public function test_it_can_mock_check_responses(): void
    {
        $this->fake->mockCheck('user:1', 'admin', 'system:1', true);

        $this->assertTrue($this->fake->check('user:1', 'admin', 'system:1'));
        $this->assertFalse($this->fake->check('user:1', 'admin', 'system:2'));
    }

    public function test_it_can_mock_list_objects_responses(): void
    {
        $this->fake->mockListObjects('user:1', 'reader', 'document', ['document:1', 'document:2']);

        $objects = $this->fake->listObjects('user:1', 'reader', 'document');
        $this->assertEquals(['document:1', 'document:2'], $objects);
    }

    public function test_it_can_perform_batch_writes(): void
    {
        $writes = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'reader', 'object' => 'document:1'],
        ];

        $deletes = [
            ['user' => 'user:3', 'relation' => 'reader', 'object' => 'document:1'],
        ];

        $this->fake->grant('user:3', 'reader', 'document:1');
        $this->assertTrue($this->fake->check('user:3', 'reader', 'document:1'));

        $this->fake->writeBatch($writes, $deletes);

        $this->assertTrue($this->fake->check('user:1', 'reader', 'document:1'));
        $this->assertTrue($this->fake->check('user:2', 'reader', 'document:1'));
        $this->assertFalse($this->fake->check('user:3', 'reader', 'document:1'));

        $writeBatch = $this->fake->getWrites();
        $this->assertCount(1, $writeBatch);
        $this->assertEquals($writes, $writeBatch[0]['writes']);
        $this->assertEquals($deletes, $writeBatch[0]['deletes']);
    }

    public function test_it_can_reset_all_data(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->fake->check('user:1', 'reader', 'document:1');
        $this->fake->mockCheck('user:2', 'writer', 'document:2', true);

        $this->fake->reset();

        $this->assertEmpty($this->fake->getTuples());
        $this->assertEmpty($this->fake->getChecks());
        $this->assertFalse($this->fake->check('user:2', 'writer', 'document:2'));
    }

    public function test_it_can_reset_failure_state(): void
    {
        $this->fake->shouldFail();
        $this->fake->shouldSucceed();

        // Should not throw
        $this->assertFalse($this->fake->check('user:1', 'reader', 'document:1'));
    }

    public function test_it_can_revoke_permissions(): void
    {
        $this->fake->grant('user:1', 'reader', 'document:1');
        $this->assertTrue($this->fake->check('user:1', 'reader', 'document:1'));

        $this->fake->revoke('user:1', 'reader', 'document:1');
        $this->assertFalse($this->fake->check('user:1', 'reader', 'document:1'));
    }

    public function test_it_can_simulate_custom_failures(): void
    {
        $customException = new InvalidArgumentException('Custom failure message');
        $this->fake->shouldFail($customException);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom failure message');

        $this->fake->check('user:1', 'reader', 'document:1');
    }

    public function test_it_can_simulate_failures(): void
    {
        $this->fake->shouldFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fake OpenFGA check failed');

        $this->fake->check('user:1', 'reader', 'document:1');
    }

    public function test_it_records_check_operations(): void
    {
        $this->fake->check('user:1', 'reader', 'document:1');
        $this->fake->check('user:1', 'writer', 'document:1');

        $checks = $this->fake->getChecks();
        $this->assertCount(2, $checks);

        $this->assertEquals('user:1', $checks[0]['user']);
        $this->assertEquals('reader', $checks[0]['relation']);
        $this->assertEquals('document:1', $checks[0]['object']);
        $this->assertFalse($checks[0]['result']);

        $this->assertEquals('user:1', $checks[1]['user']);
        $this->assertEquals('writer', $checks[1]['relation']);
        $this->assertEquals('document:1', $checks[1]['object']);
        $this->assertFalse($checks[1]['result']);
    }
}
