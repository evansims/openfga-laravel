<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\BatchWriteCompleted;
use OpenFGA\Laravel\Events\BatchWriteFailed;
use OpenFGA\Laravel\Events\PermissionChecked;
use OpenFGA\Laravel\Events\PermissionGranted;
use OpenFGA\Laravel\Events\PermissionRevoked;

/**
 * Listener to audit permission changes and operations.
 */
class AuditPermissionChanges implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle permission checked events.
     */
    public function handlePermissionChecked(PermissionChecked $event): void
    {
        if (!$this->shouldLog('check')) {
            return;
        }

        $context = array_merge([
            'user' => $event->user,
            'relation' => $event->relation,
            'object' => $event->object,
            'allowed' => $event->allowed,
            'connection' => $event->connection,
            'duration_ms' => round($event->duration * 1000, 2),
            'cached' => $event->cached,
        ], $event->context);

        Log::channel($this->getLogChannel())->info(
            'OpenFGA permission check: ' . $event->toString(),
            $context
        );
    }

    /**
     * Handle permission granted events.
     */
    public function handlePermissionGranted(PermissionGranted $event): void
    {
        if (!$this->shouldLog('grant')) {
            return;
        }

        $context = array_merge([
            'user' => $event->user,
            'relation' => $event->relation,
            'object' => $event->object,
            'connection' => $event->connection,
            'duration_ms' => round($event->duration * 1000, 2),
        ], $event->context);

        Log::channel($this->getLogChannel())->info(
            'OpenFGA permission granted: ' . $event->toString(),
            $context
        );
    }

    /**
     * Handle permission revoked events.
     */
    public function handlePermissionRevoked(PermissionRevoked $event): void
    {
        if (!$this->shouldLog('revoke')) {
            return;
        }

        $context = array_merge([
            'user' => $event->user,
            'relation' => $event->relation,
            'object' => $event->object,
            'connection' => $event->connection,
            'duration_ms' => round($event->duration * 1000, 2),
        ], $event->context);

        Log::channel($this->getLogChannel())->info(
            'OpenFGA permission revoked: ' . $event->toString(),
            $context
        );
    }

    /**
     * Handle batch write completed events.
     */
    public function handleBatchWriteCompleted(BatchWriteCompleted $event): void
    {
        if (!$this->shouldLog('batch')) {
            return;
        }

        Log::channel($this->getLogChannel())->info(
            'OpenFGA batch write completed',
            $event->getSummary()
        );
    }

    /**
     * Handle batch write failed events.
     */
    public function handleBatchWriteFailed(BatchWriteFailed $event): void
    {
        if (!$this->shouldLog('batch')) {
            return;
        }

        Log::channel($this->getLogChannel())->error(
            'OpenFGA batch write failed',
            array_merge($event->getSummary(), [
                'trace' => $event->exception->getTraceAsString(),
            ])
        );
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
            PermissionGranted::class => 'handlePermissionGranted',
            PermissionRevoked::class => 'handlePermissionRevoked',
            BatchWriteCompleted::class => 'handleBatchWriteCompleted',
            BatchWriteFailed::class => 'handleBatchWriteFailed',
        ];
    }

    /**
     * Determine if the operation should be logged.
     */
    protected function shouldLog(string $operation): bool
    {
        if (!config('openfga.logging.enabled', true)) {
            return false;
        }

        $operations = config('openfga.logging.operations', ['grant', 'revoke', 'batch']);
        
        return in_array($operation, $operations, true);
    }

    /**
     * Get the log channel to use.
     */
    protected function getLogChannel(): string
    {
        return config('openfga.logging.channel', config('logging.default'));
    }
}