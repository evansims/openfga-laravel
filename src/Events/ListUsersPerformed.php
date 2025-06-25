<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class ListUsersPerformed
{
    public function __construct(
        public string $object,
        public string $relation,
        public string $userFilter,
        public array $users,
        public float $duration,
    ) {
    }
}
