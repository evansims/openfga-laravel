<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

use function sprintf;

/**
 * Event dispatched when a new permission is successfully granted.
 *
 * This event is fired after an OpenFGA write operation adds a new authorization
 * tuple, granting a user permission on an object. Use this event for audit
 * logging, notifications, cache invalidation, or triggering dependent permission
 * updates. The event includes timing information for performance monitoring.
 */
final class PermissionGranted
{
    use Dispatchable;

    use InteractsWithSockets;

    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string               $user       The user identifier
     * @param string               $relation   The relation being granted
     * @param string               $object     The object identifier
     * @param string|null          $connection The connection used
     * @param float                $duration   The duration of the operation in seconds
     * @param array<string, mixed> $context    Additional context
     */
    public function __construct(
        public readonly string $user,
        public readonly string $relation,
        public readonly string $object,
        public readonly ?string $connection = null,
        public readonly float $duration = 0.0,
        public readonly array $context = [],
    ) {
    }

    /**
     * Get a string representation of the permission grant.
     */
    public function toString(): string
    {
        return sprintf(
            'Granted: %s#%s@%s',
            $this->user,
            $this->relation,
            $this->object,
        );
    }
}
