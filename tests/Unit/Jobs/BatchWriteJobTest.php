<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Jobs;

use DateTimeImmutable;
use OpenFGA\Laravel\Jobs\BatchWriteJob;
use OpenFGA\Laravel\Testing\FakesOpenFga;
use OpenFGA\Laravel\Tests\TestCase;

final class BatchWriteJobTest extends TestCase
{
    use FakesOpenFga;

    public function test_batch_write_job_backoff_strategy(): void
    {
        $job = new BatchWriteJob;

        $backoff = $job->backoff();

        $this->assertEquals([1, 5, 10], $backoff);
    }

    public function test_batch_write_job_can_be_instantiated(): void
    {
        $writes = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ];

        $deletes = [
            ['user' => 'user:3', 'relation' => 'reader', 'object' => 'document:3'],
        ];

        $job = new BatchWriteJob($writes, $deletes, 'main');

        $this->assertInstanceOf(BatchWriteJob::class, $job);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(120, $job->timeout);
        $this->assertTrue($job->shouldBeEncrypted);
    }

    public function test_batch_write_job_has_correct_tags(): void
    {
        $writes = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ];

        $deletes = [
            ['user' => 'user:3', 'relation' => 'reader', 'object' => 'document:3'],
        ];

        $job = new BatchWriteJob($writes, $deletes, 'test');

        $tags = $job->tags();

        $this->assertContains('openfga', $tags);
        $this->assertContains('batch-write', $tags);
        $this->assertContains('connection:test', $tags);
        $this->assertContains('writes:2', $tags);
        $this->assertContains('deletes:1', $tags);
    }

    public function test_batch_write_job_retry_until(): void
    {
        $job = new BatchWriteJob;

        $retryUntil = $job->retryUntil();

        $this->assertInstanceOf(DateTimeImmutable::class, $retryUntil);
        $this->assertGreaterThan(now(), $retryUntil);
    }
}
