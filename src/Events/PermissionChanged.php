<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\{Channel, InteractsWithSockets};
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PermissionChanged
{
    use Dispatchable;

    use InteractsWithSockets;

    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param string $action
     * @param array  $metadata
     */
    public function __construct(
        public string $user,
        public string $relation,
        public string $object,
        public string $action,
        public array $metadata = [],
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [];
    }

    /**
     * Get the event type for webhook filtering.
     */
    public function getEventType(): string
    {
        return 'permission.' . $this->action;
    }
}
