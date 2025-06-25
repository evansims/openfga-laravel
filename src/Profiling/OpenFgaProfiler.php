<?php

declare(strict_types=1);

namespace OpenFga\Laravel\Profiling;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class OpenFgaProfiler
{
    private bool $enabled;

    private Collection $profiles;

    private float $slowQueryThreshold;

    public function __construct()
    {
        $this->profiles = collect();
        $this->enabled = config('openfga.profiling.enabled', false);
        $this->slowQueryThreshold = config('openfga.profiling.slow_query_threshold', 100);
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function getProfiles(): Collection
    {
        return $this->profiles;
    }

    public function getSlowQueries(): Collection
    {
        return $this->profiles->filter(fn (ProfileEntry $entry): bool => $entry->getDuration() > $this->slowQueryThreshold);
    }

    public function getSummary(): array
    {
        $operations = $this->profiles->groupBy('operation');

        $summary = [];

        foreach ($operations as $operation => $entries) {
            $durations = $entries->map->getDuration();
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
            'total_time' => $this->profiles->sum->getDuration(),
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
        if (! config('openfga.profiling.log_slow_queries', true)) {
            return;
        }

        $this->getSlowQueries()->each(static function (ProfileEntry $entry): void {
            Log::channel(config('openfga.logging.channel', 'default'))
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

    public function startProfile(string $operation, array $parameters = []): ProfileEntry
    {
        if (! $this->enabled) {
            return new ProfileEntry($operation, $parameters);
        }

        $entry = new ProfileEntry($operation, $parameters);
        $this->profiles->push($entry);

        return $entry;
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'profiles' => $this->profiles->map->toArray()->values()->all(),
            'summary' => $this->getSummary(),
        ];
    }
}
