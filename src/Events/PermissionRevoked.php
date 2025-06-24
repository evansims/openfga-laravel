<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

use function sprintf;

/**
 * Event fired when a permission is revoked.
 */
final class PermissionRevoked
{
    use Dispatchable;

    use InteractsWithSockets;

    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string               $user       The user identifier
     * @param string               $relation   The relation being revoked
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
     * Get a string representation of the permission revoke.
     */
    public function toString(): string
    {
        return sprintf(
            'Revoked: %s#%s@%s',
            $this->user,
            $this->relation,
            $this->object,
        );
    }
}
