<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Deduplication;

use Exception;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;

use function is_string;
use function sprintf;

final class RequestDeduplicator
{
    /**
     * @var array{enabled: bool, ttl: int, in_flight_ttl: int, prefix: string}
     */
    private array $config;

    /**
     * @var array<string, array{started_at: float, result: mixed, completed: bool}>
     */
    private array $inFlight = [];

    /**
     * @var array{total_requests: int, deduplicated: int, cache_hits: int, cache_misses: int}
     */
    private array $stats = [
        'total_requests' => 0,
        'deduplicated' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
    ];

    /**
     * @param Cache                                                                  $cache
     * @param array{enabled?: bool, ttl?: int, in_flight_ttl?: int, prefix?: string} $config
     */
    public function __construct(private readonly Cache $cache, array $config = [])
    {
        $this->config = [
            'enabled' => $config['enabled'] ?? true,
            'ttl' => $config['ttl'] ?? 60,
            'in_flight_ttl' => $config['in_flight_ttl'] ?? 5,
            'prefix' => $config['prefix'] ?? 'openfga_dedup',
        ];
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
     * @template T
     *
     * @param string               $operation
     * @param array<string, mixed> $params
     * @param callable(): T        $callback
     *
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return T
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

            /** @var T $cached */
            return $cached;
        }

        // Check if the same request is already in flight
        if ($this->isInFlight($key)) {
            ++$this->stats['deduplicated'];

            /** @var T */
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
     *
     * @return array{total_requests: int, deduplicated: int, cache_hits: int, cache_misses: int, hit_rate: float, deduplication_rate: float}
     */
    public function getStats(): array
    {
        $hitRate = 0 < $this->stats['total_requests']
            ? round(((float) $this->stats['cache_hits'] / (float) $this->stats['total_requests']) * 100.0, 2)
            : 0.0;

        $deduplicationRate = 0 < $this->stats['total_requests']
            ? round(((float) $this->stats['deduplicated'] / (float) $this->stats['total_requests']) * 100.0, 2)
            : 0.0;

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
     *
     * @throws InvalidArgumentException
     */
    private function checkCache(string $key): mixed
    {
        /** @var mixed $cached */
        $cached = $this->cache->get($key);

        if (is_string($cached)) {
            return unserialize($cached);
        }

        ++$this->stats['cache_misses'];

        return null;
    }

    /**
     * Generate a cache key for the request.
     *
     * @param string               $operation
     * @param array<string, mixed> $params
     */
    private function generateKey(string $operation, array $params): string
    {
        // Sort params for consistent key generation
        ksort($params);

        $encoded = json_encode($params);

        if (false === $encoded) {
            $encoded = '';
        }

        $hash = md5($operation . ':' . $encoded);

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
     *
     * @throws RuntimeException
     */
    private function waitForInFlight(string $key): mixed
    {
        $timeout = $this->config['in_flight_ttl'];
        $start = microtime(true);

        while ((microtime(true) - $start) < $timeout) {
            // Check local in-flight
            if (isset($this->inFlight[$key])) {
                $inflight = $this->inFlight[$key];

                if ($inflight['completed']) {
                    return $inflight['result'];
                }
            }

            // Check cache (request might have completed on another server)
            /** @var mixed $cached */
            $cached = $this->checkCache($key);

            if (null !== $cached) {
                return $cached;
            }

            // Check if still in flight in cache
            if (! $this->cache->has($key . ':inflight')) {
                // Request completed, check cache again
                /** @var mixed $cached */
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
