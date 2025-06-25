<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class ReadPerformed
{
    public function __construct(
        public array $tuples,
        public int $pageSize,
        public float $duration,
    ) {
    }
}
