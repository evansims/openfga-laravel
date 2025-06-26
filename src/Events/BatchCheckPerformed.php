<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

/**
 * Event dispatched after a batch of OpenFGA permission checks.
 *
 * This event is fired when multiple permissions are checked in a single
 * operation, providing performance metrics and cache statistics. Use this
 * to monitor batch operation efficiency, track cache hit rates, and optimize
 * permission checking strategies in high-throughput scenarios.
 */
final readonly class BatchCheckPerformed
{
    /**
     * @param array<int, array{user: string, relation: string, object: string}> $checks
     * @param float                                                             $duration
     * @param int                                                               $cacheHits
     * @param int                                                               $cacheMisses
     */
    public function __construct(
        public array $checks,
        public float $duration,
        public int $cacheHits = 0,
        public int $cacheMisses = 0,
    ) {
    }
}
