<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class BatchCheckPerformed
{
    public function __construct(
        public array $checks,
        public float $duration,
        public int $cacheHits = 0,
        public int $cacheMisses = 0,
    ) {
    }
}
