<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when objects are listed for a user.
 */
class ObjectsListed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $user The user identifier
     * @param string $relation The relation being queried
     * @param string $type The object type
     * @param array<string> $objects The objects found
     * @param string|null $connection The connection used
     * @param float $duration The duration of the operation in seconds
     * @param array<string, mixed> $context Additional context
     */
    public function __construct(
        public readonly string $user,
        public readonly string $relation,
        public readonly string $type,
        public readonly array $objects,
        public readonly ?string $connection = null,
        public readonly float $duration = 0.0,
        public readonly array $context = []
    ) {
    }

    /**
     * Get the count of objects found.
     */
    public function getObjectCount(): int
    {
        return count($this->objects);
    }
}