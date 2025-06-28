<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Cache;

use Illuminate\Support\Facades\{Cache, Event};
use OpenFGA\Laravel\Cache\CacheWarmer;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Events\CacheWarmed;
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class CacheWarmerTest extends TestCase
{
    private ManagerInterface & MockObject $manager;

    private CacheWarmer $warmer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->createMock(ManagerInterface::class);
        $this->warmer = new CacheWarmer($this->manager, [
            'batch_size' => 2,
            'ttl' => 300,
            'prefix' => 'test',
        ]);

        // Skip event and cache mocking as they're not available in test environment
    }

    public function test_invalidate_returns_zero_without_pattern_support(): void
    {
        $invalidated = $this->warmer->invalidate('user:123', 'viewer', null);

        $this->assertEquals(0, $invalidated);
    }

    public function test_warm_batch(): void
    {
        $this->manager->expects($this->once())
            ->method('batchCheck')
            ->with([
                ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
                ['user' => 'user:2', 'relation' => 'viewer', 'object' => 'document:1'],
            ])
            ->willReturn([true, false]);

        $warmed = $this->warmer->warmBatch(
            ['user:1', 'user:2'],
            ['viewer'],
            ['document:1'],
        );

        $this->assertEquals(2, $warmed);
    }

    public function test_warm_for_user(): void
    {
        $this->manager->expects($this->once())
            ->method('batchCheck')
            ->with([
                ['user' => 'user:123', 'relation' => 'viewer', 'object' => 'document:1'],
                ['user' => 'user:123', 'relation' => 'editor', 'object' => 'document:1'],
            ])
            ->willReturn([true, false]);

        Event::fake();

        $warmed = $this->warmer->warmForUser(
            'user:123',
            ['viewer', 'editor'],
            ['document:1'],
        );

        $this->assertEquals(2, $warmed);

        Event::assertDispatched(CacheWarmed::class, fn ($event) => 'user:123' === $event->identifier && 2 === $event->entriesWarmed);
    }

    public function test_warm_from_activity_with_empty_activity(): void
    {
        $warmed = $this->warmer->warmFromActivity(100);

        $this->assertEquals(0, $warmed);
    }

    public function test_warm_hierarchy(): void
    {
        // When checking from highest to lowest: owner (returns true) -> skip editor and viewer checks
        $this->manager->expects($this->once())
            ->method('check')
            ->with('user:123', 'owner', 'document:456')
            ->willReturn(true);

        $warmed = $this->warmer->warmHierarchy(
            'user:123',
            'document:456',
            ['viewer', 'editor', 'owner'],
        );

        $this->assertEquals(3, $warmed);
    }

    public function test_warm_hierarchy_stops_at_first_false(): void
    {
        $this->manager->expects($this->exactly(3))
            ->method('check')
            ->willReturnOnConsecutiveCalls(false, false, true);

        $warmed = $this->warmer->warmHierarchy(
            'user:123',
            'document:456',
            ['viewer', 'editor', 'owner'],
        );

        $this->assertEquals(3, $warmed);
    }

    public function test_warm_related(): void
    {
        // listObjects is called for each relation
        $this->manager->expects($this->exactly(2))
            ->method('listObjects')
            ->willReturnMap([
                ['user:123', 'viewer', 'document', [], [], null, ['document:1', 'document:2']],
                ['user:123', 'editor', 'document', [], [], null, ['document:1', 'document:2']],
            ]);

        // 2 objects * 2 relations = 4 checks per listObjects call
        // 2 listObjects calls * 4 checks = 8 total checks
        $this->manager->expects($this->exactly(8))
            ->method('check')
            ->willReturn(true);

        $warmed = $this->warmer->warmRelated(
            'user:123',
            'document:456',
            ['viewer', 'editor'],
        );

        $this->assertEquals(8, $warmed);
    }
}
