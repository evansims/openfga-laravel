<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use function sprintf;

/**
 * Event fired when cache has been warmed.
 */
final class CacheWarmed
{
    /**
     * Create a new event instance.
     *
     * @param string               $identifier
     * @param int                  $entriesWarmed
     * @param ?string              $connection
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $identifier,
        public int $entriesWarmed,
        public ?string $connection = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Get a string representation of the event.
     */
    public function toString(): string
    {
        return sprintf(
            'Cache warmed for %s: %d entries',
            $this->identifier,
            $this->entriesWarmed,
        );
    }
}
