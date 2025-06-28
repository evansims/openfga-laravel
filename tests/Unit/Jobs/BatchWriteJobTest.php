<?php

declare(strict_types=1);

use OpenFGA\Laravel\Jobs\BatchWriteJob;
use OpenFGA\Laravel\Testing\FakesOpenFga;

describe('BatchWriteJob', function (): void {
    uses(FakesOpenFga::class);

    it('has correct backoff strategy', function (): void {
        $job = new BatchWriteJob;

        $backoff = $job->backoff();

        expect($backoff)->toBe([1, 5, 10]);
    });

    it('can be instantiated', function (): void {
        $writes = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ];

        $deletes = [
            ['user' => 'user:3', 'relation' => 'reader', 'object' => 'document:3'],
        ];

        $job = new BatchWriteJob($writes, $deletes, 'main');

        expect($job)->toBeInstanceOf(BatchWriteJob::class);
        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(120);
        expect($job->shouldBeEncrypted)->toBeTrue();
    });

    it('has correct tags', function (): void {
        $writes = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ];

        $deletes = [
            ['user' => 'user:3', 'relation' => 'reader', 'object' => 'document:3'],
        ];

        $job = new BatchWriteJob($writes, $deletes, 'test');

        $tags = $job->tags();

        expect($tags)->toContain('openfga');
        expect($tags)->toContain('batch-write');
        expect($tags)->toContain('connection:test');
        expect($tags)->toContain('writes:2');
        expect($tags)->toContain('deletes:1');
    });

    it('has retry until configured', function (): void {
        $job = new BatchWriteJob;

        $retryUntil = $job->retryUntil();

        expect($retryUntil)->toBeInstanceOf(DateTimeImmutable::class);
        expect($retryUntil->getTimestamp())->toBeGreaterThan(now()->timestamp);
    });
});
