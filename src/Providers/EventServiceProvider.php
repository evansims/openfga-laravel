<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use OpenFGA\Laravel\{Events, Listeners};
use Override;

/**
 * Event service provider for OpenFGA events and listeners.
 */
final class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        Events\PermissionChecked::class => [
            Listeners\AuditPermissionChanges::class . '@handlePermissionChecked',
            Listeners\MonitorPerformance::class . '@handlePermissionChecked',
        ],
        Events\PermissionGranted::class => [
            Listeners\AuditPermissionChanges::class . '@handlePermissionGranted',
        ],
        Events\PermissionRevoked::class => [
            Listeners\AuditPermissionChanges::class . '@handlePermissionRevoked',
        ],
        Events\BatchWriteCompleted::class => [
            Listeners\AuditPermissionChanges::class . '@handleBatchWriteCompleted',
            Listeners\MonitorPerformance::class . '@handleBatchWriteCompleted',
        ],
        Events\BatchWriteFailed::class => [
            Listeners\AuditPermissionChanges::class . '@handleBatchWriteFailed',
        ],
        Events\RelationExpanded::class => [
            Listeners\MonitorPerformance::class . '@handleRelationExpanded',
        ],
        Events\ObjectsListed::class => [
            Listeners\MonitorPerformance::class . '@handleObjectsListed',
        ],
    ];

    /**
     * The subscribers to register.
     *
     * @var array
     */
    protected $subscribe = [ // @phpstan-ignore missingType.iterableValue
        Listeners\AuditPermissionChanges::class,
        Listeners\MonitorPerformance::class,
    ];

    /**
     * Register any events for your application.
     */
    #[Override]
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    #[Override]
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
