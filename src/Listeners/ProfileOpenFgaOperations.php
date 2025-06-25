<?php

declare(strict_types=1);

namespace OpenFga\Laravel\Listeners;

use OpenFga\Laravel\Events\{BatchCheckPerformed, CheckPerformed, ExpandPerformed, ListObjectsPerformed, ListRelationsPerformed, ListUsersPerformed, ReadPerformed, WritePerformed};
use OpenFga\Laravel\Profiling\OpenFgaProfiler;

use function count;

final class ProfileOpenFgaOperations
{
    public function __construct(
        protected OpenFgaProfiler $profiler,
    ) {
    }

    public function handleBatchCheckPerformed(BatchCheckPerformed $event): void
    {
        if (! $this->profiler->isEnabled()) {
            return;
        }

        $profile = $this->profiler->startProfile('batch_check', [
            'checks_count' => count($event->checks),
        ]);

        $profile->end(true);
        $profile->addMetadata('response_time', $event->duration);
        $profile->addMetadata('cache_hits', $event->cacheHits);
        $profile->addMetadata('cache_misses', $event->cacheMisses);
    }

    public function handleCheckPerformed(CheckPerformed $event): void
    {
        if (! $this->profiler->isEnabled()) {
            return;
        }

        $profile = $this->profiler->startProfile('check', [
            'user' => $event->user,
            'relation' => $event->relation,
            'object' => $event->object,
        ]);

        $profile->end($event->allowed);
        $profile->setCacheStatus($event->cacheHit ? 'hit' : 'miss');
        $profile->addMetadata('response_time', $event->duration);
    }

    public function handleExpandPerformed(ExpandPerformed $event): void
    {
        if (! $this->profiler->isEnabled()) {
            return;
        }

        $profile = $this->profiler->startProfile('expand', [
            'object' => $event->object,
            'relation' => $event->relation,
        ]);

        $profile->end(true);
        $profile->addMetadata('response_time', $event->duration);
        $profile->addMetadata('tree_depth', $event->treeDepth);
    }

    public function handleListObjectsPerformed(ListObjectsPerformed $event): void
    {
        if (! $this->profiler->isEnabled()) {
            return;
        }

        $profile = $this->profiler->startProfile('list_objects', [
            'user' => $event->user,
            'relation' => $event->relation,
            'type' => $event->type,
        ]);

        $profile->end(true);
        $profile->addMetadata('response_time', $event->duration);
        $profile->addMetadata('results_count', count($event->objects));
    }

    public function handleListRelationsPerformed(ListRelationsPerformed $event): void
    {
        if (! $this->profiler->isEnabled()) {
            return;
        }

        $profile = $this->profiler->startProfile('list_relations', [
            'user' => $event->user,
            'object' => $event->object,
        ]);

        $profile->end(true);
        $profile->addMetadata('response_time', $event->duration);
        $profile->addMetadata('relations_count', count($event->relations));
    }

    public function handleListUsersPerformed(ListUsersPerformed $event): void
    {
        if (! $this->profiler->isEnabled()) {
            return;
        }

        $profile = $this->profiler->startProfile('list_users', [
            'object' => $event->object,
            'relation' => $event->relation,
            'user_filter' => $event->userFilter,
        ]);

        $profile->end(true);
        $profile->addMetadata('response_time', $event->duration);
        $profile->addMetadata('users_count', count($event->users));
    }

    public function handleReadPerformed(ReadPerformed $event): void
    {
        if (! $this->profiler->isEnabled()) {
            return;
        }

        $profile = $this->profiler->startProfile('read', [
            'page_size' => $event->pageSize,
        ]);

        $profile->end(true);
        $profile->addMetadata('response_time', $event->duration);
        $profile->addMetadata('tuples_count', count($event->tuples));
    }

    public function handleWritePerformed(WritePerformed $event): void
    {
        if (! $this->profiler->isEnabled()) {
            return;
        }

        $profile = $this->profiler->startProfile('write', [
            'writes_count' => count($event->writes),
            'deletes_count' => count($event->deletes),
        ]);

        $profile->end($event->success);
        $profile->addMetadata('response_time', $event->duration);
    }

    public function subscribe($events): array
    {
        return [
            CheckPerformed::class => 'handleCheckPerformed',
            BatchCheckPerformed::class => 'handleBatchCheckPerformed',
            WritePerformed::class => 'handleWritePerformed',
            ExpandPerformed::class => 'handleExpandPerformed',
            ListObjectsPerformed::class => 'handleListObjectsPerformed',
            ListRelationsPerformed::class => 'handleListRelationsPerformed',
            ListUsersPerformed::class => 'handleListUsersPerformed',
            ReadPerformed::class => 'handleReadPerformed',
        ];
    }
}
