<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\{Channel, InteractsWithSockets};
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OpenFGA\Laravel\Batch\BatchResult;

/**
 * Event dispatched after successful batch authorization processing.
 *
 * This event provides comprehensive results from batch permission operations,
 * including success rates, performance metrics, and individual operation outcomes.
 * Use this to track authorization patterns, measure batch efficiency, generate
 * audit logs, or trigger dependent workflows based on batch completion.
 */
final class BatchProcessed
{
    use Dispatchable;

    use InteractsWithSockets;

    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param BatchResult $result
     */
    public function __construct(
        public BatchResult $result,
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
