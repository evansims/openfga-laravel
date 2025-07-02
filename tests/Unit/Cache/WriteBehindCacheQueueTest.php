<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Cache;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager;
use OpenFGA\Laravel\Cache\WriteBehindCache;
use OpenFGA\Laravel\Jobs\{FlushWriteBehindCacheJob, WriteTupleToFgaJob};
use OpenFGA\Laravel\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

final class WriteBehindCacheQueueTest extends TestCase
{
    private WriteBehindCache $cache;

    private Cache $cacheStore;

    private AbstractOpenFgaManager $manager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->cacheStore = Mockery::mock(Cache::class);
        $this->cacheStore->shouldReceive('put')->byDefault()->andReturn(true);

        $this->manager = Mockery::mock(AbstractOpenFgaManager::class);
        $this->manager->shouldReceive('getDefaultConnection')->andReturn('main');

        $this->cache = new WriteBehindCache(
            cache: $this->cacheStore,
            manager: $this->manager,
            batchSize: 3,
            flushInterval: 5,
            useQueue: true,
            queueConnection: 'redis',
            queueName: 'openfga-test',
        );
    }

    #[Test]
    public function it_clears_buffers_after_queue_flush(): void
    {
        $this->cache->write('user:123', 'editor', 'document:456');
        $this->cache->delete('user:456', 'viewer', 'document:789');

        expect($this->cache->getPendingCount())->toBe([
            'writes' => 1,
            'deletes' => 1,
            'total' => 2,
        ]);

        $this->cache->flush();

        expect($this->cache->getPendingCount())->toBe([
            'writes' => 0,
            'deletes' => 0,
            'total' => 0,
        ]);
    }

    #[Test]
    public function it_dispatches_delete_jobs_when_flushing_with_queue(): void
    {
        $this->cache->delete('user:123', 'editor', 'document:456');
        $this->cache->delete('user:456', 'viewer', 'document:789');

        $stats = $this->cache->flush();

        expect($stats)->toBe(['writes' => 0, 'deletes' => 2]);

        Queue::assertPushed(WriteTupleToFgaJob::class, 2);

        Queue::assertPushed(WriteTupleToFgaJob::class, static fn ($job): bool => 'user:123' === $job->user
                && 'editor' === $job->relation
                && 'document:456' === $job->object
                && 'delete' === $job->operation
                && 'main' === $job->openfgaConnection);
    }

    #[Test]
    public function it_dispatches_write_jobs_when_flushing_with_queue(): void
    {
        $this->cache->write('user:123', 'editor', 'document:456');
        $this->cache->write('user:456', 'viewer', 'document:789');

        $stats = $this->cache->flush();

        expect($stats)->toBe(['writes' => 2, 'deletes' => 0]);

        Queue::assertPushed(WriteTupleToFgaJob::class, 2);

        Queue::assertPushed(WriteTupleToFgaJob::class, static fn ($job): bool => 'user:123' === $job->user
                && 'editor' === $job->relation
                && 'document:456' === $job->object
                && 'write' === $job->operation
                && 'main' === $job->openfgaConnection);

        Queue::assertPushed(WriteTupleToFgaJob::class, static fn ($job): bool => 'user:456' === $job->user
                && 'viewer' === $job->relation
                && 'document:789' === $job->object
                && 'write' === $job->operation
                && 'main' === $job->openfgaConnection);
    }

    #[Test]
    public function it_handles_mixed_operations_with_queue(): void
    {
        // Write then delete same tuple
        $this->cache->write('user:123', 'editor', 'document:456');
        $this->cache->delete('user:123', 'editor', 'document:456');

        // Different operations
        $this->cache->write('user:456', 'viewer', 'document:789');

        $stats = $this->cache->flush();

        expect($stats)->toBe(['writes' => 1, 'deletes' => 1]);

        Queue::assertPushed(WriteTupleToFgaJob::class, 2);

        // Should have one delete job for the first tuple
        Queue::assertPushed(WriteTupleToFgaJob::class, static fn ($job): bool => 'user:123' === $job->user
                && 'delete' === $job->operation);

        // And one write job for the second tuple
        Queue::assertPushed(WriteTupleToFgaJob::class, static fn ($job): bool => 'user:456' === $job->user
                && 'write' === $job->operation);
    }

    #[Test]
    public function it_schedules_flush_job_when_batch_size_reached(): void
    {
        // Batch size is 3
        $this->cache->write('user:1', 'editor', 'doc:1');
        $this->cache->write('user:2', 'editor', 'doc:2');

        Queue::assertNotPushed(FlushWriteBehindCacheJob::class);

        // This should trigger a flush
        $this->cache->write('user:3', 'editor', 'doc:3');

        Queue::assertPushed(FlushWriteBehindCacheJob::class);
        Queue::assertPushedOn('openfga-test', FlushWriteBehindCacheJob::class);
    }

    #[Test]
    public function it_updates_cache_immediately_with_queue_enabled(): void
    {
        config(['openfga.cache.write_behind_ttl' => 300]);

        $this->cacheStore->shouldReceive('put')
            ->once()
            ->with('openfga.check.user:123.editor.document:456', true, 300);

        $this->cache->write('user:123', 'editor', 'document:456');
    }

    #[Test]
    public function it_uses_correct_queue_configuration(): void
    {
        $this->cache->write('user:123', 'editor', 'document:456');
        $this->cache->flush();

        Queue::assertPushedOn('openfga-test', WriteTupleToFgaJob::class);
    }

    #[Test]
    public function it_uses_synchronous_flush_when_queue_disabled(): void
    {
        $syncCache = new WriteBehindCache(
            cache: $this->cacheStore,
            manager: $this->manager,
            batchSize: 100,
            flushInterval: 5,
            useQueue: false,
        );

        $this->manager->shouldReceive('writeBatch')
            ->once()
            ->with([
                ['user' => 'user:123', 'relation' => 'editor', 'object' => 'document:456'],
            ], [])
            ->andReturn(true);

        $syncCache->write('user:123', 'editor', 'document:456');
        $stats = $syncCache->flush();

        expect($stats)->toBe(['writes' => 1, 'deletes' => 0]);

        Queue::assertNothingPushed();
    }
}
