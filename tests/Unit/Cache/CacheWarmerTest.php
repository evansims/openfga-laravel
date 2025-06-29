<?php

declare(strict_types=1);

use Illuminate\Support\Facades\{Event};
use OpenFGA\Laravel\Cache\CacheWarmer;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Events\CacheWarmed;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('CacheWarmer', function (): void {
    beforeEach(function (): void {
        $this->manager = mock(ManagerInterface::class);
        $this->warmer = new CacheWarmer($this->manager, [
            'batch_size' => 2,
            'ttl' => 300,
            'prefix' => 'test',
        ]);
    });

    it('returns zero when invalidating without pattern support', function (): void {
        $invalidated = $this->warmer->invalidate('user:123', 'viewer', null);

        expect($invalidated)->toBe(0);
    });

    it('warms batch', function (): void {
        $this->manager
            ->shouldReceive('batchCheck')
            ->once()
            ->with([
                ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
                ['user' => 'user:2', 'relation' => 'viewer', 'object' => 'document:1'],
            ])
            ->andReturn([true, false]);

        $warmed = $this->warmer->warmBatch(
            ['user:1', 'user:2'],
            ['viewer'],
            ['document:1'],
        );

        expect($warmed)->toBe(2);
    });

    it('warms for user', function (): void {
        $this->manager
            ->shouldReceive('batchCheck')
            ->once()
            ->with([
                ['user' => 'user:123', 'relation' => 'viewer', 'object' => 'document:1'],
                ['user' => 'user:123', 'relation' => 'editor', 'object' => 'document:1'],
            ])
            ->andReturn([true, false]);

        Event::fake();

        $warmed = $this->warmer->warmForUser(
            'user:123',
            ['viewer', 'editor'],
            ['document:1'],
        );

        expect($warmed)->toBe(2);

        Event::assertDispatched(CacheWarmed::class, fn ($event) => 'user:123' === $event->identifier && 2 === $event->entriesWarmed);
    });

    it('warms from activity with empty activity', function (): void {
        $warmed = $this->warmer->warmFromActivity(100);

        expect($warmed)->toBe(0);
    });

    it('warms hierarchy', function (): void {
        // When checking from highest to lowest: owner (returns true) -> skip editor and viewer checks
        $this->manager
            ->shouldReceive('check')
            ->once()
            ->with('user:123', 'owner', 'document:456')
            ->andReturn(true);

        $warmed = $this->warmer->warmHierarchy(
            'user:123',
            'document:456',
            ['viewer', 'editor', 'owner'],
        );

        expect($warmed)->toBe(3);
    });

    it('warms hierarchy stops at first false', function (): void {
        $this->manager
            ->shouldReceive('check')
            ->times(3)
            ->andReturnValues([false, false, true]);

        $warmed = $this->warmer->warmHierarchy(
            'user:123',
            'document:456',
            ['viewer', 'editor', 'owner'],
        );

        expect($warmed)->toBe(3);
    });

    it('warms related', function (): void {
        // listObjects is called for each relation
        $this->manager
            ->shouldReceive('listObjects')
            ->times(2)
            ->andReturnUsing(function ($user, $relation, $type) {
                if ('user:123' === $user && in_array(needle: $relation, haystack: ['viewer', 'editor'], strict: true) && 'document' === $type) {
                    return ['document:1', 'document:2'];
                }

                return [];
            });

        // 2 objects * 2 relations = 4 checks per listObjects call
        // 2 listObjects calls * 4 checks = 8 total checks
        $this->manager
            ->shouldReceive('check')
            ->times(8)
            ->andReturn(true);

        $warmed = $this->warmer->warmRelated(
            'user:123',
            'document:456',
            ['viewer', 'editor'],
        );

        expect($warmed)->toBe(8);
    });
});
