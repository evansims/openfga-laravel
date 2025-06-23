<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Monitoring;

use Illuminate\Support\Facades\Cache;
use OpenFGA\Laravel\Events\PermissionChecked;
use OpenFGA\Laravel\Events\BatchWriteCompleted;
use OpenFGA\Laravel\Events\RelationExpanded;
use OpenFGA\Laravel\Events\ObjectsListed;

/**
 * Performance monitoring for OpenFGA operations.
 */
class PerformanceMonitor
{
    /**
     * Track a permission check operation.
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
     * Track a batch write operation.
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
     * Track a relation expansion.
     */
    public function trackRelationExpanded(RelationExpanded $event): void
    {
        $this->recordMetric('relation_expansions', [
            'user_count' => count($event->getUsers()),
            'duration' => $event->duration,
        ]);
    }

    /**
     * Track an object listing operation.
     */
    public function trackObjectsListed(ObjectsListed $event): void
    {
        $this->recordMetric('object_listings', [
            'object_count' => $event->getObjectCount(),
            'duration' => $event->duration,
        ]);
    }

    /**
     * Get performance statistics.
     *
     * @param string|null $metric Specific metric to retrieve
     * @param int $minutes Time window in minutes
     */
    public function getStatistics(?string $metric = null, int $minutes = 60): array
    {
        $stats = [];

        if ($metric === null || $metric === 'permission_checks') {
            $stats['permission_checks'] = $this->getMetricStats('permission_checks', $minutes);
        }

        if ($metric === null || $metric === 'batch_writes') {
            $stats['batch_writes'] = $this->getMetricStats('batch_writes', $minutes);
        }

        if ($metric === null || $metric === 'cache') {
            $stats['cache'] = $this->getCacheStats($minutes);
        }

        if ($metric === null || $metric === 'performance') {
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

        foreach ($keys as $pattern) {
            $this->clearCachePattern($pattern);
        }
    }

    /**
     * Record a metric data point.
     */
    protected function recordMetric(string $name, array $data): void
    {
        $key = $this->getMetricKey($name);
        $ttl = 3600; // 1 hour

        $metrics = Cache::get($key, []);
        $metrics[] = array_merge($data, [
            'timestamp' => now()->timestamp,
        ]);

        // Keep only last 1000 entries
        if (count($metrics) > 1000) {
            $metrics = array_slice($metrics, -1000);
        }

        Cache::put($key, $metrics, $ttl);
    }

    /**
     * Increment a counter.
     */
    protected function incrementCounter(string $name): void
    {
        $key = "openfga:counters:{$name}";
        Cache::increment($key);
    }

    /**
     * Record a value in a histogram.
     */
    protected function recordHistogram(string $name, float $value): void
    {
        $key = "openfga:histograms:{$name}";
        $ttl = 3600; // 1 hour

        $histogram = Cache::get($key, []);
        $histogram[] = [
            'value' => $value,
            'timestamp' => now()->timestamp,
        ];

        // Keep only last 1000 entries
        if (count($histogram) > 1000) {
            $histogram = array_slice($histogram, -1000);
        }

        Cache::put($key, $histogram, $ttl);
    }

    /**
     * Get statistics for a specific metric.
     */
    protected function getMetricStats(string $name, int $minutes): array
    {
        $key = $this->getMetricKey($name);
        $metrics = Cache::get($key, []);

        $cutoff = now()->subMinutes($minutes)->timestamp;
        $filtered = array_filter($metrics, fn($m) => $m['timestamp'] >= $cutoff);

        if (empty($filtered)) {
            return [
                'count' => 0,
                'avg_duration' => 0,
                'min_duration' => 0,
                'max_duration' => 0,
            ];
        }

        $durations = array_column($filtered, 'duration');

        return [
            'count' => count($filtered),
            'avg_duration' => array_sum($durations) / count($durations),
            'min_duration' => min($durations),
            'max_duration' => max($durations),
        ];
    }

    /**
     * Get cache statistics.
     */
    protected function getCacheStats(int $minutes): array
    {
        $hits = Cache::get('openfga:counters:cache_hits', 0);
        $misses = Cache::get('openfga:counters:cache_misses', 0);
        $total = $hits + $misses;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => $total > 0 ? ($hits / $total) * 100 : 0,
        ];
    }

    /**
     * Get overall performance statistics.
     */
    protected function getPerformanceStats(int $minutes): array
    {
        $checkStats = $this->getMetricStats('permission_checks', $minutes);
        $batchStats = $this->getMetricStats('batch_writes', $minutes);

        return [
            'total_operations' => $checkStats['count'] + $batchStats['count'],
            'avg_response_time' => ($checkStats['avg_duration'] + $batchStats['avg_duration']) / 2,
            'operations_per_minute' => ($checkStats['count'] + $batchStats['count']) / max(1, $minutes),
        ];
    }

    /**
     * Get the cache key for a metric.
     */
    protected function getMetricKey(string $name): string
    {
        return "openfga:metrics:{$name}";
    }

    /**
     * Clear cache entries matching a pattern.
     */
    protected function clearCachePattern(string $pattern): void
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

        foreach ($knownKeys as $key) {
            if (fnmatch($pattern, $key)) {
                Cache::forget($key);
            }
        }
    }
}