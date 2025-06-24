<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Listeners;

use Illuminate\Events\Dispatcher;
use OpenFGA\Laravel\Events\{BatchWriteCompleted, ObjectsListed, PermissionChecked, RelationExpanded};
use OpenFGA\Laravel\Monitoring\PerformanceMonitor;

use function is_bool;

/**
 * Listener to monitor performance of OpenFGA operations.
 */
final readonly class MonitorPerformance
{
    /**
     * Create a new listener instance.
     *
     * @param PerformanceMonitor $monitor
     */
    public function __construct(
        private PerformanceMonitor $monitor,
    ) {
    }

    /**
     * Handle batch write completed events.
     *
     * @param BatchWriteCompleted $event
     */
    public function handleBatchWriteCompleted(BatchWriteCompleted $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->monitor->trackBatchWrite($event);
    }

    /**
     * Handle objects listed events.
     *
     * @param ObjectsListed $event
     */
    public function handleObjectsListed(ObjectsListed $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->monitor->trackObjectsListed($event);
    }

    /**
     * Handle permission checked events.
     *
     * @param PermissionChecked $event
     */
    public function handlePermissionChecked(PermissionChecked $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->monitor->trackPermissionCheck($event);
    }

    /**
     * Handle relation expanded events.
     *
     * @param RelationExpanded $event
     */
    public function handleRelationExpanded(RelationExpanded $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->monitor->trackRelationExpanded($event);
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param  Dispatcher            $events
     * @return array<string, string>
     */
    public function subscribe($events): array
    {
        return [
            PermissionChecked::class => 'handlePermissionChecked',
            BatchWriteCompleted::class => 'handleBatchWriteCompleted',
            RelationExpanded::class => 'handleRelationExpanded',
            ObjectsListed::class => 'handleObjectsListed',
        ];
    }

    /**
     * Check if performance monitoring is enabled.
     */
    private function isEnabled(): bool
    {
        /** @var mixed $enabled */
        $enabled = config('openfga.monitoring.enabled', true);

        return is_bool($enabled) ? $enabled : true;
    }
}
