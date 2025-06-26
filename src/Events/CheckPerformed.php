<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

/**
 * Event dispatched after an OpenFGA permission check is performed.
 *
 * This event provides detailed information about authorization checks,
 * including the result, performance metrics, and cache status. Use this
 * for audit logging, monitoring authorization patterns, debugging permission
 * issues, or building real-time dashboards of authorization activity.
 */
final readonly class CheckPerformed
{
    public function __construct(
        public string $user,
        public string $relation,
        public string $object,
        public bool $allowed,
        public float $duration,
        public bool $cacheHit = false,
    ) {
    }
}
