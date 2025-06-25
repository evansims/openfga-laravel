<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Deduplication;

use Exception;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use RuntimeException;

use function sprintf;

final class RequestDeduplicator
{
    private array $config;

    private array $inFlight = [];

    private array $stats = [
        'total_requests' => 0,
        'deduplicated' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
    ];

    public function __construct(private readonly Cache $cache, array $config = [])
    {
        $this->config = array_merge([
            'enabled' => true,
            'ttl' => 60, // seconds
            'in_flight_ttl' => 5, // seconds
            'prefix' => 'openfga_dedup',
        ], $config);
    }

    /**
     * Clear all cached results.
     */
    public function clear(): void
    {
        // This would need to be implemented based on your cache driver
        // For tagged cache, you could use tags
        $this->inFlight = [];
    }

    /**
     * Execute a request with deduplication.
     *
     * @param string   $operation
     * @param array    $params
     * @param callable $callback
     */
    public function execute(string $operation, array $params, callable $callback)
    {
        if (! $this->config['enabled']) {
            return $callback();
        }

        ++$this->stats['total_requests'];

        $key = $this->generateKey($operation, $params);

        // Check if we have a cached result
        $cached = $this->checkCache($key);

        if (null !== $cached) {
            ++$this->stats['cache_hits'];

            return $cached;
        }

        // Check if the same request is already in flight
        if ($this->isInFlight($key)) {
            ++$this->stats['deduplicated'];

            return $this->waitForInFlight($key);
        }

        // Mark as in flight
        $this->markInFlight($key);

        try {
            // Execute the actual request
            $result = $callback();

            // Cache the result
            $this->cacheResult($key, $result);

            // Remove from in-flight
            $this->removeInFlight($key, $result);

            return $result;
        } catch (Exception $exception) {
            // Remove from in-flight on error
            $this->removeInFlight($key, null);

            throw $exception;
        }
    }

    /**
     * Get deduplication statistics.
     */
    public function getStats(): array
    {
        $hitRate = 0 < $this->stats['total_requests']
            ? round(($this->stats['cache_hits'] / $this->stats['total_requests']) * 100, 2)
            : 0;

        $deduplicationRate = 0 < $this->stats['total_requests']
            ? round(($this->stats['deduplicated'] / $this->stats['total_requests']) * 100, 2)
            : 0;

        return array_merge($this->stats, [
            'hit_rate' => $hitRate,
            'deduplication_rate' => $deduplicationRate,
        ]);
    }

    /**
     * Reset statistics.
     */
    public function resetStats(): void
    {
        $this->stats = [
            'total_requests' => 0,
            'deduplicated' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
        ];
    }

    /**
     * Cache a result.
     *
     * @param string $key
     * @param mixed  $result
     */
    private function cacheResult(string $key, $result): void
    {
        $this->cache->put(
            $key,
            serialize($result),
            $this->config['ttl'],
        );
    }

    /**
     * Check if we have a cached result.
     *
     * @param string $key
     */
    private function checkCache(string $key): mixed
    {
        $cached = $this->cache->get($key);

        if (null !== $cached) {
            return unserialize($cached);
        }

        ++$this->stats['cache_misses'];

        return null;
    }

    /**
     * Generate a cache key for the request.
     *
     * @param string $operation
     * @param array  $params
     */
    private function generateKey(string $operation, array $params): string
    {
        // Sort params for consistent key generation
        ksort($params);

        $hash = md5($operation . ':' . json_encode($params));

        return sprintf('%s:%s:%s', $this->config['prefix'], $operation, $hash);
    }

    /**
     * Check if a request is in flight.
     *
     * @param string $key
     */
    private function isInFlight(string $key): bool
    {
        return isset($this->inFlight[$key]) || $this->cache->has($key . ':inflight');
    }

    /**
     * Mark a request as in flight.
     *
     * @param string $key
     */
    private function markInFlight(string $key): void
    {
        $this->inFlight[$key] = [
            'started_at' => microtime(true),
            'result' => null,
            'completed' => false,
        ];

        // Also mark in cache for distributed systems
        $this->cache->put(
            $key . ':inflight',
            Str::uuid()->toString(),
            $this->config['in_flight_ttl'],
        );
    }

    /**
     * Remove from in-flight tracking.
     *
     * @param string $key
     * @param mixed  $result
     */
    private function removeInFlight(string $key, $result): void
    {
        if (isset($this->inFlight[$key])) {
            $this->inFlight[$key]['result'] = $result;
            $this->inFlight[$key]['completed'] = true;

            // Keep for a short time for other waiters
            // In production, this would be handled differently
            unset($this->inFlight[$key]);
        }

        $this->cache->forget($key . ':inflight');
    }

    /**
     * Wait for an in-flight request to complete.
     *
     * @param string $key
     */
    private function waitForInFlight(string $key): mixed
    {
        $timeout = $this->config['in_flight_ttl'];
        $start = microtime(true);

        while ((microtime(true) - $start) < $timeout) {
            // Check local in-flight
            if (isset($this->inFlight[$key]) && $this->inFlight[$key]['completed']) {
                return $this->inFlight[$key]['result'];
            }

            // Check cache (request might have completed on another server)
            $cached = $this->checkCache($key);

            if (null !== $cached) {
                return $cached;
            }

            // Check if still in flight in cache
            if (! $this->cache->has($key . ':inflight')) {
                // Request completed, check cache again
                $cached = $this->checkCache($key);

                if (null !== $cached) {
                    return $cached;
                }

                // Request failed or timed out
                throw new RuntimeException('In-flight request failed or timed out');
            }

            // Sleep for 10ms before checking again
            usleep(10000);
        }

        throw new RuntimeException('Timeout waiting for in-flight request');
    }
}
