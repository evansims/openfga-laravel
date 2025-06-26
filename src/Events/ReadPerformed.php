<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class ReadPerformed
{
    /**
     * @param array<int, array{user: string, relation: string, object: string}> $tuples
     * @param int                                                               $pageSize
     * @param float                                                             $duration
     */
    public function __construct(
        public array $tuples,
        public int $pageSize,
        public float $duration,
    ) {
    }
}
