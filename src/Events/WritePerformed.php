<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class WritePerformed
{
    public function __construct(
        public array $writes,
        public array $deletes,
        public bool $success,
        public float $duration,
    ) {
    }
}
