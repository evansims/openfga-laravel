<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class ListRelationsPerformed
{
    /**
     * @param array<int, string> $relations
     * @param string             $user
     * @param string             $object
     * @param float              $duration
     */
    public function __construct(
        public string $user,
        public string $object,
        public array $relations,
        public float $duration,
    ) {
    }
}
