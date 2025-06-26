<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class WritePerformed
{
    /**
     * @param array<int, array{user: string, relation: string, object: string}> $writes
     * @param array<int, array{user: string, relation: string, object: string}> $deletes
     * @param bool                                                              $success
     * @param float                                                             $duration
     */
    public function __construct(
        public array $writes,
        public array $deletes,
        public bool $success,
        public float $duration,
    ) {
    }
}
