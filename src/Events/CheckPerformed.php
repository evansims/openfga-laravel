<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

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
