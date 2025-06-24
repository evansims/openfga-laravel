<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Monitoring;

use Illuminate\Support\Facades\Cache;
use OpenFGA\Laravel\Events\{BatchWriteCompleted, ObjectsListed, PermissionChecked, RelationExpanded};

use function array_slice;
use function count;
use function is_array;

/**
 * Performance monitoring for OpenFGA operations.
 */
final class PerformanceMonitor
{
    /**
     * Get performance statistics.
     *
     * @param  string|null                                                                                                                                                                                                                                               $metric  Specific metric to retrieve
     * @param  int                                                                                                                                                                                                                                                       $minutes Time window in minutes
     * @return array<string, array{count?: float|int, avg_duration?: float, min_duration?: float, max_duration?: float, hits?: float|int, misses?: float|int, hit_rate?: float, total_operations?: float|int, avg_response_time?: float, operations_per_minute?: float}>
     */
    public function getStatistics(?string $metric = null, int $minutes = 60): array
    {
        $stats = [];

        if (null === $metric || 'permission_checks' === $metric) {
            $stats['permission_checks'] = $this->getMetricStats('permission_checks', $minutes);
        }

        if (null === $metric || 'batch_writes' === $metric) {
            $stats['batch_writes'] = $this->getMetricStats('batch_writes', $minutes);
        }

        if (null === $metric || 'cache' === $metric) {
            $stats['cache'] = $this->getCacheStats();
        }

        if (null === $metric || 'performance' === $metric) {
            $stats['performance'] = $this->getPerformanceStats($minutes);
        }

        return $stats;
    }

    /**
     * Reset all metrics.
     */
    public function reset(): void
    {
        $keys = [
            'openfga:metrics:*',
            'openfga:counters:*',
            'openfga:histograms:*',
        ];

        foreach ($keys as $key) {
            $this->clearCachePattern($key);
        }
    }

    /**
     * Track a batch write operation.
     *
     * @param BatchWriteCompleted $event
     */
    public function trackBatchWrite(BatchWriteCompleted $event): void
    {
        $this->recordMetric('batch_writes', [
            'writes' => count($event->writes),
            'deletes' => count($event->deletes),
            'duration' => $event->duration,
        ]);

        $this->recordHistogram('batch_size', $event->getTotalOperations());
    }

    /**
     * Track an object listing operation.
     *
     * @param ObjectsListed $event
     */
    public function trackObjectsListed(ObjectsListed $event): void
    {
        $this->recordMetric('object_listings', [
            'object_count' => $event->getObjectCount(),
            'duration' => $event->duration,
        ]);
    }

    /**
     * Track a permission check operation.
     *
     * @param PermissionChecked $event
     */
    public function trackPermissionCheck(PermissionChecked $event): void
    {
        $this->recordMetric('permission_checks', [
            'allowed' => $event->allowed,
            'cached' => $event->cached,
            'duration' => $event->duration,
        ]);

        if ($event->cached) {
            $this->incrementCounter('cache_hits');
        } else {
            $this->incrementCounter('cache_misses');
        }
    }

    /**
     * Track a relation expansion.
     *
     * @param RelationExpanded $event
     */
    public function trackRelationExpanded(RelationExpanded $event): void
    {
        $this->recordMetric('relation_expansions', [
            'user_count' => count($event->getUsers()),
            'duration' => $event->duration,
        ]);
    }

    /**
     * Clear cache entries matching a pattern.
     *
     * @param string $pattern
     */
    private function clearCachePattern(string $pattern): void
    {
        // This is a simplified version - in production you'd use Redis SCAN or similar
        // For now, we'll clear known keys
        $knownKeys = [
            'openfga:metrics:permission_checks',
            'openfga:metrics:batch_writes',
            'openfga:metrics:relation_expansions',
            'openfga:metrics:object_listings',
            'openfga:counters:cache_hits',
            'openfga:counters:cache_misses',
            'openfga:histograms:batch_size',
        ];

        foreach ($knownKeys as $knownKey) {
            if (fnmatch($pattern, $knownKey)) {
                Cache::forget($knownKey);
            }
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array{hits: float|int, misses: float|int, hit_rate: float}
     */
    private function getCacheStats(): array
    {
        $hitsValue = Cache::get('openfga:counters:cache_hits', 0);
        $missesValue = Cache::get('openfga:counters:cache_misses', 0);

        $hits = is_numeric($hitsValue) ? (float) $hitsValue : 0;
        $misses = is_numeric($missesValue) ? (float) $missesValue : 0;
        $total = (float) $hits + (float) $misses;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => 0 < $total ? ($hits / $total) * 100.0 : 0.0,
        ];
    }

    /**
     * Get the cache key for a metric.
     *
     * @param string $name
     */
    private function getMetricKey(string $name): string
    {
        return 'openfga:metrics:' . $name;
    }

    /**
     * Get statistics for a specific metric.
     *
     * @param  string                                                                                 $name
     * @param  int                                                                                    $minutes
     * @return array{count: float|int, avg_duration: float, min_duration: float, max_duration: float}
     */
    private function getMetricStats(string $name, int $minutes): array
    {
        $key = $this->getMetricKey($name);
        $metricsValue = Cache::get($key, []);
        $metrics = is_array($metricsValue) ? $metricsValue : [];

        $cutoff = now()->subMinutes($minutes)->timestamp;
        $filtered = array_filter($metrics, static fn ($m): bool => is_array($m) && isset($m['timestamp']) && $m['timestamp'] >= $cutoff);

        if ([] === $filtered) {
            return [
                'count' => 0,
                'avg_duration' => 0,
                'min_duration' => 0,
                'max_duration' => 0,
            ];
        }

        $durations = array_column($filtered, 'duration');
        $numericDurations = array_filter($durations, 'is_numeric');

        if ([] === $numericDurations) {
            return [
                'count' => 0,
                'avg_duration' => 0,
                'min_duration' => 0,
                'max_duration' => 0,
            ];
        }

        $floatDurations = array_map('floatval', $numericDurations);

        return [
            'count' => count($filtered),
            'avg_duration' => array_sum($floatDurations) / (float) count($floatDurations),
            'min_duration' => min($floatDurations),
            'max_duration' => max($floatDurations),
        ];
    }

    /**
     * Get overall performance statistics.
     *
     * @param  int                                                                                        $minutes
     * @return array{total_operations: float|int, avg_response_time: float, operations_per_minute: float}
     */
    private function getPerformanceStats(int $minutes): array
    {
        $checkStats = $this->getMetricStats('permission_checks', $minutes);
        $batchStats = $this->getMetricStats('batch_writes', $minutes);

        $totalOps = (float) $checkStats['count'] + (float) $batchStats['count'];
        $avgDuration = ($checkStats['avg_duration'] + $batchStats['avg_duration']) / 2.0;
        $opsPerMinute = $totalOps / (float) max(1, $minutes);

        return [
            'total_operations' => $totalOps,
            'avg_response_time' => $avgDuration,
            'operations_per_minute' => $opsPerMinute,
        ];
    }

    /**
     * Increment a counter.
     *
     * @param string $name
     */
    private function incrementCounter(string $name): void
    {
        $key = 'openfga:counters:' . $name;
        Cache::increment($key);
    }

    /**
     * Record a value in a histogram.
     *
     * @param string $name
     * @param float  $value
     */
    private function recordHistogram(string $name, float $value): void
    {
        $key = 'openfga:histograms:' . $name;
        $ttl = 3600; // 1 hour

        $histogramValue = Cache::get($key, []);
        $histogram = is_array($histogramValue) ? $histogramValue : [];

        $histogram[] = [
            'value' => $value,
            'timestamp' => now()->timestamp,
        ];

        // Keep only last 1000 entries
        if (1000 < count($histogram)) {
            $histogram = array_slice($histogram, -1000);
        }

        Cache::put($key, $histogram, $ttl);
    }

    /**
     * Record a metric data point.
     *
     * @param string               $name
     * @param array<string, mixed> $data
     */
    private function recordMetric(string $name, array $data): void
    {
        $key = $this->getMetricKey($name);
        $ttl = 3600; // 1 hour

        $metricsValue = Cache::get($key, []);
        $metrics = is_array($metricsValue) ? $metricsValue : [];

        $metrics[] = array_merge($data, [
            'timestamp' => now()->timestamp,
        ]);

        // Keep only last 1000 entries
        if (1000 < count($metrics)) {
            $metrics = array_slice($metrics, -1000);
        }

        Cache::put($key, $metrics, $ttl);
    }
}
