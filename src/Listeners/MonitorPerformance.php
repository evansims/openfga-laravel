<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Listeners;

use OpenFGA\Laravel\Events\BatchWriteCompleted;
use OpenFGA\Laravel\Events\ObjectsListed;
use OpenFGA\Laravel\Events\PermissionChecked;
use OpenFGA\Laravel\Events\RelationExpanded;
use OpenFGA\Laravel\Monitoring\PerformanceMonitor;

/**
 * Listener to monitor performance of OpenFGA operations.
 */
class MonitorPerformance
{
    /**
     * Create a new listener instance.
     */
    public function __construct(
        protected PerformanceMonitor $monitor
    ) {
    }

    /**
     * Handle permission checked events.
     */
    public function handlePermissionChecked(PermissionChecked $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->monitor->trackPermissionCheck($event);
    }

    /**
     * Handle batch write completed events.
     */
    public function handleBatchWriteCompleted(BatchWriteCompleted $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->monitor->trackBatchWrite($event);
    }

    /**
     * Handle relation expanded events.
     */
    public function handleRelationExpanded(RelationExpanded $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->monitor->trackRelationExpanded($event);
    }

    /**
     * Handle objects listed events.
     */
    public function handleObjectsListed(ObjectsListed $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->monitor->trackObjectsListed($event);
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param \Illuminate\Events\Dispatcher $events
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
    protected function isEnabled(): bool
    {
        return config('openfga.monitoring.enabled', true);
    }
}