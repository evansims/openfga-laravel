<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\{BatchWriteCompleted, BatchWriteFailed, PermissionChecked, PermissionGranted, PermissionRevoked};

use function in_array;
use function is_array;
use function is_bool;
use function is_string;

/**
 * Listener to audit permission changes and operations.
 */
final class AuditPermissionChanges implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle batch write completed events.
     *
     * @param BatchWriteCompleted $event
     */
    public function handleBatchWriteCompleted(BatchWriteCompleted $event): void
    {
        if (! $this->shouldLog('batch')) {
            return;
        }

        Log::channel($this->getLogChannel())->info(
            'OpenFGA batch write completed',
            $event->getSummary(),
        );
    }

    /**
     * Handle batch write failed events.
     *
     * @param BatchWriteFailed $event
     */
    public function handleBatchWriteFailed(BatchWriteFailed $event): void
    {
        if (! $this->shouldLog('batch')) {
            return;
        }

        Log::channel($this->getLogChannel())->error(
            'OpenFGA batch write failed',
            array_merge($event->getSummary(), [
                'trace' => $event->exception->getTraceAsString(),
            ]),
        );
    }

    /**
     * Handle permission checked events.
     *
     * @param PermissionChecked $event
     */
    public function handlePermissionChecked(PermissionChecked $event): void
    {
        if (! $this->shouldLog('check')) {
            return;
        }

        $context = array_merge([
            'user' => $event->user,
            'relation' => $event->relation,
            'object' => $event->object,
            'allowed' => $event->allowed,
            'connection' => $event->connection,
            'duration_ms' => round($event->duration * 1000.0, 2),
            'cached' => $event->cached,
        ], $event->context);

        Log::channel($this->getLogChannel())->info(
            'OpenFGA permission check: ' . $event->toString(),
            $context,
        );
    }

    /**
     * Handle permission granted events.
     *
     * @param PermissionGranted $event
     */
    public function handlePermissionGranted(PermissionGranted $event): void
    {
        if (! $this->shouldLog('grant')) {
            return;
        }

        $context = array_merge([
            'user' => $event->user,
            'relation' => $event->relation,
            'object' => $event->object,
            'connection' => $event->connection,
            'duration_ms' => round($event->duration * 1000.0, 2),
        ], $event->context);

        Log::channel($this->getLogChannel())->info(
            'OpenFGA permission granted: ' . $event->toString(),
            $context,
        );
    }

    /**
     * Handle permission revoked events.
     *
     * @param PermissionRevoked $event
     */
    public function handlePermissionRevoked(PermissionRevoked $event): void
    {
        if (! $this->shouldLog('revoke')) {
            return;
        }

        $context = array_merge([
            'user' => $event->user,
            'relation' => $event->relation,
            'object' => $event->object,
            'connection' => $event->connection,
            'duration_ms' => round($event->duration * 1000.0, 2),
        ], $event->context);

        Log::channel($this->getLogChannel())->info(
            'OpenFGA permission revoked: ' . $event->toString(),
            $context,
        );
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
            PermissionGranted::class => 'handlePermissionGranted',
            PermissionRevoked::class => 'handlePermissionRevoked',
            BatchWriteCompleted::class => 'handleBatchWriteCompleted',
            BatchWriteFailed::class => 'handleBatchWriteFailed',
        ];
    }

    /**
     * Get the log channel to use.
     */
    private function getLogChannel(): string
    {
        /** @var mixed $channel */
        $channel = config('openfga.logging.channel') ?? config('logging.default') ?? 'stack';

        return is_string($channel) ? $channel : 'stack';
    }

    /**
     * Determine if the operation should be logged.
     *
     * @param string $operation
     */
    private function shouldLog(string $operation): bool
    {
        $enabled = config('openfga.logging.enabled', true);

        if (! is_bool($enabled)) {
            return true; // Default to enabled if config is invalid
        }

        if (! $enabled) {
            return false;
        }

        $operations = config('openfga.logging.operations', ['grant', 'revoke', 'batch']);

        if (! is_array($operations)) {
            return true; // Default to logging all operations if config is invalid
        }

        return in_array($operation, $operations, true);
    }
}
