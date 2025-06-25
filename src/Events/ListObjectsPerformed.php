<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class ListObjectsPerformed
{
    public function __construct(
        public string $user,
        public string $relation,
        public string $type,
        public array $objects,
        public float $duration,
    ) {
    }
}
