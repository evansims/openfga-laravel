<?php

declare(strict_types=1);

use OpenFGA\Laravel\Profiling\ProfileEntry;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('ProfileEntry', function (): void {
    it('is marked as final and internal', function (): void {
        $reflection = new ReflectionClass(ProfileEntry::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->getDocComment())->toContain('@internal');
    });

    it('can be constructed with operation and parameters', function (): void {
        $entry = new ProfileEntry(
            operation: 'check',
            parameters: ['user' => 'user:123', 'object' => 'doc:456'],
        );

        expect($entry->getOperation())->toBe('check');
        expect($entry->getParameters())->toBe(['user' => 'user:123', 'object' => 'doc:456']);
    });

    it('can be constructed with only operation', function (): void {
        $entry = new ProfileEntry('listObjects');

        expect($entry->getOperation())->toBe('listObjects');
        expect($entry->getParameters())->toBe([]);
    });

    describe('duration tracking', function (): void {
        it('tracks duration automatically', function (): void {
            $entry = new ProfileEntry('check');

            // Duration should be calculated even without explicit end
            $duration = $entry->getDuration();
            expect($duration)->toBeGreaterThanOrEqual(0);
        });

        it('calculates duration correctly when ended', function (): void {
            $entry = new ProfileEntry('check');

            // Get start time via reflection
            $startTimeReflection = new ReflectionProperty($entry, 'startTime');
            $startTime = $startTimeReflection->getValue($entry);

            // Mock 5ms duration by setting end time
            $endTimeReflection = new ReflectionProperty($entry, 'endTime');
            $endTimeReflection->setValue($entry, $startTime + 0.005); // 5ms

            // Also update success flag since end() normally sets it
            $successReflection = new ReflectionProperty($entry, 'success');
            $successReflection->setValue($entry, true);

            $duration = $entry->getDuration();

            expect($duration)->toBeGreaterThan(4.9); // Should be about 5ms
            expect($duration)->toBeLessThan(5.1);
        });

        it('returns duration in milliseconds', function (): void {
            $entry = new ProfileEntry('check');
            $entry->end();

            $duration = $entry->getDuration();
            expect($duration)->toBeFloat();
        });
    });

    describe('end method', function (): void {
        it('marks entry as successful by default', function (): void {
            $entry = new ProfileEntry('check');
            $entry->end();

            expect($entry->isSuccess())->toBeTrue();
            expect($entry->getError())->toBeNull();
        });

        it('can mark entry as failed with error', function (): void {
            $entry = new ProfileEntry('check');
            $entry->end(false, 'Permission denied');

            expect($entry->isSuccess())->toBeFalse();
            expect($entry->getError())->toBe('Permission denied');
        });

        it('returns self for fluent interface', function (): void {
            $entry = new ProfileEntry('check');
            $result = $entry->end();

            expect($result)->toBe($entry);
        });
    });

    describe('cache status', function (): void {
        it('has null cache status by default', function (): void {
            $entry = new ProfileEntry('check');

            expect($entry->getCacheStatus())->toBeNull();
        });

        it('can set cache status', function (): void {
            $entry = new ProfileEntry('check');
            $entry->setCacheStatus('hit');

            expect($entry->getCacheStatus())->toBe('hit');
        });

        it('returns self for fluent interface when setting cache status', function (): void {
            $entry = new ProfileEntry('check');
            $result = $entry->setCacheStatus('miss');

            expect($result)->toBe($entry);
        });
    });

    describe('metadata', function (): void {
        it('has empty metadata by default', function (): void {
            $entry = new ProfileEntry('check');

            expect($entry->getMetadata())->toBe([]);
        });

        it('can add metadata', function (): void {
            $entry = new ProfileEntry('check');
            $entry->addMetadata('objects_checked', 10);
            $entry->addMetadata('cache_hits', 8);

            expect($entry->getMetadata())->toBe([
                'objects_checked' => 10,
                'cache_hits' => 8,
            ]);
        });

        it('returns self for fluent interface when adding metadata', function (): void {
            $entry = new ProfileEntry('check');
            $result = $entry->addMetadata('test', 123);

            expect($result)->toBe($entry);
        });

        it('can chain metadata additions', function (): void {
            $entry = new ProfileEntry('check');
            $entry->addMetadata('key1', 100)
                ->addMetadata('key2', 200.5)
                ->addMetadata('key3', 300);

            expect($entry->getMetadata())->toBe([
                'key1' => 100,
                'key2' => 200.5,
                'key3' => 300,
            ]);
        });
    });

    describe('toArray method', function (): void {
        it('converts entry to array with all properties', function (): void {
            $entry = new ProfileEntry(
                operation: 'check',
                parameters: ['user' => 'user:123'],
            );
            $entry->setCacheStatus('hit');
            $entry->addMetadata('objects_count', 5);

            // Mock 10ms duration
            $startTimeReflection = new ReflectionProperty($entry, 'startTime');
            $startTime = $startTimeReflection->getValue($entry);
            $endTimeReflection = new ReflectionProperty($entry, 'endTime');
            $endTimeReflection->setValue($entry, $startTime + 0.01);
            // 10ms
            $successReflection = new ReflectionProperty($entry, 'success');
            $successReflection->setValue($entry, true);

            $array = $entry->toArray();

            expect($array)->toHaveKeys([
                'operation',
                'parameters',
                'started_at',
                'duration_ms',
                'success',
                'error',
                'cache_status',
                'metadata',
            ]);

            expect($array['operation'])->toBe('check');
            expect($array['parameters'])->toBe(['user' => 'user:123']);
            expect($array['started_at'])->toBeString();
            expect($array['duration_ms'])->toBeGreaterThan(9);
            expect($array['success'])->toBeTrue();
            expect($array['error'])->toBeNull();
            expect($array['cache_status'])->toBe('hit');
            expect($array['metadata'])->toBe(['objects_count' => 5]);
        });

        it('handles incomplete entries', function (): void {
            $entry = new ProfileEntry('check');

            $array = $entry->toArray();

            expect($array['success'])->toBeNull();
            expect($array['error'])->toBeNull();
            expect($array['cache_status'])->toBeNull();
            expect($array['metadata'])->toBe([]);
        });
    });

    describe('state tracking', function (): void {
        it('tracks initial state correctly', function (): void {
            $entry = new ProfileEntry('check');

            expect($entry->isSuccess())->toBeNull();
            expect($entry->getError())->toBeNull();
            expect($entry->getCacheStatus())->toBeNull();
        });

        it('maintains state through operations', function (): void {
            $entry = new ProfileEntry(
                operation: 'batchCheck',
                parameters: ['count' => 10],
            );

            $entry->setCacheStatus('partial')
                ->addMetadata('cache_hits', 7)
                ->addMetadata('cache_misses', 3)
                ->end(true);

            expect($entry->getOperation())->toBe('batchCheck');
            expect($entry->getParameters())->toBe(['count' => 10]);
            expect($entry->getCacheStatus())->toBe('partial');
            expect($entry->getMetadata())->toBe([
                'cache_hits' => 7,
                'cache_misses' => 3,
            ]);
            expect($entry->isSuccess())->toBeTrue();
        });
    });
});
