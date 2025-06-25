<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\{Channel, InteractsWithSockets};
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OpenFGA\Laravel\Batch\BatchResult;
use Throwable;

final class BatchFailed
{
    use Dispatchable;

    use InteractsWithSockets;

    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param BatchResult $result
     * @param Throwable   $exception
     */
    public function __construct(
        public BatchResult $result,
        public Throwable $exception,
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
}
