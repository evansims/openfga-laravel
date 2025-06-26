<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class ListUsersPerformed
{
    /**
     * @param array<int, string> $users
     * @param string             $object
     * @param string             $relation
     * @param string             $userFilter
     * @param float              $duration
     */
    public function __construct(
        public string $object,
        public string $relation,
        public string $userFilter,
        public array $users,
        public float $duration,
    ) {
    }
}
