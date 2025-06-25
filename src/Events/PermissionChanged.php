<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PermissionChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $user,
        public string $relation,
        public string $object,
        public string $action,
        public array $metadata = []
    ) {}

    /**
     * Get the event type for webhook filtering
     */
    public function getEventType(): string
    {
        return "permission.{$this->action}";
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [];
    }
}