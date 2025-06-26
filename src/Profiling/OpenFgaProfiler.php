<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Profiling;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

use function is_string;

/**
 * Performance profiler for OpenFGA operations in Laravel applications.
 *
 * This profiler tracks execution times and patterns of authorization operations,
 * helping identify performance bottlenecks and slow queries. It provides detailed
 * metrics for each operation type, supports configurable slow query thresholds,
 * and can automatically log slow operations for monitoring. Use this to optimize
 * authorization performance and ensure responsive permission checks.
 *
 * @internal
 */
final class OpenFgaProfiler
{
    private readonly float $slowQueryThreshold;

    private bool $enabled;

    /**
     * @var Collection<int, ProfileEntry>
     */
    private Collection $profiles;

    public function __construct()
    {
        $this->profiles = collect();
        $this->enabled = (bool) config('openfga.profiling.enabled', false);

        /** @var mixed $threshold */
        $threshold = config('openfga.profiling.slow_query_threshold', 100);
        $this->slowQueryThreshold = is_numeric($threshold) ? (float) $threshold : 100.0;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * @return Collection<int, ProfileEntry>
     */
    public function getProfiles(): Collection
    {
        return $this->profiles;
    }

    /**
     * @return Collection<int, ProfileEntry>
     */
    public function getSlowQueries(): Collection
    {
        return $this->profiles->filter(fn (ProfileEntry $entry): bool => $entry->getDuration() > $this->slowQueryThreshold);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $operations = $this->profiles->groupBy('operation');

        $summary = [];

        foreach ($operations as $operation => $entries) {
            $durations = $entries->map(static fn (ProfileEntry $entry): float => $entry->getDuration());
            $summary[$operation] = [
                'count' => $entries->count(),
                'total_time' => $durations->sum(),
                'avg_time' => $durations->avg(),
                'min_time' => $durations->min(),
                'max_time' => $durations->max(),
            ];
        }

        return [
            'total_operations' => $this->profiles->count(),
            'total_time' => $this->profiles->sum(static fn (ProfileEntry $entry): float => $entry->getDuration()),
            'slow_queries' => $this->getSlowQueries()->count(),
            'operations' => $summary,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function logSlowQueries(): void
    {
        if (true !== config('openfga.profiling.log_slow_queries', true)) {
            return;
        }

        $this->getSlowQueries()->each(static function (ProfileEntry $entry): void {
            /** @var mixed $channel */
            $channel = config('openfga.logging.channel', 'default');
            Log::channel(is_string($channel) ? $channel : 'default')
                ->warning('Slow OpenFGA query detected', [
                    'operation' => $entry->getOperation(),
                    'duration' => $entry->getDuration(),
                    'parameters' => $entry->getParameters(),
                    'metadata' => $entry->getMetadata(),
                ]);
        });
    }

    public function reset(): void
    {
        $this->profiles = collect();
    }

    /**
     * @param array<string, mixed> $parameters
     * @param string               $operation
     */
    public function startProfile(string $operation, array $parameters = []): ProfileEntry
    {
        if (! $this->enabled) {
            return new ProfileEntry($operation, $parameters);
        }

        $entry = new ProfileEntry($operation, $parameters);
        $this->profiles->push($entry);

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'profiles' => $this->profiles->map(static fn (ProfileEntry $profile): array => $profile->toArray())->values()->all(),
            'summary' => $this->getSummary(),
        ];
    }
}
