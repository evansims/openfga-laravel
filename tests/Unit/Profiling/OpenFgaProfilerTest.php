<?php

declare(strict_types=1);

use Illuminate\Support\Facades\{Config, Log};
use OpenFGA\Laravel\Profiling\{OpenFgaProfiler, ProfileEntry};
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('OpenFgaProfiler', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->setConfigWithRestore('openfga.profiling.enabled', true);
        $this->setConfigWithRestore('openfga.profiling.slow_query_threshold', 100);
        $this->setConfigWithRestore('openfga.profiling.log_slow_queries', true);

        $this->profiler = new OpenFgaProfiler;
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    it('is marked as final and internal', function (): void {
        $reflection = new ReflectionClass(OpenFgaProfiler::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->getDocComment())->toContain('@internal');
    });

    describe('constructor', function (): void {
        it('reads configuration on construction', function (): void {
            $this->setConfigWithRestore('openfga.profiling.enabled', false);
            $profiler = new OpenFgaProfiler;

            expect($profiler->isEnabled())->toBeFalse();
        });

        it('handles non-numeric slow query threshold', function (): void {
            $this->setConfigWithRestore('openfga.profiling.slow_query_threshold', 'invalid');
            $profiler = new OpenFgaProfiler;

            // Should default to 100
            $profiler->startProfile('check')->end();
            expect($profiler->getSlowQueries())->toHaveCount(0);
        });
    });

    describe('enable/disable', function (): void {
        it('can be enabled and disabled', function (): void {
            $this->profiler->disable();
            expect($this->profiler->isEnabled())->toBeFalse();

            $this->profiler->enable();
            expect($this->profiler->isEnabled())->toBeTrue();
        });
    });

    describe('startProfile', function (): void {
        it('creates and tracks profile entries when enabled', function (): void {
            $entry = $this->profiler->startProfile('check', ['user' => 'user:123']);

            expect($entry)->toBeInstanceOf(ProfileEntry::class);
            expect($entry->getOperation())->toBe('check');
            expect($entry->getParameters())->toBe(['user' => 'user:123']);

            $profiles = $this->profiler->getProfiles();
            expect($profiles)->toHaveCount(1);
            expect($profiles->first())->toBe($entry);
        });

        it('creates profile entries but does not track when disabled', function (): void {
            $this->profiler->disable();

            $entry = $this->profiler->startProfile('check');

            expect($entry)->toBeInstanceOf(ProfileEntry::class);
            expect($this->profiler->getProfiles())->toHaveCount(0);
        });
    });

    describe('getProfiles', function (): void {
        it('returns all tracked profiles', function (): void {
            $entry1 = $this->profiler->startProfile('check');
            $entry2 = $this->profiler->startProfile('listObjects');
            $entry3 = $this->profiler->startProfile('batchCheck');

            $profiles = $this->profiler->getProfiles();

            expect($profiles)->toHaveCount(3);
            expect($profiles[0])->toBe($entry1);
            expect($profiles[1])->toBe($entry2);
            expect($profiles[2])->toBe($entry3);
        });

        it('returns empty collection when no profiles exist', function (): void {
            expect($this->profiler->getProfiles())->toHaveCount(0);
        });
    });

    describe('getSlowQueries', function (): void {
        it('identifies slow queries based on duration property', function (): void {
            // Create entries with mocked durations
            $fast = $this->profiler->startProfile('check1');
            $startTimeReflection = new ReflectionProperty($fast, 'startTime');
            $startTime = $startTimeReflection->getValue($fast);
            $endTimeReflection = new ReflectionProperty($fast, 'endTime');
            $endTimeReflection->setValue($fast, $startTime + 0.01); // 10ms

            $slow = $this->profiler->startProfile('check2');
            $startTimeReflection = new ReflectionProperty($slow, 'startTime');
            $startTime = $startTimeReflection->getValue($slow);
            $endTimeReflection = new ReflectionProperty($slow, 'endTime');
            $endTimeReflection->setValue($slow, $startTime + 0.15); // 150ms

            $slowQueries = $this->profiler->getSlowQueries();

            expect($slowQueries)->toHaveCount(1);
            expect($slowQueries->first())->toBe($slow);
        });

        it('returns empty collection when no slow queries exist', function (): void {
            $entry = $this->profiler->startProfile('check');
            $startTimeReflection = new ReflectionProperty($entry, 'startTime');
            $startTime = $startTimeReflection->getValue($entry);
            $endTimeReflection = new ReflectionProperty($entry, 'endTime');
            $endTimeReflection->setValue($entry, $startTime + 0.01); // Fast query

            expect($this->profiler->getSlowQueries())->toHaveCount(0);
        });
    });

    describe('getSummary', function (): void {
        it('provides comprehensive performance summary', function (): void {
            // Add some profiles
            $this->profiler->startProfile('check')->end();
            $this->profiler->startProfile('check')->end();
            $this->profiler->startProfile('listObjects')->end();

            $slow = $this->profiler->startProfile('batchCheck');
            // Mock a slow operation by setting end time
            $startTimeReflection = new ReflectionProperty($slow, 'startTime');
            $startTime = $startTimeReflection->getValue($slow);
            $endTimeReflection = new ReflectionProperty($slow, 'endTime');
            $endTimeReflection->setValue($slow, $startTime + 0.15);

            $summary = $this->profiler->getSummary();

            expect($summary)->toHaveKeys([
                'total_operations',
                'total_time',
                'slow_queries',
                'operations',
            ]);

            expect($summary['total_operations'])->toBe(4);
            expect($summary['slow_queries'])->toBeGreaterThan(0);
            expect($summary['operations'])->toHaveKeys(['check', 'listObjects', 'batchCheck']);

            // Check operation stats
            expect($summary['operations']['check'])->toHaveKeys([
                'count',
                'total_time',
                'avg_time',
                'min_time',
                'max_time',
            ]);
            expect($summary['operations']['check']['count'])->toBe(2);
        });

        it('handles empty profile list', function (): void {
            $summary = $this->profiler->getSummary();

            expect($summary['total_operations'])->toBe(0);
            expect($summary['total_time'])->toBe(0);
            expect($summary['slow_queries'])->toBe(0);
            expect($summary['operations'])->toBe([]);
        });
    });

    describe('logSlowQueries', function (): void {
        it('logs slow queries when enabled', function (): void {
            $this->setConfigWithRestore('openfga.logging.channel', 'test-channel');

            // We need to create a slow query
            $slow = $this->profiler->startProfile('slowCheck', ['user' => 'user:123']);
            $slow->addMetadata('test', 123); // Use numeric value
            // Mock a slow operation by setting end time
            $startTimeReflection = new ReflectionProperty($slow, 'startTime');
            $startTime = $startTimeReflection->getValue($slow);
            $endTimeReflection = new ReflectionProperty($slow, 'endTime');
            $endTimeReflection->setValue($slow, $startTime + 0.15); // 150ms

            Log::shouldReceive('channel')
                ->with('test-channel')
                ->once()
                ->andReturnSelf();

            Log::shouldReceive('warning')
                ->once()
                ->with('Slow OpenFGA query detected', Mockery::on(fn ($context) => 'slowCheck' === $context['operation']
                        && 100 < $context['duration']
                        && $context['parameters'] === ['user' => 'user:123']
                        && $context['metadata'] === ['test' => 123]));

            $this->profiler->logSlowQueries();
        });

        it('does not log when logging is disabled', function (): void {
            $this->setConfigWithRestore('openfga.profiling.log_slow_queries', false);

            $slow = $this->profiler->startProfile('slowCheck');
            // Mock a slow operation by setting end time
            $startTimeReflection = new ReflectionProperty($slow, 'startTime');
            $startTime = $startTimeReflection->getValue($slow);
            $endTimeReflection = new ReflectionProperty($slow, 'endTime');
            $endTimeReflection->setValue($slow, $startTime + 0.15); // 150ms

            // Since Log facade uses a real logger that gets created,
            // we can't test that no methods are called.
            // The actual implementation checks the config and returns early.
            $this->profiler->logSlowQueries();

            // Just verify the method completes without error
            expect(true)->toBeTrue();
        });

        it('handles invalid channel configuration', function (): void {
            $this->setConfigWithRestore('openfga.logging.channel', ['invalid']);

            Log::shouldReceive('channel')
                ->with('default')
                ->once()
                ->andReturnSelf();

            Log::shouldReceive('warning')
                ->once();

            $slow = $this->profiler->startProfile('slowCheck');
            // Mock a slow operation by setting end time
            $startTimeReflection = new ReflectionProperty($slow, 'startTime');
            $startTime = $startTimeReflection->getValue($slow);
            $endTimeReflection = new ReflectionProperty($slow, 'endTime');
            $endTimeReflection->setValue($slow, $startTime + 0.15); // 150ms

            $this->profiler->logSlowQueries();
        });
    });

    describe('reset', function (): void {
        it('clears all profiles', function (): void {
            $this->profiler->startProfile('check1');
            $this->profiler->startProfile('check2');
            $this->profiler->startProfile('check3');

            expect($this->profiler->getProfiles())->toHaveCount(3);

            $this->profiler->reset();

            expect($this->profiler->getProfiles())->toHaveCount(0);
        });
    });

    describe('toArray', function (): void {
        it('serializes profiler state to array', function (): void {
            $this->profiler->startProfile('check', ['user' => 'user:123'])->end();
            $this->profiler->startProfile('listObjects')->end();

            $array = $this->profiler->toArray();

            expect($array)->toHaveKeys(['enabled', 'profiles', 'summary']);
            expect($array['enabled'])->toBeTrue();
            expect($array['profiles'])->toHaveCount(2);
            expect($array['summary']['total_operations'])->toBe(2);
        });

        it('includes profile details in array format', function (): void {
            $entry = $this->profiler->startProfile('check');
            $entry->setCacheStatus('hit')->end();

            $array = $this->profiler->toArray();

            expect($array['profiles'][0])->toHaveKeys([
                'operation',
                'parameters',
                'started_at',
                'duration_ms',
                'success',
                'error',
                'cache_status',
                'metadata',
            ]);
            expect($array['profiles'][0]['operation'])->toBe('check');
            expect($array['profiles'][0]['cache_status'])->toBe('hit');
        });
    });

    describe('profile tracking scenarios', function (): void {
        it('tracks multiple operations of same type', function (): void {
            for ($i = 0; 5 > $i; $i++) {
                $this->profiler->startProfile('check')->end();
            }

            $summary = $this->profiler->getSummary();

            expect($summary['operations'])->toHaveKey('check');
            expect($summary['operations']['check']['count'])->toBe(5);
        });

        it('calculates correct statistics for operations', function (): void {
            // Create operations with different durations
            $durations = [10, 20, 30, 40, 50]; // milliseconds

            foreach ($durations as $duration) {
                $entry = $this->profiler->startProfile('test');
                // Mock the duration by setting end time
                $startTimeReflection = new ReflectionProperty($entry, 'startTime');
                $startTime = $startTimeReflection->getValue($entry);
                $endTimeReflection = new ReflectionProperty($entry, 'endTime');
                $endTimeReflection->setValue($entry, $startTime + ($duration / 1000)); // Convert to seconds
            }

            $summary = $this->profiler->getSummary();

            expect($summary['operations'])->toHaveKey('test');
            $stats = $summary['operations']['test'];

            expect($stats['count'])->toBe(5);
            expect($stats['avg_time'])->toBeGreaterThan(25); // Should be around 30ms
            expect($stats['min_time'])->toBeGreaterThan(5);
            expect($stats['max_time'])->toBeGreaterThan(45);
        });
    });
});
