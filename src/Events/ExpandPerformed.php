<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

final readonly class ExpandPerformed
{
    public function __construct(
        public string $object,
        public string $relation,
        public float $duration,
        public int $treeDepth = 0,
    ) {
    }
}
